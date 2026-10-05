/**
 * وسيط التحويلات — الوصلة التي كانت مفقودة.
 *
 * جدول `redirect_rules` موجود منذ البداية، و`RedirectService::match()` مكتوب
 * ومُختبَر، و‎/api/v1/redirects/resolve‎ مُسجَّل — **ولا شيء في الواجهة كان
 * يستدعي أيًّا منها**. النتيجة المقيسة: تغييرُ رابط منتج يُنتج 404 دائمًا،
 * وقاعدة تحويل يكتبها المشرف لا تُطبَّق على زائر واحد.
 *
 * ثلاثة قرارات ليست تحسينًا بل صحّة:
 *
 *  · **قائمة لا استعلام لكل مسار.** الوسيط يعمل على كل طلب؛ نداءُ شبكة لكل
 *    زيارة يضيف رحلة إلى كل صفحة. تُجلب القواعد مرّة وتُطابَق محلّيًّا.
 *
 *  · **الفشل يمرّ ولا يحجب.** تعذُّر جلب القواعد لا يمنع الزائر من الصفحة:
 *    التحويل تحسينٌ فوق التصفّح، وحجبُ المتجر لأجل جدول تحويلات عطلٌ أسوأ
 *    ممّا يعالجه.
 *
 *  · **الحلقة تُقطع لا تُتبَع.** قاعدة تحوّل المسار إلى نفسه — أو إلى مسار
 *    تحوّله قاعدة أخرى إليه — تُنتج حلقة تحويل لا نهائية في المتصفّح. تُحلّ
 *    السلسلة هنا حتى وجهتها النهائية، وتُهمَل عند اكتشاف دورة.
 */

import { NextResponse, type NextRequest } from 'next/server';
import { budget } from './lib/fetch-budget';

interface Rule {
  from: string;
  to: string;
  status: number;
}

/** حدّ تتبّع السلسلة: أطول من أي سلسلة سليمة، وقاطعٌ لأي دورة. */
const MAX_HOPS = 5;

/**
 * مدّة الاحتفاظ بالقواعد قبل **مراجعتها** — لا قبل إعادة جلبها كاملةً.
 *
 * كانت خمس دقائق بلا مراجعة: قاعدةٌ يحفظها المشغّل لا تعمل قبل خمس دقائق،
 * وهو زمنٌ طويل حين يكون الحفظ إصلاحًا لرابطٍ مكسورٍ يُشارَك الآن. والطلب
 * الشرطيّ يجعل المراجعة بلا جسم (٣٠٤) حين لا شيء تغيّر، فتقصير المدّة لا
 * يكلّف إلّا ترويسة.
 */
const TTL_MS = 30 * 1000;

/**
 * أصل الـAPI للنداء من الخادم.
 *
 * الداخلي أوّلًا: الوسيط يعمل داخل شبكة compose، فالخروج إلى المضيف العامّ
 * والعودة عبر Traefik رحلةٌ زائدة على كل تحديث للقواعد — وهي المسار الذي
 * يفشل فيه الجلب أصلًا. العامّ يبقى احتياطًا للتشغيل خارج compose.
 */
function apiOrigin(): string | undefined {
  return process.env.INTERNAL_API_URL || process.env.NEXT_PUBLIC_API_URL;
}

/** يُسجَّل مرّة واحدة: سطر لكل طلب فاشل يُغرق السجلّ ويُخفي غيره. */
let loggedFailure = false;

/*
 * ذاكرة على مستوى الوحدة: نسخ الوسيط تُعاد استعمالها، فتُجلب القواعد مرّة
 * كل خمس دقائق لا مرّة لكل طلب. الحالة هنا كاش صرف — القاعدة هي المصدر.
 */
let cache: { rules: Rule[]; at: number; etag: string | null } | null = null;
let inFlight: Promise<Rule[]> | null = null;

async function loadRules(base: string): Promise<Rule[]> {
  const now = Date.now();
  if (cache && now - cache.at < TTL_MS) return cache.rules;

  // طلب واحد في الطريق: عشرة طلبات متزامنة عند انتهاء المدّة لا تصير عشرة
  // نداءات إلى الـAPI.
  inFlight ??= fetch(`${base}/api/v1/redirects/rules`, {
    signal: budget('edge'),
    headers: cache?.etag
      ? { Accept: 'application/json', 'If-None-Match': cache.etag }
      : { Accept: 'application/json' },
    cache: 'no-store',
  })
    .then(async (res) => {
      // ٣٠٤: لا شيء تغيّر — تُجدَّد الطوابع ويُحتفظ بالقواعد كما هي.
      if (res.status === 304 && cache) {
        cache = { ...cache, at: Date.now() };
        return cache.rules;
      }

      if (!res.ok) {
        cache = cache ? { ...cache, at: Date.now() } : null;
        return cache?.rules ?? [];
      }

      const body = (await res.json()) as { data?: Rule[] } | null;
      const rules = body?.data ?? [];
      cache = { rules, at: Date.now(), etag: res.headers.get('ETag') };
      return rules;
    })
    .catch((error: unknown) => {
      /*
       * الفشل يمرّ ولا يحجب — لكنه لا يُبتلع صامتًا.
       *
       * ابتلاعه بلا أثر هو ما جعل تعطّل التحويلات على الإنتاج غير مرئي:
       * الوسيط يعمل، والجدول يُغذّى، ولا تحويل يقع، ولا سطر في السجلّ يقول
       * لماذا. صمتُ العطل أسوأ من العطل.
       */
      if (!loggedFailure) {
        loggedFailure = true;
        console.error('[tahdheer] تعذّر جلب قواعد التحويل — لا تحويلات حتى ينجح الجلب:', error);
      }

      return cache?.rules ?? [];
    })
    .finally(() => {
      inFlight = null;
    });

  return inFlight;
}

/**
 * وجهة داخلية فقط — دفاع بالبنية لا بالثقة في الخادم.
 *
 * الخادم يرفض الوجهات الخارجية عند الحفظ، لكن الوسيط هو من يُصدر التحويل
 * فعلًا: قاعدة قديمة محفوظة قبل ذلك الرفض، أو صفّ يُكتب في القاعدة مباشرةً،
 * لا يجب أن يتحوّل إلى إعادة توجيه مفتوحة تحمل ثقة النطاق.
 */
function isInternal(target: string): boolean {
  // «//host» يبدأ بشرطة فيبدو داخليًّا، وهو بروتوكول-نسبي يخرج من الموقع.
  return target.startsWith('/') && !target.startsWith('//');
}

/** المسار المطبَّع كما يطبّعه الخادم: بشرطة بادئة وبلا شرطة لاحقة. */
function normalize(path: string): string {
  const withLead = path.startsWith('/') ? path : `/${path}`;
  return withLead.length > 1 ? withLead.replace(/\/+$/, '') : withLead;
}

/** أطول بادئة مطابِقة — نفس قاعدة RedirectService::match. */
function findTarget(rules: Rule[], path: string): Rule | null {
  const exact = rules.find((r) => r.from === path);
  if (exact) return exact;

  let best: Rule | null = null;
  let bestLength = -1;

  for (const rule of rules) {
    if (!rule.from.endsWith('*')) continue;
    const prefix = rule.from.slice(0, -1);
    // «/old/*» يطابق «/old/x» و«/old»، ولا يطابق «/older» أبدًا.
    const hit = path.startsWith(prefix) || prefix.replace(/\/+$/, '') === path;
    if (hit && prefix.length > bestLength) {
      best = rule;
      bestLength = prefix.length;
    }
  }

  return best;
}

/** يحلّ السلسلة إلى وجهتها النهائية، ويُهمل عند الدورة. */
function resolveChain(rules: Rule[], from: string): { to: string; status: number } | null {
  const seen = new Set<string>([from]);
  let current = from;
  let status = 301;
  let hops = 0;

  for (; hops < MAX_HOPS; hops++) {
    const rule = findTarget(rules, current);
    if (!rule) break;

    if (!isInternal(rule.to)) return null;

    const next = normalize(rule.to);
    // الوجهة تساوي المصدر، أو زُرناها: دورة — لا تحويل أفضل من حلقة.
    if (seen.has(next)) return null;

    seen.add(next);
    current = next;
    status = rule.status;
  }

  if (current === from) return null;
  // تجاوز الحدّ يعني سلسلة أطول ممّا يُعقل — تُعامَل معاملة الدورة.
  return hops >= MAX_HOPS ? null : { to: current, status };
}

export async function proxy(request: NextRequest) {
  const base = apiOrigin();
  if (!base) return NextResponse.next();

  const path = normalize(request.nextUrl.pathname);
  const rules = await loadRules(base);
  const target = resolveChain(rules, path);

  if (!target) {
    /*
     * المسار يُمرَّر في ترويسة ليقرأه not-found: صفحة 404 في App Router لا
     * تتلقّى المسار، وبدونه لا يمكن الإبلاغ عن الرابط المكسور — فيبقى جدول
     * not_found_hits فارغًا كما كان.
     */
    const headers = new Headers(request.headers);
    headers.set('x-tahdheer-path', path);
    return NextResponse.next({ request: { headers } });
  }

  const url = request.nextUrl.clone();
  url.pathname = target.to;
  // معاملات الاستعلام تُحمَل: تحويلٌ يُسقط ?utm_source يُفقد إسناد الحملة.
  return NextResponse.redirect(url, target.status === 302 ? 302 : 301);
}

export const config = {
  /*
   * كل شيء عدا أصول البناء ومسارات الـAPI الداخلية. الوسيط الذي يعمل على
   * ‎/_next/static‎ يضيف عملًا إلى كل ملفّ في الصفحة بلا أي قاعدة تخصّه.
   */
  matcher: [
    // محرّك التحويلات: كل شيء عدا أصول البناء ومسارات الـAPI.
    '/((?!_next/static|_next/image|favicon.ico|robots.txt|sitemap.xml|api/).*)',
  ],
};
