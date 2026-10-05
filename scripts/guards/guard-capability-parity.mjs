/**
 * حارس تكافؤ القدرات: لا قدرةَ بلا تصنيف، ولا شاشةَ بلا قدرة.
 *
 * ─── العيب الذي يمنعه ───
 *
 * اللوحة راكمت ثماني شاشاتٍ تقرأ من نقاطٍ لا وجود لها في الخادم، وعمليّاتٍ
 * في الخادم بلا سطحٍ يُشغّلها. وكلا النوعين لا يُصدر عطلًا يُلاحَظ:
 *
 *   · شاشةٌ بلا واجهة خلفيّة تقول «تعذّر جلب البيانات» — فيقرؤها المشغّل
 *     عطلًا مؤقّتًا ويُعيد المحاولة ويبلّغ، ثمّ يفقد الثقة في الرسائل كلّها.
 *   · نقطةُ نهايةٍ بلا شاشة تعني قدرةً مدفوعةَ الثمن لا يعرف أحدٌ أنّها
 *     موجودة — وحين تُطلب تُبنى من جديد.
 *
 * ─── العقد ───
 *
 * `docs/capabilities.json` يصنّف كلّ قدرة:
 *
 *   COMPLETE     نقاط نهاية + شاشة تعمل.
 *   ADMIN_UI     شاشة تؤلّف بيانات قدراتٍ أخرى، بلا عمليّاتٍ خاصّة.
 *   API_ONLY     نقاط نهاية بلا شاشة — بقرارٍ مكتوب.
 *   SYSTEM_ONLY  لا سطح مشغّل بالتصميم (جلسة، مجدولات).
 *   DEFERRED     غير مبنيّ. شاشتُه — إن وُجدت — **تحمل** `CapabilityGate`.
 *
 * ─── لماذا الإعلان يُفرَض في الاتّجاهين ───
 *
 * المؤجَّلة يجب أن تحمل الإعلان لئلّا تكذب على المشغّل. والمكتملة يجب ألّا
 * تحمله لئلّا يبقى تحذيرٌ بائتٌ يكذب في الاتّجاه المعاكس — وهو ما يقع دائمًا
 * حين تُبنى الواجهة الخلفيّة ولا ينتبه أحدٌ إلى اللافتة القديمة.
 */

import { existsSync, readFileSync, readdirSync, statSync } from 'node:fs';
import { join } from 'node:path';
import { ROOT, read, makeResults, report } from './_lib.mjs';

const { results, pass, fail } = makeResults();

const REGISTRY = join(ROOT, 'docs/capabilities.json');
const SPEC = join(ROOT, 'docs/api/openapi.json');
const SCREENS_ROOT = join(ROOT, 'apps/admin/app/(workspaces)');
const NAV = join(ROOT, 'apps/admin/lib/nav.ts');

for (const [label, path] of [['السجلّ', REGISTRY], ['العقد', SPEC]]) {
  if (! existsSync(path)) {
    fail('inputs', `${label} غير موجود: ${path}`);
    process.exit(report('حارس تكافؤ القدرات', results));
  }
}

const registry = JSON.parse(readFileSync(REGISTRY, 'utf8'));
const spec = JSON.parse(readFileSync(SPEC, 'utf8'));
const capabilities = registry.capabilities ?? [];

const VALID = new Set(['COMPLETE', 'ADMIN_UI', 'API_ONLY', 'SYSTEM_ONLY', 'DEFERRED']);
const NEEDS_SCREEN = new Set(['COMPLETE', 'ADMIN_UI']);
const NO_SCREEN = new Set(['API_ONLY', 'SYSTEM_ONLY']);

// ── ١ — التصنيف معلَن وصالح ─────────────────────────────────────────────
const badClass = capabilities.filter((c) => ! VALID.has(c.classification));

if (badClass.length > 0) {
  fail('classification', `تصنيفٌ غير معروف: ${badClass.map((c) => `${c.key}=${c.classification}`).join('، ')}`);
} else {
  pass('classification', `${capabilities.length} قدرة · كلّها مصنّفة`);
}

// ── ٢ — كلّ عمليّة مُعلنة موجودة في العقد ────────────────────────────────
const specOperations = new Set();
const mutatingAdmin = new Set();

for (const [path, methods] of Object.entries(spec.paths)) {
  for (const [method, operation] of Object.entries(methods)) {
    if (! path.startsWith('/api/v1/admin/')) continue;

    specOperations.add(operation.operationId);

    if (['post', 'put', 'patch', 'delete'].includes(method)) {
      mutatingAdmin.add(operation.operationId);
    }
  }
}

const ghosts = capabilities.flatMap((c) =>
  (c.operations ?? []).filter((id) => ! specOperations.has(id)).map((id) => `${c.key} → ${id}`));

if (ghosts.length > 0) {
  // عمليّةٌ في السجلّ وليست في الخادم: السجلّ يَعِد بما لا يوجد.
  fail('declared-operations', `عمليّةٌ معلَنة ليست في العقد: ${ghosts.join('، ')}`);
} else {
  pass('declared-operations', 'كلّ عمليّةٍ معلَنة موجودة في العقد');
}

// ── ٣ — كلّ عمليّة إدارة في العقد مُطالَبٌ بها مرّةً واحدة ────────────────
const owner = new Map();
const duplicated = [];

for (const capability of capabilities) {
  for (const id of capability.operations ?? []) {
    if (owner.has(id)) {
      duplicated.push(`${id} (${owner.get(id)} · ${capability.key})`);

      continue;
    }

    owner.set(id, capability.key);
  }
}

const unclaimed = [...specOperations].filter((id) => ! owner.has(id));

if (unclaimed.length > 0) {
  /*
   * هذه هي فجوة «خلفيّةٌ بلا واجهة» بعينها: قدرةٌ في الخادم لا يعرف أحدٌ
   * أنّها موجودة. تُصنَّف — ولو `API_ONLY` — أو تُبنى لها شاشة.
   */
  fail('unclaimed-operations', `عمليّة إدارةٍ بلا قدرة: ${unclaimed.join('، ')}`);
} else if (duplicated.length > 0) {
  fail('unclaimed-operations', `عمليّةٌ تطالب بها قدرتان: ${duplicated.join('، ')}`);
} else {
  pass('unclaimed-operations', `${specOperations.size} عمليّة إدارة · لكلٍّ قدرةٌ واحدة (${mutatingAdmin.size} منها تغيّر حالة)`);
}

// ── ٤ — الشاشات المعلَنة موجودة، والتصنيف يطابق وجودها ──────────────────
const screenFile = (screen) => join(SCREENS_ROOT, screen.replace(/^\//, ''), 'page.tsx');

const missingScreens = [];
const forbiddenScreens = [];

for (const capability of capabilities) {
  const screens = capability.screens ?? [];

  if (NEEDS_SCREEN.has(capability.classification) && screens.length === 0) {
    missingScreens.push(`${capability.key} (${capability.classification} بلا شاشة)`);
  }

  if (NO_SCREEN.has(capability.classification) && screens.length > 0) {
    forbiddenScreens.push(`${capability.key} (${capability.classification} يُعلن شاشة)`);
  }

  for (const screen of screens) {
    if (! existsSync(screenFile(screen))) {
      missingScreens.push(`${capability.key} → ${screen}`);
    }
  }
}

if (missingScreens.length > 0 || forbiddenScreens.length > 0) {
  fail('screens', [...missingScreens, ...forbiddenScreens].join('، '));
} else {
  pass('screens', 'كلّ شاشةٍ معلَنة موجودة، وكلّ تصنيفٍ يطابق وجودها');
}

// ── ٥ — كلّ شاشة على القرص تطالب بها قدرة ───────────────────────────────
const onDisk = existsSync(SCREENS_ROOT)
  ? [...walkScreens(SCREENS_ROOT)].sort()
  : [];

const declared = capabilities.flatMap((c) => c.screens ?? []);

const orphans = onDisk.filter((screen) => ! declared.some((d) => screen === d || screen.startsWith(`${d}/`)));

if (orphans.length > 0) {
  // شاشةٌ لا تنتمي إلى قدرة: لا أحد يعرف ماذا تفعل ولا هل تعمل.
  fail('orphan-screens', `شاشةٌ بلا قدرة: ${orphans.join('، ')}`);
} else {
  pass('orphan-screens', `${onDisk.length} شاشة · لكلٍّ قدرةٌ تطالب بها`);
}

// ── ٦ — الإعلان يُفرَض في الاتّجاهين ────────────────────────────────────
/*
 * الإعلان صار بوّابة.
 *
 * `UnbuiltNotice` كان يُعلن العجز **بعد** أن تُرسل الشاشة طلبها وتتلقّى ٤٠٤.
 * `CapabilityGate` يقرأ سجلًّا مولَّدًا وقت البناء فيُغلق قبل فتح أيّ
 * اتّصال — والفرق ليس تجميليًّا: الأوّل يستعمل رمز خطأٍ كشفًا عن قدرة،
 * والثاني لا يحتاج إلى الخادم ليعرف.
 */
const NOTICE = 'CapabilityGate';
const missingNotice = [];
const staleNotice = [];

for (const capability of capabilities) {
  for (const screen of capability.screens ?? []) {
    const file = screenFile(screen);

    if (! existsSync(file)) continue;

    const marked = read(file).includes(NOTICE);

    if (capability.classification === 'DEFERRED' && ! marked) {
      missingNotice.push(`${screen} (مؤجَّلة بلا إعلان)`);
    }

    if (capability.classification !== 'DEFERRED' && marked) {
      staleNotice.push(`${screen} (${capability.classification} تحمل إعلانًا بائتًا)`);
    }
  }
}

if (missingNotice.length > 0 || staleNotice.length > 0) {
  fail('unbuilt-notices', [...missingNotice, ...staleNotice].join('، '));
} else {
  pass('unbuilt-notices', 'المؤجَّلة تُعلن، والمكتملة لا تحمل إعلانًا بائتًا');
}

// ── ٧ — المؤجَّلة بلا عمليّات ───────────────────────────────────────────
const deferredWithOps = capabilities
  .filter((c) => c.classification === 'DEFERRED' && (c.operations ?? []).length > 0)
  .map((c) => c.key);

if (deferredWithOps.length > 0) {
  fail('deferred-clean', `مؤجَّلةٌ تملك عمليّات — صنّفها COMPLETE: ${deferredWithOps.join('، ')}`);
} else {
  pass('deferred-clean', 'لا قدرةَ مؤجَّلةٍ تملك عمليّاتٍ في العقد');
}

// ── ٨ — كلّ وجهة تنقّلٍ تصل إلى شاشة ────────────────────────────────────
if (! existsSync(NAV)) {
  fail('navigation', 'سجلّ التنقّل غير موجود');
} else {
  /*
   * تُقتطع سلسلة الاستعلام: `/studio/design?t=pages` وجهةٌ مشروعة تفتح
   * تبويبًا داخل شاشةٍ قائمة، ومقارنتُها بالمسار كاملًا تبليغٌ كاذب.
   */
  const hrefs = [...read(NAV).matchAll(/href:\s*'([^']+)'/g)].map((m) => m[1].split('?')[0]);
  const dead = [...new Set(hrefs.filter((href) => href.startsWith('/') && ! onDisk.includes(href)))];

  if (dead.length > 0) {
    // وجهةٌ ميّتة: نقرةٌ تؤدّي إلى ٤٠٤ داخل اللوحة نفسها.
    fail('navigation', `وجهةٌ لا تقابلها شاشة: ${dead.join('، ')}`);
  } else {
    pass('navigation', `${hrefs.length} وجهة تنقّل · كلّها تصل إلى شاشة`);
  }
}

process.exit(report('حارس تكافؤ القدرات', results));

/** يمشي شجرة الأسطح ويُعيد مسارات التوجيه (لا مسارات الملفّات). */
function* walkScreens(dir, prefix = '') {
  for (const entry of readdirSync(dir)) {
    const full = join(dir, entry);

    if (entry === 'page.tsx') {
      /*
       * المسار المحوِّل ليس شاشة.
       *
       * بعد دمج الأسطح صارت المسارات القديمة ملفّاتٍ جسمُها `redirect()`
       * وحده — تُبقي الروابط المحفوظة تعمل ولا تعرض شيئًا. وعدُّها شاشةً
       * يطلب لها قدرةً في السجلّ، فيمتلئ السجلّ بقدراتٍ لأسطحَ لا وجود
       * لها — وهو تضخّمٌ يُفقد السجلّ معناه.
       *
       * والفحص على الجسم لا على الاسم: ملفٌّ يستورد `redirect` ويستدعيه
       * ولا يُصيّر عنصرًا واحدًا.
       */
      const body = readFileSync(full, 'utf8');
      const forwards = /from 'next\/navigation'/.test(body)
        && /\bredirect\(/.test(body)
        && ! /<[A-Za-z]/.test(body);

      if (! forwards) yield prefix === '' ? '/' : prefix;

      continue;
    }

    // `_shared` و`_tabs` مجلّدات خاصّة لا مسارات — Next يتجاهل ما يبدأ بـ«_».
    if (entry.startsWith('_') || entry.startsWith('.')) continue;

    if (statSync(full).isDirectory()) {
      yield* walkScreens(full, `${prefix}/${entry}`);
    }
  }
}
