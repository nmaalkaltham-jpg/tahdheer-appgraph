<?php

declare(strict_types=1);

namespace App\Domain\ContentIntelligence\Extractors;

use App\Domain\ContentIntelligence\Contracts\ContentExtractor;
use App\Domain\ContentIntelligence\ExtractionOutcome;
use App\Domain\ContentIntelligence\ExtractionStatus;
use App\Domain\ContentIntelligence\Ocr\TesseractOcr;
use Illuminate\Support\Facades\Process;

/**
 * نصُّ صفحات PDF — عبر `pdftotext` (poppler)، فوق قراءة البيانات الوصفيّة.
 *
 * ─── لماذا أداةٌ خارجيّة لا تحليلٌ داخليّ ───
 *
 * نصُّ PDF العربيّ مرسومٌ بخطوطٍ مجزّأة تُترجَم أحرفُها بخرائط ToUnicode،
 * وقد يُخزَّن بترتيبٍ بصريّ لا منطقيّ. poppler يعالج ذلك منذ سنوات؛ ومحلّلٌ
 * يُكتب هنا من الصفر يُدخل نصًّا مشوّهًا إلى البحث — أسوأ من لا نصّ.
 *
 * ─── وحيث لا توجد الأداة ───
 *
 * النتيجةُ هي قراءةُ `PdfMetadataExtractor` حرفيًّا (العنوان والموضوع
 * والكلمات المفتاحيّة، `partial`). والنسخة تحمل غيابَ الأداة، فحين تُثبَّت
 * تصير الصفوف القديمة «قديمة» ويلتقطها `content:extract` التالي بلا تدخّل.
 *
 * ─── وحين توجد ───
 *
 *   ok               البيانات الوصفيّة ثمّ نصُّ الصفحات، سطرًا سطرًا؛ الوحدات = الصفحات
 *   no_native_text   صفحاتٌ بلا طبقة نصّ (مسحٌ ضوئيّ) — عجزُ OCR مُسمّى
 *   pdftotext_failed فشلُ الأداة — فشلٌ يُعاد لاحقًا، لا نجاحٌ فارغ
 */
final class PdfTextExtractor implements ContentExtractor
{
    public const NAME = 'pdf_text';

    public const VERSION = '2026.09.26';

    /** صفحاتُ المسح التي تُقرأ بـOCR من كلّ ملفّ — ملزمةٌ من مئة صفحة لا تُحبس المعالجة. */
    public const MAX_OCR_PAGES = 20;

    public const MAX_TEXT_CHARS = 500_000;

    public function __construct(
        private readonly PdfMetadataExtractor $metadata = new PdfMetadataExtractor,
        private readonly TesseractOcr $ocr = new TesseractOcr,
    ) {}

    public function name(): string
    {
        return self::NAME;
    }

    /** النسخة تحمل وجود الأداة: تثبيتُها يُقدِّم كلَّ صفٍّ قُرئ بدونها. */
    public function version(): string
    {
        /*
         * ≤ ٢٠ حرفًا (عمود varchar(20)). تحمل ما تقدر عليه البيئة: بلا pdftotext
         * «-meta»، وبه بلا OCR «-t»، وبهما النسخة وحدها. فتثبيتُ أيٍّ منهما يُقدِّم
         * صفوف ما قُرئ بدونه ويلتقطها `content:extract` التالي.
         */
        return self::VERSION.match (true) {
            $this->engine() === null => '-meta',
            ! $this->ocr->available() => '-t',
            default => '',
        };
    }

    public function supports(string $mime): bool
    {
        return $this->metadata->supports($mime);
    }

    public function extract(string $bytes, string $mime): ExtractionOutcome
    {
        $meta = $this->metadata->extract($bytes, $mime);

        /* رأسٌ مفقود أو مستندٌ مُعمّى: حكمُ قراءة البيانات الوصفيّة نهائيّ. */
        if ($meta->status === ExtractionStatus::Failed || $meta->errorCode === 'pdf_encrypted') {
            return $meta;
        }

        $engine = $this->engine();
        if ($engine === null) {
            return $meta->withMeta(['pdf_text_engine' => 'missing']);
        }

        $path = tempnam(sys_get_temp_dir(), 'pdf');
        if ($path === false || file_put_contents($path, $bytes) === false) {
            return ExtractionOutcome::failed('temp_write_failed');
        }

        try {
            $run = Process::timeout((int) config('content.pdftotext_timeout', 30))
                ->run([$engine, '-enc', 'UTF-8', '-eol', 'unix', $path, '-']);
        } finally {
            @unlink($path);
        }

        if (! $run->successful()) {
            return ExtractionOutcome::failed('pdftotext_failed', [
                'exit_code' => $run->exitCode(),
                'stderr' => mb_substr(trim($run->errorOutput()), 0, 300),
            ]);
        }

        /* poppler يُنهي كلَّ صفحةٍ بفاصل صفحة (\f)، والأخيرةَ أيضًا. */
        $pages = explode("\f", $run->output());
        if (end($pages) !== false && trim((string) end($pages)) === '') {
            array_pop($pages);
        }
        $pageCount = count($pages);

        $lines = [];
        foreach ($pages as $page) {
            foreach (preg_split('/\R/u', $page) ?: [] as $line) {
                $line = trim((string) preg_replace('/\s+/u', ' ', $line));
                if ($line !== '') {
                    $lines[] = $line;
                }
            }
        }

        $info = ['engine' => 'poppler-pdftotext', 'pages' => $pageCount];

        /* بلا طبقة نصّ: مسحٌ ضوئيّ — تُرسم الصفحات صورًا وتُقرأ بـOCR حيث يوجد. */
        if ($lines === [] && $this->ocr->available()) {
            $scanned = $this->ocrPages($bytes, $engine);
            if ($scanned['lines'] !== []) {
                $header = $meta->text !== null && trim($meta->text) !== '' ? preg_split('/\R/u', trim($meta->text)) ?: [] : [];

                return ExtractionOutcome::ok(implode("\n", [...$header, ...$scanned['lines']]), $pageCount, $info + [
                    'ocr' => true,
                    'ocr_pages' => $scanned['pages'],
                    'mean_confidence' => $scanned['confidence'],
                    'words' => $scanned['words'],
                    'truncated' => $pageCount > self::MAX_OCR_PAGES,
                ]);
            }
            $info += ['ocr' => true, 'ocr_pages' => $scanned['pages']];
        }

        if ($lines === []) {
            return ExtractionOutcome::capabilityGap('no_native_text', $info + [
                'capability' => 'image_ocr',
                'requires' => ['tesseract-ocr', 'حزمة اللغة العربيّة tesseract-ocr-ara'],
                'note_ar' => 'صفحاتٌ بلا طبقة نصّ — مسحٌ ضوئيّ غالبًا. قراءتها تحتاج OCR.',
            ]);
        }

        $header = $meta->text !== null && trim($meta->text) !== '' ? preg_split('/\R/u', trim($meta->text)) ?: [] : [];
        $text = implode("\n", [...$header, ...$lines]);

        if (mb_strlen($text) > self::MAX_TEXT_CHARS) {
            return ExtractionOutcome::partial(mb_substr($text, 0, self::MAX_TEXT_CHARS), $pageCount, $info + ['truncated' => true]);
        }

        return ExtractionOutcome::ok($text, $pageCount, $info + ['truncated' => false, 'metadata_fields' => $meta->meta['fields'] ?? []]);
    }

    /**
     * يقرأ صور صفحات المسح بدقّتها الأصليّة (pdfimages)، ويرسم الصفحات
     * (pdftoppm، ١٥٠ نقطة) حين لا صور فيها.
     *
     * ─── لماذا الصورة الأصليّة لا رسمُ الصفحة ───
     *
     * قيس: صفحةٌ ممسوحة رُسمت بـ٢٠٠ نقطة كُبّر نصُّها حتّى أسقط تحليلُ التخطيط
     * في Tesseract سطرَها الأوّل كلّه («الغشاء البلازمي» غاب، لا بثقةٍ منخفضة
     * بل لم يُرَ). والصورة نفسها بدقّتها الأصليّة قُرئت كاملةً بثقة ٨٥–٩٣.
     * المسح صورةٌ واحدة لكلّ صفحة، فقراءتها كما هي أدقّ وأسرع.
     *
     * @return array{lines: list<string>, pages: int, words: int, confidence: ?float}
     */
    private function ocrPages(string $bytes, string $pdftotext): array
    {
        $dir = sys_get_temp_dir().'/pdfocr_'.bin2hex(random_bytes(6));
        @mkdir($dir);
        $pdf = $dir.'/in.pdf';
        file_put_contents($pdf, $bytes);
        $bin = dirname($pdftotext);
        $timeout = (int) config('content.pdftotext_timeout', 30) * 4;
        $last = (string) self::MAX_OCR_PAGES;

        try {
            $run = Process::timeout($timeout)->run([$bin.'/pdfimages', '-png', '-f', '1', '-l', $last, $pdf, $dir.'/img']);
            $images = $run->successful() ? (glob($dir.'/img*.png') ?: []) : [];
            if ($images === []) {
                $run = Process::timeout($timeout)->run([$bin.'/pdftoppm', '-r', '150', '-png', '-f', '1', '-l', $last, $pdf, $dir.'/page']);
                $images = $run->successful() ? (glob($dir.'/page*.png') ?: []) : [];
            }
            if ($images === []) {
                return ['lines' => [], 'pages' => 0, 'words' => 0, 'confidence' => null];
            }

            sort($images, SORT_NATURAL);
            $lines = [];
            $words = 0;
            $weighted = 0.0;
            foreach ($images as $image) {
                try {
                    $read = $this->ocr->read($image);
                } catch (\RuntimeException) {
                    continue;
                }
                array_push($lines, ...$read['lines']);
                $words += $read['words'];
                $weighted += ($read['confidence'] ?? 0) * $read['words'];
            }

            return ['lines' => $lines, 'pages' => count($images), 'words' => $words, 'confidence' => $words === 0 ? null : round($weighted / $words, 1)];
        } finally {
            foreach (glob($dir.'/*') ?: [] as $f) {
                @unlink($f);
            }
            @rmdir($dir);
        }
    }

    private function engine(): ?string
    {
        $bin = (string) config('content.pdftotext', '/usr/bin/pdftotext');

        return $bin !== '' && is_file($bin) && is_executable($bin) ? $bin : null;
    }
}
