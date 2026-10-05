<?php

namespace App\Console\Commands;

use App\Domain\Catalog\CoPurchaseMiner;
use Illuminate\Console\Command;

/**
 * يعيد حساب توصيات «يُشترى معه عادةً» من الطلبات المدفوعة.
 *
 * تشغيله مرّتين لا يغيّر شيئًا: يستبدل الصفوف السلوكيّة كاملةً في معاملةٍ
 * واحدة ولا يمسّ التحريريّة. وصفرُ نتيجةٍ نتيجةٌ صحيحة، لا فشل.
 */
class CatalogMineCoPurchaseCommand extends Command
{
    protected $signature = 'catalog:mine-co-purchase {--min-support= : أقلّ عددِ طلباتٍ يجمع المنتجين}';

    protected $description = 'Recompute «bought together» relationships from paid orders.';

    public function handle(CoPurchaseMiner $miner): int
    {
        $minSupport = $this->option('min-support');

        if ($minSupport !== null && ! ctype_digit((string) $minSupport)) {
            $this->error('--min-support يجب أن يكون عددًا صحيحًا.');

            return self::INVALID;
        }

        $result = $miner->mine($minSupport === null ? CoPurchaseMiner::MIN_SUPPORT : max(1, (int) $minSupport));

        if ($result['written'] === 0) {
            $this->info(
                'لا اقترانَ بلغ العتبة — لم تُكتب توصية. '
                ."(حُذف {$result['removed']} صفًّا سلوكيًّا قديمًا)"
            );

            return self::SUCCESS;
        }

        $this->info(
            "كُتبت {$result['written']} توصية لـ{$result['pairs']} منتجًا، "
            ."وحُذف {$result['removed']} صفًّا سلوكيًّا قديمًا."
        );

        return self::SUCCESS;
    }
}
