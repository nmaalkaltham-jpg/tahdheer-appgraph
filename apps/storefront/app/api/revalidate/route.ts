/**
 * إبطال المخزن عند تغيّر عنوان — يناديه صندوق صادر الخادم.
 *
 * السرّ في رأس `X-Revalidate-Secret` ويُقارَن بزمنٍ ثابت. لا سرّ مضبوط =
 * المسار مغلق (٥٠٣) لا مفتوح: مسار إبطالٍ بلا حراسة يجعل أيّ أحدٍ يُفرغ مخزن
 * المتجر كلّه بحلقة. والمسارات تُبطل كما وردت — القديم والجديد معًا — فلا
 * يبقى رابطٌ محوَّل يخدم صفحته القديمة من المخزن.
 */
import { revalidatePath, revalidateTag } from 'next/cache';
import { timingSafeEqual } from 'node:crypto';

export const dynamic = 'force-dynamic';

const MAX_PATHS = 200;
const MAX_TAGS = 50;

/*
 * الوسوم لا المسارات — للتنقّل.
 *
 * القائمة تعيش في التخطيط الجذر فتمسّ كلّ صفحة: إبطالها بالمسارات يعني
 * تعداد المتجر كلّه (وهو متغيّر العدد أصلًا)، فيُنتظر انقضاء المهلة بدلًا
 * من ذلك — وهو ما كان يجعل النشر غير مرئيّ دقيقةً كاملة. الوسم يُبطل
 * المورد نفسه أيًّا كانت الصفحة التي طلبته.
 */
const TAG_PATTERN = /^[a-z0-9][a-z0-9:_-]{0,63}$/;

function safeEqual(a: string, b: string): boolean {
  const left = Buffer.from(a);
  const right = Buffer.from(b);
  return left.length === right.length && timingSafeEqual(left, right);
}

export async function POST(request: Request): Promise<Response> {
  const secret = process.env.REVALIDATE_SECRET ?? '';
  if (secret === '') {
    return Response.json({ error: 'REVALIDATE_NOT_CONFIGURED' }, { status: 503 });
  }
  const presented = request.headers.get('x-revalidate-secret') ?? '';
  if (!safeEqual(presented, secret)) {
    return Response.json({ error: 'FORBIDDEN' }, { status: 403 });
  }

  let body: unknown;
  try {
    body = await request.json();
  } catch {
    return Response.json({ error: 'INVALID_JSON' }, { status: 400 });
  }
  const paths = (body as { paths?: unknown })?.paths ?? [];
  const tags = (body as { tags?: unknown })?.tags ?? [];

  if (!Array.isArray(paths) || paths.length > MAX_PATHS) {
    return Response.json({ error: 'INVALID_PATHS' }, { status: 422 });
  }
  if (!Array.isArray(tags) || tags.length > MAX_TAGS) {
    return Response.json({ error: 'INVALID_TAGS' }, { status: 422 });
  }
  // طلبٌ بلا مسارٍ ولا وسم لا يُبطل شيئًا — يُردّ ولا يُجاب بنجاحٍ كاذب.
  if (paths.length === 0 && tags.length === 0) {
    return Response.json({ error: 'NOTHING_TO_REVALIDATE' }, { status: 422 });
  }

  const revalidated: string[] = [];
  for (const raw of paths) {
    if (typeof raw !== 'string' || !raw.startsWith('/') || raw.startsWith('//')) continue;
    revalidatePath(raw);
    revalidated.push(raw);
  }

  const revalidatedTags: string[] = [];
  for (const raw of tags) {
    if (typeof raw !== 'string' || !TAG_PATTERN.test(raw)) continue;
    // `{ expire: 0 }` = انتهاءٌ فوريّ. `updateTag` أقوى لكنّه محصورٌ بأفعال
    // الخادم، وهذا مسار مُعالج يناديه صندوق الصادر لا فعلُ خادم.
    revalidateTag(raw, { expire: 0 });
    revalidatedTags.push(raw);
  }

  return Response.json(
    { revalidated, revalidatedTags, at: new Date().toISOString() },
    { headers: { 'Cache-Control': 'no-store' } },
  );
}
