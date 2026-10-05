<?php

namespace App\Domain\UrlPlatform;

use Normalizer;

/**
 * محرّك الروابط — حتميٌّ، عربيٌّ أوّلًا، بلا حالةٍ خفيّة.
 *
 * المدخل نفسه يعطي المخرج نفسه دائمًا: تطبيع يونيكود (NFKC)، إسقاط
 * التشكيل والتطويل، توحيد الفراغ والشرطات، إسقاط ما ليس حرفًا أو رقمًا،
 * إزالة الكلمة المكرّرة المتتالية، وقصٌّ عند حدّ الكلمة. العربيّة تبقى
 * عربيّةً في لغتها، وتُنقحَر لاتينيًّا حين تكون اللغة لاتينيّة.
 *
 * التفرّد ليس من شأن المحرّك: هو يقترح، والسجلّ يحجز تحت قيد القاعدة.
 */
final class SlugEngine
{
    public const DEFAULT_MIN = 1;

    public const DEFAULT_MAX = 80;

    /** حروف عربيّة → لاتينيّة: نقحرةٌ بسيطة تكفي لرابطٍ مقروء لا لنطقٍ دقيق. */
    private const TRANSLITERATION = [
        'ا' => 'a', 'أ' => 'a', 'إ' => 'i', 'آ' => 'a', 'ب' => 'b', 'ت' => 't', 'ث' => 'th', 'ج' => 'j',
        'ح' => 'h', 'خ' => 'kh', 'د' => 'd', 'ذ' => 'dh', 'ر' => 'r', 'ز' => 'z', 'س' => 's', 'ش' => 'sh',
        'ص' => 's', 'ض' => 'd', 'ط' => 't', 'ظ' => 'z', 'ع' => 'a', 'غ' => 'gh', 'ف' => 'f', 'ق' => 'q',
        'ك' => 'k', 'ل' => 'l', 'م' => 'm', 'ن' => 'n', 'ه' => 'h', 'و' => 'w', 'ي' => 'y', 'ى' => 'a',
        'ة' => 'h', 'ء' => '', 'ؤ' => 'w', 'ئ' => 'y', 'لا' => 'la',
    ];

    public function __construct(
        private readonly int $minLength = self::DEFAULT_MIN,
        private readonly int $maxLength = self::DEFAULT_MAX,
    ) {}

    /**
     * رابطٌ من عنوان — أو من رابطٍ كتبه المشغّل ليُطبَّع.
     *
     * `$transliterate` يُفرض للغات اللاتينيّة، ويُقبل اختيارًا للعربيّة لمن
     * يريد رابطًا ASCII.
     */
    public function generate(string $title, string $locale = 'ar', bool $transliterate = false): string
    {
        $text = $this->normalizeUnicode($title);
        $text = $this->stripArabicMarks($text);

        if ($transliterate || ! $this->isArabicLocale($locale)) {
            $text = $this->transliterate($text);
        }

        $text = mb_strtolower($text);
        // كلّ ما ليس حرفًا أو رقمًا يصير فاصلًا — الفراغ والشرطة السفليّة والترقيم سواء.
        $text = preg_replace('/[^\p{L}\p{N}]+/u', '-', $text) ?? '';
        $text = trim($text, '-');
        $text = $this->dedupeWords($text);

        $text = $this->truncate($text, $this->maxLength);

        if (mb_strlen($text) < $this->minLength) {
            throw UrlPlatformError::invalidSlug('أقصر من الحدّ الأدنى بعد التطبيع');
        }

        return $text;
    }

    /**
     * اقتراحاتٌ حتميّة: الأساس، ثمّ المنقحَر إن اختلف، ثمّ أقصر بكلمةٍ من الذيل.
     *
     * @return list<string>
     */
    public function suggestions(string $title, string $locale = 'ar', int $count = 3): array
    {
        $out = [];
        $push = function (string $candidate) use (&$out): void {
            if ($candidate !== '' && ! in_array($candidate, $out, true)) {
                $out[] = $candidate;
            }
        };

        try {
            $base = $this->generate($title, $locale);
            $push($base);
            if ($this->isArabicLocale($locale)) {
                $push($this->generate($title, $locale, true));
            }
            $words = explode('-', $base);
            while (count($words) > 1 && count($out) < $count) {
                array_pop($words);
                $push(implode('-', $words));
            }
        } catch (UrlPlatformError) {
            // عنوانٌ لا يُنتج رابطًا يُعاد فارغًا — والمستدعي يطلب رابطًا يدويًّا.
        }

        return array_slice($out, 0, $count);
    }

    /** الشكل الذي تُقارَن به الروابط للتفرّد: مطبَّعٌ ومطويّ الحالة. */
    public function uniquenessKey(string $slug): string
    {
        return mb_strtolower($this->normalizeUnicode($slug));
    }

    /**
     * تحقّقٌ من رابطٍ كتبه المشغّل حرفيًّا — بلا إعادة توليد.
     *
     * @return list<string> أسباب الرفض؛ فارغةٌ إن قُبل
     */
    public function validate(string $slug): array
    {
        $errors = [];
        if ($slug !== trim($slug) || $slug === '') {
            $errors[] = 'فارغٌ أو بفراغٍ على طرفيه';
        }
        if (preg_match('/[\s\/#?%&+\\\\]/u', $slug) === 1) {
            $errors[] = 'يحوي فراغًا أو محرف عنوانٍ ذا معنى';
        }
        if (preg_match('/[\x00-\x1F\x7F]/u', $slug) === 1 || ! mb_check_encoding($slug, 'UTF-8')) {
            $errors[] = 'محارف تحكّم أو ترميزٌ فاسد';
        }
        if (mb_strlen($slug) > $this->maxLength) {
            $errors[] = "أطول من {$this->maxLength} محرفًا";
        }
        if (ReservedRoutes::isReserved($slug)) {
            $errors[] = 'اسمٌ محجوز للنظام';
        }
        if ($slug === '.' || $slug === '..') {
            $errors[] = 'مقطع اجتياز';
        }

        return $errors;
    }

    /** الرابط بلاحقة رقميّة حتميّة: `slug` ثمّ `slug-2` ثمّ `slug-3`. */
    public function withSuffix(string $slug, int $n): string
    {
        if ($n <= 1) {
            return $slug;
        }
        $suffix = '-'.$n;
        $room = $this->maxLength - mb_strlen($suffix);

        return $this->truncate($slug, max(1, $room)).$suffix;
    }

    private function normalizeUnicode(string $text): string
    {
        if (class_exists(Normalizer::class)) {
            $normalized = Normalizer::normalize($text, Normalizer::FORM_KC);
            if (is_string($normalized)) {
                $text = $normalized;
            }
        }

        return preg_replace('/\s+/u', ' ', trim($text)) ?? $text;
    }

    private function stripArabicMarks(string $text): string
    {
        // التشكيل U+064B–U+0652 والتطويل U+0640 لا يميّزان كلمةً عن أخرى في رابط.
        return preg_replace('/[\x{064B}-\x{0652}\x{0640}\x{0670}]/u', '', $text) ?? $text;
    }

    private function transliterate(string $text): string
    {
        return strtr($text, self::TRANSLITERATION);
    }

    private function dedupeWords(string $slug): string
    {
        $out = [];
        foreach (explode('-', $slug) as $word) {
            if ($word !== '' && ($out === [] || end($out) !== $word)) {
                $out[] = $word;
            }
        }

        return implode('-', $out);
    }

    /** يقصّ عند حدّ الكلمة حين يمكن — والكلمة الواحدة الطويلة تُقصّ حرفيًّا. */
    private function truncate(string $slug, int $max): string
    {
        if (mb_strlen($slug) <= $max) {
            return $slug;
        }
        $cut = mb_substr($slug, 0, $max);
        $lastDash = mb_strrpos($cut, '-');

        return rtrim($lastDash !== false && $lastDash > 0 ? mb_substr($cut, 0, $lastDash) : $cut, '-');
    }

    private function isArabicLocale(string $locale): bool
    {
        return str_starts_with(strtolower($locale), 'ar');
    }
}
