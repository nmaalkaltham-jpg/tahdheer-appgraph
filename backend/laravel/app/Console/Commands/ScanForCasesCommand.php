<?php

namespace App\Console\Commands;

use App\Domain\Cases\CaseScanner;
use Illuminate\Console\Command;

/**
 * `cases:scan` — يفتح قضيّةً لكلّ ما تكشفه الكاشفات.
 *
 * آمنٌ للتكرار: المفاتيح مستقرّة، فالمسح المتكرّر يُضيف إلى خيط القضيّة
 * القائمة ولا يفتح ثانية.
 */
class ScanForCasesCommand extends Command
{
    protected $signature = 'cases:scan';

    protected $description = 'فتح قضايا للفروق الماليّة وعناقيد المخاطرة';

    public function handle(CaseScanner $scanner): int
    {
        $result = $scanner->scan();

        $this->info(sprintf(
            'فُتحت %d · متكرّرة %d · حوادث %d · احتيال %d',
            $result['opened'], $result['recurring'],
            $result['byKind']['INCIDENT'], $result['byKind']['FRAUD'],
        ));

        return self::SUCCESS;
    }
}
