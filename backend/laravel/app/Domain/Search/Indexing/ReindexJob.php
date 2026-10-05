<?php

declare(strict_types=1);

namespace App\Domain\Search\Indexing;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

/**
 * إعادة بناء الفهرس كاملًا في الخلفية.
 *
 * في الطابور لا في الطلب: كتالوج ينمو يجعل البناء المتزامن يتجاوز مهلة HTTP
 * فيفشل «النداء» بينما البناء مستمرّ — حالة يستحيل تفسيرها من اللوحة.
 *
 * `ShouldBeUnique`: نقرتان متتاليتان على «أعد الفهرسة» لا تبنيان جيلين
 * متسابقين على نفس صفّ الحالة.
 *
 * الفشل يُترك لحالة `failed` التي يكتبها الـIndexer نفسه — اللوحة تقرؤها
 * من نقطة الحالة، فلا نجاح صامت ولا فشل مخفي.
 */
class ReindexJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    /** بناء الفهرس شأن خلفي — لا يزاحم طابور الدفع والطلبات. */
    public function __construct()
    {
        $this->onQueue('search-index');
    }

    public int $timeout = 600;

    public int $uniqueFor = 660;

    /**
     * محاولة واحدة: إعادةُ بناءٍ كامل بعد مهلة قتلٍ هي بالضبط سيناريو
     * الجيلين المتسابقين. الفشل يبقى «failed» في صفّ الحالة ويعيد المشرف
     * الطلب بنفسه من الشاشة التي تعرضه.
     */
    public int $tries = 1;

    public function handle(Indexer $indexer): void
    {
        $out = $indexer->reindex();

        Log::info('search.reindex.completed', [
            'index' => $out['index'],
            'generation' => $out['generation'],
            'documents' => $out['documents'],
        ]);
    }
}
