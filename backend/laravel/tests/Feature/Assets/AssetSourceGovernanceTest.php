<?php

declare(strict_types=1);

namespace Tests\Feature\Assets;

use App\Domain\Assets\Contracts\DigitalAssetSource;
use App\Domain\Assets\Exceptions\SourceNotConfigured;
use App\Domain\Assets\Exceptions\SourceUnavailable;
use App\Domain\Assets\Models\Asset;
use App\Domain\Assets\Models\AssetSource;
use App\Domain\Assets\Sources\GoogleDriveSource;
use App\Domain\Assets\Sources\SourceHealth;
use App\Domain\Assets\Sources\SourceMetadata;
use App\Domain\Assets\SyncGovernor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * حوكمة مصادر الأصول (§١٦).
 *
 * الاختبارات هنا عن **التمييز**: أربع حالات فشلٍ مختلفة كانت ستنهار إلى
 * «فشل» واحد لو استُعمل `catch` واحد، وكلٌّ منها يطلب من المشغّل عملًا
 * مختلفًا. لا شبكة هنا — المحوّل مُستبدَل بمزيَّف يُملي النتيجة.
 */
class AssetSourceGovernanceTest extends TestCase
{
    use RefreshDatabase;

    private function asset(string $suffix = '1'): Asset
    {
        return Asset::create([
            'provider' => 'local',
            'provider_ref' => "obj/{$suffix}",
            'original_name' => "file-{$suffix}.pdf",
            'sha256' => str_pad($suffix, 64, 'a'),
            'size_bytes' => 1024,
            'mime' => 'application/pdf',
        ]);
    }

    private function source(array $attrs = []): AssetSource
    {
        return AssetSource::create($attrs + [
            'asset_id' => $this->asset(uniqid())->id,
            'provider' => 'GOOGLE_DRIVE',
            'delivery_mode' => 'IMPORT_ONCE',
            'external_id' => 'drive-'.uniqid(),
            'sync_state' => 'SYNC_PENDING',
        ]);
    }

    /** محوّل مزيَّف: يُملي ما يُرجعه `verifyAccess` و`inspect`. */
    private function fakeAdapter(bool $access, SourceMetadata|\Throwable|null $result): void
    {
        $adapter = new class($access, $result) implements DigitalAssetSource
        {
            public function __construct(private bool $access, private mixed $result) {}

            public function provider(): string
            {
                return 'GOOGLE_DRIVE';
            }

            public function verifyAccess(string $externalId): bool
            {
                return $this->access;
            }

            public function inspect(string $externalId): SourceMetadata
            {
                if ($this->result instanceof \Throwable) {
                    throw $this->result;
                }

                return $this->result;
            }

            public function validate(string $externalId): array
            {
                return [];
            }

            public function import(string $externalId): array
            {
                return ['key' => 'k', 'sha256' => 'h', 'size_bytes' => 1];
            }

            public function sync(string $externalId, ?string $since): ?array
            {
                return null;
            }

            public function getMetadata(string $externalId): SourceMetadata
            {
                return $this->inspect($externalId);
            }

            public function getRevision(string $externalId): ?string
            {
                return null;
            }

            public function getChecksum(string $externalId): ?string
            {
                return null;
            }

            public function openSecureStream(string $externalId)
            {
                return fopen('php://memory', 'r');
            }

            public function health(): SourceHealth
            {
                return new SourceHealth('GOOGLE_DRIVE', true, true);
            }
        };

        $this->app->instance(GoogleDriveSource::class, $adapter);
    }

    private function governor(): SyncGovernor
    {
        return $this->app->make(SyncGovernor::class);
    }

    public function test_a_revoked_permission_is_not_a_missing_file(): void
    {
        $this->fakeAdapter(access: false, result: null);
        $source = $this->source();

        $this->governor()->refresh($source);

        // الملفّ سليم والإذن ذهب — الإصلاح في الاعتماد لا في المصدر.
        $this->assertSame('ACCESS_REVOKED', $source->fresh()->sync_state);
        $this->assertStringContainsString('الإذن', (string) $source->fresh()->last_error);
    }

    public function test_a_deleted_file_is_recorded_as_missing(): void
    {
        $this->fakeAdapter(access: true, result: SourceMetadata::missing('gone'));
        $source = $this->source();

        $this->governor()->refresh($source);

        $this->assertSame('SOURCE_MISSING', $source->fresh()->sync_state);
    }

    public function test_an_unconfigured_connector_never_reports_a_missing_file(): void
    {
        $this->fakeAdapter(access: true, result: new SourceNotConfigured('GOOGLE_DRIVE', 'لا رمز وصول'));
        $source = $this->source();

        $this->governor()->refresh($source);

        /*
         * الفرق الذي يستحقّ اختبارًا: غياب الإعداد عندنا لا يقول شيئًا عن
         * الملفّ. لو سُجّل SOURCE_MISSING لَطُلب استبدال ستّة وعشرين مصدرًا
         * سليمًا لأنّ أحدًا لم يضبط رمزًا.
         */
        $this->assertSame('SYNC_FAILED', $source->fresh()->sync_state);
        $this->assertNotSame('SOURCE_MISSING', $source->fresh()->sync_state);
    }

    public function test_an_unreachable_provider_is_a_retryable_failure(): void
    {
        $this->fakeAdapter(access: true, result: new SourceUnavailable('GOOGLE_DRIVE', 'تعذّر الاتّصال'));
        $source = $this->source();

        $this->governor()->refresh($source);

        $this->assertSame('SYNC_FAILED', $source->fresh()->sync_state);
        $this->assertSame(1, $source->fresh()->failed_attempts);
    }

    public function test_a_changed_revision_marks_the_source_changed(): void
    {
        $this->fakeAdapter(access: true, result: new SourceMetadata(externalId: 'f', revision: '9'));
        $source = $this->source(['imported_revision' => '7', 'source_revision' => '7']);

        $this->governor()->refresh($source);
        $fresh = $source->fresh();

        $this->assertSame('SOURCE_CHANGED', $fresh->sync_state);
        $this->assertSame('9', $fresh->source_revision);
        // الفحص يقرأ ولا يجلب: المراجعة المستورَدة لا تتحرّك بلا بايتات.
        $this->assertSame('7', $fresh->imported_revision);
    }

    public function test_an_unchanged_revision_is_current_and_clears_the_error(): void
    {
        $this->fakeAdapter(access: true, result: new SourceMetadata(externalId: 'f', revision: '7'));
        $source = $this->source([
            'imported_revision' => '7', 'source_revision' => '7',
            'sync_state' => 'SYNC_FAILED', 'last_error' => 'عطل سابق', 'failed_attempts' => 3,
        ]);

        $this->governor()->refresh($source);
        $fresh = $source->fresh();

        $this->assertSame('CURRENT', $fresh->sync_state);
        $this->assertNull($fresh->last_error);
        $this->assertSame(0, $fresh->failed_attempts);
        $this->assertSame('NO_CHANGE', $fresh->syncs()->first()->outcome);
    }

    public function test_a_frozen_version_is_not_overwritten_by_a_changed_source(): void
    {
        $this->fakeAdapter(access: true, result: new SourceMetadata(externalId: 'f', revision: '9'));
        $source = $this->source([
            'imported_revision' => '7', 'source_revision' => '7', 'version_frozen' => true,
        ]);

        $this->governor()->refresh($source);
        $fresh = $source->fresh();

        // المشتري يأخذ ما اشتراه، لا ما صار عليه الملفّ بعده.
        $this->assertSame('SOURCE_CHANGED', $fresh->sync_state);
        $this->assertSame('SKIPPED_FROZEN', $fresh->syncs()->first()->outcome);
        $this->assertTrue($fresh->isServingFrozenCopy());
    }

    public function test_every_refresh_leaves_an_audit_row(): void
    {
        $this->fakeAdapter(access: true, result: new SourceMetadata(externalId: 'f', revision: null));
        $source = $this->source();

        $this->governor()->refresh($source, 'purchase_check');
        $this->governor()->refresh($source, 'manual');

        // §١٦٠ يطلب صفر إخفاقاتٍ غير مُفسَّرة — والتفسير يحتاج تاريخًا لا حالة.
        $this->assertSame(2, $source->fresh()->syncs()->count());
        $this->assertSame(['manual', 'purchase_check'], $source->fresh()->syncs()->pluck('trigger')->all());
    }

    public function test_blocking_states_stop_delivery(): void
    {
        $this->assertFalse($this->source(['sync_state' => 'SOURCE_MISSING', 'last_error' => 'x'])->isDeliverable());
        $this->assertFalse($this->source(['sync_state' => 'ACCESS_REVOKED', 'last_error' => 'x'])->isDeliverable());
        $this->assertTrue($this->source(['sync_state' => 'SOURCE_CHANGED'])->isDeliverable());
    }
}
