<?php

declare(strict_types=1);

namespace App\Domain\ContentIntelligence\Extractors;

use App\Domain\ContentIntelligence\Contracts\ContentExtractor;
use App\Domain\ContentIntelligence\ExtractionOutcome;
use DOMDocument;
use DOMXPath;

/**
 * نصّ SVG الأصليّ — أغنى مصدرٍ في هذا المستودع، وبلا OCR.
 *
 * ─── لماذا هذا المستخرِج أوّلًا ───
 *
 * أربعةٌ وعشرون من أصولنا السبعة والعشرين `image/svg+xml`. والشيفرة المحذوفة
 * عدّتها «صورًا» فكتبت لها أربعةً وعشرين صفَّ `ocr_unavailable` (ما زالت في
 * قاعدة التطوير). وهي ليست صورًا: هي XML يحمل النصّ حرفًا حرفًا في عُقَد
 * `<text>` و`<title>`. البند ٢ صريح: النصّ الأصليّ أوّلًا، وOCR حيث لا نصّ
 * أصليّ. فما احتاج محرّكًا لم يكن يحتاج إلّا مُحلّل XML.
 *
 * والمقيس في `025b…5614b.svg`: أحد عشرَ وحدةً نصّيّة منها «تحضير» و«العلوم»
 * و«الصف الخامس الابتدائي» و«الفصل الدراسي الثالث» و«DOCX · قابل للتعديل» —
 * أي المادّة والصفّ والفصل ونوع المورد وصيغته.
 *
 * ─── وما لا يفعله ───
 *
 * لا تطبيع عربيّ هنا. التطبيع واحدٌ في المشروع
 * (`app/Domain/Search/ArabicNormalizer.php:16`) ويُطبَّق على النصّ المفهرَس
 * والاستعلام معًا وقت الفهرسة؛ وتطبيعُ المخزون يجعل النصّ القانونيّ نسخةً
 * مشوّهة لا تُردّ.
 */
final class SvgNativeTextExtractor implements ContentExtractor
{
    public const NAME = 'svg_native_text';

    /**
     * نسخةٌ مؤرّخة لا `1.0.0`.
     *
     * الجدول يحمل صفوفًا كتبتها شيفرةٌ محذوفة بأسماء مستخرِجات ونسخة
     * `1.0.0`. لو تطابق المفتاح لظهر عملُ غيرنا في حصيلتنا، ولصار «لم
     * يُستخرَج شيءٌ جديد» جوابًا لا يُميَّز عن «كان مستخرَجًا أصلًا».
     */
    public const VERSION = '2026.09.23';

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
        return MimeFamily::normalise($mime) === 'image/svg+xml';
    }

    public function extract(string $bytes, string $mime): ExtractionOutcome
    {
        /*
         * XXE: بلا شبكة وبلا توسيع كياناتٍ خارجيّة (`LIBXML_NONET` ولا
         * `LIBXML_NOENT`). ملفّ SVG مرفوعٌ من الخارج، ومُحلّلٌ يتبع كيانًا
         * خارجيًّا يقرأ `/etc/passwd` أو يفتح اتّصالًا من داخل العامل.
         */
        $previous = libxml_use_internal_errors(true);
        $document = new DOMDocument;
        $loaded = $document->loadXML($bytes, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        $errors = libxml_get_errors();
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if ($loaded === false) {
            return ExtractionOutcome::failed('svg_parse_failed', [
                'libxml' => trim((string) ($errors[0]->message ?? 'unknown')),
            ]);
        }

        /*
         * `title|desc|text` فقط — و`tspan` **لا** تُطلَب بذاتها: `textContent`
         * للأب يضمّ أبناءه، فطلبُها يكرّر النصّ نفسه مرّتين ويُضاعف العدّ.
         * و`local-name()` لا اسمٌ مؤهَّل: كثيرٌ من الملفّات يعلن مجال الأسماء
         * افتراضيًّا وبعضُها لا يعلنه أصلًا، فالمطابقة بالاسم المحلّي تعمل
         * في الحالتين.
         */
        $nodes = (new DOMXPath($document))->query(
            '//*[local-name()="title" or local-name()="desc" or local-name()="text"]'
        );

        $lines = [];
        $byTag = [];

        foreach ($nodes ?: [] as $node) {
            $value = self::collapse($node->textContent);

            if ($value === '') {
                continue;
            }

            $lines[] = $value;
            $tag = $node->localName ?? 'unknown';
            $byTag[$tag] = ($byTag[$tag] ?? 0) + 1;
        }

        if ($lines === []) {
            /*
             * SVG بلا عُقدة نصّ = رسمٌ محض. ليس فشلًا وليس نجاحًا بنصٍّ فارغ:
             * صفٌّ `ok` بلا نصّ يجعل «تغطية الاستخراج» تعدّه مقروءًا وهو لم
             * يُقرأ. القدرة التالية — لو لزمت — تصييرٌ ثمّ OCR.
             */
            return ExtractionOutcome::capabilityGap('svg_without_native_text', [
                'capability' => 'svg_raster_ocr',
                'note_ar' => 'ملفّ SVG بلا عقدة <text> أو <title>: رسمٌ محض. قراءتُه تحتاج تصييرًا ثمّ OCR.',
            ]);
        }

        return ExtractionOutcome::ok(implode("\n", $lines), count($lines), [
            'nodes_by_tag' => $byTag,
            'source_encoding' => $document->encoding ?: 'UTF-8',
        ]);
    }

    /** مسافاتٌ متتالية أو أسطر داخل عقدةٍ واحدة تصير مسافةً واحدة. */
    private static function collapse(string $value): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $value));
    }
}
