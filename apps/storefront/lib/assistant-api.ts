/**
 * نداء مساعد المتجر — `POST /api/v1/assistant/ask`.
 *
 * كانت النقطة بلا مستهلك في المتجر ولا في اللوحة، بينما تصفها الوثائق
 * مبنيّة ويصف تعليقُ وحدة التحكّم واجهةً تعرض جوابها. هذا هو الطرف الناقص.
 *
 * ولماذا لا يمرّ بـ`apiGet`: النقطة POST، وردّها في حالة الرفض (٥٠٣) يحمل
 * **الجسم كاملًا** — نصّ الانصراف وسببه. فابتلاعُ الجسم مع الرمز يجعل
 * الواجهة تخترع جملتها بدل أن تنقل جملة الخادم.
 */

import { apiBase } from './api';

/** ما يبنيه الخادم من الجداول — لا شيء منه من نصّ النموذج. */
export interface AssistantProduct {
  id: number;
  title: string;
  slug: string;
  stage?: string | null;
  grade?: string | null;
  subject?: string | null;
  /** بالهللة؛ `null` يعني «لا سعر» لا «مجّانيّ». */
  priceMinor?: number | null;
}

export interface AssistantAnswer {
  answer: string;
  products: AssistantProduct[];
  /** `false` مع `refusal` حين لم يجد سياقًا — امتناعٌ معلَن لا جوابٌ مخترَع. */
  grounded: boolean;
  refusal?: string;
  code?: string;
}

export interface AssistantResult {
  data: AssistantAnswer | null;
  /** 0 = الواجهة غير مهيّأة أو لا تُجيب. */
  status: number;
}

export async function askAssistant(question: string): Promise<AssistantResult> {
  const base = apiBase();
  if (!base) return { data: null, status: 0 };

  try {
    const res = await fetch(`${base}/api/v1/assistant/ask`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
      body: JSON.stringify({ question }),
      cache: 'no-store',
    });

    /* الجسم يُقرأ في الحالين: ٥٠٣ يحمل نصّ الانصراف وسببه. */
    const body = (await res.json().catch(() => null)) as AssistantAnswer | null;

    return { data: body, status: res.status };
  } catch {
    return { data: null, status: 0 };
  }
}
