<?php

namespace Tests\Unit\Reviews;

use App\Http\Resources\ReviewResource;
use PHPUnit\Framework\TestCase;

/**
 * The reviewer's public name. It is always derived from the account — there is
 * no free-text author field to test, by design.
 */
class DisplayNameTest extends TestCase
{
    /**
     * The «ال» trap: Arabic family names overwhelmingly begin with the definite
     * article, so a naive first-character initial renders every reviewer as the
     * same «ا.» and distinguishes nobody.
     */
    public function test_the_definite_article_is_stripped_before_taking_the_initial(): void
    {
        $this->assertSame('نورة ع.', ReviewResource::displayName('نورة العتيبي'));
        $this->assertSame('سارة ق.', ReviewResource::displayName('سارة القحطاني'));
        $this->assertSame('منى ش.', ReviewResource::displayName('منى الشمري'));

        // A family name without the article keeps its own first letter.
        $this->assertSame('فيصل د.', ReviewResource::displayName('فيصل دوسري'));
    }

    public function test_edge_shapes_never_produce_a_fabricated_name(): void
    {
        // Single token: shown as-is rather than abbreviated to nothing.
        $this->assertSame('نورة', ReviewResource::displayName('نورة'));

        // More than two tokens: the LAST is the family name.
        $this->assertSame('نورة ع.', ReviewResource::displayName('نورة بنت محمد العتيبي'));

        // Extra whitespace must not create an empty initial.
        $this->assertSame('نورة ع.', ReviewResource::displayName('  نورة   العتيبي  '));

        // No account name ⇒ an explicit honest label, never an invented person.
        $this->assertSame('مشترٍ موثّق', ReviewResource::displayName(null));
        $this->assertSame('مشترٍ موثّق', ReviewResource::displayName('   '));
    }

    /**
     * A two-character family name is NOT stripped (that would leave nothing to
     * initial), so it contributes its own first letter like any other name.
     */
    public function test_a_two_letter_family_name_is_not_emptied_by_the_article_strip(): void
    {
        $this->assertSame('نورة ا.', ReviewResource::displayName('نورة ال'));
    }
}
