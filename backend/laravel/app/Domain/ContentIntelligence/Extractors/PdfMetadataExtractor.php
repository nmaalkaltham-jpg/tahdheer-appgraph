<?php

declare(strict_types=1);

namespace App\Domain\ContentIntelligence\Extractors;

use App\Domain\ContentIntelligence\Contracts\ContentExtractor;
use App\Domain\ContentIntelligence\ExtractionOutcome;

/**
 * PDF — بيانات المستند الوصفيّة الأصليّة، وإعلانُ ما وراءها.
 *
 * ─── لماذا الوصف وحده ───
 *
 * نصّ صفحات PDF مكتوبٌ بمعرّفات رسوم (glyph ids) داخل مجاري مضغوطة، ولا
 * يُردّ حرفًا عربيًّا إلّا بقراءة خرائط `ToUnicode` لكلّ خطٍّ مجزّأ ثمّ
 * إعادة ترتيبٍ ثنائيّ الاتّجاه. ذلك مُحلّل PDF كامل، وهو حزمة
 * `smalot/pdfparser` — ولا تُضاف تبعيّةٌ في هذا العمل.
 *
 * والبديل المرفوض: فكّ المجاري ثمّ التقاط ما بين الأقواس في `Tj` وتخزينه
 * «نصًّا». مقيسٌ في ملفّ الكتالوج الوحيد (`5f06…bf89`): مئةٌ وستّون عامل
 * `Tj/TJ` كلّها معرّفات رسومٍ لخطوطٍ مجزّأة — أي مئةٌ وستّون سطر خردةٍ
 * تُخزَّن نصًّا وتدخل الفهرس وتظهر في نتائج بحثٍ عربيّ.
 *
 * فيُستخرَج ما هو **نصٌّ فعلًا**: قاموس `Info` — وفيه في ملفّنا
 * «بنر العروض التقديمية الرقمية» — وتُعلَن الحالة `partial` مع تسمية الجزء
 * الغائب وعدد عوامله المقيس. ناقصٌ يُقال خيرٌ من كاملٍ مُلفَّق.
 */
final class PdfMetadataExtractor implements ContentExtractor
{
    public const NAME = 'pdf_metadata';

    /** مؤرّخة لا `1.0.0`: انظر SvgNativeTextExtractor. */
    public const VERSION = '2026.09.23';

    /** حقول `Info` التي تحمل نصًّا يكتبه إنسان. */
    private const FIELDS = ['Title', 'Subject', 'Keywords', 'Author'];

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
        return MimeFamily::normalise($mime) === 'application/pdf';
    }

    public function extract(string $bytes, string $mime): ExtractionOutcome
    {
        if (! str_starts_with($bytes, '%PDF-')) {
            return ExtractionOutcome::failed('pdf_header_missing', [
                'note_ar' => 'البايتات لا تبدأ بـ%PDF-: النوع المعلَن يخالف المحتوى.',
            ]);
        }

        if (preg_match('/\/Encrypt\s/', $bytes) === 1) {
            /* مستندٌ مُعمّى: كلّ سلسلةٍ فيه مشفّرة، وفكُّها قدرةٌ أخرى. */
            return ExtractionOutcome::capabilityGap('pdf_encrypted', [
                'capability' => 'pdf_decryption',
                'note_ar' => 'المستند مُعمّى. قراءة سلاسله تحتاج فكّ تعميةٍ غير مبنيّ.',
            ]);
        }

        $fields = [];

        foreach (self::FIELDS as $field) {
            $value = self::readField($bytes, $field);

            if ($value !== null && $value !== '') {
                $fields[$field] = $value;
            }
        }

        $pageTextOperators = self::countTextOperators($bytes);

        $gap = [
            'capability' => 'pdf_page_text',
            'page_text_operators' => $pageTextOperators,
            'requires' => ['smalot/pdfparser'],
            'note_ar' => 'نصّ الصفحات لم يُقرأ ولم يُدّعَ: يحتاج قراءة خرائط ToUnicode للخطوط المجزّأة. '
                .'الحزمة المطلوبة smalot/pdfparser ولم تُضَف.',
        ];

        if ($fields === []) {
            return ExtractionOutcome::capabilityGap('pdf_page_text_unavailable', $gap);
        }

        $lines = array_values($fields);

        return ExtractionOutcome::partial(implode("\n", $lines), count($lines), [
            ...$gap,
            'fields' => array_keys($fields),
        ]);
    }

    /**
     * قيمة حقلٍ من قاموس `Info` — سلسلةٌ ستّ عشريّة أو حرفيّة.
     *
     * لا مُحلّل PDF هنا: مطابقةٌ نصّيّة على شكل السلسلة. وحدودها مكتوبة —
     * مستندٌ يخزّن وصفَه في مجرًى مضغوط (نادر، ويُنتجه مولّدون قليلون) لا
     * يُقرأ وصفُه، فيُعلَن عجزًا لا يُخترع له نصّ.
     */
    private static function readField(string $bytes, string $field): ?string
    {
        if (preg_match('/\/'.$field.'\s*<([0-9A-Fa-f\s]+)>/', $bytes, $match) === 1) {
            $raw = (string) hex2bin((string) preg_replace('/\s+/', '', $match[1]));

            /* BOM FEFF = UTF-16BE، وهو ما يكتبه كلّ مولّدٍ للعربيّة. */
            if (str_starts_with($raw, "\xFE\xFF")) {
                $decoded = mb_convert_encoding(substr($raw, 2), 'UTF-8', 'UTF-16BE');

                return self::clean(is_string($decoded) ? $decoded : '');
            }

            return self::clean($raw);
        }

        if (preg_match('/\/'.$field.'\s*\(((?:[^()\\\\]|\\\\.)*)\)/', $bytes, $match) === 1) {
            $value = str_replace(['\\(', '\\)', '\\\\'], ['(', ')', '\\'], $match[1]);

            return mb_check_encoding($value, 'UTF-8') ? self::clean($value) : null;
        }

        return null;
    }

    /**
     * عدد عوامل الرسم النصّيّ في المجاري المضغوطة.
     *
     * رقمٌ مقيسٌ لا تقدير: هو الدليل على أنّ الفجوة المعلَنة حقيقيّة — صفرٌ
     * يعني مستندًا بلا نصّ صفحاتٍ أصلًا، ومئةٌ وستّون تعني نصًّا موجودًا لا
     * نملك قراءته.
     */
    private static function countTextOperators(string $bytes): int
    {
        if (preg_match_all('/stream\r?\n(.*?)endstream/s', $bytes, $matches) === 0) {
            return 0;
        }

        $operators = 0;

        foreach ($matches[1] as $stream) {
            /* `@`: مجرًى غير مضغوط أو بمرشّحٍ آخر يُتجاوَز بلا ضجيج. */
            $inflated = @gzuncompress(ltrim($stream, "\r\n"));

            if ($inflated === false) {
                continue;
            }

            $operators += (int) preg_match_all('/(?:Tj|TJ)\b/', $inflated);
        }

        return $operators;
    }

    private static function clean(string $value): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $value));
    }
}
