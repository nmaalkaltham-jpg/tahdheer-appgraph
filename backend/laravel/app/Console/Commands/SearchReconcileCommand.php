<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Search\Indexing\IndexDrift;
use App\Domain\Search\Indexing\Indexer;
use App\Domain\Search\Providers\MeilisearchProvider;
use App\Models\Product;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * يُصلح انحراف فهرس Meilisearch عن القاعدة — المنتجَ المنحرف وحده.
 *
 *   php artisan search:reconcile             # يُصلح ويطبع ما أصلح
 *   php artisan search:reconcile --dry-run   # يطبع ولا يكتب
 *
 * المزامنة الحدثيّة يُطلقها متحكّم المنتجات وحده؛ تغييرُ السعر والكلمات
 * المفتاحيّة واستخراجُ الملفّات لا يُعلنها أحد. هذا الأمر يلتقط ذلك كلّه
 * بمقارنة الوثيقة المتوقَّعة بالمفهرسة، فلا يعتمد على أن يتذكّر كلُّ كاتبٍ
 * أن يُعلن. مُجدوَلٌ كلَّ ساعة (`routes/console.php`).
 *
 * للجدولة: وثيقةٌ يرفضها المحرّك لا توقف البقيّة — تُعدّ وتُسجَّل
 * (`search.reconcile.document_failed`) ويُعاد تجريبها تلقائيًّا في التشغيل
 * التالي، لأنّ الانحراف يُقاس من جديد كلّ مرّة. وكلّ تشغيلٍ يكتب سطرًا مُهيكلًا
 * واحدًا (`search.reconcile`) للرصد، ويخرج فاشلًا إن فشل شيء.
 */
class SearchReconcileCommand extends Command
{
    protected $signature = 'search:reconcile {--dry-run : اعرض الانحراف ولا تُصلحه}';

    protected $description = 'Detect and repair Meilisearch index drift: push missing/stale products, delete orphan documents';

    public function handle(IndexDrift $drift, Indexer $indexer, MeilisearchProvider $meili): int
    {
        try {
            $d = $drift->measure();
        } catch (\Throwable $e) {
            $this->error('  تعذّر قياس الانحراف: '.$e->getMessage());
            Log::error('search.reconcile.unmeasurable', ['reason' => $e->getMessage()]);

            return self::FAILURE;
        }

        $this->line(sprintf('  %s · منشور %d · مفهرس %d · ناقص %d · قديم %d · يتيم %d',
            $d['index'], $d['published'], $d['indexed'], count($d['missing']), count($d['staleIds']), count($d['orphans'])));
        foreach ($d['stale'] as $field => $ids) {
            $this->line(sprintf('    قديم «%s»: %s', $field, implode(', ', array_slice($ids, 0, 10))));
        }

        if ($this->option('dry-run')) {
            return self::SUCCESS;
        }

        $toPush = array_values(array_unique([...$d['missing'], ...$d['staleIds']]));
        $pushed = $deleted = $failed = 0;
        foreach (Product::query()->whereIn('id', $toPush)->get() as $product) {
            $this->attempt(fn () => $indexer->syncProduct($product), (int) $product->id, 'push', $pushed, $failed);
        }
        foreach ($d['orphans'] as $id) {
            $this->attempt(fn () => $meili->deleteDocument($d['index'], (int) $id), (int) $id, 'delete', $deleted, $failed);
        }

        $this->line(sprintf('  أُصلح: %d وثيقةً حُدّثت · %d يتيمةً حُذفت · %d فشلت', $pushed, $deleted, $failed));
        Log::info('search.reconcile', [
            'index' => $d['index'],
            'published' => $d['published'],
            'indexed' => $d['indexed'],
            'missing' => count($d['missing']),
            'stale' => count($d['staleIds']),
            'orphans' => count($d['orphans']),
            'pushed' => $pushed,
            'deleted' => $deleted,
            'failed' => $failed,
        ]);

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * إصلاحُ وثيقةٍ واحدة معزولًا عن البقيّة.
     *
     * @param  callable(): void  $write
     */
    private function attempt(callable $write, int $id, string $action, int &$done, int &$failed): void
    {
        try {
            $write();
            $done++;
        } catch (\Throwable $e) {
            $failed++;
            $this->warn(sprintf('    فشل %s للوثيقة %d: %s', $action, $id, mb_substr($e->getMessage(), 0, 160)));
            Log::warning('search.reconcile.document_failed', ['id' => $id, 'action' => $action, 'reason' => mb_substr($e->getMessage(), 0, 300)]);
        }
    }
}
