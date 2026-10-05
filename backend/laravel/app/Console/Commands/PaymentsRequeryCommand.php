<?php

namespace App\Console\Commands;

use App\Domain\Payments\ProviderRequery;
use Illuminate\Console\Command;

/**
 * `payments:requery` — يسأل المزوّد عن الرسوم المعلّقة والاستردادات المفتوحة.
 *
 * آمنٌ للتكرار: يسأل ويحسم عبر المسارات المحروسة نفسها، ولا يُنشئ رسمًا ولا
 * استردادًا جديدًا.
 */
class PaymentsRequeryCommand extends Command
{
    protected $signature = 'payments:requery {--limit=100} {--refunds : اكتشاف ما رُدّ من لوحة المزوّد (يُجدوَل كلّ ساعة)}';

    protected $description = 'حسم الرسوم المعلّقة والاستردادات المفتوحة بسؤال مزوّد الدفع';

    public function handle(ProviderRequery $requery): int
    {
        if ($this->option('refunds')) {
            $d = $requery->discoverProviderRefunds((int) $this->option('limit'));
            $this->info(sprintf('استردادات المزوّد: فُحص %d تحصيلًا · سُجِّل %d · بلا جواب %d', $d['capturesChecked'], $d['refundsRecorded'], $d['unavailable']));

            return self::SUCCESS;
        }

        $r = $requery->run((int) $this->option('limit'));

        $this->info(sprintf(
            'رسوم: فُحص %d · قُبض %d · فرق %d · فشل %d · مفتوح %d · بلا جواب %d — استردادات: فُحص %d · حُسم %d',
            $r['chargesChecked'], $r['captured'], $r['unfundable'], $r['failed'], $r['open'], $r['unavailable'],
            $r['refundsChecked'], $r['refundsSettled'],
        ));

        return self::SUCCESS;
    }
}
