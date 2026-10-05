<?php

namespace Tests\Unit;

use App\Domain\Search\ArabicNormalizer;
use PHPUnit\Framework\TestCase;

class ArabicNormalizerTest extends TestCase
{
    public function test_alef_forms_fold_to_bare_alef(): void
    {
        $this->assertSame('اوراق', ArabicNormalizer::normalize('أوراق'));
        $this->assertSame(
            ArabicNormalizer::normalize('اوراق'),
            ArabicNormalizer::normalize('أوراق'),
        );
        $this->assertSame('اسلاميه', ArabicNormalizer::normalize('إسلامية'));
        $this->assertSame('اخر', ArabicNormalizer::normalize('آخر'));
    }

    public function test_taa_marbuta_folds_to_haa_so_variants_group(): void
    {
        $this->assertSame(
            ArabicNormalizer::normalize('ملزمه'),
            ArabicNormalizer::normalize('ملزمة'),
        );
        $this->assertSame('ملزمه', ArabicNormalizer::normalize('ملزمة'));
    }

    public function test_typo_is_not_silently_replaced(): void
    {
        // «تحظير» (ظ) is a real-world typo for «تحضير» (ض): the normalizer must
        // NOT merge them — spell-correction suggests, it never silently replaces.
        $this->assertNotSame(
            ArabicNormalizer::normalize('تحظير'),
            ArabicNormalizer::normalize('تحضير'),
        );
        // The character-level distance helper is what powers the suggestion.
        $this->assertSame(1, ArabicNormalizer::distance('تحظير', 'تحضير'));
    }

    public function test_arabic_and_farsi_digits_become_latin(): void
    {
        $this->assertSame('6', ArabicNormalizer::normalize('٦'));
        $this->assertSame('123', ArabicNormalizer::normalize('١٢٣'));
        $this->assertSame('456', ArabicNormalizer::normalize('۴۵۶')); // Eastern/Farsi
        $this->assertSame('الصف 6', ArabicNormalizer::normalizeDigits('الصف ٦'));
    }

    public function test_tashkeel_is_stripped(): void
    {
        $this->assertSame('مدرس', ArabicNormalizer::normalize('مُدَرِّس'));
        $this->assertSame(
            ArabicNormalizer::normalize('تحضير'),
            ArabicNormalizer::normalize('تَحْضِير'),
        );
    }

    public function test_tatweel_is_stripped(): void
    {
        $this->assertSame('تحضير', ArabicNormalizer::normalize('تحـــضير'));
    }

    public function test_mixed_arabic_english_query_normalizes(): void
    {
        $this->assertSame(
            'ملزمه math اول ثانوي',
            ArabicNormalizer::normalize('ملزمة MATH أول ثانوي'),
        );
    }

    public function test_whitespace_and_separators_collapse(): void
    {
        $this->assertSame('اوراق عمل', ArabicNormalizer::normalize("  أوراق   عمل \u{200B} "));
        $this->assertSame('تحضير لغتي', ArabicNormalizer::normalize('تحضير/لغتي'));
    }

    public function test_tokenize_returns_unique_tokens_dropping_noise(): void
    {
        $this->assertSame(
            ['ملزمه', 'math', 'اول', 'ثانوي'],
            ArabicNormalizer::tokenize('ملزمة math اول ثانوي ة'),
        );
    }

    public function test_distance_is_character_based_not_byte_based(): void
    {
        $this->assertSame(0, ArabicNormalizer::distance('تحضير', 'تحضير'));
        $this->assertSame(3, ArabicNormalizer::distance('اب', 'ابجده', 3));
        // Bounded: certainly exceeding max returns max + 1.
        $this->assertSame(3, ArabicNormalizer::distance('تحضير', 'x', 2));
    }

    public function test_adjacent_transposition_is_one_edit(): void
    {
        // «ريضايات» is the most common way «رياضيات» is mistyped: two letters swapped.
        $this->assertSame(1, ArabicNormalizer::distance('ريضايات', 'رياضيات'));
        $this->assertSame(1, ArabicNormalizer::distance('لتغي', 'لغتي'));
        $this->assertSame(1, ArabicNormalizer::distance('ab', 'ba'));
        // A transposition is not a free pass: two swaps are still two edits.
        $this->assertSame(2, ArabicNormalizer::distance('badc', 'abcd'));
        // Bound still holds.
        $this->assertSame(2, ArabicNormalizer::distance('abcd', 'wxyz', 1));
    }

    public function test_contains_word_anchors_at_a_word_start_after_attached_prefixes(): void
    {
        $this->assertTrue(ArabicNormalizer::containsWord('دليل المعلم للصف', 'معلم'));
        $this->assertTrue(ArabicNormalizer::containsWord('مادة العلوم', 'علوم'), 'definite article');
        $this->assertTrue(ArabicNormalizer::containsWord('وبالعلوم', 'علوم'), 'stacked clitics');
        $this->assertTrue(ArabicNormalizer::containsWord('الرياضيات', 'رياض'), 'a stem still matches its word');
        $this->assertTrue(ArabicNormalizer::containsWord('mathematics worksheets', 'math'));
        // «علم» sits inside «المعلم» but no word there starts with it.
        $this->assertFalse(ArabicNormalizer::containsWord('دليل المعلم', 'علم'));
        $this->assertFalse(ArabicNormalizer::containsWord('', 'علم'));
        // A short stem («مد» once the article goes) must be the whole word: «المد» is not «المدرسة».
        $this->assertFalse(ArabicNormalizer::containsWord('المدرسة الحديثة', 'المد'));
        $this->assertTrue(ArabicNormalizer::containsWord('أحكام المد الطبيعي', 'المد'));
        $this->assertTrue(ArabicNormalizer::containsWord('بالمد', 'المد'), 'clitics still attach');
        $this->assertFalse(ArabicNormalizer::containsWord('الحلول', 'حل'));
    }

    public function test_bidi_control_marks_are_stripped(): void
    {
        // pdftotext wraps Arabic runs in RLE…PDF (U+202B/U+202C); browsers and Word add LRM/RLM and isolates.
        $wrapped = "\u{202B}بنر العروض\u{202C} \u{200F}رقمية\u{200E} \u{2067}تقديمية\u{2069}";

        $this->assertSame('بنر العروض رقميه تقديميه', ArabicNormalizer::normalize($wrapped));
        $this->assertTrue(ArabicNormalizer::containsWord(ArabicNormalizer::normalize($wrapped), 'بنر'));
    }

    public function test_starts_with_uses_normalized_forms(): void
    {
        $this->assertTrue(ArabicNormalizer::startsWith('أوراق عمل رياضيات', 'اوراق'));
        $this->assertFalse(ArabicNormalizer::startsWith('أوراق عمل', ''));
    }
}
