<?php

declare(strict_types=1);

namespace App\Domain\Navigation;

use App\Domain\Admin\Models\AdminUser;
use App\Domain\Navigation\Models\NavigationMenu;
use App\Domain\Navigation\Models\NavigationPublishOperation as Operation;
use App\Domain\Navigation\Models\NavigationRevision;
use App\Domain\Navigation\NavigationPublishContract as Contract;
use App\Domain\Outbox\OutboxWriter;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * منسّق نشر التنقّل.
 *
 * لماذا في الخادم لا في المتصفّح: النشر تسلسلٌ من خطوات حسّاسة — قفل، فحص
 * مسبق، لقطة، التزام، حدث، إبطال كاش، تحقّق. متصفّحٌ ينسّقها يعني أن إغلاق
 * تبويب في منتصفها يترك النظام في حالة لا يعرفها أحد ولا يستطيع أحد إتمامها.
 *
 * التسلسل:
 *   CREATED → LOCKED → PREFLIGHT_PASSED → SNAPSHOTTED → COMMITTED
 *   → INVALIDATING → VERIFIED → COMPLETED
 *
 * وعند الفشل بعد الالتزام: ROLLING_BACK → ROLLED_BACK (أو ROLLBACK_FAILED).
 *
 * القفل موزّع عبر ذاكرة التخزين المشتركة لا داخل العملية: خادمان يعالجان
 * طلبَي نشر متزامنَين لا يرى أحدهما قفل الآخر لو كان محلّيًا.
 */
final class NavigationPublishService
{
    /** مهلة القفل: أطول بكثير من نشرٍ سليم، وأقصر من أن يجمّد القائمة للأبد. */
    private const LOCK_SECONDS = 60;

    public function __construct(private readonly OutboxWriter $outbox) {}

    /**
     * ينشر مسوّدة القائمة.
     *
     * `$idempotencyKey` يجعل إعادة المحاولة بعد انقطاع الشبكة قراءةً للعملية
     * القائمة لا نشرًا ثانيًا — الالتزام قد يكون تمّ والردّ ضاع.
     *
     * @throws NavigationPublishFailed
     */
    public function publish(
        NavigationMenu $menu,
        string $idempotencyKey,
        ?int $baseRevision,
        ?AdminUser $actor,
    ): Operation {
        /*
         * ١· التكرار قبل كل شيء: العملية المعروفة تُعاد كما هي.
         *
         * والمفتاح فريدٌ عالميًّا لا لكلّ قائمة، فمفتاحٌ أُعيد استعماله لقائمة
         * أخرى كان يُرجع عمليّةَ قائمةٍ ثالثة ويقول للمشغّل إنّ نشره تمّ —
         * ولم يمسّ موقعه أحد. الانتماء يُفحص، ولا يُخمَّن.
         */
        $existing = Operation::query()->where('idempotency_key', $idempotencyKey)->first();
        if ($existing !== null) {
            if ((int) $existing->menu_id !== (int) $menu->id) {
                throw NavigationPublishFailed::keyReused($idempotencyKey);
            }

            return $existing;
        }

        $operation = Operation::query()->create([
            'id' => (string) Str::uuid(),
            'idempotency_key' => $idempotencyKey,
            'menu_id' => $menu->id,
            'actor_id' => $actor?->getKey(),
            'base_revision' => $baseRevision,
            'target_revision' => (int) $menu->version,
            'status' => Operation::CREATED,
            'current_step' => Operation::CREATED,
        ]);

        $lock = Cache::lock($this->lockKey($menu), self::LOCK_SECONDS);

        // ٢· قفل موزّع: نشران متزامنان لنفس القائمة، واحد فقط يمرّ.
        if (! $lock->get()) {
            $operation->record(Contract::LOCK, 'FAILED', 'القفل محجوز');

            return $this->fail($operation, 'PUBLISH_LOCKED', 'نشر آخر لهذه القائمة قيد التنفيذ.');
        }

        /*
         * `advance(LOCKED)` كان خارج `try`: استثناءٌ منه — والكتابة تلمس
         * القاعدة — يترك القفل محجوزًا حتى تنتهي مهلته، فتُمنع القائمة من
         * النشر دقيقةً كاملة بلا سبب ظاهر.
         */
        try {
            $operation->advance(Operation::LOCKED);
            $operation->record(Contract::LOCK, 'SUCCESS');

            return $this->run($operation, $menu, $baseRevision, $actor);
        } catch (Throwable $e) {
            /*
             * عطلٌ غير متوقّع كان يترك العمليّة عالقةً في خطوتها إلى الأبد:
             * لا حالة نهائيّة، فالواجهة تستجوب دفترًا لا يتغيّر. تُختم فاشلة.
             */
            if (! $this->isTerminal($operation->refresh())) {
                $this->fail($operation, 'UNEXPECTED_ERROR', $e->getMessage());
            }

            throw $e;
        } finally {
            $lock->release();
        }
    }

    /** هل بلغت العمليّة حالةً نهائيّة؟ ما دونها يعني أنّها عالقة. */
    private function isTerminal(Operation $operation): bool
    {
        return in_array($operation->status, [
            Operation::COMPLETED, Operation::FAILED,
            Operation::ROLLED_BACK, Operation::ROLLBACK_FAILED,
        ], true);
    }

    private function run(
        Operation $operation,
        NavigationMenu $menu,
        ?int $baseRevision,
        ?AdminUser $actor,
    ): Operation {
        $menu->refresh();
        $draft = (array) $menu->draft_nodes;
        $expectedVersion = (int) $menu->version;

        // ٣· الفحص المسبق — قبل أي كتابة.
        $problems = $this->preflight($menu, $draft, $baseRevision);
        if ($problems !== []) {
            $operation->record(Contract::PREFLIGHT, 'FAILED', $problems[0]);

            return $this->fail($operation, 'PREFLIGHT_FAILED', implode(' · ', $problems));
        }
        $operation->advance(Operation::PREFLIGHT_PASSED);
        $operation->record(Contract::PREFLIGHT, 'SUCCESS');

        // ٤· اللقطة: الإصدار المنشور حاليًّا هو وجهة التراجع.
        $operation->forceFill(['snapshot_revision' => $menu->published_version])->save();
        $operation->advance(Operation::SNAPSHOTTED);
        $operation->record(Contract::SNAPSHOT, 'SUCCESS', 'وجهة التراجع: '.($menu->published_version ?? 'لا إصدار منشور'));

        /*
         * ٥· الالتزام: الحالة والحدث معًا أو لا شيء. لا نداء شبكة هنا.
         *
         * المسوّدة تُقرأ **داخل** المعاملة من الصفّ المقفول، لا من النسخة
         * التي فُحصت قبلها: حفظٌ يقع بين الفحص والالتزام كان يُكتب رقمُ
         * إصدارِه الجديد مع شجرةِ الإصدار القديم، فيظهر للمشغّل أنّ تعديله
         * نُشر وعلى المتجر شجرةٌ لا تحمله. الإصدار يُفحص هنا أيضًا فيُرفض
         * السباق بدل أن يُخدَم.
         */
        $stale = null;
        $targetFingerprint = null;

        try {
            $targetFingerprint = DB::transaction(function () use ($menu, $operation, $actor, $expectedVersion, &$stale): string {
                /** @var NavigationMenu $fresh */
                $fresh = NavigationMenu::query()->lockForUpdate()->findOrFail($menu->id);

                if ((int) $fresh->version !== $expectedVersion) {
                    $stale = (int) $fresh->version;

                    return '';
                }

                $draft = (array) $fresh->draft_nodes;
                $fingerprint = MenuTree::fingerprint($draft);

                $fresh->forceFill([
                    'published_nodes' => $draft,
                    'published_version' => (int) $fresh->version,
                    'published_at' => now(),
                    'updated_by' => $actor?->getKey(),
                ])->save();

                $this->outbox->write(
                    aggregateType: 'navigation_menu',
                    aggregateId: (string) $fresh->id,
                    eventType: 'navigation.published',
                    payload: [
                        'location' => $fresh->location,
                        'revision' => (int) $fresh->published_version,
                        'fingerprint' => $fingerprint,
                        'operation_id' => $operation->id,
                    ],
                    idempotencyKey: "navigation:{$fresh->id}:published:{$fresh->version}",
                );

                return $fingerprint;
            });
        } catch (Throwable $e) {
            // فشل قبل الالتزام ⇒ لا شيء كُتب ⇒ لا تراجع مطلوب.
            $operation->record(Contract::COMMIT, 'FAILED', $e->getMessage());

            return $this->fail($operation, 'COMMIT_FAILED', $e->getMessage());
        }

        if ($stale !== null) {
            $detail = "حُفظت المسوّدة أثناء النشر: بدأ على الإصدار {$expectedVersion} والحالي {$stale}. أعد النشر ليشمل الحفظ الأخير.";
            $operation->record(Contract::COMMIT, 'FAILED', $detail);

            return $this->fail($operation, 'DRAFT_CHANGED', $detail);
        }

        $operation->advance(Operation::COMMITTED);
        $operation->record(Contract::COMMIT, 'SUCCESS');

        // ٦· إبطال موجَّه — خارج المعاملة عمدًا: نداء شبكة داخلها يقفل صفوفًا.
        $operation->advance(Operation::INVALIDATING);
        NavigationCache::invalidate($menu->location);
        $operation->record(Contract::INVALIDATE, 'SUCCESS', $menu->location);

        // ٧· التحقّق: يُقرأ ما يخدمه المسار العام فعلًا ويُقارَن بالبصمة.
        $servedFingerprint = MenuTree::fingerprint(NavigationCache::published($menu->location));

        if (! hash_equals($targetFingerprint, $servedFingerprint)) {
            /*
             * انحراف بعد الالتزام: المنشور ليس ما قُصد. التراجع إلى اللقطة
             * أصدق من ترك قائمةٍ نصف صحيحة تُخدم لكل زائر.
             */
            $operation->record(Contract::VERIFY, 'FAILED', 'البصمة المخدومة لا تطابق المقصود — تراجعٌ تلقائيّ');
            $this->performRollback($operation, $menu, $actor);

            return $operation->refresh();
        }

        $operation->forceFill([
            'verified_fingerprint' => $servedFingerprint,
            'verified_at' => now(),
        ])->save();
        $operation->advance(Operation::VERIFIED);
        $operation->record(Contract::VERIFY, 'SUCCESS');
        $operation->complete();
        $operation->record(Contract::COMPLETE, 'SUCCESS');

        return $operation;
    }

    /**
     * فحوص لا تُكتب قبلها بايت واحد.
     *
     * @param  list<array<string, mixed>>  $draft
     * @return list<string>
     */
    private function preflight(NavigationMenu $menu, array $draft, ?int $baseRevision): array
    {
        $problems = [];

        if ($baseRevision !== null && $baseRevision !== (int) $menu->version) {
            $problems[] = "النسخة تغيّرت: تحرّر على {$baseRevision} والحالي {$menu->version}.";
        }

        if ($draft === []) {
            // قائمة فارغة منشورة تُفرغ الترويسة لكل زائر — تُرفض لا تُنشر.
            $problems[] = 'المسوّدة فارغة: نشرها يترك هذا الموقع بلا روابط.';
        }

        /*
         * التطبيع يُسقط المخالف؛ فرقٌ هنا يعني أن ما في القاعدة ليس ما يُنشر.
         * والعنصر يُسمّى: «لا تجتاز التطبيع» عن شجرةٍ فيها ستّون عنصرًا تترك
         * المشغّل يفتّش عنها بيده، وهو يملك اسمها.
         */
        if (MenuTree::fingerprint(MenuTree::normalize($draft)) !== MenuTree::fingerprint($draft)) {
            $dropped = array_diff($this->idsOf($draft), $this->idsOf(MenuTree::normalize($draft)));
            $problems[] = $dropped === []
                ? 'المسوّدة المحفوظة لا تجتاز التطبيع (تغيّر ترتيبٌ أو عمق) — أعد حفظها قبل النشر.'
                : 'عناصر يُسقطها التطبيع: '.$this->name($draft, $dropped).'. أعد حفظ المسوّدة.';
        }

        $temps = [];
        foreach ($draft as $node) {
            if (str_starts_with((string) ($node['id'] ?? ''), 'tmp_')) {
                $temps[] = (string) $node['id'];
            }
        }
        if ($temps !== []) {
            $problems[] = 'عناصر بمعرّفات مؤقّتة لم يمنحها الخادم هوية دائمة: '
                .$this->name($draft, $temps).'. احفظ المسوّدة ثم انشر.';
        }

        return $problems;
    }

    /**
     * @param  array<mixed>  $nodes
     * @return list<string>
     */
    private function idsOf(array $nodes): array
    {
        return array_values(array_map(static fn ($node): string => (string) ($node['id'] ?? ''), $nodes));
    }

    /**
     * أسماء عناصر بمعرّفاتها — تسميةٌ مقروءة لا معرّفات خام.
     *
     * @param  array<mixed>  $nodes
     * @param  iterable<string>  $ids
     */
    private function name(array $nodes, iterable $ids): string
    {
        $labels = [];
        foreach ($nodes as $node) {
            $labels[(string) ($node['id'] ?? '')] = trim((string) ($node['label'] ?? ''));
        }

        $named = [];
        foreach ($ids as $id) {
            $label = $labels[$id] ?? '';
            $named[] = $label === '' ? "«{$id}»" : "«{$label}»";
        }

        $shown = array_slice($named, 0, 5);
        $rest = count($named) - count($shown);

        return implode('، ', $shown).($rest > 0 ? " وغيرها ({$rest})" : '');
    }

    /**
     * يستعيد الإصدار الذي كان منشورًا، ويتحقّق من الاستعادة بالبصمة.
     */
    public function rollback(Operation $operation, NavigationMenu $menu, ?AdminUser $actor): Operation
    {
        /*
         * الأهليّة قبل أيّ كتابة.
         *
         * كان التراجع يقبل أيّ عمليّة: فاشلةً لم تكتب شيئًا (فيُستعاد إصدارٌ
         * لم يُغادَر أصلًا)، أو مُتراجَعًا عنها من قبل (فيُستعاد مرّتين). وأمّا
         * انتماؤها إلى هذه القائمة فيفحصه المتحكّم قبل أن يبلغنا الصفّ.
         */
        if ($operation->status !== Operation::COMPLETED) {
            $operation->record(Contract::ROLLBACK_ELIGIBLE, 'FAILED', "الحالة {$operation->status}");

            return $this->fail(
                $operation,
                'ROLLBACK_INELIGIBLE',
                "لا يُتراجَع عن عمليّة حالتها {$operation->status}؛ التراجع للعمليّات المكتملة وحدها.",
                Operation::ROLLBACK_FAILED,
            );
        }

        $lock = Cache::lock($this->lockKey($menu), self::LOCK_SECONDS);
        if (! $lock->get()) {
            $operation->record(Contract::ROLLBACK_ELIGIBLE, 'FAILED', 'القفل محجوز');

            return $this->fail($operation, 'PUBLISH_LOCKED', 'عمليّة أخرى لهذه القائمة قيد التنفيذ.', Operation::ROLLBACK_FAILED);
        }

        try {
            $operation->record(Contract::ROLLBACK_ELIGIBLE, 'SUCCESS');

            return $this->performRollback($operation, $menu, $actor);
        } finally {
            $lock->release();
        }
    }

    /** التراجع نفسه — يُستدعى من الحارس، ومن مسار الانحراف بعد الالتزام وهو ممسكٌ بالقفل. */
    private function performRollback(Operation $operation, NavigationMenu $menu, ?AdminUser $actor): Operation
    {
        $operation->advance(Operation::ROLLING_BACK);

        $snapshotRevision = $operation->snapshot_revision;

        // لا لقطة ⇒ لم يكن منشورًا قبلها ⇒ الاستعادة سحبٌ من النشر.
        $snapshot = $snapshotRevision === null
            ? null
            : NavigationRevision::query()
                ->where('menu_id', $menu->id)
                ->where('revision', $snapshotRevision)
                ->first();

        if ($snapshotRevision !== null && $snapshot === null) {
            $operation->record(Contract::ROLLBACK_SNAPSHOT, 'FAILED', "لا إصدار برقم {$snapshotRevision}");

            return $this->fail($operation, 'SNAPSHOT_MISSING', "لا إصدار محفوظ برقم {$snapshotRevision}.", Operation::ROLLBACK_FAILED);
        }
        $operation->record(Contract::ROLLBACK_SNAPSHOT, 'SUCCESS', $snapshotRevision === null ? 'سحبٌ من النشر' : "الإصدار {$snapshotRevision}");

        try {
            DB::transaction(function () use ($menu, $snapshot, $snapshotRevision, $actor, $operation): void {
                /** @var NavigationMenu $fresh */
                $fresh = NavigationMenu::query()->lockForUpdate()->findOrFail($menu->id);

                $fresh->forceFill([
                    'published_nodes' => $snapshot?->nodes,
                    'published_version' => $snapshotRevision,
                    'published_at' => $snapshot === null ? null : now(),
                    'updated_by' => $actor?->getKey(),
                ])->save();

                $this->outbox->write(
                    aggregateType: 'navigation_menu',
                    aggregateId: (string) $fresh->id,
                    eventType: 'navigation.rolled_back',
                    payload: [
                        'location' => $fresh->location,
                        'revision' => $snapshotRevision,
                        'operation_id' => $operation->id,
                    ],
                    idempotencyKey: "navigation:{$fresh->id}:rollback:{$operation->id}",
                );
            });
        } catch (Throwable $e) {
            $operation->record(Contract::ROLLBACK_COMMIT, 'FAILED', $e->getMessage());

            return $this->fail($operation, 'ROLLBACK_COMMIT_FAILED', $e->getMessage(), Operation::ROLLBACK_FAILED);
        }

        $operation->record(Contract::ROLLBACK_COMMIT, 'SUCCESS');
        NavigationCache::invalidate($menu->location);
        $operation->record(Contract::ROLLBACK_INVALIDATE, 'SUCCESS', $menu->location);

        // التحقّق ببصمة اللقطة نفسها — لا بالثقة في أن الكتابة وقعت.
        $expected = $snapshot === null ? MenuTree::fingerprint([]) : $snapshot->fingerprint;
        $actual = MenuTree::fingerprint(NavigationCache::published($menu->location));

        if (! hash_equals($expected, $actual)) {
            $operation->record(Contract::ROLLBACK_VERIFY, 'FAILED', 'البصمة لا تطابق اللقطة');

            return $this->fail($operation, 'ROLLBACK_VERIFY_FAILED', 'البصمة بعد الاستعادة لا تطابق اللقطة.', Operation::ROLLBACK_FAILED);
        }

        $operation->forceFill([
            'status' => Operation::ROLLED_BACK,
            'current_step' => Operation::ROLLED_BACK,
            'completed_at' => now(),
        ])->save();
        $operation->record(Contract::ROLLBACK_VERIFY, 'SUCCESS');

        return $operation;
    }

    private function fail(Operation $operation, string $code, string $detail, string $status = Operation::FAILED): Operation
    {
        Log::warning('navigation.publish.failed', [
            'operation_id' => $operation->id,
            'code' => $code,
            'step' => $operation->current_step,
        ]);

        $operation->forceFill([
            'status' => $status,
            'failure_code' => $code,
            'failure_detail' => mb_substr($detail, 0, 1000),
            'completed_at' => now(),
        ])->save();

        return $operation;
    }

    private function lockKey(NavigationMenu $menu): string
    {
        return "navigation:publish:{$menu->id}";
    }
}
