<?php

declare(strict_types=1);

namespace App\Domain\ContentIntelligence\Ocr;

use Illuminate\Support\Facades\Process;
use RuntimeException;

/**
 * قراءةُ النصّ من صورة — Tesseract بالعربيّة والإنجليزيّة معًا.
 *
 * يُقرأ مخرجُ TSV لا النصّ الخام: فيه ثقةُ كلّ كلمة (0–100). والكلمة دون
 * `MIN_CONFIDENCE` تُسقط قبل أن تدخل البحث — نصُّ OCR الرديء يُطابق كلماتٍ
 * لم تُكتب، وهو أسوأ من لا نصّ. والسطر يُبنى من كلماته بترتيب القراءة.
 */
final class TesseractOcr
{
    public const MIN_CONFIDENCE = 60;

    public const LANGUAGES = 'ara+eng';

    public function available(): bool
    {
        $bin = $this->binary();

        return $bin !== '' && is_file($bin) && is_executable($bin);
    }

    /**
     * @return array{lines: list<string>, words: int, dropped: int, confidence: ?float}
     *
     * @throws RuntimeException حين تفشل الأداة
     */
    public function read(string $imagePath): array
    {
        $run = Process::timeout((int) config('content.ocr_timeout', 60))
            /*
             * عتبةُ Sauvola التكيّفيّة (`thresholding_method=2`) لا Otsu الافتراضيّة:
             * قيس على صفحةٍ ممسوحة بضغط JPEG — Otsu أسقط سطر العنوان كلّه
             * («الغشاء البلازمي»)، وSauvola قرأه بثقة ٨٢–٩٣، والصورة النظيفة
             * كما هي في الحالين.
             */
            ->run([$this->binary(), $imagePath, 'stdout', '-l', self::LANGUAGES, '--psm', '3', '-c', 'thresholding_method=2', 'tsv']);

        if (! $run->successful()) {
            throw new RuntimeException('tesseract: '.mb_substr(trim($run->errorOutput()), 0, 300));
        }

        $lines = [];
        $kept = 0;
        $dropped = 0;
        $confidence = 0.0;
        foreach (preg_split('/\R/u', $run->output()) ?: [] as $i => $row) {
            $cols = explode("\t", $row);
            if ($i === 0 || count($cols) < 12 || (int) $cols[0] !== 5) {
                continue;
            }
            $text = trim($cols[11]);
            if ($text === '') {
                continue;
            }
            $conf = (float) $cols[10];
            if ($conf < self::MIN_CONFIDENCE) {
                $dropped++;

                continue;
            }
            $key = $cols[1].':'.$cols[2].':'.$cols[3].':'.$cols[4];
            $lines[$key] = isset($lines[$key]) ? $lines[$key].' '.$text : $text;
            $kept++;
            $confidence += $conf;
        }

        return [
            'lines' => array_values($lines),
            'words' => $kept,
            'dropped' => $dropped,
            'confidence' => $kept === 0 ? null : round($confidence / $kept, 1),
        ];
    }

    /** نسخةُ المحرّك في النسخة المسجّلة: تثبيتُه يُقدِّم صفوف ما قُرئ بدونه. */
    public function versionTag(): string
    {
        return $this->available() ? '' : '-noocr';
    }

    private function binary(): string
    {
        return (string) config('content.tesseract', '/usr/bin/tesseract');
    }
}
