/**
 * مساراتٌ فيها مفتاح — وما يُرسل منها إلى القياس.
 *
 * `/orders/{token}` هو الباب الوحيد لطلب الضيف ودفعه وتنزيلاته، والخادم لا يحفظ
 * منه إلّا بصمته. فلا يخرج من المتصفّح في حدث قياسٍ ولا في بلاغ 404 إلّا مكانُه:
 * `/orders/[token]`. ووسائط الاستعلام تُسقط كلّها — `/account/orders?token=…`
 * يحمل المفتاح نفسه.
 *
 * نظيره في الخادم `App\Domain\Privacy\CredentialPaths` وهو الحدّ الفعليّ: الخادم
 * لا يثق بالمتصفّح. وهذا الملفّ يجعل المفتاح لا يعبر الشبكة أصلًا.
 */

export const TOKEN_PLACEHOLDER = '[token]';

/**
 * مقطعٌ يلي `orders` في أيّ موضع — بادئة اللغة، ومسار إعادة الدفع — بعد فاصلٍ أو أكثر
 * (`//`، `\\`): الرابط المصنوع يُقاس كما يُقاس الصحيح.
 */
const SEGMENT = /(^|[/\\])(orders)[/\\]+([^/?#"\s\\]+)/gi;

function withoutQuery(path: string): string {
  return path.trim().replace(/[?#][\s\S]*$/, '');
}

/**
 * فكّ الترميز مرّاتٍ حتّى يثبت (`%252F` مرّتان)، وكلُّ `%XX` وحده: هروبٌ فاسد مثل
 * `%ZZ` لا يُسقط فكّ ما حوله — وهو ما كان يُبقي المفتاح في رابطٍ مصنوع.
 */
function decode(path: string): string {
  let current = path;
  for (let pass = 0; pass < 3; pass += 1) {
    const next = current.replace(/%[0-9a-f]{2}/gi, (escape) => {
      try {
        return decodeURIComponent(escape);
      } catch {
        return escape;
      }
    });
    if (next === current) break;
    current = next;
  }
  return current;
}

/**
 * شكل المفتاح نفسه: ٤٣ محرفًا من base64url بحروفٍ كبيرة وصغيرة معًا (HMAC-SHA256).
 * يلتقط التهجئات المصنوعة التي لا يلتقطها المقطع (`/orders;x/…`، `/orders/./…`)،
 * ولا يطابق مُعرِّفًا نصّيًّا: المعرّفات بحروفٍ صغيرة.
 */
const TOKEN_SHAPE = /(?<![A-Za-z0-9_-])(?=[A-Za-z0-9_-]*[A-Z])(?=[A-Za-z0-9_-]*[a-z])[A-Za-z0-9_-]{43}(?![A-Za-z0-9_-])/g;

/** المسار بلا استعلام، والمفتاح مكانُه لا قيمتُه. المسار العاديّ يعود كما ورد. */
export function redactPath(path: string): string {
  const bare = withoutQuery(path);
  // `/orders%2F<token>` صفحة 404، لكنّ نصّها يحمل المفتاح.
  const decoded = decode(bare);
  const credential = decoded.search(SEGMENT) !== -1 || decoded.search(TOKEN_SHAPE) !== -1;

  return credential
    ? decoded.replace(SEGMENT, `$1$2/${TOKEN_PLACEHOLDER}`).replace(TOKEN_SHAPE, TOKEN_PLACEHOLDER)
    : bare;
}

/** هل في هذا المسار مفتاحٌ حقيقيّ؟ (المكان المستبدَل ليس مفتاحًا.) */
export function isCredentialPath(path: string): boolean {
  const decoded = decode(withoutQuery(path));

  return (
    [...decoded.matchAll(SEGMENT)].some((match) => match[3] !== TOKEN_PLACEHOLDER) ||
    decoded.search(TOKEN_SHAPE) !== -1
  );
}

/** أصلٌ ومسارٌ منقّى: بلا اسم مستخدم ولا استعلام ولا جزء. `null` لما ليس http(s). */
export function redactUrl(url: string | null | undefined): string | null {
  const raw = (url ?? '').trim();
  if (raw === '') return null;
  if (raw.startsWith('/') && !raw.startsWith('//')) return redactPath(raw);

  try {
    const { origin, protocol } = new URL(raw);
    if (protocol !== 'http:' && protocol !== 'https:') return null;
    // المسار كما ورد لا كما يطبّعه `URL` — فيطابق ما يحفظه الخادم حرفًا بحرف.
    return origin + redactPath(raw.replace(/^[a-z][a-z0-9+.-]*:\/\/[^/?#]*/i, ''));
  } catch {
    return null;
  }
}

/**
 * المُرجِع الذي يجوز إرساله: أصلُ موقعٍ آخر وحده.
 *
 * مُرجِعٌ من صفحاتنا نفسها ليس مصدر زيارة، وقد يكون صفحةَ طلبٍ بمفتاحها. والخادم لا
 * يحفظ من المُرجِع إلّا مضيفه، فالأصل يكفيه.
 */
export function externalReferrer(referrer: string, ownOrigin: string): string | undefined {
  if (referrer === '') return undefined;

  try {
    const { origin, protocol } = new URL(referrer);
    if (protocol !== 'http:' && protocol !== 'https:') return undefined;
    return origin === ownOrigin ? undefined : origin;
  } catch {
    return undefined;
  }
}
