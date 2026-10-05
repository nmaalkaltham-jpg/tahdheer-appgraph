<?php

namespace Tests\Feature\Storage;

use App\Domain\Assets\Contracts\StorageProvider;
use App\Domain\Assets\Exceptions\StorageUnavailable;
use App\Domain\Assets\Models\Asset;
use App\Domain\Assets\StorageResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class AssetsReconcileCommandTest extends TestCase
{
    use RefreshDatabase;

    private function asset(string $ref): Asset
    {
        return Asset::create([
            'provider' => 'local', 'provider_ref' => $ref, 'original_name' => 'f.pdf',
            'sha256' => str_pad(uniqid(), 64, 'a'), 'size_bytes' => 10, 'mime' => 'application/pdf',
        ]);
    }

    /** @param array<string, bool|\Throwable> $answers */
    private function bindProvider(array $answers): void
    {
        $provider = $this->createMock(StorageProvider::class);
        $provider->method('exists')->willReturnCallback(function (string $key) use ($answers): bool {
            $answer = $answers[$key] ?? false;
            if ($answer instanceof \Throwable) {
                throw $answer;
            }

            return (bool) $answer;
        });

        $resolver = $this->createMock(StorageResolver::class);
        $resolver->method('provider')->willReturn($provider);
        $this->app->instance(StorageResolver::class, $resolver);
    }

    public function test_a_row_whose_object_is_gone_is_reported_and_fails_the_run(): void
    {
        $present = $this->asset('obj/present');
        $missing = $this->asset('obj/missing');
        $this->bindProvider(['obj/present' => true, 'obj/missing' => false]);

        $exit = Artisan::call('assets:reconcile', ['--json' => true]);
        $out = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(1, $exit);
        $this->assertSame([$missing->id], $out['missing']);

        $this->assertDatabaseHas('assets', ['id' => $present->id]); // قراءةٌ محضة — لا يحذف
        $this->assertDatabaseHas('assets', ['id' => $missing->id]);
    }

    public function test_an_unreachable_store_is_not_reported_as_missing(): void
    {
        $this->asset('obj/unknown');
        $this->bindProvider(['obj/unknown' => new StorageUnavailable(StorageUnavailable::DISK_UNAVAILABLE)]);

        // «لم أستطع السؤال» ليست «غير موجود»: لا فشل ولا ادّعاء.
        $exit = Artisan::call('assets:reconcile', ['--json' => true]);
        $out = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame(1, $out['unreachable']);
        $this->assertSame([], $out['missing']);
    }
}
