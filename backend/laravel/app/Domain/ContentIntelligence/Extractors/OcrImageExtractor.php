<?php

declare(strict_types=1);

namespace App\Domain\ContentIntelligence\Extractors;

use App\Domain\ContentIntelligence\Contracts\ContentExtractor;
use App\Domain\ContentIntelligence\ExtractionOutcome;
use App\Domain\ContentIntelligence\Ocr\TesseractOcr;
use RuntimeException;

/**
 * نصُّ الصور النقطيّة (png · jpeg · webp · tiff · gif) — عبر OCR.
 *
 * صورُ المعاينة وأوراقُ العمل المصوّرة وشرائحُ المسح: لا نصَّ أصليًّا فيها
 * البتّة. كانت عجزًا مُسمًّى (`ocr_engine_missing`)؛ وحيث لا يوجد المحرّك
 * ما زالت كذلك حرفًا بحرف، والنسخة تحمل غيابه فيُعاد قراءتها حين يُثبَّت.
 *
 *   ok                  نصٌّ بثقةٍ ≥ الحدّ الأدنى؛ الوحدات = الكلمات
 *   no_text_detected    صورةٌ قُرئت فلم يُعثر فيها على نصٍّ موثوق
 *   ocr_failed          فشلُ المحرّك — يُعاد لاحقًا
 */
final class OcrImageExtractor implements ContentExtractor
{
    public const NAME = 'image_ocr';

    public const VERSION = '2026.09.26';

    /** صورةٌ أكبر من هذا ليست ورقةً ولا شريحة — تُرفض قبل المحرّك. */
    public const MAX_BYTES = 20 * 1024 * 1024;

    public function __construct(private readonly TesseractOcr $ocr = new TesseractOcr) {}

    public function name(): string
    {
        return self::NAME;
    }

    public function version(): string
    {
        return self::VERSION.$this->ocr->versionTag();
    }

    public function supports(string $mime): bool
    {
        return MimeFamily::isRasterImage($mime);
    }

    public function extract(string $bytes, string $mime): ExtractionOutcome
    {
        $base = MimeFamily::normalise($mime);
        if (! $this->ocr->available()) {
            return ExtractionOutcome::capabilityGap('ocr_engine_missing', [
                'capability' => 'image_ocr',
                'mime' => $base,
                'requires' => ['tesseract-ocr', 'حزمة اللغة العربيّة tesseract-ocr-data-ara'],
                'note_ar' => 'صورةٌ نقطيّة: لا نصّ أصليّ فيها. قراءتها تحتاج محرّك OCR بالعربيّة، ولا محرّك في الحاوية.',
            ]);
        }
        if (strlen($bytes) > self::MAX_BYTES) {
            return ExtractionOutcome::failed('image_too_large', ['bytes' => strlen($bytes), 'limit' => self::MAX_BYTES]);
        }

        $path = tempnam(sys_get_temp_dir(), 'ocr');
        if ($path === false || file_put_contents($path, $bytes) === false) {
            return ExtractionOutcome::failed('temp_write_failed');
        }

        try {
            $read = $this->ocr->read($path);
        } catch (RuntimeException $e) {
            return ExtractionOutcome::failed('ocr_failed', ['error' => $e->getMessage()]);
        } finally {
            @unlink($path);
        }

        $meta = [
            'engine' => 'tesseract',
            'languages' => TesseractOcr::LANGUAGES,
            'mean_confidence' => $read['confidence'],
            'words' => $read['words'],
            'dropped_low_confidence' => $read['dropped'],
            'min_confidence' => TesseractOcr::MIN_CONFIDENCE,
            'mime' => $base,
        ];

        if ($read['lines'] === []) {
            return ExtractionOutcome::capabilityGap('no_text_detected', $meta + [
                'capability' => 'image_text',
                'note_ar' => 'قُرئت الصورة ولم يُعثر فيها على نصٍّ بثقةٍ كافية.',
            ]);
        }

        return ExtractionOutcome::ok(implode("\n", $read['lines']), $read['words'], $meta);
    }
}
