/**
 * حلُّ المسار المشروح — `GET /api/v1/url/resolve`، وكان بلا مستهلك.
 *
 * ─── ما الذي يضيفه على الوسيط ───
 *
 * وسيط التحويلات يلتقط ما تطابقه قاعدةٌ في `redirect_rules` ويحوّله قبل أن
 * تُبنى الصفحة. فما يبلغ صفحة ٤٠٤ هو ما **لم** تطابقه قاعدة. وهنا يعرف
 * السجلّ شيئين لا يعرفهما الوسيط:
 *
 *  · **الفرق بين «ذهب» و«لم يوجد»**: كيانٌ تقاعد بسياسة `gone` يردّ ٤١٠ لا
 *    ٤٠٤. والرمزان معنيان مختلفان — «كان هنا وأُزيل» تُطمئن الزائر أنّه لم
 *    يُخطئ، و«لا شيء بهذا العنوان» تدفعه يراجع ما نسخ (قاعدة ١١).
 *
 *  · **مساراتٌ قريبة مقيسة**: السجلّ يحسب تشابهًا مع العناوين المنشورة ويعيد
 *    أقربها بدرجته وسببه. وصفحةٌ تعرض «الأكثر مبيعًا» لمن أخطأ حرفًا واحدًا
 *    في اسم منتجٍ قائم تُضيّع ما تملكه: الجواب نفسه.
 *
 * والغياب لا يُسقط الصفحة: تعذُّر النداء يعيد `null`، فتبقى ٤٠٤ كما كانت.
 */

import { apiBase } from './api';
import { budget } from './fetch-budget';

export interface UrlSuggestion {
  path: string;
  entityType: string;
  entityId: number;
  /** درجة تشابهٍ مقيسة بين ٠ و١ — لا تُعرض، لكنّها ترتيب القائمة. */
  score: number;
  /** لماذا اقتُرح — بنصّ الخادم، لا تفسيرٌ يُكتب هنا. */
  why: string;
}

export interface UrlResolution {
  outcome: string;
  httpStatus: number;
  suggestions: UrlSuggestion[];
}

/** كم مسارًا قريبًا يُعرض: أكثر من ثلاثة يصير قائمةً تُقرأ بحثًا لا جوابًا. */
export const MAX_SUGGESTIONS = 3;

export async function resolvePath(path: string): Promise<UrlResolution | null> {
  const base = apiBase();
  if (!base) return null;

  try {
    const response = await fetch(`${base}/api/v1/url/resolve?path=${encodeURIComponent(path)}`, {
      signal: budget('edge'),
      headers: { Accept: 'application/json' },
      cache: 'no-store',
    });

    if (!response.ok && response.status !== 404) return null;

    const body = (await response.json()) as { data?: UrlResolution } | null;

    return body?.data ?? null;
  } catch {
    /* الشرح تحسينٌ فوق ٤٠٤ — غيابه لا يحجب الصفحة. */
    return null;
  }
}
