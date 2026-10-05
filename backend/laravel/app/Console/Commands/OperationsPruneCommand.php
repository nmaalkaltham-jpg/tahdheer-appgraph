<?php

namespace App\Console\Commands;

use App\Domain\Operations\Models\OperationRecord;
use Illuminate\Console\Command;

/**
 * تشذيب سجلّ العمليّات: المنتهية القديمة تُحذف، والحيّة والمجهولة لا تُمسّ أبدًا.
 *
 * مفاتيح الخمول كانت تبقى إلى الأبد بنتائجها؛ والفهرس الفريد ينمو بلا حدّ.
 * والمهلة طويلة عمدًا: إعادةُ إرسالٍ بعد تسعين يومًا ليست إعادةً بل طلبٌ جديد.
 */
class OperationsPruneCommand extends Command
{
    protected $signature = 'operations:prune {--days=90 : احذف المنتهية الأقدم من هذا} {--dry-run : اعرض العدد بلا حذف}';

    protected $description = 'يحذف العمليّات المنتهية (ناجحة/فاشلة) الأقدم من المدّة — لا يلمس الحيّة ولا المجهولة';

    public function handle(): int
    {
        $days = max(7, (int) $this->option('days'));

        $query = OperationRecord::query()
            ->whereIn('status', ['SUCCEEDED', 'FAILED'])
            ->whereNotNull('completed_at')
            ->where('completed_at', '<', now()->subDays($days));

        $count = (clone $query)->count();

        if ($this->option('dry-run')) {
            $this->line("تجربة جافّة: {$count} عمليّة منتهية أقدم من {$days} يومًا كانت ستُحذف.");

            return self::SUCCESS;
        }

        $deleted = $query->delete();
        $this->line("حُذفت {$deleted} عمليّة منتهية أقدم من {$days} يومًا.");

        return self::SUCCESS;
    }
}
