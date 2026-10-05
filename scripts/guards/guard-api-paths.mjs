/**
 * حارس المسارات: كلّ نداءٍ من الواجهة يقابله مسارٌ في العقد.
 *
 * ─── العيب الذي يكشفه ───
 *
 * المسار في اللوحة نصٌّ حرّ. وحذفُ مسارٍ من Laravel — أو خطأٌ مطبعيّ في
 * حرفٍ واحد — لا يُسقط بناءً ولا اختبارًا: يُسقط زرًّا واحدًا عند مستخدمٍ
 * واحد، بـ٤٠٤ لا يقرأها أحد. وقد وُجد بهذا الفحص أحد عشر نداءً في اللوحة
 * إلى مساراتٍ لا وجود لها في الخادم أصلًا.
 *
 * ─── لماذا سجلُّ دَينٍ لا تجاهل ───
 *
 * النداءات الميّتة القائمة مُثبتة في `known-missing-endpoints.json`. وهذا
 * ليس إعفاءً: السجلّ **مُحصى**، فأيّ نداءٍ ميّتٍ جديد يفشل فورًا، وأيّ
 * مدخلٍ يُصلَح ولا يُحذف من السجلّ يفشل أيضًا. الدَّين مجمّدٌ ومُسمّى، لا
 * مسكوتٌ عنه.
 *
 * ─── ما لا يُفحص ───
 *
 * المسار المبنيّ من متغيّر (`${base}/publish`) لا يُقرأ ساكنًا؛ يُحصى
 * ويُعلَن ولا يُدّعى فحصه.
 */

import { existsSync, readFileSync } from 'node:fs';
import { join } from 'node:path';
import { ROOT, expandRoots, walk, read, rel, lineOf, makeResults, report } from './_lib.mjs';

const { results, pass, fail } = makeResults();

const SPEC = join(ROOT, 'docs/api/openapi.json');

if (!existsSync(SPEC)) {
  fail('spec', 'لا وثيقة OpenAPI — شغّل: node scripts/openapi.mjs');
  process.exit(report('حارس مسارات الواجهة', results));
}

const spec = JSON.parse(readFileSync(SPEC, 'utf8'));

/** بادئة وسيط اللوحة: `api.get('/admin/…')` تعني `/api/v1/admin/…`. */
const PROXY_BASE = '/api/v1';

/*
 * القطعة الديناميكيّة تُطابق أيّ قطعةٍ في العقد — حرفيّةً كانت أو وسيطًا.
 * `api.post(`/admin/reviews/${id}/${action}`)` مسارٌ سليم يقابل
 * `/admin/reviews/{reviewId}/approve`، ورفضُه تبليغٌ كاذب.
 */
/*
 * مسارات Laravel ليست كلّ العقد: تطبيق اللوحة نفسه يخدم معالِجات مسارٍ تحت
 * `apps/admin/app/api/…/route.ts` — و`/intel` منها. الاقتصار على العقد
 * الخلفيّ كان يعدّها «ناقصة» وهي مبنيّةٌ وتعمل، فيمتلئ سجلّ الدَّين بدَينٍ
 * غير قائم. والأخطر أنّ الدَّين الكاذب يُدرّب القارئ على تجاهل السجلّ.
 */
const HANDLERS = join(ROOT, 'apps/admin/app/api');

const handlerSegments = (existsSync(HANDLERS) ? walk(HANDLERS, ['.ts']) : [])
  .filter((file) => file.endsWith('route.ts'))
  .map((file) =>
    ('/api' + rel(file).slice('apps/admin/app/api'.length).replace(/\/route\.ts$/, ''))
      /* `[...path]` و`[id]` كلاهما قطعةٌ تُطابق أيّ قطعة. */
      .split('/')
      .map((segment) => (segment.startsWith('[') ? '{param}' : segment)),
  );

const specSegments = Object.keys(spec.paths)
  .map((path) => path.split('/'))
  .concat(handlerSegments);

/** قطعةٌ ديناميكيّة في النداء أو وسيطٌ في العقد: كلاهما يطابق أيّ قطعة. */
function matches(callSegments) {
  return specSegments.some((segments) => {
    if (segments.length !== callSegments.length) return false;

    return segments.every((segment, i) => {
      const call = callSegments[i];

      return segment.startsWith('{') || call === DYNAMIC || segment === call;
    });
  });
}

const DYNAMIC = '\u0001';

const LEDGER = join(ROOT, 'scripts/guards/known-missing-endpoints.json');
const ledger = existsSync(LEDGER) ? JSON.parse(readFileSync(LEDGER, 'utf8')).entries : [];
const ledgerSet = new Set(ledger.map((e) => e.call));

const roots = expandRoots(['apps/admin', 'apps/storefront']);
const files = roots.flatMap((dir) => walk(dir, ['.ts', '.tsx']));

/*
 * نمطان لا واحد. الاقتصار على `api.*` كان يفوّت **كلّ القراءات**: اللوحة
 * تقرأ عبر `useResource` لا عبر `api.get` مباشرةً، فكان الحارس يفحص
 * الكتابات ويعمى عن الشاشات التي تقرأ من مساراتٍ لا وجود لها.
 */
const CALLS = [
  /\bapi\.(?:get|post|put|patch|delete)\s*(?:<[^>()]*>)?\s*\(\s*([`'"])([^`'"]*)\1/g,
  /\buseResource\s*(?:<[^>()]*>)?\s*\(\s*([`'"])([^`'"]*)\1/g,
];

const unknown = [];
const dynamic = [];
let checked = 0;

for (const file of files) {
  const src = read(file);

  for (const match of [...CALLS].flatMap((pattern) => [...src.matchAll(pattern)])) {
    const raw = match[2];

    if (raw === '') continue;

    // مسارٌ يبدأ بمتغيّر: لا يُقرأ ساكنًا، فيُحصى ولا يُدّعى فحصه.
    if (raw.startsWith('${')) {
      dynamic.push(`${rel(file)}:${lineOf(src, match.index)}`);

      continue;
    }

    checked += 1;

    const segments = (PROXY_BASE + raw.split('?')[0])
      .split('/')
      .map((segment) => (segment.includes('${') ? DYNAMIC : segment));

    if (matches(segments)) continue;

    unknown.push({ call: raw.split('?')[0], where: `${rel(file)}:${lineOf(src, match.index)}` });
  }
}

const unlisted = unknown.filter((u) => !ledgerSet.has(u.call));
const seen = new Set(unknown.map((u) => u.call));
const stale = ledger.filter((e) => !seen.has(e.call));

if (unlisted.length > 0) {
  fail(
    'unknown-paths',
    `نداءٌ إلى مسارٍ ليس في العقد: ${unlisted.map((u) => `${u.call} (${u.where})`).join('، ')}`,
  );
} else {
  pass('unknown-paths', `${checked} نداءً ثابتًا · كلّها في العقد أو في سجلّ الدَّين`);
}

if (stale.length > 0) {
  // مدخلٌ صار مسارُه موجودًا: يُحذف من السجلّ وإلّا تضخّم الإعفاء بلا سبب.
  fail('stale-ledger', `مدخلٌ في سجلّ الدَّين لم يعد ناقصًا: ${stale.map((e) => e.call).join('، ')}`);
} else {
  pass('stale-ledger', `${ledger.length} مدخلًا في سجلّ الدَّين · لا مدخل بائت`);
}

/*
 * الدَّين صفرٌ ويبقى صفرًا.
 *
 * السجلّ كان ثمانية عشر مدخلًا: شاشاتٌ تنادي نطاقاتٍ لم تُبنَ فتردّ ٤٠٤
 * يقرأها المشغّل عطلًا. لم تُخترع النطاقات ولم تُخفَ النداءات — أُغلقت
 * الشاشات ببوّابة قدرةٍ ساكنةٍ وقت البناء، فلا طلبَ يُرسل أصلًا.
 *
 * وإبقاء الحدّ عند صفرٍ هو ما يمنع عودة النمط: لا مدخل يُضاف هنا ليمرّ
 * فحصٌ، ومَن أراد شاشةً بلا نطاق فليُغلقها بالبوّابة.
 */
if (ledger.length > 0) {
  fail('ledger-empty', `سجلّ الدَّين ليس فارغًا: ${ledger.length} مدخلًا — أغلق الشاشة ببوّابة قدرةٍ أو ابنِ النطاق`);
} else {
  pass('ledger-empty', 'سجلّ الدَّين فارغ · لا نداءَ ميّتًا مسموحًا به');
}

pass('dynamic', `${dynamic.length} نداءً مبنيًّا من متغيّر — خارج الفحص الساكن بالإعلان`);

process.exit(report('حارس مسارات الواجهة', results));
