/**
 * نداء سجلّ محاور المنهج — `GET /api/v1/curriculum`، وكان بلا مستهلك.
 *
 * منفصلٌ عن `curriculum.ts` عمدًا: ذاك قواعدُ اختيارٍ خالصة يختبرها
 * `node --test` بلا Next ولا شبكة، وهذا هو الطرف الذي يلمس الشبكة. وخلطهما
 * كان سيجرّ زمن تشغيل الإطار إلى حزمة الاختبارات.
 */

import { apiGet } from './api';
import type { CurriculumHub } from './curriculum';

export { hubsForTerm, siblingHubs, type CurriculumHub, type HubLink } from './curriculum';

/**
 * القائمة **هي** المؤهَّلة بقرار الخادم (عتبة العنقود نفسها التي تحرس خريطة
 * الموقع). وانقطاعُ النداء يعيد `null` فيسقط القسم كلّه — لا قسمٌ فارغ ولا
 * رابطٌ مخترَع.
 */
export function fetchHubs() {
  return apiGet<{ data: CurriculumHub[] }>('/api/v1/curriculum');
}
