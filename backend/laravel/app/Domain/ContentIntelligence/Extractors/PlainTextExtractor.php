<?php

declare(strict_types=1);

namespace App\Domain\ContentIntelligence\Extractors;

use App\Domain\ContentIntelligence\Contracts\ContentExtractor;
use App\Domain\ContentIntelligence\ExtractionOutcome;

/**
 * نصّ صريح — البايتات هي النصّ، ولا شيء يُفكّ.
 *
 * الحذر الوحيد هنا الترميز: ملفٌّ عربيٌّ بترميز Windows-1256 يمرّ كبايتاتٍ
 * صالحة ويُخزَّن نصًّا مشوّهًا لا يُبحَث فيه ولا يُقرأ — وهو أسوأ من ملفٍّ
 * لم يُستخرَج، لأنّه يبدو مستخرَجًا. فما لم يكن UTF-8 صالحًا يُعلَن عجزًا
 * باسمه ولا يُخمَّن ترميزُه.
 */
final class PlainTextExtractor implements ContentExtractor
{
    public const NAME = 'plain_text';

    /** مؤرّخة لا `1.0.0`: النسخة الأخيرة تخصّ شيفرةً محذوفة، انظر SvgNativeTextExtractor. */
    public const VERSION = '2026.09.23';

    /**
     * سقف ما يُخزَّن.
     *
     * عمود `text` في PostgreSQL بلا حدّ، والحدّ هنا لأجل ما يقرؤه بعدُ: صفٌّ
     * بمليون حرفٍ يُحمَّل في كلّ استعلامٍ يلمس الجدول. ومئتا ألف حرفٍ تسع
     * كتابًا عربيًّا كاملًا، والقطع يُعلَن في `meta.truncated` فلا يُظنّ
     * الناقص تامًّا.
     */
    private const MAX_CHARS = 200_000;

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
        return in_array(MimeFamily::normalise($mime), ['text/plain', 'text/markdown'], true);
    }

    public function extract(string $bytes, string $mime): ExtractionOutcome
    {
        /* BOM يُحذف: يبقى وإلّا حرفًا خفيًّا في أوّل كلمةٍ تُفهرَس. */
        $text = str_starts_with($bytes, "\u{FEFF}") ? substr($bytes, 3) : $bytes;

        if (! mb_check_encoding($text, 'UTF-8')) {
            return ExtractionOutcome::capabilityGap('encoding_not_utf8', [
                'capability' => 'legacy_encoding_detection',
                'note_ar' => 'البايتات ليست UTF-8 صالحًا. تخمين الترميز (Windows-1256 وغيره) قدرةٌ غير مبنيّة، '
                    .'وتحويلٌ بافتراضٍ خاطئ يُنتج نصًّا مشوّهًا يبدو صحيحًا.',
            ]);
        }

        $length = mb_strlen($text, 'UTF-8');
        $truncated = $length > self::MAX_CHARS;

        if ($truncated) {
            $text = mb_substr($text, 0, self::MAX_CHARS, 'UTF-8');
        }

        $lines = array_values(array_filter(
            array_map(trim(...), preg_split('/\R/u', $text) ?: []),
            static fn (string $line): bool => $line !== '',
        ));

        return ExtractionOutcome::ok(implode("\n", $lines), count($lines), [
            'source_encoding' => 'UTF-8',
            'truncated' => $truncated,
            'text_bytes' => strlen($bytes),
        ]);
    }
}
