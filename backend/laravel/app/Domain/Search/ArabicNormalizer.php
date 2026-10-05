<?php

namespace App\Domain\Search;

/**
 * Arabic text normalisation for Tahdheer search.
 *
 * Faithful port of the legacy theme's Tahdheer_Search_Normalizer
 * (inc/search/class-tahdheer-search-normalizer.php). Pure static functions —
 * no framework, no database — applied to BOTH indexed text and queries,
 * never mutating stored canonical text.
 *
 * Deliberately conservative: no stemming and no definite-article stripping.
 * The store sells exact, named files, so precision matters more than recall.
 */
final class ArabicNormalizer
{
    /**
     * Diacritics (tashkeel), tatweel and other zero-width marks to strip.
     *
     * @var array<int, string>
     */
    private const STRIP = [
        "\u{064B}", // ً  fathatan
        "\u{064C}", // ٌ  dammatan
        "\u{064D}", // ٍ  kasratan
        "\u{064E}", // َ  fatha
        "\u{064F}", // ُ  damma
        "\u{0650}", // ِ  kasra
        "\u{0651}", // ّ  shadda
        "\u{0652}", // ْ  sukun
        "\u{0653}", // ٓ  maddah above
        "\u{0654}", // ٔ  hamza above
        "\u{0655}", // ٕ  hamza below
        "\u{0656}", // ٖ  subscript alef
        "\u{0657}", // ٗ  inverted damma
        "\u{0670}", // ٰ  superscript alef
        "\u{0640}", // ـ  tatweel / kashida
        "\u{200B}", // zero-width space
        "\u{200C}", // zero-width non-joiner
        "\u{200D}", // zero-width joiner
        "\u{FEFF}", // BOM
        // Bidi controls: pdftotext wraps Arabic runs in RLE…PDF, and browsers/Word
        // emit LRM/RLM and isolates. Left in place they sit before a word's first
        // letter and defeat word-start matching on every extracted file.
        "\u{200E}", // LRM
        "\u{200F}", // RLM
        "\u{202A}", // LRE
        "\u{202B}", // RLE
        "\u{202C}", // PDF (pop directional formatting)
        "\u{202D}", // LRO
        "\u{202E}", // RLO
        "\u{2066}", // LRI
        "\u{2067}", // RLI
        "\u{2068}", // FSI
        "\u{2069}", // PDI
    ];

    /**
     * Letter folding map. Applied after diacritics are stripped.
     *
     * @var array<string, string>
     */
    private const FOLD = [
        // Alef forms → bare alef.
        'أ' => 'ا',
        'إ' => 'ا',
        'آ' => 'ا',
        'ٱ' => 'ا',
        'ٲ' => 'ا',
        'ٳ' => 'ا',
        // Ya / alef maqsura → ya.
        'ى' => 'ي',
        'ئ' => 'ي',
        'ۍ' => 'ي',
        'ې' => 'ي',
        'ی' => 'ي',
        // Waw with hamza → waw.
        'ؤ' => 'و',
        // Taa marbuta → haa, so «مادة» and «ماده» meet.
        'ة' => 'ه',
        // Kaf / heh variants typed on Persian and Urdu keyboards.
        'ک' => 'ك',
        'ھ' => 'ه',
        'ہ' => 'ه',
    ];

    /**
     * Arabic-Indic and Eastern Arabic-Indic digits → Latin digits.
     *
     * @var array<string, string>
     */
    private const DIGITS = [
        '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
        '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
        '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
    ];

    /**
     * Punctuation treated as a word separator.
     *
     * @var array<int, string>
     */
    private const SEPARATORS = [
        '،', '؛', '؟', '«', '»', '“', '”', '‘', '’', '—', '–', '_', '/', '\\', '|',
        '(', ')', '[', ']', '{', '}', ':', ';', '!', '?', '"', "'", ',', '.', '+',
        '*', '#', '&', '%', '@', '=', '<', '>', '~', '^', '$',
    ];

    /**
     * Normalise a string for comparison. The caller keeps the raw text for
     * display; this value is for matching and cache keys only.
     */
    public static function normalize(string $text): string
    {
        if ($text === '') {
            return '';
        }

        // Unicode NFC first so composed and decomposed forms agree.
        if (class_exists(\Normalizer::class)) {
            $normalized = \Normalizer::normalize($text, \Normalizer::FORM_C);
            if (is_string($normalized)) {
                $text = $normalized;
            }
        }

        $text = str_replace(self::STRIP, '', $text);
        $text = strtr($text, self::FOLD);
        $text = strtr($text, self::DIGITS);
        $text = str_replace(self::SEPARATORS, ' ', $text);

        // Lowercase Latin without touching Arabic.
        $text = mb_strtolower($text, 'UTF-8');

        // Collapse every run of whitespace, including Arabic-script spaces.
        $text = (string) preg_replace('/\s+/u', ' ', $text);

        return trim($text);
    }

    /**
     * Normalise only the digits, preserving everything else.
     * Used for SKU matching, where letter folding would be wrong.
     */
    public static function normalizeDigits(string $text): string
    {
        return strtr($text, self::DIGITS);
    }

    /**
     * Split normalised text into unique search tokens, order preserved.
     *
     * @return array<int, string>
     */
    public static function tokenize(string $text, int $min = 2, int $limit = 10): array
    {
        $normalized = self::normalize($text);

        if ($normalized === '') {
            return [];
        }

        $parts = preg_split('/\s+/u', $normalized) ?: [];
        $tokens = [];

        foreach ($parts as $part) {
            if ($part === '' || self::length($part) < $min) {
                continue;
            }
            if (! in_array($part, $tokens, true)) {
                $tokens[] = $part;
            }
            if (count($tokens) >= $limit) {
                break;
            }
        }

        return $tokens;
    }

    /** Multibyte-safe string length in characters. */
    public static function length(string $text): int
    {
        return (int) mb_strlen($text, 'UTF-8');
    }

    /** Whether the needle is a prefix of the haystack, after normalisation. */
    public static function startsWith(string $haystack, string $needle): bool
    {
        $haystack = self::normalize($haystack);
        $needle = self::normalize($needle);

        if ($needle === '') {
            return false;
        }

        return str_starts_with($haystack, $needle);
    }

    /**
     * Edit distance over UTF-8 characters (optimal string alignment:
     * insert, delete, substitute, or swap two neighbours = one edit each).
     *
     * PHP's levenshtein() is byte-based and reports nonsense for Arabic, where
     * one letter is two bytes. This walks characters instead, bounded by $max
     * so a long pair exits early instead of filling the matrix.
     *
     * @return int Distance, or $max + 1 when it certainly exceeds $max.
     */
    public static function distance(string $a, string $b, int $max = 2): int
    {
        $a = self::chars($a);
        $b = self::chars($b);
        $la = count($a);
        $lb = count($b);

        if (abs($la - $lb) > $max) {
            return $max + 1;
        }
        if ($la === 0) {
            return $lb;
        }
        if ($lb === 0) {
            return $la;
        }

        /*
         * Optimal string alignment: an adjacent transposition costs ONE edit.
         * Swapped neighbours are the commonest Arabic typo («ريضايات» for
         * «رياضيات»); plain Levenshtein charged them two, which put them
         * past the one-edit tolerance every word under eight letters gets.
         */
        $prevPrev = null;
        $prev = range(0, $lb);

        for ($i = 1; $i <= $la; $i++) {
            $row = [$i];
            $best = $i;
            for ($j = 1; $j <= $lb; $j++) {
                $cost = $a[$i - 1] === $b[$j - 1] ? 0 : 1;
                $cell = min($row[$j - 1] + 1, $prev[$j] + 1, $prev[$j - 1] + $cost);
                if ($prevPrev !== null && $j > 1 && $a[$i - 1] === $b[$j - 2] && $a[$i - 2] === $b[$j - 1]) {
                    $cell = min($cell, $prevPrev[$j - 2] + 1);
                }
                $row[] = $cell;
                $best = min($best, $cell);
            }

            /*
             * The row minimum can be undercut by at most one transposition
             * reaching back two rows, so exit only once both rows exceed.
             */
            if ($best > $max && ($prevPrev === null || min($prev) > $max)) {
                return $max + 1;
            }

            $prevPrev = $prev;
            $prev = $row;
        }

        return min((int) $prev[$lb], $max + 1);
    }

    /**
     * Does some word of $haystack START with $token, after optional attached
     * Arabic prefixes (و ف ب ك ل and the article)? Both sides normalized.
     *
     * Substring matching let «علم» match «المعلم» — the token sits in the
     * middle of a word that means something else. Anchoring at a word start
     * keeps stems working («رياض» ⊂ «الرياضيات») and drops the accidental.
     */
    public static function containsWord(string $haystack, string $token): bool
    {
        if ($haystack === '' || $token === '') {
            return false;
        }

        $clitics = '(?:[وف]?(?:[بكل]|ال|بال|كال|لل)?)';

        /*
         * جذعٌ قصير (أقلّ من أربعة أحرف بعد «ال») يجب أن يكون الكلمةَ كلَّها:
         * «المد» بادئةُ «المدرسة» و«المدارس» و«المدينة»، فمطابقتُه بادئةً
         * تُغرق «المد الطبيعي» في كلّ ما فيه مدرسة. والطويل يبقى بادئة:
         * «رياض» ⊂ «الرياضيات» جذعٌ حقيقيّ.
         */
        $stem = str_starts_with($token, 'ال') ? mb_substr($token, 2) : $token;
        $end = mb_strlen($stem) < 4 ? '(?=\s|$)' : '';

        return preg_match('/(?:^|\s)'.$clitics.preg_quote($token, '/').$end.'/u', $haystack) === 1;
    }

    /**
     * Split a UTF-8 string into characters.
     *
     * @return array<int, string>
     */
    private static function chars(string $text): array
    {
        $chars = preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY);

        return is_array($chars) ? $chars : [];
    }
}
