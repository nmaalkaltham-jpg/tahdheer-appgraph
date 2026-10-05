<?php

namespace Tests\Feature\Storage;

use App\Domain\Downloads\DownloadDelivery;
use App\Domain\Downloads\PaidContentViolation;
use App\Domain\Downloads\SignedDeliveryUrl;
use App\Models\DownloadEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * حرمة المحتوى المدفوع — a paid original is reachable through a valid grant and
 * nothing else, and no response ever discloses the object key.
 */
class PaidContentSanctityTest extends TestCase
{
    use RefreshDatabase;
    use StorageFixtures;

    private const BYTES = "محتوى مدفوع حقيقي — لا يجوز تسريبه.\n";

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('assets');
    }

    public function test_every_way_around_the_grant_is_denied_and_audited(): void
    {
        $product = $this->makeProduct('TD-STG-001');
        $asset = $this->importAsset($product, self::BYTES);
        $fixture = $this->paidEntitlement($product);

        $url = $this->grantUrl($fixture['token'], (int) $fixture['entitlement']->id);
        $rawToken = $this->rawGrantToken($url);
        $key = (string) $asset->provider_ref;

        $cases = [
            // (a) no signature at all
            'unsigned' => [url(SignedDeliveryUrl::PATH.$rawToken), 'invalid_signature'],
            // (b) signature kept, window widened → HMAC no longer matches
            'tampered' => [preg_replace('/expires=\d+/', 'expires='.(time() + 86400), $url), 'invalid_signature'],
            // (c) correctly signed, but the window has closed
            'expired' => [SignedDeliveryUrl::until($rawToken, now()->subMinute()), 'expired'],
        ];

        foreach ($cases as $name => [$candidate, $expectedReason]) {
            $before = DownloadEvent::query()->where('event_type', 'denied')->count();

            $response = $this->getJson((string) $candidate);
            $response->assertForbidden()->assertJsonPath('reason', $expectedReason);

            $this->assertStringNotContainsString($key, (string) $response->getContent(), "{$name} leaked the key");
            $this->assertSame(
                $before + 1,
                DownloadEvent::query()->where('event_type', 'denied')->count(),
                "{$name} must leave an audit row",
            );
        }

        // (d) valid signature, revoked entitlement
        $fixture['entitlement']->update(['status' => 'REVOKED', 'revoked_at' => now()]);
        $revoked = $this->getJson($url)->assertForbidden();
        $revoked->assertJsonPath('reason', 'revoked');
        $this->assertStringNotContainsString($key, (string) $revoked->getContent());

        // (e) another customer's grant token pasted into a valid signed URL
        $otherProduct = $this->makeProduct('TD-STG-002');
        $this->importAsset($otherProduct, "محتوى منتج آخر.\n", 'ملف آخر.txt');
        $other = $this->paidEntitlement($otherProduct, 'huda@example.com');
        $otherUrl = $this->grantUrl($other['token'], (int) $other['entitlement']->id);
        $otherRawToken = $this->rawGrantToken($otherUrl);

        $swapped = str_replace($rawToken, $otherRawToken, $url);
        $this->getJson($swapped)
            ->assertForbidden()
            ->assertJsonPath('reason', 'invalid_signature');

        // The control: that same grant works for its own owner, so the denial
        // above is about binding — not about a broken fixture.
        $this->get($otherUrl)->assertOk();

        $this->assertDatabaseHas('download_events', [
            'entitlement_id' => $fixture['entitlement']->id,
            'event_type' => 'denied',
        ]);
    }

    public function test_a_public_url_for_a_paid_asset_cannot_be_constructed(): void
    {
        $product = $this->makeProduct('TD-STG-003');
        $paid = $this->importAsset($product, self::BYTES);
        $sample = $this->importAsset(
            $product,
            "عيّنة مجانية.\n",
            'عيّنة.txt',
            role: 'sample',
            visibility: 'public_sample',
        );

        $delivery = app(DownloadDelivery::class);

        // A sample may ask for a public URL; the local provider has no
        // key-addressable route, so it answers null (NOT_CONFIGURED) — not a stub.
        $this->assertNull($delivery->publicUrl($sample, 900));

        $this->expectException(PaidContentViolation::class);
        $delivery->publicUrl($paid, 900);
    }

    public function test_no_unsigned_route_serves_a_paid_object_key(): void
    {
        $product = $this->makeProduct('TD-STG-004');
        $asset = $this->importAsset($product, self::BYTES);

        $response = $this->get('/storage/'.$asset->provider_ref);

        $this->assertContains($response->getStatusCode(), [403, 404]);
        $this->assertStringNotContainsString(self::BYTES, (string) $response->getContent());
    }

    public function test_paid_delivery_never_hands_out_a_url_containing_the_key(): void
    {
        $product = $this->makeProduct('TD-STG-005');
        $asset = $this->importAsset($product, self::BYTES);
        $fixture = $this->paidEntitlement($product);

        $url = $this->grantUrl($fixture['token'], (int) $fixture['entitlement']->id);

        $this->assertStringNotContainsString((string) $asset->provider_ref, $url);
        $this->assertStringNotContainsString((string) $asset->sha256, $url);
    }
}
