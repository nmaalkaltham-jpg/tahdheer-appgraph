<?php

namespace App\Console\Commands;

use App\Domain\Sitemaps\SitemapBuilder;
use Illuminate\Console\Command;

/**
 * بناء خرائط الموقع — يُشغَّل من الجدولة لا من طلب زائر (MIP §7.3.8).
 *
 * All logic lives in {@see SitemapBuilder}; this is a thin console shim.
 */
class SitemapBuildCommand extends Command
{
    protected $signature = 'sitemap:build {--path= : Output directory (defaults to storage/app/sitemaps)}';

    protected $description = 'Build the chunked sitemap parts and index atomically (tmp file then rename).';

    public function handle(SitemapBuilder $builder): int
    {
        $result = $builder->build($this->option('path'));

        foreach ($result['parts'] as $part) {
            $this->line(sprintf(
                '  %-12s part %d  %6d urls  %8d bytes  %s',
                $part['provider'],
                $part['part'],
                $part['urlCount'],
                $part['bytes'],
                $part['file'],
            ));
        }

        // Dormant providers are REPORTED, never silently skipped.
        foreach ($result['dormant'] as $dormant) {
            $this->warn(sprintf('  %-12s معطّل — %s', $dormant['provider'], $dormant['reason']));
        }

        $this->info(sprintf(
            'تمت الكتابة: %d رابطًا في %d جزءًا → %s',
            $result['totalUrls'],
            count($result['parts']),
            $result['directory'],
        ));

        return self::SUCCESS;
    }
}
