<?php

namespace App\Domain\UrlPlatform\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * إبطال مخزن المتجر — نداءٌ شبكيّ خارج أيّ معاملة، بمفتاح خمولٍ هو حدث الصادر.
 *
 * حين لا يُضبط `STOREFRONT_REVALIDATE_URL` يُعلَن الغياب في السجلّ ولا
 * يُدّعى الإبطال: المتجر يعتمد عندها على مهلة التحديث الزمنيّة وحدها.
 *
 * بالمسارات **وبالوسوم**: مورِدٌ يظهر في كلّ صفحة (القائمة في التخطيط الجذر)
 * لا يُبطَل بتعداد صفحات المتجر — يُبطَل بوسمه أيًّا كانت الصفحة التي طلبته.
 */
final class RevalidateStorefrontJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    public int $backoff = 30;

    public int $timeout = 30;

    public function failed(\Throwable $e): void
    {
        Log::error('url.revalidate_failed', ['paths' => $this->paths, 'tags' => $this->tags, 'error' => $e->getMessage()]);
    }

    /**
     * @param  list<string>  $paths
     * @param  list<string>  $tags
     */
    public function __construct(
        public readonly array $paths,
        public readonly string $eventId,
        public readonly array $tags = [],
    ) {}

    public function handle(): void
    {
        $url = (string) config('url.storefront_revalidate_url', '');
        $secret = (string) config('url.storefront_revalidate_secret', '');

        if ($url === '' || $secret === '') {
            Log::info('storefront revalidation not configured — relying on timed revalidation', [
                'paths' => $this->paths,
                'tags' => $this->tags,
            ]);

            return;
        }

        // طلبٌ بلا مسارٍ ولا وسم يردّه المتجر ٤٢٢ — لا يُرسَل أصلًا.
        if ($this->paths === [] && $this->tags === []) {
            return;
        }

        $response = Http::timeout(8)
            ->withHeaders(['X-Revalidate-Secret' => $secret, 'Idempotency-Key' => $this->eventId])
            ->post($url, array_filter([
                'paths' => $this->paths,
                'tags' => $this->tags,
            ], static fn (array $v): bool => $v !== []));

        if (! $response->successful()) {
            throw new \RuntimeException("storefront revalidate failed: HTTP {$response->status()}");
        }
    }
}
