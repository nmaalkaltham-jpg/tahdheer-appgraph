<?php

declare(strict_types=1);

namespace App\Domain\ContentIntelligence\Extractors;

use App\Domain\ContentIntelligence\Contracts\ContentExtractor;
use App\Domain\ContentIntelligence\ExtractionOutcome;
use XMLReader;
use ZipArchive;

/**
 * نصُّ Office الحديث — pptx · docx · xlsx (وقوالبها): حزمة ZIP فيها XML.
 *
 * ─── لماذا هنا وبلا مكتبة ───
 *
 * كانت الصيغ الثلاث «عجزًا مُسمّى» في `CapabilityGapExtractor`، وهي صيغُ أغلب
 * منتجات المتجر — فطبقةُ «البحث داخل الملفّ» كانت مبنيّةً لا ترى شيئًا منها.
 * والقراءة لا تحتاج تبعيّة: `ext-zip` و`XMLReader` في الحاوية، والنصّ في
 * عُقدٍ معروفة (`a:t` للشرائح، `w:t` للمستند، `t` لجداول البيانات).
 *
 * ─── ما يُقرأ، وبأيّ ترتيب ───
 *
 *   pptx  الشرائح بترقيمها العدديّ (slide2 قبل slide10)، ثمّ ملاحظات المتحدّث
 *   docx  المتن (والجداول فيه)، ثمّ الرؤوس والتذييلات والحواشي والتعليقات
 *   xlsx  أسماءُ الأوراق، ثمّ النصوص المشتركة (نصُّ الخلايا)
 *
 * الفقرة سطر، وتتّصل مقاطعها بلا فاصل («المفعول » + «به»). ولا يُقرأ نصّ
 * الحقول (`instrText`) ولا المحذوف (`delText`): ليسا ممّا يراه القارئ.
 *
 * ─── الحراسة ───
 *
 * · الحجمُ بعد الفكّ يُفحص من فهرس الحزمة **قبل** القراءة: حزمةٌ صغيرة تنتفخ
 *   إلى مئات الميغابايت تُرفض باسمها (`package_too_large`).
 * · لا كيانات خارجيّة: `LIBXML_NONET`، والإحلالُ مطفأ — `&e;` تبقى عقدةً
 *   تُتجاهل، فلا يُقرأ ملفٌّ من الخادم عبر DOCTYPE.
 * · حزمةٌ بلا أيّ نصّ (شرائحُ صور) عجزُ OCR مُسمّى، لا نجاحٌ فارغ.
 */
final class OfficeOpenXmlExtractor implements ContentExtractor
{
    public const NAME = 'office_open_xml';

    public const VERSION = '2026.09.25';

    /** مجموعُ XML المقروء بعد الفكّ. */
    public const MAX_XML_BYTES = 16 * 1024 * 1024;

    /** ما زاد يُقتطع والنتيجة `partial`. */
    public const MAX_TEXT_CHARS = 500_000;

    public function name(): string
    {
        return self::NAME;
    }

    public function version(): string
    {
        return self::VERSION;
    }

    public function supports(string $mime): bool
    {
        return $this->family($mime) !== null;
    }

    public function extract(string $bytes, string $mime): ExtractionOutcome
    {
        $family = $this->family($mime);
        if ($family === null) {
            return ExtractionOutcome::failed('unsupported_mime', ['mime' => MimeFamily::normalise($mime)]);
        }

        $path = tempnam(sys_get_temp_dir(), 'ooxml');
        if ($path === false || file_put_contents($path, $bytes) === false) {
            return ExtractionOutcome::failed('temp_write_failed');
        }

        try {
            $zip = new ZipArchive;
            if ($zip->open($path, ZipArchive::RDONLY) !== true) {
                return ExtractionOutcome::failed('corrupt_package', ['format' => $family]);
            }

            try {
                return $this->read($zip, $family);
            } finally {
                $zip->close();
            }
        } finally {
            @unlink($path);
        }
    }

    private function read(ZipArchive $zip, string $family): ExtractionOutcome
    {
        $parts = $this->parts($zip, $family);

        $inflated = 0;
        foreach (array_merge(...array_values($parts)) as $name) {
            $inflated += (int) ($zip->statName($name)['size'] ?? 0);
        }
        if ($inflated > self::MAX_XML_BYTES) {
            return ExtractionOutcome::failed('package_too_large', ['format' => $family, 'inflated_bytes' => $inflated, 'limit' => self::MAX_XML_BYTES]);
        }

        $lines = [];
        $meta = ['format' => $family];

        if ($family === 'pptx') {
            $titles = [];
            foreach ($parts['slides'] as $name) {
                $slide = $this->paragraphs((string) $zip->getFromName($name), ['p']);
                if ($slide !== [] && count($titles) < 50) {
                    $titles[] = $slide[0];
                }
                array_push($lines, ...$slide);
            }
            foreach ($parts['notes'] as $name) {
                array_push($lines, ...$this->paragraphs((string) $zip->getFromName($name), ['p']));
            }
            $units = count($parts['slides']);
            $meta += ['slides' => $units, 'notes' => count($parts['notes']), 'titles' => $titles];
        } elseif ($family === 'docx') {
            foreach ($parts['text'] as $name) {
                array_push($lines, ...$this->paragraphs((string) $zip->getFromName($name), ['p']));
            }
            $units = count($lines);
            $meta += ['paragraphs' => $units, 'parts' => count($parts['text'])];
        } else {
            $sheets = $this->sheetNames((string) $zip->getFromName('xl/workbook.xml'));
            array_push($lines, ...$sheets);
            foreach ($parts['text'] as $name) {
                array_push($lines, ...$this->paragraphs((string) $zip->getFromName($name), ['si']));
            }
            $units = count($sheets);
            $meta += ['sheets' => $units];
        }

        $text = implode("\n", $lines);
        if (trim($text) === '') {
            return ExtractionOutcome::capabilityGap('no_native_text', $meta + [
                'capability' => 'image_ocr',
                'requires' => ['tesseract-ocr', 'حزمة اللغة العربيّة tesseract-ocr-ara'],
                'note_ar' => 'الحزمة سليمة ولا نصَّ أصليًّا فيها — محتواها صور. قراءتها تحتاج OCR.',
            ]);
        }

        if (mb_strlen($text) > self::MAX_TEXT_CHARS) {
            return ExtractionOutcome::partial(mb_substr($text, 0, self::MAX_TEXT_CHARS), $units, $meta + ['truncated' => true]);
        }

        return ExtractionOutcome::ok($text, $units, $meta + ['truncated' => false]);
    }

    /**
     * أسماءُ الأجزاء المقروءة بترتيبها — من فهرس الحزمة، بلا افتراض.
     *
     * @return array<string, list<string>>
     */
    private function parts(ZipArchive $zip, string $family): array
    {
        $names = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $names[] = (string) $zip->getNameIndex($i);
        }

        $numbered = static function (string $pattern) use ($names): array {
            $hits = [];
            foreach ($names as $n) {
                if (preg_match($pattern, $n, $m) === 1) {
                    $hits[(int) ($m[1] ?? 0)] = $n;
                }
            }
            ksort($hits);

            return array_values($hits);
        };
        $one = static fn (string $n): array => in_array($n, $names, true) ? [$n] : [];

        return match ($family) {
            'pptx' => [
                'slides' => $numbered('#^ppt/slides/slide(\d+)\.xml$#'),
                'notes' => $numbered('#^ppt/notesSlides/notesSlide(\d+)\.xml$#'),
            ],
            'docx' => ['text' => [
                ...$one('word/document.xml'),
                ...$numbered('#^word/header(\d*)\.xml$#'),
                ...$numbered('#^word/footer(\d*)\.xml$#'),
                ...$one('word/footnotes.xml'),
                ...$one('word/endnotes.xml'),
                ...$one('word/comments.xml'),
            ]],
            default => ['text' => $one('xl/sharedStrings.xml'), 'workbook' => $one('xl/workbook.xml')],
        };
    }

    /**
     * الفقرات غير الفارغة: كلُّ عنصرٍ من `$paragraph` سطرٌ، ونصُّه عُقد `t` فيه.
     *
     * @param  list<string>  $paragraph  أسماءٌ محلّيّة: `p` للشرائح والمستند، `si` للجداول
     * @return list<string>
     */
    private function paragraphs(string $xml, array $paragraph): array
    {
        $reader = $this->reader($xml);
        if ($reader === null) {
            return [];
        }

        $lines = [];
        $stack = [];
        $inText = false;

        while (@$reader->read()) {
            $name = $reader->localName;
            if ($reader->nodeType === XMLReader::ELEMENT) {
                if (in_array($name, $paragraph, true) && ! $reader->isEmptyElement) {
                    $stack[] = '';
                } elseif ($name === 't' && ! $reader->isEmptyElement) {
                    $inText = true;
                }
            } elseif ($reader->nodeType === XMLReader::END_ELEMENT) {
                if ($name === 't') {
                    $inText = false;
                } elseif (in_array($name, $paragraph, true) && $stack !== []) {
                    $line = trim((string) preg_replace('/\s+/u', ' ', array_pop($stack)));
                    if ($line !== '') {
                        $lines[] = $line;
                    }
                }
            } elseif ($inText && $stack !== [] && in_array($reader->nodeType, [XMLReader::TEXT, XMLReader::SIGNIFICANT_WHITESPACE, XMLReader::WHITESPACE, XMLReader::CDATA], true)) {
                $stack[count($stack) - 1] .= $reader->value;
            }
        }
        $reader->close();

        return $lines;
    }

    /** @return list<string> */
    private function sheetNames(string $xml): array
    {
        $reader = $this->reader($xml);
        if ($reader === null) {
            return [];
        }

        $names = [];
        while (@$reader->read()) {
            if ($reader->nodeType === XMLReader::ELEMENT && $reader->localName === 'sheet') {
                $name = trim((string) $reader->getAttribute('name'));
                if ($name !== '') {
                    $names[] = $name;
                }
            }
        }
        $reader->close();

        return $names;
    }

    private function reader(string $xml): ?XMLReader
    {
        if ($xml === '') {
            return null;
        }
        $reader = XMLReader::XML($xml, 'UTF-8', LIBXML_NONET | LIBXML_COMPACT | LIBXML_NOERROR | LIBXML_NOWARNING);
        if ($reader === false) {
            return null;
        }
        /* لا تحميلَ لـDTD ولا إحلالَ كيانات: `&e;` تبقى عقدةً تُتجاهل. */
        $reader->setParserProperty(XMLReader::LOADDTD, false);
        $reader->setParserProperty(XMLReader::SUBST_ENTITIES, false);

        return $reader;
    }

    /** العائلة من النوع المعلَن: أصلُ المستند وقوالبُه وعروضه تتشارك البنية. */
    private function family(string $mime): ?string
    {
        $base = MimeFamily::normalise($mime);
        if (! MimeFamily::isOfficeOpenXml($base)) {
            return null;
        }

        return match (true) {
            str_contains($base, '.presentationml.') => 'pptx',
            str_contains($base, '.wordprocessingml.') => 'docx',
            str_contains($base, '.spreadsheetml.') => 'xlsx',
            default => null,
        };
    }
}
