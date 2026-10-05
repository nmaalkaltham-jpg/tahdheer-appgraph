<?php

declare(strict_types=1);

namespace App\Domain\ContentIntelligence;

use App\Domain\Assets\Exceptions\StorageUnavailable;
use App\Domain\Assets\Models\Asset;
use App\Domain\Assets\StorageResolver;
use App\Domain\ContentIntelligence\Contracts\FileAwareExtractor;
use App\Domain\ContentIntelligence\Contracts\StreamAwareExtractor;
use App\Domain\ContentIntelligence\Models\AssetExtraction;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use LogicException;
use RuntimeException;
use Throwable;
use ZipArchive;

/**
 * خط استخراج آمن وعام للأصول.
 *
 * يدعم معماريًا:
 *
 * - Text / Markdown / CSV / JSON / XML.
 * - PDF.
 * - Microsoft Office القديم والجديد.
 * - OpenDocument.
 * - SVG والصور النقطية.
 * - الصوت والفيديو.
 * - ZIP والأرشيفات.
 * - EPUB.
 * - الملفات الثنائية وغير المعروفة.
 * - الملفات الكبيرة عبر file/stream دون تحميلها كاملة في RAM.
 *
 * مفتاح dedup:
 *
 * (asset_sha256, extractor, extractor_version)
 *
 * terminal:
 * لا يُعاد إلا مع force=true.
 *
 * failed:
 * قابل للإعادة.
 *
 * capability_gap:
 * نهائي لهذه النسخة من المستخرج.
 */
final readonly class AssetExtractionService
{
    /**
     * أقصى حجم مصدر يسمح لهذه الطبقة بقبوله.
     *
     * هذا ليس سقف RAM؛ الأصل يُنسخ streaming إلى ملف مؤقت.
     */
    private const DEFAULT_MAX_SOURCE_BYTES = 8_589_934_592; // 8 GiB

    /**
     * سقف الرجوع إلى extract(string $bytes) للمستخرجات القديمة.
     */
    private const DEFAULT_MAX_MEMORY_BYTES = 67_108_864; // 64 MiB

    private const DEFAULT_CHUNK_BYTES = 1_048_576; // 1 MiB

    /**
     * حدود تستخدمها مستخرجات الأرشيفات لمنع zip bombs.
     */
    private const DEFAULT_MAX_ARCHIVE_ENTRIES = 10_000;

    private const DEFAULT_MAX_UNPACKED_BYTES = 2_147_483_648; // 2 GiB

    private const DEFAULT_PROCESS_TIMEOUT_SECONDS = 300;

    private const UNIQUE_RACE_RETRIES = 3;

    private const MAX_ERROR_TOKEN_LENGTH = 80;

    /**
     * MIME values التي لا تكفي وحدها لتحديد نوع المحتوى.
     *
     * @var array<string, true>
     */
    private const GENERIC_MIMES = [
        '' => true,
        'application/octet-stream' => true,
        'binary/octet-stream' => true,
        'application/x-empty' => true,
        'inode/x-empty' => true,
    ];

    public function __construct(
        private ExtractorRegistry $registry,
        private StorageResolver $storage,
    ) {}

    public function extract(
        Asset $asset,
        bool $force = false,
    ): ExtractionAttempt {
        $identity = $this->assetIdentity($asset);

        $sha = $identity['sha'];
        $declaredMime = $identity['mime'];

        /*
         * Fast path:
         *
         * إذا كان MIME المسجل محددًا نستطيع اختيار المستخرج وفحص
         * cache قبل فتح التخزين.
         */
        if (! $this->isGenericMime($declaredMime)) {
            $extractor = $this->registry->for($declaredMime);

            $cached = $this->terminalExtraction(
                sha: $sha,
                extractor: $this->extractorName($extractor),
                version: $this->extractorVersion($extractor),
            );

            if (! $force && $cached !== null) {
                return ExtractionAttempt::skipped($cached);
            }
        }

        $startedAt = hrtime(true);
        $materialized = null;

        try {
            $materialized = $this->materialize($asset);

            /*
             * البصمة حُسبت أثناء streaming من التخزين، فلا نقرأ الملف
             * قراءة إضافية كاملة للتحقق منها.
             */
            if (
                ! hash_equals(
                    strtolower($sha),
                    $materialized->sha256,
                )
            ) {
                $effectiveMime = $this->effectiveMime(
                    declared: $declaredMime,
                    detected: $materialized->detectedMime,
                );

                $extractor = $this->registry->for($effectiveMime);

                return $this->persist(
                    asset: $asset,
                    sha: $sha,
                    extractor: $this->extractorName($extractor),
                    version: $this->extractorVersion($extractor),
                    outcome: ExtractionOutcome::failed(
                        'sha256_mismatch',
                        [
                            'measured_sha256' => $materialized->sha256,
                            'declared_size_bytes' => $identity['size'],
                            'measured_size_bytes' => $materialized->size,
                            'note_ar' => 'محتوى التخزين لا يطابق بصمة الأصل المسجلة، لذلك لم يُمرر إلى المستخرج.',
                        ],
                    ),
                    bytesRead: $materialized->size,
                    startedAt: $startedAt,
                    force: $force,
                    effectiveMime: $effectiveMime,
                    detectedMime: $materialized->detectedMime,
                );
            }

            $effectiveMime = $this->effectiveMime(
                declared: $declaredMime,
                detected: $materialized->detectedMime,
            );

            $extractor = $this->registry->for($effectiveMime);

            $extractorName = $this->extractorName($extractor);
            $extractorVersion = $this->extractorVersion($extractor);

            /*
             * الفحص الثاني ضروري عندما كان MIME المسجل generic
             * ولم نعرف المستخرج الحقيقي إلا بعد فحص المحتوى.
             */
            if (! $force) {
                $cached = $this->terminalExtraction(
                    sha: $sha,
                    extractor: $extractorName,
                    version: $extractorVersion,
                );

                if ($cached !== null) {
                    return ExtractionAttempt::skipped($cached);
                }
            }

            $context = $this->context(
                asset: $asset,
                declaredMime: $declaredMime,
                effectiveMime: $effectiveMime,
                materialized: $materialized,
            );

            $outcome = $this->runExtractor(
                extractor: $extractor,
                materialized: $materialized,
                mime: $effectiveMime,
                context: $context,
            );

            return $this->persist(
                asset: $asset,
                sha: $sha,
                extractor: $extractorName,
                version: $extractorVersion,
                outcome: $outcome,
                bytesRead: $materialized->size,
                startedAt: $startedAt,
                force: $force,
                effectiveMime: $effectiveMime,
                detectedMime: $materialized->detectedMime,
            );
        } catch (AssetTooLarge $exception) {
            $extractor = $this->registry->for($declaredMime);

            return $this->persist(
                asset: $asset,
                sha: $sha,
                extractor: $this->extractorName($extractor),
                version: $this->extractorVersion($extractor),
                outcome: ExtractionOutcome::capabilityGap(
                    'asset_too_large',
                    [
                        'capability' => 'large_file_extraction',
                        'observed_bytes_at_least' => $exception->actualBytes,
                        'max_bytes' => $exception->maximumBytes,
                        'note_ar' => 'الأصل يتجاوز الحد الأقصى المسموح لمرحلة الاستخراج الحالية.',
                    ],
                ),
                bytesRead: null,
                startedAt: $startedAt,
                force: $force,
            );
        } catch (StorageUnavailable $exception) {
            $extractor = $this->registry->for($declaredMime);

            return $this->persist(
                asset: $asset,
                sha: $sha,
                extractor: $this->extractorName($extractor),
                version: $this->extractorVersion($extractor),
                outcome: ExtractionOutcome::failed(
                    $this->storageErrorCode($exception->reason),
                    [
                        'note_ar' => 'تعذرت قراءة الأصل من مزود التخزين. لم تُسجل مفاتيح التخزين أو بيانات الاعتماد.',
                    ],
                ),
                bytesRead: null,
                startedAt: $startedAt,
                force: $force,
            );
        } finally {
            $materialized?->delete();
        }
    }

    private function runExtractor(
        object $extractor,
        MaterializedAsset $materialized,
        string $mime,
        ExtractionContext $context,
    ): ExtractionOutcome {
        try {
            /*
             * الأولوية للمستخرج القادر على stream.
             */
            if ($extractor instanceof StreamAwareExtractor) {
                $stream = @fopen(
                    $materialized->path,
                    'rb',
                );

                if ($stream === false) {
                    throw new RuntimeException(
                        'Unable to reopen materialized asset stream.',
                    );
                }

                try {
                    return $extractor->extractStream(
                        stream: $stream,
                        mime: $mime,
                        context: $context,
                    );
                } finally {
                    if (is_resource($stream)) {
                        fclose($stream);
                    }
                }
            }

            /*
             * المسار الثاني للمستخرجات التي تحتاج pathname
             * مثل pdftotext / ffmpeg / libreoffice / image tools.
             */
            if ($extractor instanceof FileAwareExtractor) {
                return $extractor->extractFile(
                    path: $materialized->path,
                    mime: $mime,
                    context: $context,
                );
            }

            /*
             * Legacy compatibility:
             * extract(string $bytes, string $mime)
             */
            if (
                $materialized->size
                > $context->maxInMemoryBytes
            ) {
                return ExtractionOutcome::capabilityGap(
                    'extractor_requires_in_memory_input',
                    [
                        'capability' => 'file_or_stream_extraction',
                        'size_bytes' => $materialized->size,
                        'max_in_memory_bytes' => $context->maxInMemoryBytes,
                        'mime' => $mime,
                        'note_ar' => 'المستخرج الحالي يعمل على سلسلة في الذاكرة فقط. الملف يحتاج مستخرجًا يدعم path أو stream.',
                    ],
                );
            }

            $bytes = file_get_contents(
                $materialized->path,
            );

            if ($bytes === false) {
                throw new RuntimeException(
                    'Unable to read materialized asset.',
                );
            }

            return $extractor->extract(
                $bytes,
                $mime,
            );
        } catch (Throwable $exception) {
            /*
             * لا نخزن رسالة الاستثناء لأنها قد تحتوي:
             * paths / commands / credentials / provider refs.
             */
            return ExtractionOutcome::failed(
                'extractor_exception',
                [
                    'extractor' => $this->extractorName($extractor),
                    'exception_class' => $exception::class,
                    'note_ar' => 'فشل المستخرج أثناء معالجة الأصل. حُفظ نوع الخطأ دون رسالته لتجنب تسريب بيانات داخلية.',
                ],
            );
        }
    }

    /**
     * ينقل الأصل streaming إلى ملف مؤقت آمن، ويحسب SHA والحجم
     * في المرور نفسه.
     */
    private function materialize(
        Asset $asset,
    ): MaterializedAsset {
        $providerRef = trim(
            (string) $asset->provider_ref,
        );

        if ($providerRef === '') {
            throw new LogicException(
                sprintf(
                    'Asset [%s] has an empty provider_ref.',
                    (string) $asset->getKey(),
                ),
            );
        }

        $maximum = $this->positiveConfigInt(
            'content_intelligence.max_source_bytes',
            self::DEFAULT_MAX_SOURCE_BYTES,
        );

        $chunkSize = $this->positiveConfigInt(
            'content_intelligence.read_chunk_bytes',
            self::DEFAULT_CHUNK_BYTES,
        );

        $declaredSize = (int) $asset->size_bytes;

        if ($declaredSize > $maximum) {
            throw new AssetTooLarge(
                actualBytes: $declaredSize,
                maximumBytes: $maximum,
            );
        }

        $directory = storage_path(
            'framework/content-intelligence',
        );

        if (
            ! is_dir($directory)
            && ! mkdir(
                $directory,
                0700,
                true,
            )
            && ! is_dir($directory)
        ) {
            throw new RuntimeException(
                "Unable to create extraction directory [{$directory}].",
            );
        }

        $path = tempnam(
            $directory,
            'asset-',
        );

        if ($path === false) {
            throw new RuntimeException(
                'Unable to create extraction temporary file.',
            );
        }

        @chmod(
            $path,
            0600,
        );

        $source = null;
        $destination = null;
        $success = false;

        try {
            $source = $this->storage
                ->provider()
                ->stream($providerRef);

            if (! is_resource($source)) {
                throw new StorageUnavailable(
                    StorageUnavailable::DISK_UNAVAILABLE,
                );
            }

            $destination = @fopen(
                $path,
                'wb',
            );

            if ($destination === false) {
                throw new RuntimeException(
                    'Unable to open extraction temporary file.',
                );
            }

            $hash = hash_init('sha256');

            $size = 0;

            while (! feof($source)) {
                $chunk = fread(
                    $source,
                    $chunkSize,
                );

                if ($chunk === false) {
                    throw new StorageUnavailable(
                        StorageUnavailable::DISK_UNAVAILABLE,
                    );
                }

                if ($chunk === '') {
                    if (feof($source)) {
                        break;
                    }

                    throw new StorageUnavailable(
                        StorageUnavailable::DISK_UNAVAILABLE,
                    );
                }

                $chunkBytes = strlen($chunk);
                $size += $chunkBytes;

                if ($size > $maximum) {
                    throw new AssetTooLarge(
                        actualBytes: $size,
                        maximumBytes: $maximum,
                    );
                }

                hash_update(
                    $hash,
                    $chunk,
                );

                $this->writeAll(
                    $destination,
                    $chunk,
                );
            }

            if (! fflush($destination)) {
                throw new RuntimeException(
                    'Unable to flush extraction temporary file.',
                );
            }

            $sha256 = hash_final($hash);

            $detectedMime = $this->detectMime(
                $path,
            );

            $result = new MaterializedAsset(
                path: $path,
                size: $size,
                sha256: $sha256,
                detectedMime: $detectedMime,
            );

            $success = true;

            return $result;
        } finally {
            if (is_resource($source)) {
                fclose($source);
            }

            if (is_resource($destination)) {
                fclose($destination);
            }

            /*
             * عند النجاح ينتقل ownership إلى MaterializedAsset.
             * عند الفشل تنظف هذه الدالة الملف بنفسها.
             */
            if (! $success && is_file($path)) {
                @unlink($path);
            }
        }
    }

    /**
     * MIME من المحتوى نفسه، لا من extension.
     */
    private function detectMime(
        string $path,
    ): string {
        $mime = null;

        if (class_exists(\finfo::class)) {
            $finfo = new \finfo(
                FILEINFO_MIME_TYPE,
            );

            $detected = $finfo->file(
                $path,
            );

            if (
                is_string($detected)
                && trim($detected) !== ''
            ) {
                $mime = strtolower(
                    trim($detected),
                );
            }
        }

        $mime ??=
            'application/octet-stream';

        /*
         * ZIP مجرد Container؛ نحاول معرفة الصيغة داخله بدون
         * فك الأرشيف.
         */
        if ($this->looksLikeZipMime($mime)) {
            $containerMime =
                $this->detectZipContainerMime(
                    $path,
                );

            if ($containerMime !== null) {
                return $containerMime;
            }
        }

        return $mime;
    }

    private function detectZipContainerMime(
        string $path,
    ): ?string {
        if (! class_exists(ZipArchive::class)) {
            return null;
        }

        $zip = new ZipArchive;

        $opened = $zip->open(
            $path,
            ZipArchive::RDONLY,
        );

        if ($opened !== true) {
            return null;
        }

        try {
            if (
                $zip->locateName(
                    'word/document.xml',
                    ZipArchive::FL_NOCASE,
                ) !== false
            ) {
                return 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';
            }

            if (
                $zip->locateName(
                    'xl/workbook.xml',
                    ZipArchive::FL_NOCASE,
                ) !== false
            ) {
                return 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
            }

            if (
                $zip->locateName(
                    'ppt/presentation.xml',
                    ZipArchive::FL_NOCASE,
                ) !== false
            ) {
                return 'application/vnd.openxmlformats-officedocument.presentationml.presentation';
            }

            if (
                $zip->locateName(
                    'META-INF/container.xml',
                    ZipArchive::FL_NOCASE,
                ) !== false
            ) {
                return 'application/epub+zip';
            }

            $mimeIndex = $zip->locateName(
                'mimetype',
            );

            if ($mimeIndex !== false) {
                $mimetype = $zip->getFromIndex(
                    $mimeIndex,
                    256,
                );

                if (is_string($mimetype)) {
                    $mimetype = trim(
                        $mimetype,
                    );

                    if (
                        str_starts_with(
                            $mimetype,
                            'application/vnd.oasis.opendocument.',
                        )
                    ) {
                        return $mimetype;
                    }

                    if (
                        $mimetype
                        === 'application/epub+zip'
                    ) {
                        return $mimetype;
                    }
                }
            }

            return null;
        } finally {
            $zip->close();
        }
    }

    private function effectiveMime(
        string $declared,
        string $detected,
    ): string {
        $declared = strtolower(
            trim($declared),
        );

        $detected = strtolower(
            trim($detected),
        );

        if ($this->isGenericMime($declared)) {
            return $detected !== ''
                ? $detected
                : 'application/octet-stream';
        }

        if (
            $this->mimeCompatible(
                $declared,
                $detected,
            )
        ) {
            return $declared;
        }

        /*
         * فحص Container أدق من application/zip.
         */
        if ($this->isStructuredMime($detected)) {
            return $detected;
        }

        /*
         * نحافظ على MIME المعلن المحدد لعدم تغيير سلوك مستخرجات
         * قائمة لمجرد اختلاف libmagic البسيط.
         */
        return $declared;
    }

    private function mimeCompatible(
        string $declared,
        string $detected,
    ): bool {
        if ($declared === $detected) {
            return true;
        }

        return $this->isStructuredMime($declared)
            && $this->looksLikeZipMime($detected);
    }

    private function isStructuredMime(
        string $mime,
    ): bool {
        return str_starts_with(
            $mime,
            'application/vnd.openxmlformats-officedocument.',
        )
            || str_starts_with(
                $mime,
                'application/vnd.oasis.opendocument.',
            )
            || $mime === 'application/epub+zip';
    }

    private function looksLikeZipMime(
        string $mime,
    ): bool {
        return in_array(
            $mime,
            [
                'application/zip',
                'application/x-zip',
                'application/x-zip-compressed',
            ],
            true,
        );
    }

    private function isGenericMime(
        string $mime,
    ): bool {
        return isset(
            self::GENERIC_MIMES[
                strtolower(trim($mime))
            ],
        );
    }

    private function context(
        Asset $asset,
        string $declaredMime,
        string $effectiveMime,
        MaterializedAsset $materialized,
    ): ExtractionContext {
        return new ExtractionContext(
            assetId: (int) $asset->id,

            sha256: $materialized->sha256,

            declaredMime: $declaredMime,

            effectiveMime: $effectiveMime,

            sizeBytes: $materialized->size,

            maxInMemoryBytes: $this->positiveConfigInt(
                'content_intelligence.max_in_memory_bytes',
                self::DEFAULT_MAX_MEMORY_BYTES,
            ),

            maxArchiveEntries: $this->positiveConfigInt(
                'content_intelligence.max_archive_entries',
                self::DEFAULT_MAX_ARCHIVE_ENTRIES,
            ),

            maxUnpackedBytes: $this->positiveConfigInt(
                'content_intelligence.max_unpacked_bytes',
                self::DEFAULT_MAX_UNPACKED_BYTES,
            ),

            processTimeoutSeconds: $this->positiveConfigInt(
                'content_intelligence.process_timeout_seconds',
                self::DEFAULT_PROCESS_TIMEOUT_SECONDS,
            ),
        );
    }

    private function persist(
        Asset $asset,
        string $sha,
        string $extractor,
        string $version,
        ExtractionOutcome $outcome,
        ?int $bytesRead,
        int $startedAt,
        bool $force,
        ?string $effectiveMime = null,
        ?string $detectedMime = null,
    ): ExtractionAttempt {
        $attributes = [
            'asset_sha256' => $sha,
            'extractor' => $extractor,
            'extractor_version' => $version,
        ];

        $meta = [
            ...$outcome->meta,

            /*
             * حقائق تقيسها هذه الطبقة تفوز على meta القادمة
             * من المستخرج.
             */
            'asset_id' => (int) $asset->id,

            'declared_mime' => (string) $asset->mime,
        ];

        if ($effectiveMime !== null) {
            $meta['effective_mime'] =
                $effectiveMime;
        }

        if ($detectedMime !== null) {
            $meta['detected_mime'] =
                $detectedMime;
        }

        if ($bytesRead !== null) {
            $meta['bytes_read'] =
                $bytesRead;

            $declaredSize =
                (int) $asset->size_bytes;

            if ($declaredSize !== $bytesRead) {
                $meta['declared_size_bytes'] =
                    $declaredSize;

                $meta['measured_size_bytes'] =
                    $bytesRead;

                $meta['size_mismatch'] = true;
            }
        }

        $values = [
            'asset_id' => $asset->id,

            'status' => $outcome->status->value,

            'text' => $outcome->text,

            'units' => $outcome->units,

            'meta' => $meta,

            'error_code' => $outcome->errorCode,

            'duration_ms' => $this->elapsedMilliseconds(
                $startedAt,
            ),

            'bytes_read' => $bytesRead,
        ];

        $lastRace = null;

        for (
            $attempt = 1;
            $attempt <= self::UNIQUE_RACE_RETRIES;
            $attempt++
        ) {
            try {
                return DB::transaction(
                    function () use (
                        $attributes,
                        $values,
                        $force,
                    ): ExtractionAttempt {
                        $row = AssetExtraction::query()
                            ->where($attributes)
                            ->lockForUpdate()
                            ->first();

                        /*
                         * الفحص الأول في extract() مجرد fast path.
                         *
                         * هذا هو فحص التزامن الحقيقي بعد العمل المكلف.
                         */
                        if (
                            $row !== null
                            && ! $force
                            && self::isTerminal($row)
                        ) {
                            return ExtractionAttempt::skipped(
                                $row,
                            );
                        }

                        if ($row === null) {
                            $row =
                                new AssetExtraction;

                            $row->forceFill([
                                ...$attributes,
                                ...$values,
                            ]);

                            $row->save();

                            return ExtractionAttempt::extracted(
                                $row,
                            );
                        }

                        $row->forceFill(
                            $values,
                        );

                        $row->save();

                        return ExtractionAttempt::extracted(
                            $row,
                        );
                    },
                    attempts: 3,
                );
            } catch (
                UniqueConstraintViolationException $exception
            ) {
                $lastRace = $exception;

                if (
                    $attempt
                    === self::UNIQUE_RACE_RETRIES
                ) {
                    throw $exception;
                }
            }
        }

        throw new LogicException(
            'Extraction persistence retry loop was exhausted.',
            previous: $lastRace,
        );
    }

    private function terminalExtraction(
        string $sha,
        string $extractor,
        string $version,
    ): ?AssetExtraction {
        $row = AssetExtraction::query()
            ->where('asset_sha256', $sha)
            ->where('extractor', $extractor)
            ->where(
                'extractor_version',
                $version,
            )
            ->first();

        return (
            $row !== null
            && self::isTerminal($row)
        )
            ? $row
            : null;
    }

    /**
     * @return array{
     *     sha:string,
     *     mime:string,
     *     size:int
     * }
     */
    private function assetIdentity(
        Asset $asset,
    ): array {
        $sha = trim(
            (string) $asset->sha256,
        );

        if (
            preg_match(
                '/\A[0-9a-f]{64}\z/i',
                $sha,
            ) !== 1
        ) {
            throw new LogicException(
                sprintf(
                    'Asset [%s] has an invalid SHA-256.',
                    (string) $asset->getKey(),
                ),
            );
        }

        $mime = strtolower(
            trim(
                (string) $asset->mime,
            ),
        );

        if ($mime === '') {
            $mime =
                'application/octet-stream';
        }

        $size =
            (int) $asset->size_bytes;

        if ($size < 0) {
            throw new LogicException(
                sprintf(
                    'Asset [%s] has a negative size.',
                    (string) $asset->getKey(),
                ),
            );
        }

        return [
            'sha' => strtolower($sha),
            'mime' => $mime,
            'size' => $size,
        ];
    }

    private function extractorName(
        object $extractor,
    ): string {
        if (! method_exists($extractor, 'name')) {
            throw new LogicException(
                sprintf(
                    'Extractor [%s] does not expose name().',
                    $extractor::class,
                ),
            );
        }

        $name = trim(
            (string) $extractor->name(),
        );

        if ($name === '') {
            throw new LogicException(
                'Extractor name cannot be empty.',
            );
        }

        return $name;
    }

    private function extractorVersion(
        object $extractor,
    ): string {
        if (! method_exists($extractor, 'version')) {
            throw new LogicException(
                sprintf(
                    'Extractor [%s] does not expose version().',
                    $extractor::class,
                ),
            );
        }

        $version = trim(
            (string) $extractor->version(),
        );

        if ($version === '') {
            throw new LogicException(
                'Extractor version cannot be empty.',
            );
        }

        return $version;
    }

    private function storageErrorCode(
        mixed $reason,
    ): string {
        if (! is_scalar($reason)) {
            return 'storage_unavailable';
        }

        $reason = strtolower(
            trim(
                (string) $reason,
            ),
        );

        $reason = preg_replace(
            '/[^a-z0-9._-]+/',
            '_',
            $reason,
        ) ?? '';

        $reason = trim(
            $reason,
            '._-',
        );

        if ($reason === '') {
            return 'storage_unavailable';
        }

        if (
            strlen($reason)
            > self::MAX_ERROR_TOKEN_LENGTH
        ) {
            $reason =
                'reason_'.substr(
                    hash(
                        'sha256',
                        $reason,
                    ),
                    0,
                    16,
                );
        }

        return 'storage_'.$reason;
    }

    /**
     * @param  resource  $stream
     */
    private function writeAll(
        $stream,
        string $bytes,
    ): void {
        $length = strlen($bytes);
        $offset = 0;

        while ($offset < $length) {
            $written = fwrite(
                $stream,
                substr(
                    $bytes,
                    $offset,
                ),
            );

            if (
                $written === false
                || $written === 0
            ) {
                throw new RuntimeException(
                    'Unable to write extraction temporary file.',
                );
            }

            $offset += $written;
        }
    }

    private function positiveConfigInt(
        string $key,
        int $default,
    ): int {
        $value = config(
            $key,
            $default,
        );

        if (
            ! is_int($value)
            && ! (
                is_string($value)
                && ctype_digit($value)
            )
        ) {
            throw new RuntimeException(
                "Configuration [{$key}] must be a positive integer.",
            );
        }

        $value = (int) $value;

        if ($value <= 0) {
            throw new RuntimeException(
                "Configuration [{$key}] must be greater than zero.",
            );
        }

        return $value;
    }

    private function elapsedMilliseconds(
        int $startedAt,
    ): int {
        $elapsed =
            hrtime(true) - $startedAt;

        if ($elapsed <= 0) {
            return 0;
        }

        return (int) intdiv(
            $elapsed,
            1_000_000,
        );
    }

    private static function isTerminal(
        AssetExtraction $row,
    ): bool {
        return ExtractionStatus::tryFrom(
            (string) $row->status,
        )?->isTerminal() ?? false;
    }
}
