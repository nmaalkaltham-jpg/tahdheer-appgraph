<?php

declare(strict_types=1);

namespace App\Domain\Navigation;

use App\Domain\Navigation\Models\NavigationPublishOperation as Operation;

/**
 * عقد النشر — تعريف الخطوات في الخادم، لا في الواجهة.
 *
 * الحوار في `navigation/dialogs.tsx` كان يعرض «لم يصل عقد النشر من الخادم
 * بعد» دائمًا لأنّ `meta.publishContract` لم يكن يُنتَج قطّ. القائمة تُكتب
 * هنا لأنّ المنسّق هو من ينفّذها: خطوةٌ تُضاف إلى `NavigationPublishService`
 * بلا سطرٍ هنا تجعل الحوار يكذب، و`NavigationPublishContractTest` يُسقط
 * البناء حين يفترق المكتوب عن المنفَّذ.
 *
 * `implemented` قدرةٌ مبنيّة في الخادم، و`required` أنّ النشر لا يصحّ بدونها.
 * أنّها **نُفّذت** لا يُقرأ من هنا بل من دفتر عمليّة بعينها (`steps`).
 */
final class NavigationPublishContract
{
    public const LOCK = 'lock';

    public const PREFLIGHT = 'preflight';

    public const SNAPSHOT = 'snapshot';

    public const COMMIT = 'commit';

    public const INVALIDATE = 'invalidate';

    public const VERIFY = 'verify';

    public const COMPLETE = 'complete';

    public const ROLLBACK_ELIGIBLE = 'rollback_eligible';

    public const ROLLBACK_SNAPSHOT = 'rollback_snapshot';

    public const ROLLBACK_COMMIT = 'rollback_commit';

    public const ROLLBACK_INVALIDATE = 'rollback_invalidate';

    public const ROLLBACK_VERIFY = 'rollback_verify';

    /** @return array{publish: list<array<string, mixed>>, rollback: list<array<string, mixed>>} */
    public static function describe(): array
    {
        return ['publish' => self::publish(), 'rollback' => self::rollback()];
    }

    /** @return list<array<string, mixed>> */
    public static function publish(): array
    {
        return [
            self::step(
                self::LOCK, 'قفلٌ موزّع على القائمة',
                'نشران متزامنان لنفس الموقع، واحدٌ فقط يمرّ؛ القفل في الكاش المشترك لا في الذاكرة المحليّة فيراه كلّ خادم.',
                Operation::LOCKED, 'Cache::lock(navigation:publish:{id})',
            ),
            self::step(
                self::PREFLIGHT, 'الفحص المسبق',
                'النسخة الأساسيّة، ومسوّدةٌ غير فارغة، واجتياز التطبيع، وخلوّها من المعرّفات المؤقّتة — قبل أن يُكتب بايتٌ واحد.',
                Operation::PREFLIGHT_PASSED, 'NavigationPublishService::preflight',
            ),
            self::step(
                self::SNAPSHOT, 'تسجيل وجهة التراجع',
                'الإصدار المنشور الآن يُسجَّل في العمليّة؛ هو ما يستعيده التراجع لاحقًا من `navigation_revisions`.',
                Operation::SNAPSHOTTED, 'NavigationPublishService::run',
            ),
            self::step(
                self::COMMIT, 'الالتزام الذرّي',
                'المسوّدة تُقرأ وتُكتب منشورةً مع حدث الصندوق الصادر في معاملةٍ واحدة تحت قفل صفٍّ — الحالة والحدث معًا أو لا شيء.',
                Operation::COMMITTED, 'NavigationPublishService::run · OutboxWriter::write',
            ),
            self::step(
                self::INVALIDATE, 'إبطال الكاش',
                'خارج المعاملة عمدًا: نداءُ كاشٍ داخلها يُطيل قفل الصفّ بمقدار زمن الشبكة.',
                Operation::INVALIDATING, 'NavigationCache::invalidate',
            ),
            self::step(
                self::VERIFY, 'التحقّق بالبصمة',
                'يُقرأ ما يخدمه المسار العامّ فعلًا وتُقارَن بصمته بالمقصود؛ الانحراف يُشغّل التراجع لا يُتجاهَل.',
                Operation::VERIFIED, 'MenuTree::fingerprint · NavigationCache::published',
            ),
            self::step(
                self::COMPLETE, 'الإتمام',
                'العمليّة تُختم بوقتها فتصير إعادةُ المحاولة بالمفتاح نفسه قراءةً لها لا نشرًا ثانيًا.',
                Operation::COMPLETED, 'NavigationPublishOperation::complete',
            ),
        ];
    }

    /** @return list<array<string, mixed>> */
    public static function rollback(): array
    {
        return [
            self::step(
                self::ROLLBACK_ELIGIBLE, 'أهليّة العمليّة',
                'العمليّة تخصّ هذه القائمة، وقد اكتملت، ولها لقطةٌ مسجّلة — وإلّا رُفض التراجع قبل أيّ كتابة.',
                null, 'NavigationPublishService::rollback',
            ),
            self::step(
                self::ROLLBACK_SNAPSHOT, 'قراءة اللقطة',
                'الإصدار المسجَّل يُقرأ من `navigation_revisions`؛ غيابه رفضٌ لا تخمين.',
                Operation::ROLLING_BACK, 'NavigationRevision',
            ),
            self::step(
                self::ROLLBACK_COMMIT, 'استعادة المنشور',
                'شجرة اللقطة تُكتب منشورةً مع حدث التراجع في معاملةٍ واحدة.',
                null, 'NavigationPublishService::rollback · OutboxWriter::write',
            ),
            self::step(self::ROLLBACK_INVALIDATE, 'إبطال الكاش', 'كما في النشر — بعد الالتزام لا داخله.', null, 'NavigationCache::invalidate'),
            self::step(
                self::ROLLBACK_VERIFY, 'التحقّق بالبصمة',
                'بصمة اللقطة نفسها تُقارَن بما يُخدَم بعد الاستعادة؛ الفارق يُعلن `ROLLBACK_FAILED` لا نجاحًا.',
                Operation::ROLLED_BACK, 'MenuTree::fingerprint',
            ),
        ];
    }

    /** @return array<string, mixed> */
    private static function step(string $id, string $label, string $detail, ?string $produces, string $executor): array
    {
        return [
            'id' => $id,
            'label' => $label,
            'detail' => $detail,
            'required' => true,
            'implemented' => true,
            'produces' => $produces,
            'executor' => $executor,
        ];
    }
}
