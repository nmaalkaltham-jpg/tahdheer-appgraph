<?php

namespace App\Console\Commands;

use App\Domain\Cases\CaseEscalator;
use Illuminate\Console\Command;

/**
 * `cases:escalate` — يرصد خرق المهل ويرفع التصعيد.
 *
 * آمنٌ للتكرار: لا حدث ولا إصدار ما لم تتغيّر الدرجة.
 */
class EscalateCasesCommand extends Command
{
    protected $signature = 'cases:escalate {--limit=500}';

    protected $description = 'رصد خرق مهل القضايا ورفع التصعيد';

    public function handle(CaseEscalator $escalator): int
    {
        $result = $escalator->run((int) $this->option('limit'));

        $this->info(sprintf(
            'فُحصت %d · خُرقت %d · صُعّدت %d',
            $result['scanned'], $result['breached'], $result['escalated'],
        ));

        return self::SUCCESS;
    }
}
