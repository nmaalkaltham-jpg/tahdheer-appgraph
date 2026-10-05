<?php

namespace Tests\Unit\Storage;

use App\Domain\Downloads\DownloadDelivery;
use App\Domain\Downloads\SignedDeliveryUrl;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * The delivery link is verified by the framework, so it must be signed exactly
 * the way the framework signs. These assertions fail loudly if that ever drifts.
 */
class SignedDeliveryUrlTest extends TestCase
{
    public function test_a_generated_link_satisfies_the_framework_signature_check(): void
    {
        $url = SignedDeliveryUrl::forSeconds('a1b2c3', 900);

        $this->assertTrue(URL::hasValidSignature(Request::create($url)));
    }

    public function test_a_tampered_expiry_invalidates_the_signature(): void
    {
        $url = SignedDeliveryUrl::forSeconds('a1b2c3', 900);
        $tampered = (string) preg_replace('/expires=\d+/', 'expires='.(time() + 86_400), $url);

        $request = Request::create($tampered);
        $this->assertFalse(URL::hasValidSignature($request));
        $this->assertFalse(URL::hasCorrectSignature($request));
    }

    public function test_an_elapsed_window_is_an_expiry_not_a_forgery(): void
    {
        $request = Request::create(SignedDeliveryUrl::until('a1b2c3', now()->subMinute()));

        $this->assertTrue(URL::hasCorrectSignature($request));
        $this->assertFalse(URL::hasValidSignature($request));
    }

    public function test_the_link_carries_the_grant_token_and_nothing_else(): void
    {
        $url = SignedDeliveryUrl::forSeconds('a1b2c3', 900);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        $this->assertSame(['expires', 'signature'], array_keys($query));
        $this->assertStringEndsWith('/api/v1/downloads/redeem/a1b2c3', (string) parse_url($url, PHP_URL_PATH));
    }

    public function test_arabic_filenames_survive_as_rfc_5987_with_an_ascii_fallback(): void
    {
        $header = DownloadDelivery::contentDisposition('تحضير لغتي — الفصل الأول.pptx');

        $this->assertStringStartsWith('attachment; filename="', $header);
        $this->assertStringContainsString(
            "filename*=UTF-8''".rawurlencode('تحضير لغتي — الفصل الأول.pptx'),
            $header,
        );
        // The ASCII fallback must still be a usable filename, not a dotfile.
        preg_match('/filename="([^"]+)"/', $header, $matches);
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9][A-Za-z0-9._-]*$/', $matches[1]);
        $this->assertStringEndsWith('.pptx', $matches[1]);
    }
}
