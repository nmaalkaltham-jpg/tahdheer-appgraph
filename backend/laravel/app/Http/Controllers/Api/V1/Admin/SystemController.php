<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Admin\AdminAuditLogger;
use App\Domain\Admin\Models\AdminUser;
use App\Domain\Outbox\OutboxEvent;
use App\Domain\Outbox\OutboxProcessor;
use App\Domain\Outbox\OutboxRegistry;
use App\Domain\Queueing\QueueDepth;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * التشغيل والطوابير — الجواب على «هل يعمل الخلف؟».
 *
 * ─── لماذا هذه الشاشة ───
 *
 * صندوق الصادر عمود الاعتماديّة: منه تُرسَل رسائل الطلبات ويُحدَّث فهرس
 * البحث وتُبنى المشتقّات. وكان يعمل بلا سطحٍ واحد يقول ماذا يجري فيه، فبقي
 * عطلان صامتان لا يُكتشفان إلّا بشكوى مشترٍ:
 *
 *   ١ — حدثٌ يموت بعد خمس محاولات. لا أحد يعلم، ولا سبيل إلى إحيائه.
 *   ٢ — نوعُ حدثٍ **بلا مستهلك** يُعالَج «بنجاح» ولا يفعل شيئًا. وهذا أخبث
 *       من الموت: العدّادات خضراء والأثر لم يقع.
 *
 * ─── لماذا `hasConsumer` حقلٌ مستقلّ ───
 *
 * إحياء حدثٍ لا مستهلك له إهدارٌ مضمون: سيُعالَج «بنجاح» ولن يفعل شيئًا.
 * الواجهة تحتاج أن تفرّق بين «فشل فليُعَد» و«لا أحد يستمع» — والثاني عيبُ
 * تسجيلٍ في الشيفرة لا عطلُ تشغيل.
 *
 * ─── لماذا `stalled` مشتقّة لا محفوظة ───
 *
 * «متوقّف» تعني أنّ المجدول لا يُصرّف: أقدمُ معلَّقٍ تجاوز عمرًا معقولًا.
 * حفظُها علمًا يتطلّب من يكتبه — ومن يكتبه هو نفسه ما قد يكون متوقّفًا.
 */
class SystemController extends Controller
{
    /** أقدم معلَّقٍ يتجاوزها يعني أنّ المجدول لا يُصرّف (`outbox:process` كلّ دقيقة). */
    private const STALL_SECONDS = 300;

    public function __construct(private readonly AdminAuditLogger $audit) {}

    public function health(): JsonResponse
    {
        $counts = OutboxEvent::query()
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $oldestPending = OutboxEvent::query()
            ->where('status', 'PENDING')
            ->min('created_at');

        $oldestPendingSeconds = $oldestPending === null
            ? null
            : max(0, now()->diffInSeconds(Carbon::parse($oldestPending), absolute: true));

        return response()->json([
            'outbox' => [
                'pending' => (int) ($counts['PENDING'] ?? 0),
                'processing' => (int) ($counts['PROCESSING'] ?? 0),
                'processed' => (int) ($counts['PROCESSED'] ?? 0),
                'dead' => (int) ($counts['DEAD'] ?? 0),
                'oldestPendingSeconds' => $oldestPendingSeconds,
                'stalled' => $oldestPendingSeconds !== null && $oldestPendingSeconds > self::STALL_SECONDS,
            ],
            'queue' => $this->queue(),
            'operations' => $this->operations(),
            'consumers' => $this->consumers(),
            'backups' => $this->backups(),
            'security' => $this->security(),
        ]);
    }

    /**
     * وضع الأمان — من يملك المفاتيح، وما فُعل بها، وكيف ضُبط الخادم.
     *
     * ─── لماذا هنا لا في نقطةٍ جديدة ───
     *
     * `‎/admin/system‎` محروسٌ بـ`audit.read` أصلًا، وهي الصلاحية نفسها التي
     * يحتاجها كلّ سطرٍ أدناه. ونقطةٌ ثانية بالحارس نفسه والقارئ نفسه نداءٌ
     * زائد في كلّ تحميل صفحة، ومسار اعتمادٍ ثانٍ يتباعد عن الأوّل.
     *
     * ─── وما لا يُقاس هنا ───
     *
     * محاولات الدخول الفاشلة ليست محفوظة في أيّ جدول: الحماية مُحدِّد معدّلٍ
     * في الذاكرة المؤقّتة (RateLimitServiceProvider) لا سجلًّا. فتُعلَن
     * `NOT_CONFIGURED` بسببها ولا تُرَدّ صفرًا — و«لم يحاول أحد» و«لا أعلم
     * إن حاول» جوابان لا يجوز خلطهما في لوحة أمان بالذات.
     *
     * @return array<string, mixed>
     */
    private function security(): array
    {
        /*
         * الأفعال التي تُغيّر مَن يملك ماذا، أو تُخرج مالًا، أو تسحب وصولًا.
         * القائمة من مفردات `AdminAuditLogger` الفعليّة لا من تخمين.
         */
        $privileged = [
            'order.refunded',
            'entitlement.revoked',
            'admin_user.created',
            'admin_user.deleted',
            'admin_user.role_changed',
            'admin_user.password_reset',
            'integration.credential.created',
            'integration.credential.rotated',
            'integration.credential.revoked',
            'system.outbox.replayed',
        ];

        $recent = DB::table('admin_audit_log')
            ->whereIn('action', $privileged)
            ->where('created_at', '>=', now()->subDay())
            ->selectRaw('action, count(*) as total')
            ->groupBy('action')
            ->orderByDesc('total')
            ->get()
            ->map(fn ($row) => ['action' => (string) $row->action, 'count' => (int) $row->total])
            ->all();

        $byRole = DB::table('admin_users')
            ->selectRaw('role, count(*) as total')
            ->groupBy('role')
            ->pluck('total', 'role');

        /*
         * المزوّد المضبوط والمزوّد المحلول قد يختلفان: `PAYMENTS_PROVIDER=fake`
         * في الإنتاج يسقط إلى Tap الحيّ صامتًا — وهو الفارق الذي تكشفه
         * `ProviderDiagnostics`. هنا يُقال الاسمان معًا ليُرى الفرق.
         */
        $configuredProvider = config('services.payments.provider');
        $resolvedProvider = $configuredProvider === null || $configuredProvider === ''
            ? (app()->environment('production') ? 'tap' : 'fake')
            : (string) $configuredProvider;

        return [
            'admins' => [
                'total' => (int) $byRole->sum(),
                'byRole' => $byRole->map(fn ($v) => (int) $v)->all(),
                'owners' => (int) ($byRole['owner'] ?? 0),
                /*
                 * حسابٌ لم يُستعمل قطّ بابٌ مفتوح بلا حارس: كلمة مرورٍ أوّليّة
                 * ما زالت صالحة ولا أحد يلاحظ دخولًا غريبًا لأنّه أوّل دخول.
                 */
                'neverSignedIn' => DB::table('admin_users')->whereNull('last_login_at')->count(),
            ],
            'privilegedActions24h' => [
                'total' => array_sum(array_column($recent, 'count')),
                'byAction' => $recent,
            ],
            'posture' => [
                /*
                 * `APP_DEBUG` في الإنتاج يسرّب المسارات والاستعلامات وقيم
                 * البيئة في كلّ صفحة خطأ. يُقال صراحةً لا يُفترض.
                 */
                'debug' => (bool) config('app.debug'),
                'environment' => (string) config('app.env'),
                'paymentProvider' => [
                    'configured' => $configuredProvider === null ? null : (string) $configuredProvider,
                    'resolved' => $resolvedProvider,
                    /*
                     * سائقٌ وهميّ لا يُحصّل مالًا. في بيئة التطوير هذا هو
                     * المقصود، وفي الإنتاج عطلٌ صامت: المتجر يقبل الطلبات
                     * ولا يصل ريال.
                     */
                    'live' => $resolvedProvider !== 'fake',
                ],
                'secureCookies' => (bool) config('session.secure'),
            ],
            /*
             * صارت مقيسة: `AuthController::login` يكتب `admin_user.login_failed`
             * في سجلّ التدقيق مع البريد المُحاوَل وعنوان المصدر. وكان الإعلان
             * «غير مهيّأ» صحيحًا حتّى بُني الكاتب — وبقاؤه بعده كان سيصير
             * كذبًا.
             *
             * والتمييز بين «بريد مجهول» و«كلمة مرور خاطئة» هو ما يفرّق المسح
             * الأعمى عن التخمين الموجَّه على حسابٍ معروف.
             */
            'failedLogins' => $this->failedLogins(),
        ];
    }

    /**
     * محاولات الدخول الفاشلة — آخر يومٍ وآخر سبعة.
     *
     * @return array<string, mixed>
     */
    private function failedLogins(): array
    {
        $since = static fn (int $days) => DB::table('admin_audit_log')
            ->where('action', 'admin_user.login_failed')
            ->where('created_at', '>=', now()->subDays($days))
            ->count();

        $last24h = $since(1);

        /*
         * أكثر الحسابات استهدافًا في الأسبوع — وهو ما يفرّق حملةً موجَّهة عن
         * مشرفٍ نسي كلمته. ثلاثةٌ تكفي: قائمةٌ أطول ضجيج.
         */
        $targets = DB::table('admin_audit_log')
            ->where('action', 'admin_user.login_failed')
            ->where('created_at', '>=', now()->subDays(7))
            ->whereNotNull('actor_email')
            ->selectRaw('actor_email, count(*) as total')
            ->groupBy('actor_email')
            ->orderByDesc('total')
            ->limit(3)
            ->get()
            ->map(fn ($row): array => ['email' => (string) $row->actor_email, 'attempts' => (int) $row->total])
            ->all();

        return [
            'state' => 'MEASURED',
            'last24h' => $last24h,
            'last7d' => $since(7),
            'topTargets' => $targets,
            /*
             * عتبةٌ معلنة لا سرّيّة: عشر محاولاتٍ في يومٍ على لوحةٍ لها مشرفون
             * معدودون تستحقّ نظرة. والعتبة تُعرض مع الرقم فيعرف المشغّل لماذا
             * لُوّن.
             */
            'alert' => $last24h >= 10,
            'alertAfter' => 10,
        ];
    }

    /**
     * حال النسخ الاحتياطيّ — أحدث نسخةٍ وعمرها.
     *
     * ─── لماذا لم يكن لهذا قياسٌ أصلًا ───
     *
     * خطّة التعافي موصوفة في `infrastructure/backups/README.md` ومنفَّذة على
     * المضيف: ‏pgBackRest وRestic وقاعدة إنذارٍ في Prometheus تقرأ
     * `tahdheer_backup_last_success_timestamp`. ولا شيء من ذلك يصل إلى
     * المشغّل داخل اللوحة: يفتح «حال النظام» فلا يجد سطرًا واحدًا عن النسخ،
     * فيفترض أنّها تعمل. وافتراضُ وجود نسخةٍ هو بالضبط ما يُكتشف خطؤه يوم
     * الحاجة إليها.
     *
     * ─── ولماذا `NOT_CONFIGURED` لا صفر ───
     *
     * هذه نافذةُ قراءةٍ على دليلٍ يملكه المضيف، لا مُنشئ نسخ. فإن لم يُضبط
     * `BACKUP_PATH` قيل «غير مهيّأ» بسببه — و«لا نسخة منذ يومين» و«لا أدري
     * أين أنظر» جوابان مختلفان تمامًا، وخلطُهما يجعل عطلًا صامتًا يبدو
     * نظافة. وكذلك دليلٌ مضبوطٌ لا وجود له: خطأُ ضبطٍ يُقال لا يُبتلع.
     *
     * @return array<string, mixed>
     */
    private function backups(): array
    {
        $path = config('backups.path');

        if (! is_string($path) || trim($path) === '') {
            return [
                'state' => 'NOT_CONFIGURED',
                'reason' => 'لم يُضبط BACKUP_PATH — لا دليل نسخٍ تُقرأ منه الحالة. '
                    .'خطّة التعافي موصوفة في infrastructure/backups/README.md وتُنفَّذ على المضيف.',
            ];
        }

        if (! is_dir($path)) {
            return [
                'state' => 'NOT_CONFIGURED',
                'reason' => "BACKUP_PATH مضبوط على «{$path}» ولا دليل هناك — ضبطٌ خاطئ لا غياب نسخ.",
            ];
        }

        $files = [];

        foreach ((array) glob(rtrim($path, '/').'/*.{sql,sql.gz,dump,tar,tar.gz,zst}', GLOB_BRACE) as $file) {
            if (! is_file($file)) {
                continue;
            }

            $files[] = [
                'name' => basename($file),
                'sizeBytes' => (int) filesize($file),
                'modifiedAt' => Carbon::createFromTimestamp(filemtime($file))->toIso8601String(),
                'modifiedTs' => (int) filemtime($file),
            ];
        }

        if ($files === []) {
            return [
                'state' => 'NO_DATA',
                'reason' => "الدليل «{$path}» موجودٌ وخالٍ من أيّ ملفّ نسخة — لا نسخة احتياطيّة واحدة.",
                'path' => $path,
            ];
        }

        usort($files, fn (array $a, array $b) => $b['modifiedTs'] <=> $a['modifiedTs']);
        $newest = $files[0];
        $ageSeconds = max(0, now()->getTimestamp() - $newest['modifiedTs']);

        return [
            'state' => 'MEASURED',
            'path' => $path,
            'count' => count($files),
            'totalBytes' => array_sum(array_column($files, 'sizeBytes')),
            'newest' => [
                'name' => $newest['name'],
                'sizeBytes' => $newest['sizeBytes'],
                'modifiedAt' => $newest['modifiedAt'],
            ],
            'ageSeconds' => $ageSeconds,
            /*
             * ستٌّ وعشرون ساعة لا أربعٌ وعشرون: العتبة نفسها التي تستعملها
             * قاعدة `StaleBackup` في prometheus-rules.yml، وساعتان هامشُ
             * تأخّرٍ مقبول لمهمّةٍ يوميّة. عتبتان مختلفتان للحقيقة نفسها
             * تجعلان اللوحة تطمئنّ بينما الإنذار يصرخ.
             */
            'stale' => $ageSeconds > 26 * 3600,
            'staleAfterSeconds' => 26 * 3600,
        ];
    }

    /**
     * حالة الطابور.
     *
     * ─── لماذا `NOT_MEASURABLE` حالةٌ صريحة ───
     *
     * سائق Redis لا يُعدّ من قاعدة البيانات. وعرضُ صفرٍ حينها كذبٌ مطمئن:
     * يقول «لا شيء عالق» ولا أحد يعرف. الحالة تقول «لا أقيس» صراحةً، و
     * `failed_jobs` يبقى مقروءًا لأنّه جدولٌ في القاعدة مهما كان السائق.
     *
     * @return array<string, mixed>
     */
    private function queue(): array
    {
        $driver = (string) config('queue.default');

        $failed = Schema::hasTable('failed_jobs')
            ? (int) DB::table('failed_jobs')->count()
            : null;

        /*
         * القياس من القارئ المشترك: كانت هذه الشاشة تردّ «غير مقيس» تحت
         * Redis — أي في الإنتاج وحده — بينما لوحة الذكاء تعرض رقمًا من
         * المخزن نفسه. شاشتان تتناقضان في السؤال نفسه.
         */
        $summary = QueueDepth::summary();

        /*
         * أقدمُ منتظِرٍ يبقى من سائق قاعدة البيانات وحده: Redis لا يحفظ وقت
         * الإتاحة للمهمّة الجاهزة، والمؤجَّلة وحدها لها طابع. فاستخراجُ «أقدم»
         * منه كان سيقيس المؤجَّل ويسمّيه عالقًا.
         */
        $oldest = $driver === 'database' && Schema::hasTable('jobs')
            ? DB::table('jobs')->min('available_at')
            : null;

        return [
            'driver' => $driver,
            'status' => $summary['state'],
            'pending' => $summary['pending'],
            'failed' => $failed,
            'oldestPendingSeconds' => $oldest === null ? null : max(0, now()->timestamp - (int) $oldest),
            'monitored' => QueueDepth::monitored(),
            'reason' => $summary['reason'],
        ];
    }

    /**
     * سجلّ العمليّات: ما يحتاج إنسانًا.
     *
     * ─── لماذا تُعرض «المجهولة» بذاتها ───
     *
     * العمليّة المجهولة النتيجة **تحجز موضوعها**: وحدةُ تنفيذٍ مجهولة لا
     * يُعاد محاولتها، وصفُّ مخزونٍ مجهول لا يُصحَّح. والتوفيق التلقائيّ
     * يحسم أكثرها كلّ دقيقة، لكن ما يعجز المزوّد عن الإجابة عنه يبقى —
     * وبلا هذه الشاشة يبقى إلى الأبد ولا يعلم به أحد.
     *
     * ─── ولماذا «إيجارٌ منتهٍ» عدّادٌ مستقلّ ───
     *
     * وجودُه يعني عاملًا مات ولم يلتقطه التعافي بعد. رقمٌ يزيد باطّراد
     * يعني أنّ التعافي نفسه لا يعمل — وهو عطلٌ لا يكشفه شيءٌ آخر.
     *
     * @return array<string, mixed>
     */
    private function operations(): array
    {
        $counts = DB::table('operation_records')
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $uncertain = DB::table('operation_records')
            ->where('status', 'UNCERTAIN')
            ->orderBy('id')
            ->limit(50)
            ->get(['id', 'kind', 'subject_type', 'subject_id', 'provider_state', 'reconcile_attempts', 'last_error', 'created_at']);

        return [
            'reserved' => (int) ($counts['RESERVED'] ?? 0),
            'processing' => (int) ($counts['PROCESSING'] ?? 0),
            'uncertain' => (int) ($counts['UNCERTAIN'] ?? 0),
            'succeeded' => (int) ($counts['SUCCEEDED'] ?? 0),
            'failed' => (int) ($counts['FAILED'] ?? 0),
            'expiredLeases' => (int) DB::table('operation_records')
                ->whereIn('status', ['PROCESSING', 'UNCERTAIN'])
                ->whereNotNull('lease_expires_at')
                ->where('lease_expires_at', '<', now())
                ->count(),
            'uncertainSample' => $uncertain->map(fn ($row) => [
                'id' => (int) $row->id,
                'kind' => $row->kind,
                'subject' => $row->subject_type === null
                    ? null
                    : $row->subject_type.'#'.$row->subject_id,
                'providerState' => $row->provider_state,
                'reconcileAttempts' => (int) $row->reconcile_attempts,
                'lastError' => $row->last_error,
                'createdAt' => $row->created_at === null
                    ? null
                    : Carbon::parse($row->created_at)->toIso8601String(),
            ])->values()->all(),
        ];
    }

    /**
     * ما كُتب من كلّ نوعٍ حدث، وكم مستهلكًا يستمع له.
     *
     * `consumers = 0` هو الاكتشاف الذي تُوجد الشاشة لأجله: أحداثٌ تُكتب
     * وتُعالَج «بنجاح» ولا تفعل شيئًا.
     *
     * @return list<array<string, mixed>>
     */
    private function consumers(): array
    {
        return OutboxEvent::query()
            ->selectRaw('event_type, count(*) as written, max(created_at) as last_written_at')
            ->groupBy('event_type')
            ->orderByDesc('written')
            ->get()
            ->map(fn ($row) => [
                'eventType' => $row->event_type,
                'written' => (int) $row->written,
                'lastWrittenAt' => $row->last_written_at === null
                    ? null
                    : Carbon::parse($row->last_written_at)->toIso8601String(),
                'consumers' => count(OutboxRegistry::consumersFor((string) $row->event_type)),
            ])
            ->values()
            ->all();
    }

    /** الأحداث الميّتة — أقدمها أوّلًا: الأقدم أطول تأخّرًا في أثره. */
    public function deadEvents(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'page' => 'sometimes|integer|min:1',
            'perPage' => 'sometimes|integer|min:1|max:100',
        ]);

        $paginator = OutboxEvent::query()
            ->where('status', 'DEAD')
            ->orderBy('created_at')
            ->paginate(
                perPage: (int) ($filters['perPage'] ?? 50),
                page: (int) ($filters['page'] ?? 1),
            );

        return response()->json([
            'data' => $paginator->getCollection()->map(fn (OutboxEvent $e) => [
                'id' => (string) $e->id,
                'eventType' => $e->event_type,
                'aggregateType' => $e->aggregate_type,
                'aggregateId' => (string) $e->aggregate_id,
                'attempts' => (int) $e->attempts,
                // إحياءُ ما لا مستهلك له إهدارٌ مضمون — الواجهة تحتاج التفرقة.
                'hasConsumer' => OutboxRegistry::consumersFor((string) $e->event_type) !== [],
                'createdAt' => $e->created_at?->toIso8601String(),
                'lastAttemptAt' => $e->updated_at?->toIso8601String(),
            ])->values(),
            'meta' => [
                'page' => $paginator->currentPage(),
                'perPage' => $paginator->perPage(),
                'lastPage' => $paginator->lastPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    /**
     * إحياء حدثٍ ميّت: يعود `PENDING` بعدّادٍ صفر.
     *
     * ─── لماذا هذا آمن ───
     *
     * الأمان من المستهلكين لا من هنا: كلٌّ منهم خاملُ التكرار بمفتاح الحدث،
     * وصندوق الصادر نفسه يحمل `idempotency_key` فريدًا. إعادةُ حدثٍ عولج
     * سلفًا لا تُنتج أثرًا ثانيًا.
     *
     * ─── لماذا لا يُحيا ما لا مستهلك له ───
     *
     * سيُعالَج «بنجاح» ولن يفعل شيئًا، فيختفي من قائمة الموتى ويبدو أنّ
     * المشكلة حُلّت. والمشكلة أنّ أحدًا لا يستمع — وهي عيبُ تسجيلٍ في
     * الشيفرة لا عطلُ تشغيل، ولا يُصلحها زرّ.
     *
     * ─── لماذا القفل ───
     *
     * المعالج قد يلتقط الصفّ بين قراءتنا وكتابتنا فيعود `PROCESSING` ثمّ
     * نكتب فوقه `PENDING` — فيُعالَج مرّتين متوازيتين.
     */
    public function replayOutboxEvent(Request $request, AdminUser $actor, string $id): JsonResponse
    {
        $outcome = DB::transaction(function () use ($id, $actor, $request) {
            $event = OutboxEvent::query()->whereKey($id)->lockForUpdate()->first();

            if ($event === null) {
                return ['status' => 404, 'body' => ['message' => 'لا حدث بهذا المعرّف.']];
            }

            if ($event->status !== 'DEAD') {
                return ['status' => 422, 'body' => [
                    'message' => 'لا يُحيا إلّا الحدث الميّت — حالتُه الآن: '.$event->status,
                ]];
            }

            if (OutboxRegistry::consumersFor((string) $event->event_type) === []) {
                return ['status' => 422, 'body' => [
                    'message' => 'لا مستهلك مسجَّل لنوع «'.$event->event_type.'» — إحياؤه لن يُحدث أثرًا.',
                    'code' => 'NO_CONSUMER',
                ]];
            }

            $before = ['status' => $event->status, 'attempts' => (int) $event->attempts];

            $event->update(['status' => 'PENDING', 'attempts' => 0, 'next_retry_at' => null]);

            $this->audit->log(
                $actor, 'system.outbox.replayed', 'outbox_event', $event->id,
                $before, ['status' => 'PENDING', 'attempts' => 0],
                $request->input('reason'), $request,
            );

            return ['status' => 200, 'body' => [
                'id' => (string) $event->id,
                'status' => 'PENDING',
                'maxAttempts' => OutboxProcessor::MAX_ATTEMPTS,
            ]];
        });

        return response()->json($outcome['body'], $outcome['status']);
    }
}
