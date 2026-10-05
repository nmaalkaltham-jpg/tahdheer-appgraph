<?php

namespace Tests\Unit\Privacy;

use App\Domain\Privacy\CredentialPaths;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The redaction contract every sink that stores a visitor-supplied path relies on.
 *
 * The same case table lives in apps/storefront/lib/__tests__/private-paths.test.mjs:
 * a row changed in one file and not the other is a drift between the browser and
 * the server, so keep them in step.
 */
class CredentialPathsTest extends TestCase
{
    private const TOKEN = 'Al0vVqN_FYriiSDOtyC_pQWH0CnDFWRbBtmfQKd_gyI';

    /** @return array<string, array{string, string}> */
    public static function paths(): array
    {
        $t = self::TOKEN;

        return [
            'order page' => ["/orders/{$t}", '/orders/[token]'],
            'trailing slash' => ["/orders/{$t}/", '/orders/[token]/'],
            'sub-page' => ["/orders/{$t}/receipt", '/orders/[token]/receipt'],
            'locale prefix' => ["/en/orders/{$t}", '/en/orders/[token]'],
            'upper case' => ["/ORDERS/{$t}", '/ORDERS/[token]'],
            'payment retry' => ["/checkout/orders/{$t}/payment", '/checkout/orders/[token]/payment'],
            'query dropped' => ["/orders/{$t}?tap_id=chg_1", '/orders/[token]'],
            'fragment dropped' => ["/orders/{$t}#files", '/orders/[token]'],
            'query token dropped' => ["/account/orders?token={$t}", '/account/orders'],
            'percent-encoded slash' => ["/orders%2F{$t}", '/orders/[token]'],
            'encoded slash after the separator' => ["/orders/%2F{$t}", '/orders/[token]'],
            'doubled slash' => ["/orders//{$t}", '/orders/[token]'],
            'double-encoded slash' => ["/orders%252F{$t}", '/orders/[token]'],
            'encoded backslash' => ["/orders%5C{$t}", '/orders/[token]'],
            'invalid escape after the token' => ["/orders%2F{$t}/%ZZ", '/orders/[token]/%ZZ'],
            'matrix parameter' => ["/orders;x/{$t}", '/orders;x/[token]'],
            'dot segment' => ["/orders/./{$t}", '/orders/[token]/[token]'],
            'token shape anywhere' => ["/anything/{$t}/x", '/anything/[token]/x'],
            'long lowercase slug kept' => ['/products/grade-five-science-lesson-plans-term-one-v2', '/products/grade-five-science-lesson-plans-term-one-v2'],
            'already redacted' => ['/orders/[token]', '/orders/[token]'],
            'account list' => ['/account/orders', '/account/orders'],
            'bare orders' => ['/orders', '/orders'],
            'product slug' => ['/products/orders-guide', '/products/orders-guide'],
            'arabic slug' => ['/products/تحضير-لغتي', '/products/تحضير-لغتي'],
            'root' => ['/', '/'],
        ];
    }

    #[DataProvider('paths')]
    public function test_path_never_keeps_a_credential(string $raw, string $expected): void
    {
        $redacted = CredentialPaths::redactPath($raw);

        $this->assertSame($expected, $redacted);
        $this->assertStringNotContainsString(self::TOKEN, $redacted);
    }

    /** @return array<string, array{?string, ?string}> */
    public static function urls(): array
    {
        $t = self::TOKEN;

        return [
            'same-site order page' => ["https://store.example/orders/{$t}", 'https://store.example/orders/[token]'],
            'port kept' => ["http://127.0.0.1:3000/orders/{$t}?x=1", 'http://127.0.0.1:3000/orders/[token]'],
            'account query dropped' => ["https://store.example/account/orders?token={$t}", 'https://store.example/account/orders'],
            'search query dropped' => ['https://www.google.com/search?q=name', 'https://www.google.com/search'],
            'credentials in authority dropped' => ['https://user:pass@evil.example/x', 'https://evil.example/x'],
            'origin only' => ['https://store.example', 'https://store.example'],
            'relative' => ["/orders/{$t}", '/orders/[token]'],
            'empty' => ['', null],
            'null' => [null, null],
            'not a url' => ['javascript:alert(1)', null],
        ];
    }

    #[DataProvider('urls')]
    public function test_url_keeps_origin_and_redacted_path_only(?string $raw, ?string $expected): void
    {
        $this->assertSame($expected, CredentialPaths::redactUrl($raw));
    }

    public function test_tokens_are_found_in_provider_evidence_however_the_link_is_encoded(): void
    {
        $t = self::TOKEN;
        $payload = [
            'transaction' => ['url' => 'https://checkout.tap.company/?mode=page&token=eyJhbGciOiJIUzI1NiJ9%2Fx'],
            'redirect' => ['url' => "https://store.example/orders/{$t}"],
            'post' => ['url' => 'https://api.example/api/v1/payments/webhook/tap'],
            'nested' => ['return' => 'https://tap.example/?redirect='.rawurlencode("https://store.example/orders/{$t}")],
            'escaped' => json_encode(['u' => "https://store.example/orders/{$t}"]),
        ];

        $this->assertSame([$t], CredentialPaths::tokensInDeep($payload), 'only the order token, never the provider JWT');

        $redacted = CredentialPaths::redactDeep($payload);
        $this->assertStringNotContainsString($t, (string) json_encode($redacted));
        $this->assertStringNotContainsString($t, rawurldecode((string) json_encode($redacted, JSON_UNESCAPED_SLASHES)));
        $this->assertSame($payload['transaction'], $redacted['transaction'], 'unrelated evidence is byte-identical');
        $this->assertSame($payload['post'], $redacted['post']);
        $this->assertSame('https://store.example/orders/[token]', $redacted['redirect']['url']);
    }

    public function test_tokens_are_extracted_for_exposure_accounting(): void
    {
        $this->assertSame(
            [self::TOKEN],
            CredentialPaths::tokensIn('https://store.example/orders/'.self::TOKEN.'?tap_id=1'),
        );
        $this->assertSame([], CredentialPaths::tokensIn('/orders/[token]'));
        $this->assertSame([self::TOKEN], CredentialPaths::tokensIn('/account/orders?token='.self::TOKEN));
    }
}
