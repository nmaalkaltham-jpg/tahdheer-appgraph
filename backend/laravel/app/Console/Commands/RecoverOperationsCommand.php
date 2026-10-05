<?php

namespace App\Console\Commands;

use App\Domain\Operations\StaleOperationRecovery;
use Illuminate\Console\Command;

/**
 * `operations:recover` — يوفّق كلّ عمليّةٍ فقدت عاملها.
 *
 * آمنٌ للتشغيل المتكرّر بالتصميم: يسأل ولا يُعيد، ويستحوذ على إيجارٍ فلا
 * يتصادم مع نسخةٍ أخرى منه.
 */
class RecoverOperationsCommand extends Command
{
    protected $signature = 'operations:recover {--limit=100}';

    protected $description = 'توفيق العمليّات المجهولة النتيجة مع المزوّدين';

    public function handle(StaleOperationRecovery $recovery): int
    {
        $tally = $recovery->run((int) $this->option('limit'));

        $this->info(sprintf(
            'فُحصت %d · مؤكّدة %d · غائبة %d · مجهولة %d · متخطّاة %d',
            $tally['scanned'], $tally['confirmed'], $tally['absent'], $tally['unknown'], $tally['skipped'],
        ));

        return self::SUCCESS;
    }
}
