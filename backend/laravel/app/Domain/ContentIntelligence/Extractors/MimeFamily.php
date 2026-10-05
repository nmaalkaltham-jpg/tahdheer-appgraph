<?php

declare(strict_types=1);

namespace App\Domain\ContentIntelligence\Extractors;

/**
 * قراءة نوع MIME — موضعٌ واحد لا سطرٌ في كلّ مستخرِج.
 *
 * `assets.mime` قد يحمل مُعاملات (`text/plain; charset=utf-8`) وقد يحمل
 * حروفًا كبيرة. ومقارنةٌ نصّيّة مباشرة في كلّ مستخرِجٍ تعني أنّ أوّل ملفٍّ
 * يصل بمُعامِلٍ يسقط إلى «غير مدعوم» بلا سببٍ ظاهر.
 */
final class MimeFamily
{
    /** النوع بلا مُعاملات وبحروفٍ صغيرة. */
    public static function normalise(string $mime): string
    {
        $base = strtolower(trim(explode(';', $mime, 2)[0]));

        return $base;
    }

    /** صيغ Office المضغوطة (docx/pptx/xlsx) — فئةٌ واحدة لعجزٍ واحد. */
    public static function isOfficeOpenXml(string $mime): bool
    {
        return str_starts_with(self::normalise($mime), 'application/vnd.openxmlformats-officedocument.');
    }

    /** صيغ Office القديمة (doc/ppt/xls). */
    public static function isLegacyOffice(string $mime): bool
    {
        return in_array(self::normalise($mime), [
            'application/msword',
            'application/vnd.ms-powerpoint',
            'application/vnd.ms-excel',
        ], true);
    }

    /** صورةٌ نقطيّة — أي صورةٍ عدا SVG، فـSVG نصٌّ لا بكسل. */
    public static function isRasterImage(string $mime): bool
    {
        $base = self::normalise($mime);

        return str_starts_with($base, 'image/') && $base !== 'image/svg+xml';
    }
}
