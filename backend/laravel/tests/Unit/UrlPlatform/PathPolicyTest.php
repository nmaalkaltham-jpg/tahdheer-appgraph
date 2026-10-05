<?php

namespace Tests\Unit\UrlPlatform;

use App\Domain\UrlPlatform\PathPolicy;
use App\Domain\UrlPlatform\RedirectSafety;
use App\Domain\UrlPlatform\UrlPlatformError;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class PathPolicyTest extends TestCase
{
    #[DataProvider('equivalentForms')]
    public function test_noncanonical_forms_converge(string $raw, string $expected): void
    {
        $this->assertSame($expected, PathPolicy::normalize($raw));
    }

    public static function equivalentForms(): array
    {
        return [
            'trailing slash' => ['/products/math-4/', '/products/math-4'],
            'duplicate slashes' => ['//products///math-4', '/products/math-4'],
            'latin case' => ['/Products/Math-4', '/products/math-4'],
            'percent-encoded arabic' => ['/products/%D8%AA%D8%AD%D8%B6%D9%8A%D8%B1', '/products/تحضير'],
            'query and fragment stripped' => ['/products/math-4?utm_source=x#top', '/products/math-4'],
            'absolute url keeps path' => ['https://shop.example/products/math-4', '/products/math-4'],
            'dot segment' => ['/products/./math-4', '/products/math-4'],
            'root' => ['', '/'],
        ];
    }

    #[DataProvider('hostileInputs')]
    public function test_hostile_inputs_are_rejected_not_normalized(string $raw): void
    {
        $this->expectException(UrlPlatformError::class);
        PathPolicy::normalize($raw);
    }

    public static function hostileInputs(): array
    {
        return [
            'traversal' => ['/products/../admin'],
            'encoded slash' => ['/products/a%2Fb'],
            'control char' => ["/products/a\x00b"],
            'invalid utf8' => ["/products/\xC3\x28"],
        ];
    }

    public function test_query_normalization_drops_tracking_sorts_and_removes_defaults(): void
    {
        $kept = PathPolicy::normalizeQuery(['utm_source' => 'x', 'page' => '1', 'sort' => 'price', 'grade' => '4', 'fbclid' => 'y', 'redirect' => '/evil']);
        $this->assertSame(['grade' => '4', 'sort' => 'price'], $kept);
        $this->assertSame('/shop?grade=4&sort=price', PathPolicy::canonicalPath('/shop/', ['sort' => 'price', 'grade' => '4', 'utm_campaign' => 'z']));
    }

    public function test_parameter_classification(): void
    {
        $this->assertSame('tracking', PathPolicy::classify('utm_whatever'));
        $this->assertSame('pagination', PathPolicy::classify('page'));
        $this->assertSame('forbidden', PathPolicy::classify('return_to'));
        $this->assertSame('internal', PathPolicy::classify('unknown_param'));
    }

    #[DataProvider('unsafeTargets')]
    public function test_redirect_safety_rejects_unsafe_targets(string $target): void
    {
        $this->expectException(UrlPlatformError::class);
        RedirectSafety::assertSafe($target, ['partner.example']);
    }

    public static function unsafeTargets(): array
    {
        return [
            'protocol relative' => ['//evil.example/x'],
            'backslash trick' => ['/\\evil.example'],
            'javascript scheme' => ['javascript:alert(1)'],
            'data scheme' => ['data:text/html,hi'],
            'external not allowlisted' => ['https://evil.example/x'],
            'traversal' => ['/a/../admin'],
            'encoded slash' => ['/a%2Fb'],
            'control' => ["/a\x0db"],
            'relative' => ['products/x'],
            'userinfo' => ['https://partner.example@evil.example/'],
        ];
    }

    public function test_redirect_safety_accepts_internal_and_allowlisted_external(): void
    {
        $this->assertSame('/products/math-4', RedirectSafety::assertSafe('/Products/math-4/?utm_source=x', []));
        $this->assertSame('https://partner.example/page', RedirectSafety::assertSafe('https://partner.example/page', ['partner.example']));
    }
}
