<?php

namespace App\Console\Commands;

use App\Domain\Outbox\OutboxProcessor;
use Illuminate\Console\Command;

class ProcessOutbox extends Command
{
    protected $signature = 'outbox:process {--limit=50 : Max rows per poll}';

    protected $description = 'Dispatch due PENDING outbox events to their registered consumers';

    public function handle(OutboxProcessor $processor): int
    {
        $stats = $processor->process((int) $this->option('limit'));

        $this->info(sprintf(
            'processed=%d failed=%d dead=%d',
            $stats['processed'], $stats['failed'], $stats['dead'],
        ));

        return self::SUCCESS;
    }
}
