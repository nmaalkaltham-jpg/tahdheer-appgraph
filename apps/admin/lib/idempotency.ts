// UNIT: LIB-IDEMPOTENCY
// FILE: idempotency.ts
// STYLE: none
// PARENT: PAGE-OPERATIONS-CASES-ID, PAGE-ORDERS-ID, REDIRECTS-PANEL
// USES: none
// API: none
// MAP: docs/PROJECT_MAP.md

/**
 * مفاتيح الخمول في اللوحة — بدائيّةٌ واحدة لكلّ الكتابات.
 *
 * ─── لماذا المفتاح مشتقٌّ من المحتوى لا مولَّدٌ لكلّ نقرة ───
 *
 * مفتاحٌ جديدٌ لكلّ نقرة يُبطل الخمول: إعادةُ الإرسال بعد مهلةٍ عند الوكيل
 * تحمل مفتاحًا آخر فتُنفَّذ مرّتين — وهو العطل الذي يُفترض أن يمنعه.
 * ومفتاحٌ ثابتٌ للنموذج كلّه يُبطله من الجهة الأخرى: المشغّل يُصحّح حقلًا
 * بعد رفضٍ فيُرَدّ عليه «تعارض مفتاح» لأنّ الجسم تغيّر.
 *
 * فالمفتاح = هويّة التبويب + بصمة الجسم. الطلب نفسه مرّتين مفتاحٌ واحد،
 * والجسم المُصحّح مفتاحٌ جديد، بلا أن يُدير أحدٌ المفاتيح يدويًّا.
 *
 * ─── لماذا هويّة التبويب جزءٌ منه ───
 *
 * تبويبان للمشرف نفسه محرّران مستقلّان. ومفتاحٌ يعتمد على الجسم وحده يجعل
 * إنشاءَين متطابقين مقصودين من تبويبين ينهاران إلى واحد بلا أن يُطلب ذلك.
 */

/** هويّة التبويب — تُولَّد مرّةً وتعيش ما عاش التبويب. */
export function tabClientId(scope: string): string {
  const key = `tahdheer:${scope}:client-id`;

  try {
    const existing = sessionStorage.getItem(key);
    if (existing !== null) return existing;

    const fresh = `tab-${crypto.randomUUID()}`;
    sessionStorage.setItem(key, fresh);

    return fresh;
  } catch {
    // تخزين محجوب: هويّةٌ لكلّ تحميل. أضعف من الثابتة ولا تكذب.
    return `tab-${crypto.randomUUID()}`;
  }
}

/**
 * بصمةٌ مستقرّة للجسم.
 *
 * المفاتيح تُرتَّب لأنّ ترتيب الحقول ليس جزءًا من المعنى — تمامًا كما
 * يفعل `RequestFingerprint` في الخادم. واختلافُ الجهتين في هذا كان
 * سيُنتج ٤٠٩ على طلبٍ لم يتغيّر منه شيء.
 */
function fingerprint(payload: unknown): string {
  const json = JSON.stringify(payload, (_key, value: unknown) => {
    if (value === null || typeof value !== 'object' || Array.isArray(value)) return value;

    return Object.fromEntries(Object.entries(value as Record<string, unknown>).sort(([a], [b]) => a.localeCompare(b)));
  });

  // FNV-1a: قصيرةٌ وكافية للتمييز بين أجسامٍ يكتبها إنسان في نموذج واحد.
  let hash = 0x811c9dc5;

  for (let i = 0; i < (json ?? '').length; i += 1) {
    hash ^= (json ?? '').charCodeAt(i);
    hash = Math.imul(hash, 0x01000193);
  }

  return (hash >>> 0).toString(16).padStart(8, '0');
}

/** مفتاح خمولٍ لطلبٍ بعينه: نفسه عند الإعادة، جديدٌ عند التعديل. */
export function idempotencyKey(scope: string, payload: unknown): string {
  return `${tabClientId(scope)}:${fingerprint(payload)}`;
}
