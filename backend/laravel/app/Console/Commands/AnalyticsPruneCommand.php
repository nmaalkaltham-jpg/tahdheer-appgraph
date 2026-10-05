<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Analytics\Models\AnalyticsEvent;
use App\Domain\Redirects\NotFoundHit;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * تشذيب أحداث القياس — تنفيذُ وعدٍ منشور.
 *
 * ─── لماذا وُجد ───
 *
 * سياسة الخصوصيّة المنشورة تقول: «تفاصيل أحداث القياس تُحذف بعد تسعين يومًا».
 * وكان جدول مدد الاحتفاظ **فارغًا** ولا أمر تشذيبٍ في المشروع كلّه. أي أنّ
 * الوعد قائمٌ على الصفحة العامّة ولا منفّذ له.
 *
 * وقد وجدتُ قبل ذلك وصفًا في لوحة التحليلات يقول «السجلّ يُشذَّب ليلًا»
 * فصحّحتُه إلى الحقيقة. وهذا الأمر يجعل الوصف الأوّل صحيحًا بدل أن يبقى
 * مصحَّحًا نحو النقص.
 *
 * ─── والمدّة من الجدول لا من الشيفرة ───
 *
 * `retention_policies` هو مصدر الحقيقة، وفيه `legal_basis` مكتوبًا. ورقمٌ
 * مثبَّت في الشيفرة يفترق عن السياسة عند أوّل تعديل، فيصير الحذف مخالفًا لما
 * وُعد به الجمهور.
 *
 * ─── وكنستان لا واحدة ───
 *
 * `analytics_events` تُحذف، و`order_attributions` تُخفى هويّتها. والفرق
 * مقصود: سطر الإسناد إيرادٌ محاسَب، وحذفُه يُنقص «إيراد الصفحة» بأثرٍ رجعيّ
 * فيصير تقرير الربع الماضي يتغيّر كلّ ليلة. المحو يطال البصمة وحدها.
 *
 * والإجراء لا يُفترض هنا: كلّ كنسةٍ تشترط `on_expiry` الذي كُتب لها في
 * `retention_policies`، وسياسةٌ تُبدَّل إلى إجراءٍ آخر تُوقف كنستها وتُعلن
 * بدل أن تنفّذ ما لم يُؤذن به.
 *
 *   php artisan analytics:prune
 *   php artisan analytics:prune --dry-run
 */
class AnalyticsPruneCommand extends Command
{
    protected $signature = 'analytics:prune {--dry-run : يعدّ ولا يحذف}';

    protected $description = 'تنفيذ مدد الاحتفاظ المعلنة على بيانات القياس.';

    /** يُحذف على دفعاتٍ كي لا يقفل الجدول على حركةٍ حيّة. */
    private const CHUNK = 5000;

    public function handle(): int
    {
        $events = $this->policy('analytics_events', 'DELETE');
        $attributions = $this->policy('order_attributions', 'ANONYMIZE');
        $notFound = $this->policy('not_found_hits', 'DELETE');

        if ($events === null && $attributions === null && $notFound === null) {
            /*
             * لا سياسة تعني لا تفويض. الصمت أسلم من حذفٍ بمدّةٍ مخترعة —
             * والوعد المنشور يُنفَّذ بنصّه لا بتقديرنا.
             */
            $this->error('لا سياسة احتفاظٍ فعّالة للقياس. لا حذف بلا تفويض مكتوب.');

            return self::FAILURE;
        }

        if ($events !== null) {
            $this->pruneEvents($events);
        }

        if ($attributions !== null) {
            $this->anonymiseAttributions($attributions);
            /* بصماتُ البحث بالسياسة نفسها: إسنادٌ يربط شخصًا بأسئلته، فمصيره مصيرُ إسناد الطلب. */
            $this->anonymiseSearchIdentity($attributions);
        }

        if ($notFound !== null) {
            $this->pruneNotFoundHits($notFound);
        }

        return self::SUCCESS;
    }

    /**
     * سطر السياسة — أو `null` مع إعلان السبب.
     *
     * الإجراء يُتحقَّق منه ولا يُفترض: سياسةٌ بُدِّلت إلى `ARCHIVE` مثلًا
     * تعني أنّ أحدًا قرّر شيئًا آخر، وتنفيذُ الحذف رغمها تجاوزٌ للتفويض.
     */
    private function policy(string $dataClass, string $expected): ?object
    {
        $policy = DB::table('retention_policies')
            ->where('data_class', $dataClass)
            ->where('active', true)
            ->first(['id', 'retain_days', 'on_expiry']);

        if ($policy === null) {
            $this->warn("لا سياسة فعّالة لـ{$dataClass} — لا شيء يُفعل بها.");

            return null;
        }

        if ($policy->on_expiry !== $expected) {
            $this->warn("سياسة {$dataClass} إجراؤها «{$policy->on_expiry}» لا «{$expected}» — تُترك.");

            return null;
        }

        return $policy;
    }

    private function pruneEvents(object $policy): void
    {
        $cutoff = now()->subDays((int) $policy->retain_days);
        $due = AnalyticsEvent::query()->where('occurred_at', '<', $cutoff)->count();

        $this->line("الأحداث · المدّة: {$policy->retain_days} يومًا · الحدّ: {$cutoff->toDateString()} · المستحقّ: {$due}");

        if ($this->option('dry-run') || $due === 0) {
            return;
        }

        $deleted = 0;

        do {
            $batch = AnalyticsEvent::query()
                ->where('occurred_at', '<', $cutoff)
                ->limit(self::CHUNK)
                ->delete();

            $deleted += $batch;
        } while ($batch > 0);

        $this->logSweep($policy, ['table' => 'analytics_events', 'row' => 'deleted'], $deleted);
        $this->info("حُذف {$deleted} صفًّا من الأحداث.");
    }

    /**
     * كنسُ سجلّ ٤٠٤ — الوعد الذي كانت اللوحة تعرضه بلا منفّذ.
     *
     * لوحة «الروابط المكسورة» تطبع «يُحذف بعد N يومًا **منذ آخر ظهور**»،
     * فالحدّ على `last_seen_at` لا `created_at`: مسارٌ مكسورٌ ما زال يُطلَب
     * اليوم صفٌّ حيّ مهما قدُم أوّل ظهوره، وحذفُه بعمر الصفّ يمحو العطل
     * الجاري من الشاشة ويُبقيه في المتجر.
     *
     * والصفّ تجميعةٌ بلا معرّف شخص، فالإجراء حذفٌ لا إخفاء هويّة.
     */
    private function pruneNotFoundHits(object $policy): void
    {
        $cutoff = now()->subDays((int) $policy->retain_days);

        $due = NotFoundHit::query()
            ->whereNotNull('last_seen_at')
            ->where('last_seen_at', '<', $cutoff)
            ->count();

        $this->line("الروابط المكسورة · المدّة: {$policy->retain_days} يومًا · الحدّ: {$cutoff->toDateString()} · المستحقّ: {$due}");

        if ($this->option('dry-run') || $due === 0) {
            return;
        }

        $deleted = 0;

        do {
            $batch = NotFoundHit::query()
                ->whereNotNull('last_seen_at')
                ->where('last_seen_at', '<', $cutoff)
                ->limit(self::CHUNK)
                ->delete();

            $deleted += $batch;
        } while ($batch > 0);

        $this->logSweep($policy, ['table' => 'not_found_hits', 'row' => 'deleted'], $deleted);
        $this->info("حُذف {$deleted} صفًّا من سجلّ ٤٠٤.");
    }

    /**
     * محو البصمة وإبقاء ما لا يعرّف بأحد.
     *
     * `anonymized_at` هو ما يمنع إعادة المحو كلّ ليلة — وبدونه يصير الأمر
     * يكتب سطرًا في دفتر الإتلاف كلّ يوم عن صفوفٍ مُحيت مرّة، فيبدو الدفتر
     * كأنّ الإتلاف جارٍ بلا توقّف.
     */
    private function anonymiseAttributions(object $policy): void
    {
        $cutoff = now()->subDays((int) $policy->retain_days);

        $due = DB::table('order_attributions')
            ->where('created_at', '<', $cutoff)
            ->whereNull('anonymized_at')
            ->count();

        $this->line("الإسناد · المدّة: {$policy->retain_days} يومًا · الحدّ: {$cutoff->toDateString()} · المستحقّ: {$due}");

        if ($this->option('dry-run') || $due === 0) {
            return;
        }

        $cleared = DB::table('order_attributions')
            ->where('created_at', '<', $cutoff)
            ->whereNull('anonymized_at')
            ->update([
                'visitor_hash' => null,
                'session_hash' => null,
                'anonymized_at' => now(),
            ]);

        $this->logSweep(
            $policy,
            ['table' => 'order_attributions', 'cleared' => ['visitor_hash', 'session_hash']],
            $cleared,
        );
        $this->info("مُحيت بصمة {$cleared} صفًّا من الإسناد.");
    }

    /**
     * قيد الكنسة في دفتر إخفاء الهويّة القائم.
     *
     * الموضوع **سطر السياسة** لا صاحب بيانات: الكنس الدوريّ ليس له شخصٌ
     * واحد يُنسب إليه، و`subject_id` ليس قابلًا للعدم. فوضعُ صفرٍ مكانه
     * يخترع موضوعًا لا وجود له ويلوّث فهرس `(subject_type, subject_id)`
     * الذي يُسأل عند طلب صاحب بيانات. ونسبتُه إلى السياسة تجعل السؤال
     * المفيد مُجابًا: «ماذا فعلت هذه السياسة، ومتى، وكم صفًّا مسّت».
     *
     * @param  array<string, mixed>  $fields
     */
    /**
     * يمحو بصمتي الزائر والجلسة من أحداث البحث وإسناداته بعد المدّة، ويُبقي العدّ:
     * الظهور والنقر والإسناد تبقى أرقامًا بلا صاحب — كما يبقى إسناد الطلب بلا صاحب.
     */
    private function anonymiseSearchIdentity(object $policy): void
    {
        $cutoff = now()->subDays((int) $policy->retain_days);
        $dryRun = (bool) $this->option('dry-run');

        $queries = DB::table('search_queries')->where('created_at', '<', $cutoff)->whereNotNull('session_hash');
        $attrs = DB::table('search_attributions')->where('created_at', '<', $cutoff)
            ->where(fn ($q) => $q->whereNotNull('visitor_hash')->orWhereNotNull('session_hash'));
        $this->line("بصمات البحث · المدّة: {$policy->retain_days} يومًا · أحداث: {$queries->count()} · إسنادات: {$attrs->count()}");
        if ($dryRun) {
            return;
        }

        /* الدفتر يسجّل ما مُحي فعلًا: كنسةٌ لم تجد شيئًا لا تُكتب كلّ ليلة. */
        $cleared = (clone $queries)->update(['session_hash' => null]);
        if ($cleared > 0) {
            $this->logSweep($policy, ['table' => 'search_queries', 'cleared' => ['session_hash']], $cleared);
        }
        $clearedAttrs = (clone $attrs)->update(['visitor_hash' => null, 'session_hash' => null]);
        if ($clearedAttrs > 0) {
            $this->logSweep($policy, ['table' => 'search_attributions', 'cleared' => ['visitor_hash', 'session_hash']], $clearedAttrs);
        }
        $this->info("مُحيت بصمات {$cleared} حدث بحث و{$clearedAttrs} إسنادًا.");
    }

    private function logSweep(object $policy, array $fields, int $affected): void
    {
        DB::table('anonymization_log')->insert([
            'subject_type' => 'retention_policies',
            'subject_id' => $policy->id,
            'fields_cleared' => json_encode($fields, JSON_UNESCAPED_UNICODE),
            'records_affected' => $affected,
            'retained_for' => 'انقضاء مدّة الاحتفاظ المعلنة ('.$policy->retain_days.' يومًا)',
            'created_at' => now(),
        ]);
    }
}
