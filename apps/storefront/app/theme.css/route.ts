/**
 * ورقة أنماط الثيم: /theme.css
 *
 * لماذا ورقة مستقلّة لا وسم <style> داخل التخطيط: أربعة وعشرون مسارًا في هذا
 * المتجر تُصيَّر مسبقًا وقت البناء. أي قيمة تُقرأ داخل التخطيط تُخبز في HTML
 * الساكن، فتعديلٌ في اللوحة لا يظهر إلا بعد إعادة بناء — وهذا يُبطل الغرض من
 * نظام تخصيص كامل. (وقعنا في هذا الفخّ نفسه مع ADMIN_URL في نشر سابق.)
 *
 * الورقة المنفصلة تفصل عمر الصفحة عن عمر الثيم: HTML يبقى ساكنًا وسريعًا،
 * والأنماط تُطلب طلبًا مستقلًّا يُخزَّن ب ETag ويتجدّد فور تغيّر البصمة.
 * ولأنها <link> في <head> فهي حاجبة للتصيير: لا ومضة نصّ بلا تنسيق.
 *
 * الاستيراد داخلها لا خارجها: رابط الخطوط يتغيّر بتغيّر الأوزان المستعملة،
 * فوضعه هنا يبقيه مشتقًّا من الوثيقة لا مكتوبًا في التخطيط.
 */
import { apiBase } from '../../lib/api';
import { budget } from '../../lib/fetch-budget';

export const dynamic = 'force-dynamic';

interface ThemePayload {
  css: string;
  fontsUrl: string | null;
  etag: string;
}

/**
 * احتياطي عند تعذّر الوصول للواجهة الخلفية: ورقة فارغة، لا أنماط مخترعة.
 * رموز نظام التصميم في tokens.css تحمل التصميم الأساسي وحدها، فالمتجر يبقى
 * مقروءًا ومنسّقًا — ينقصه التخصيص فقط، وهذا أصدق من قيم مُلفّقة.
 */
const EMPTY = '/* theme unavailable — design-system defaults apply */';

export async function GET(request: Request): Promise<Response> {
  let payload: ThemePayload | null = null;
  const base = apiBase();

  try {
    if (base === null) {
      throw new Error('API غير مضبوطة');
    }

    const response = await fetch(`${base}/api/v1/theme`, {
      signal: budget('chrome'),
      // لا تخزين هنا: التخزين مسؤولية الترويسات أدناه والمتصفّح، وطبقة ثالثة
      // بينهما تعني قيمة قديمة لا يُبطلها حفظٌ ناجح.
      cache: 'no-store',
      headers: { Accept: 'application/json' },
    });
    if (response.ok) {
      const body = (await response.json()) as { data?: ThemePayload };
      payload = body.data ?? null;
    }
  } catch {
    payload = null;
  }

  if (payload === null) {
    return new Response(EMPTY, {
      status: 200,
      headers: {
        'Content-Type': 'text/css; charset=utf-8',
        // مهلة قصيرة: الخلل مؤقّت غالبًا، فلا تُثبَّت الورقة الفارغة طويلًا.
        'Cache-Control': 'public, max-age=15',
      },
    });
  }

  const etag = `"${payload.etag}"`;
  if (request.headers.get('if-none-match') === etag) {
    return new Response(null, { status: 304, headers: { ETag: etag } });
  }

  // ‎@import‎ يجب أن يسبق أي قاعدة أخرى، وإلّا تجاهله المتصفّح بصمت.
  const fonts = payload.fontsUrl ? `@import url("${payload.fontsUrl}");\n` : '';

  return new Response(fonts + payload.css, {
    status: 200,
    headers: {
      'Content-Type': 'text/css; charset=utf-8',
      ETag: etag,
      'Cache-Control': 'public, max-age=60, stale-while-revalidate=600',
    },
  });
}
