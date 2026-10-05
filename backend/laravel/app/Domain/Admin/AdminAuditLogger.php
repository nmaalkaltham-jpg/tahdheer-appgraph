<?php

namespace App\Domain\Admin;

use App\Domain\Admin\Models\AdminAuditEntry;
use App\Domain\Admin\Models\AdminUser;
use Illuminate\Http\Request;

/**
 * Append-only audit writer (MIP §8.5). Mandatory coverage implemented here:
 * price changes, publishing, refunds, entitlement changes, search synonym
 * rules. Each row records actor, action, entity, before/after, reason, IP.
 */
class AdminAuditLogger
{
    public function log(
        ?AdminUser $actor,
        string $action,
        string $entityType,
        int|string|null $entityId,
        ?array $before = null,
        ?array $after = null,
        ?string $reason = null,
        ?Request $request = null,
    ): AdminAuditEntry {
        return AdminAuditEntry::query()->create([
            'admin_user_id' => $actor?->id,
            'actor_email' => $actor?->email,
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId === null ? null : (string) $entityId,
            'before' => $before,
            'after' => $after,
            'reason' => $reason,
            'ip' => $request?->ip(),
            'created_at' => now(),
        ]);
    }

    /**
     * محاولة دخولٍ فاشلة.
     *
     * ─── لماذا في سجلّ التدقيق لا في جدولٍ جديد ───
     *
     * `admin_audit_log` يقبل فاعلًا مجهولًا أصلًا (`admin_user_id` قابل
     * للإفراغ)، ويحمل `actor_email` مستقلًّا عنه، و`ip`، وفهرسًا على
     * (‏`action`, `created_at`) يخدم العدّ تمامًا. وجدولٌ ثانٍ لمحاولةٍ فاشلة
     * يعني سجلّي أمانٍ يُقرآن في شاشتين.
     *
     * ─── وما يُحفظ وما لا يُحفظ ───
     *
     * البريد المُحاوَل ضروريّ للتحقيق: «من يُحاوَل حسابه» هو السؤال نفسه.
     * وكلمة المرور لا تُلمس — لا تُخزَّن ولا تُسجَّل ولا تُشتقّ منها بصمة.
     * والسبب يميّز «بريدٌ مجهول» عن «كلمة مرورٍ خاطئة»: الأوّل مسحٌ أعمى،
     * والثاني تخمينٌ موجَّه على حسابٍ معروف.
     */
    public function loginFailed(string $attemptedEmail, bool $accountExists, ?Request $request = null): AdminAuditEntry
    {
        return AdminAuditEntry::query()->create([
            'admin_user_id' => null,
            // يُقصّ ويُطبَّع: العمود محدود، والمدخل يأتي من غريب.
            'actor_email' => mb_substr(mb_strtolower(trim($attemptedEmail)), 0, 190),
            'action' => 'admin_user.login_failed',
            'entity_type' => 'admin_user',
            'entity_id' => null,
            'before' => null,
            'after' => ['accountExists' => $accountExists],
            'reason' => $accountExists ? 'كلمة مرور خاطئة' : 'بريد غير معروف',
            'ip' => $request?->ip(),
            'created_at' => now(),
        ]);
    }

    /** The most recent entry for one entity, or null when nothing was recorded. */
    public function lastFor(string $entityType, int|string $entityId, string $action): ?AdminAuditEntry
    {
        return AdminAuditEntry::query()
            ->where('entity_type', $entityType)
            ->where('entity_id', (string) $entityId)
            ->where('action', $action)
            ->orderByDesc('id')
            ->first();
    }
}
