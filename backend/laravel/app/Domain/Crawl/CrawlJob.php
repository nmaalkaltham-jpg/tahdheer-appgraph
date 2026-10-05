<?php

declare(strict_types=1);

namespace App\Domain\Crawl;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * زحف في الخلفية.
 *
 * طابور مستقلّ خلف `default`: الزحف يجلب مئات الصفحات، وتركه يزاحم طابور
 * الدفع والطلبات يجعل عملية تشخيص تؤخّر معاملة عميل.
 *
 * `ShouldBeUnique` بلا محاولات إعادة: زحفان متزامنان يكتبان جولتين متسابقتين،
 * وإعادةُ زحفٍ كامل بعد مهلة قتلٍ هي نفس السباق.
 */
class CrawlJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $timeout = 900;

    public int $uniqueFor = 960;

    public int $tries = 1;

    public function __construct(public readonly int $maxPages = 400)
    {
        $this->onQueue('crawler');
    }

    public function handle(SiteCrawler $crawler): void
    {
        $crawler->crawl($this->maxPages);
    }
}
