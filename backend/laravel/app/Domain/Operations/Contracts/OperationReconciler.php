<?php

namespace App\Domain\Operations\Contracts;

use App\Domain\Operations\Models\OperationRecord;

/**
 * يسأل: هل وقع الأثر فعلًا؟
 *
 * ─── لماذا يوجد هذا العقد ───
 *
 * حين تنتهي المهلة أو يموت العامل لا نعرف إن كان المزوّد نفّذ. وأمامنا
 * ثلاثة طرق:
 *   · نُعيد ← قد ننفّذ مرّتين.
 *   · نستسلم ← قد نترك أثرًا واقعًا بلا تسجيل.
 *   · **نسأل** ← وهذا وحده صحيح.
 *
 * ─── القاعدة الملزمة ───
 *
 * `reconcile` **قراءةٌ فقط**. لا تستورد، ولا تُنشئ، ولا تُصلح، ولا تُعيد
 * تنفيذ شيء. اللحظة التي يُحدث فيها الموفّق أثرًا يصير هو نفسه مصدر
 * التنفيذ المزدوج الذي وُجد ليمنعه.
 *
 * ─── لماذا `UNKNOWN` جوابٌ مشروع ───
 *
 * المزوّد قد يكون هو نفسه معطّلًا لحظة السؤال. «لا أعرف» تُبقي العمليّة
 * مجهولةً فلا تُعاد — وهذا هو السلوك الآمن، لا الفشل.
 */
interface OperationReconciler
{
    /** نوع العمليّة الذي يوفّقه هذا الموفّق (`operation_records.kind`). */
    public function kind(): string;

    /**
     * @return array{state: string, result?: array<string, mixed>, reference?: string}
     *                                                                                 state ∈ CONFIRMED | ABSENT | UNKNOWN
     */
    public function reconcile(OperationRecord $record): array;
}
