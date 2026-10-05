<?php

namespace Tests\Feature\Storage;

use App\Domain\Assets\Models\ProductFile;
use App\Domain\Entitlements\EntitlementService;
use App\Domain\Entitlements\RevocationPolicy;
use App\Models\DownloadGrant;
use App\Models\Product;
use Database\Seeders\DevAssetSeeder;
use Database\Seeders\DevCommerceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * End-to-end digital delivery: seed real bytes → grant → fetch the signed URL →
 * receive the actual file → revoke → the same URL stops working.
 */
class DownloadLoopTest extends TestCase
{
    use RefreshDatabase;
    use StorageFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('assets');
    }

    public function test_seeded_asset_is_downloaded_end_to_end_then_revoked(): void
    {
        $this->seed(DevCommerceSeeder::class);
        $this->seed(DevAssetSeeder::class);

        $product = Product::query()->where('sku', 'DEV-COMMERCE-001')->firstOrFail();

        $asset = ProductFile::query()
            ->where('product_id', $product->id)
            ->where('role', 'main')
            ->firstOrFail()
            ->asset()
            ->firstOrFail();
        $this->assertTrue(Storage::disk('assets')->exists((string) $asset->provider_ref));

        $fixture = $this->paidEntitlement($product);

        $grant = $this->withHeader('X-Order-Token', $fixture['token'])
            ->postJson("/api/v1/downloads/{$fixture['entitlement']->id}/grant")
            ->assertOk()
            ->assertJsonPath('fileAvailable', true);

        $url = (string) $grant->json('url');
        $this->assertStringContainsString('/api/v1/downloads/redeem/', $url);
        $this->assertStringContainsString('signature=', $url);
        $this->assertStringNotContainsString((string) $asset->provider_ref, $url);

        // The URL window is the shorter of the 24h grant and the 15m ceiling.
        $this->assertTrue(
            strtotime((string) $grant->json('urlExpiresAt')) < strtotime((string) $grant->json('expiresAt')),
        );

        $expected = Storage::disk('assets')->get((string) $asset->provider_ref);

        $download = $this->get($url);
        $download->assertOk();
        $this->assertSame($expected, $download->streamedContent(), 'The real stored bytes must be delivered.');
        $this->assertStringStartsWith((string) $asset->mime, (string) $download->headers->get('Content-Type'));
        $this->assertStringContainsString(
            "filename*=UTF-8''".rawurlencode($asset->downloadName()),
            (string) $download->headers->get('Content-Disposition'),
        );

        // Audit + counters moved, and the grant is marked used.
        $this->assertSame(1, (int) $fixture['entitlement']->refresh()->download_count);
        $this->assertDatabaseHas('download_events', [
            'entitlement_id' => $fixture['entitlement']->id,
            'event_type' => 'completed',
        ]);
        $this->assertNotNull(
            DownloadGrant::query()
                ->where('token_hash', hash('sha256', $this->rawGrantToken($url)))
                ->value('used_at'),
        );

        // Revoke: the SAME still-signed URL must now be refused.
        app(EntitlementService::class)->revoke(
            $fixture['entitlement'],
            'refund',
            RevocationPolicy::Immediate,
        );

        $after = $this->getJson($url);
        $after->assertForbidden()->assertJsonPath('reason', 'revoked');
        $this->assertStringNotContainsString((string) $asset->provider_ref, (string) $after->getContent());
        $this->assertSame(1, (int) $fixture['entitlement']->refresh()->download_count);
    }

    public function test_grant_is_honest_when_no_file_is_attached(): void
    {
        $product = $this->makeProduct('TD-STG-NOFILE');
        $fixture = $this->paidEntitlement($product);

        $grant = $this->withHeader('X-Order-Token', $fixture['token'])
            ->postJson("/api/v1/downloads/{$fixture['entitlement']->id}/grant")
            ->assertOk()
            ->assertJsonPath('fileAvailable', false)
            ->assertJsonPath('filename', null);

        $this->getJson((string) $grant->json('url'))
            ->assertStatus(501)
            ->assertJsonPath('code', 'NOT_CONFIGURED');

        $this->assertDatabaseHas('download_events', [
            'entitlement_id' => $fixture['entitlement']->id,
            'event_type' => 'denied',
        ]);
    }
}
