/**
 * حالة الطلب — التصنيف الوحيد الذي تقرأ منه كلّ أسطح ما بعد الشراء.
 *
 * العيب الذي أوجب هذا الملفّ ظهر حيًّا: صفحة الطلب كانت تفحص
 * `order.status === 'paid'` والخادم يبثّ `PAID` بحروفٍ كبيرة، فلا يطابق
 * الشرط أبدًا وينهار كلّه إلى `Boolean(order.paidAt)`. و`paid_at` يبقى
 * مكتوبًا بعد الاسترجاع — فكان الطلب المسترد يُتوَّج «تم الدفع — ملفاتك
 * جاهزة الآن» فوق صفوفٍ مقفلة كلّها.
 *
 * ولأنّ التصنيف نفسه كان مكرَّرًا في صفحة الطلب وفي ‎/account/downloads‎،
 * كان لكلٍّ فرصةُ أن ينحرف عن الآخر. القاعدة تُفرض مرّة واحدة هنا.
 *
 * التعداد مصدره `orders.status` في
 * `2026_08_31_000005_create_commerce_tables.php`، ومجموعةُ «المدفوع» هي
 * `OrderController::PAID_STATUSES` حرفيًّا — لا اجتهاد هنا.
 */

/** الطلب دُفع فعلًا: تُفتح ملفّاته. */
const PAID = new Set(['PAID', 'PROCESSING', 'COMPLETED']);

/** الطلب سُجّل ولم يُدفع بعد: الفتح ما زال ممكنًا. */
const AWAITING = new Set(['PENDING', 'AWAITING_PAYMENT']);

export interface ClosedCopy {
  title: string;
  note: string;
}

/**
 * الحالات المُغلَقة — لا هي دفعٌ ولا هي انتظار.
 *
 * النصّ هنا لا يَعِد بفتحٍ لن يقع، ولا يصف الاسترجاع آليّةً لا يملكها
 * المتجر: «سُحبت صلاحية التحميل» أثرٌ يفرضه الاستحقاق فعلًا، بخلاف وصف
 * وجهة المبلغ — وهي ممّا لا تعرفه هذه الصفحة.
 */
const CLOSED: Record<string, ClosedCopy> = {
  REFUNDED: {
    title: 'استُرجع مبلغ هذا الطلب',
    note: 'سُحبت صلاحية تحميل ملفّات هذا الطلب.',
  },
  PARTIALLY_REFUNDED: {
    /*
     * لا ادّعاء «الملفّات المشمولة بالاسترجاع وحدها»: الحالة ليست ضمن
     * `PAID`، فتُقفل صفوف الطلب **كلّها** في العرض. ادّعاءُ قفلٍ انتقائيّ
     * يصف سلوكًا لا يفعله المتجر — والنصّ يصف المعروض.
     */
    title: 'استُرجع جزءٌ من مبلغ هذا الطلب',
    note: 'أُقفلت ملفّات هذا الطلب كلّها. تواصل معنا إن كنت تنتظر بقاء ملفٍّ منها.',
  },
  CANCELLED: { title: 'أُلغي هذا الطلب', note: 'لم يُخصم أي مبلغ.' },
  FAILED: { title: 'لم يكتمل دفع هذا الطلب', note: 'لم يُخصم أي مبلغ.' },
};

/** التطبيع مرّة واحدة: الخادم يبثّ حروفًا كبيرة، ولا نتّكل على ذلك ضمنًا. */
function normalize(status: string | null | undefined): string {
  return typeof status === 'string' ? status.trim().toUpperCase() : '';
}

export function isPaidStatus(status: string | null | undefined): boolean {
  return PAID.has(normalize(status));
}

export function isAwaitingStatus(status: string | null | undefined): boolean {
  return AWAITING.has(normalize(status));
}

/** نسخة الحالة المُغلَقة، أو null لحالةٍ مدفوعة أو منتظرة أو مجهولة. */
export function closedCopyFor(status: string | null | undefined): ClosedCopy | null {
  return CLOSED[normalize(status)] ?? null;
}

/**
 * سبب قفل صفٍّ في قائمة التنزيلات.
 *
 * كان كلّ صفٍّ مقفل يُوسَم «يُفتح فور تأكيد الدفع»، فيُعرض الاستحقاق المسحوب
 * أو المسترد بوصفه طلبًا لم يُدفع — وعدٌ بفتحٍ لن يقع.
 */
export function lockedNoteFor(status: string | null | undefined): string {
  if (isAwaitingStatus(status)) return 'يُفتح فور تأكيد الدفع';

  const closed = closedCopyFor(status);
  if (closed !== null) return closed.note;

  /*
   * طلبٌ مدفوع وصفٌّ مقفل: استحقاقٌ سُحب، أو لم يُكتب بعدُ صفُّه. الجملة
   * تصف المعروض ولا تخترع سببًا — و«تواصل معنا» لأنّ الحالتين يفصلهما
   * المشغّل لا الزائر.
   */
  return 'هذا الملف غير متاح للتحميل الآن — تواصل معنا إن كنت تراه خطأً.';
}
