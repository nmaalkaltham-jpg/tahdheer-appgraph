<?php

namespace Tests\Unit\UrlPlatform;

use App\Domain\UrlPlatform\SlugEngine;
use App\Domain\UrlPlatform\UrlPlatformError;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class SlugEngineTest extends TestCase
{
    private SlugEngine $engine;

    protected function setUp(): void
    {
        parent::setUp();
        $this->engine = new SlugEngine(1, 40);
    }

    public function test_arabic_titles_keep_arabic_letters_and_drop_diacritics(): void
    {
        $this->assertSame('تحضير-الرياضيات-الصف-الرابع', $this->engine->generate('تَحضيرُ  الرياضيات — الصفّ الرابع', 'ar'));
    }

    public function test_latin_locale_transliterates_arabic(): void
    {
        $this->assertSame('thdyr-alryadyat', $this->engine->generate('تحضير الرياضيات', 'en'));
    }

    public function test_output_is_deterministic_and_lowercased(): void
    {
        $a = $this->engine->generate('Math Grade 4 — Term One!', 'en');
        $b = $this->engine->generate('Math Grade 4 — Term One!', 'en');
        $this->assertSame('math-grade-4-term-one', $a);
        $this->assertSame($a, $b);
    }

    public function test_consecutive_duplicate_words_collapse(): void
    {
        $this->assertSame('math-book', $this->engine->generate('math math math book', 'en'));
    }

    public function test_long_titles_truncate_at_a_word_boundary(): void
    {
        $slug = $this->engine->generate('one two three four five six seven eight nine ten eleven twelve', 'en');
        $this->assertLessThanOrEqual(40, mb_strlen($slug));
        $this->assertStringEndsNotWith('-', $slug);
        $this->assertSame('one-two-three-four-five-six-seven-eight', $slug);
    }

    public function test_emoji_and_punctuation_only_titles_are_rejected(): void
    {
        $this->expectException(UrlPlatformError::class);
        $this->engine->generate('🎉 !!! ???', 'en');
    }

    public function test_suffixes_are_deterministic_and_respect_the_maximum(): void
    {
        $engine = new SlugEngine(1, 10);
        $this->assertSame('product', $engine->withSuffix('product', 1));
        $this->assertSame('product-2', $engine->withSuffix('product', 2));
        $this->assertSame('product-12', $engine->withSuffix('productive', 12));
        $this->assertSame('produc-123', $engine->withSuffix('productive', 123));
    }

    public function test_suggestions_are_distinct_and_ordered(): void
    {
        $out = $this->engine->suggestions('تحضير الرياضيات الصف الرابع', 'ar', 3);
        $this->assertSame('تحضير-الرياضيات-الصف-الرابع', $out[0]);
        $this->assertCount(3, $out);
        $this->assertSame(count($out), count(array_unique($out)));
    }

    #[DataProvider('invalidSlugs')]
    public function test_validation_names_the_reason(string $slug, string $needle): void
    {
        $errors = $this->engine->validate($slug);
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString($needle, implode(' ', $errors));
    }

    public static function invalidSlugs(): array
    {
        return [
            'space' => ['a b', 'فراغًا'],
            'hash' => ['a#b', 'محرف'],
            'reserved' => ['admin', 'محجوز'],
            'traversal' => ['..', 'اجتياز'],
            'control' => ["a\x01b", 'تحكّم'],
            'too-long' => [str_repeat('a', 41), 'أطول'],
        ];
    }

    public function test_uniqueness_key_is_case_insensitive(): void
    {
        $this->assertSame($this->engine->uniquenessKey('Math-4'), $this->engine->uniquenessKey('math-4'));
    }
}
