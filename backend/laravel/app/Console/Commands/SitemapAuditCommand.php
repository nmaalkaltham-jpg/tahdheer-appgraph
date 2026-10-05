<?php

namespace App\Console\Commands;

use App\Domain\Sitemaps\Models\SitemapAudit;
use App\Domain\Sitemaps\SitemapAuditor;
use Illuminate\Console\Command;

/**
 * تدقيق نقاء خريطة الموقع (MIP §7.3.6) — الهدف نظافة ١٠٠٪.
 *
 * Exits non-zero when any URL would be expelled, so CI and the nightly cron
 * both fail loudly rather than reporting a clean map that is not clean.
 */
class SitemapAuditCommand extends Command
{
    protected $signature = 'sitemap:audit {--json : Emit the raw report as JSON}';

    protected $description = 'Expel any sitemap URL failing IndexabilityPolicy and report real coverage.';

    public function handle(SitemapAuditor $auditor): int
    {
        $report = $auditor->audit();
        $this->persist($report);

        if ($this->option('json')) {
            $this->line((string) json_encode($report, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

            return $report['clean'] ? self::SUCCESS : self::FAILURE;
        }

        foreach ($report['providers'] as $provider) {
            $this->line(sprintf(
                '  %-12s عُرض %5d · بقي %5d · طُرد %4d · التغطية %s',
                $provider['provider'],
                $provider['offered'],
                $provider['kept'],
                $provider['expelled'],
                // «غير مقيس» — never a flattering 100% for an empty set.
                $provider['coveragePct'] === null ? 'غير مقيس' : $provider['coveragePct'].'%',
            ));

            foreach ($provider['samples'] as $sample) {
                $this->line('      ✗ '.$sample['loc'].' — '.$sample['reason']);
            }
        }

        foreach ($report['dormant'] as $dormant) {
            $this->warn(sprintf('  %-12s معطّل — %s', $dormant['provider'], $dormant['reason']));
        }

        $coverage = $report['coveragePct'] === null ? 'غير مقيس' : $report['coveragePct'].'%';

        if ($report['clean']) {
            $this->info('الخريطة نظيفة — التغطية '.$coverage);

            return self::SUCCESS;
        }

        $this->error('طُرد '.$report['expelled'].' رابطًا — التغطية '.$coverage);

        return self::FAILURE;
    }

    /**
     * يُحفظ القياس قبل أن يُطبع.
     *
     * ─── لماذا الحفظ أوّلًا ───
     *
     * الأمر مجدولٌ كلّ ليلة في ٠٣:٤٠، ومخرَجه القياسيّ في حاويةٍ لا يقرؤه
     * أحد. فكان التدقيق يجري كلّ ليلة وتضيع نتيجته كلّ ليلة، وتبقى شاشة
     * `/seo-health/sitemap` مغلقةً ببوّابةٍ تقول إنّ الناقص هو **تخزين**
     * النتائج وعرضها. هذا هو التخزين، وهو الطرف الذي كان يفصل أمرًا يعمل
     * عن شاشةٍ لا تعمل.
     *
     * ─── ولماذا لا يُسقِط الفشلُ الحفظَ ───
     *
     * خريطةٌ غير نظيفة نتيجةٌ صحيحة تُحفظ وتُقرأ، لا خطأً يُطرح. والقيمة
     * كلّها في الاتّجاه عبر الليالي: ليلةٌ بطردٍ أقلّ من سابقتها دليلُ إصلاح.
     */
    private function persist(array $report): void
    {
        SitemapAudit::create([
            'ran_at' => now(),
            'clean' => (bool) $report['clean'],
            'offered' => (int) $report['offered'],
            'kept' => (int) $report['kept'],
            'expelled' => (int) $report['expelled'],
            /* `null` يبقى `null`: «غير مقيس» ليس صفرًا بالمئة. */
            'coverage_pct' => $report['coveragePct'],
            'missing_count' => count($report['missing']),
            'report' => $report,
        ]);
    }
}
