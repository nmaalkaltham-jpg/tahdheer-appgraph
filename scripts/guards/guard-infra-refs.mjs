/**
 * حارس مراجع البنية التحتية.
 *
 * ─── العيب الذي يمنعه ───
 *
 * حذفُ خدمةٍ من Compose أو إعادةُ تسميتها لا يكسر شيئًا وقت التحرير: يبقى
 * Caddyfile يشير إلى المضيف القديم، ويبقى متغيّر بيئةٍ يحمل عنوانًا لا
 * يُحَلّ. ولا يظهر ذلك إلّا عند نشرٍ يفشل أو — أسوأ — عند وكيلٍ يردّ 502
 * على نطاقٍ فرعيّ واحد بينما البقيّة تعمل.
 *
 * وقع هذا فعلًا: `admin.{$DOMAIN}` كان يُحيل إلى `admin:3000` وخدمةُ
 * `admin` غير موجودة في `docker-compose.yml` إطلاقًا.
 *
 * ─── ما يفحصه ───
 *
 * ١ — كلّ `reverse_proxy` و`php_fastcgi` في Caddyfile يشير إلى خدمةٍ قائمة.
 * ٢ — كلّ مضيفٍ داخليّ في متغيّرات بيئة Compose يشير إلى خدمةٍ قائمة.
 * ٣ — كلّ `dockerfile:` في Compose يشير إلى ملفٍّ موجود.
 * ٤ — كلّ `COPY --from=build` لمسار بناءٍ في Dockerfile لتطبيق Next لا
 *     يطلب `standalone` بينما `next.config` يعطّله.
 * ٥ — الطوابير: كلّ طابورٍ تُرسَل إليه مهمّةٌ يستهلكه العامل، وما يستهلكه
 *     العامل هو ما تقيسه اللوحة (`queue.monitored`). اختبارُ PHP لهذا يُتخطّى
 *     دائمًا لأنّ الحاوية لا ترى ملفّ compose — فمكانُه هنا.
 *
 * الفحص نصّيّ عمدًا: تحليلُ YAML الكامل يُدخل تبعيّةً لحارسٍ يجب أن يعمل
 * في أبسط بيئة ممكنة، والأنماط هنا مستقرّة بما يكفي.
 */

import { readFileSync, existsSync } from 'node:fs';
import { join } from 'node:path';
import { ROOT, makeResults, report, walk } from './_lib.mjs';

const { results, pass, fail } = makeResults();
const COMPOSE_DIR = join(ROOT, 'infrastructure/docker');

/** مضيفون خارج Compose بطبيعتهم — لا يُطالَبون بخدمة. */
const EXTERNAL_HOSTS = new Set(['localhost', '127.0.0.1', '0.0.0.0', 'host.docker.internal']);

const read = (p) => (existsSync(p) ? readFileSync(p, 'utf8') : null);

/** أسماء الخدمات في ملفّ compose — المفاتيح على مستوى مسافتين تحت `services:`. */
function servicesIn(text) {
  const names = new Set();
  let inServices = false;

  for (const line of text.split('\n')) {
    if (/^services:\s*$/.test(line)) {
      inServices = true;
      continue;
    }
    if (inServices && /^[a-zA-Z]/.test(line)) break; // مفتاح جذر آخر
    const m = inServices ? line.match(/^ {2}([a-z0-9][a-z0-9_-]*):\s*$/) : null;
    if (m) names.add(m[1]);
  }

  return names;
}

const composeFiles = ['docker-compose.yml', 'docker-compose.local.yml', 'docker-compose.traefik.yml']
  .map((f) => ({ name: f, path: join(COMPOSE_DIR, f) }))
  .filter((f) => existsSync(f.path));

if (composeFiles.length === 0) {
  fail('compose', 'لا ملفّ compose واحد — لا شيء يُفحص مقابله');
  process.exit(report('حارس مراجع البنية التحتية', results));
}

/*
 * الخدمات تُفحص **لكلّ ملفٍّ على حدة** لا كاتّحاد.
 *
 * الاتّحاد كان يُخفي العيب بالضبط: `admin` معرَّفة في تراكب Traefik وحده،
 * فيبدو مرجعُ Caddyfile سليمًا بينما النشر الأساسيّ — وهو الذي يشغّل Caddy —
 * لا يعرفها. المرجع يجب أن يُحَلّ في الطوبولوجيا التي يعمل فيها.
 */
const servicesByFile = new Map(
  composeFiles.map((f) => [f.name, servicesIn(readFileSync(f.path, 'utf8'))]),
);

/** الملفّ الذي يشغّل Caddy فعلًا هو الذي تُقاس مراجعه عليه. */
const caddyFile = composeFiles.find((f) => servicesByFile.get(f.name).has('caddy'));

pass(
  'compose',
  composeFiles.map((f) => `${f.name}: ${servicesByFile.get(f.name).size}`).join(' · '),
);

// ── ١ — مراجع Caddy ─────────────────────────────────────────────────────────
const caddyPath = join(ROOT, 'infrastructure/caddy/Caddyfile');
const caddy = read(caddyPath);

if (caddy === null) {
  pass('caddy', 'لا Caddyfile — لا شيء يُفحص');
} else {
  const refs = [...caddy.matchAll(/^\s*(?:reverse_proxy|php_fastcgi)\s+([a-z0-9][a-z0-9_.-]*):(\d+)/gm)]
    .map((m) => ({ host: m[1], port: m[2] }));

  const known = caddyFile === undefined ? new Set() : servicesByFile.get(caddyFile.name);
  const broken = refs.filter((r) => !EXTERNAL_HOSTS.has(r.host) && !known.has(r.host));

  if (broken.length > 0) {
    fail(
      'caddy',
      `يشير إلى خدمةٍ غير موجودة في Compose: ${broken.map((b) => `${b.host}:${b.port}`).join('، ')}`,
    );
  } else {
    pass('caddy', `${refs.length} مرجعًا تُحَلّ داخل ${caddyFile?.name ?? '—'}`);
  }
}

// ── ٢ — مضيفون داخليّون في متغيّرات بيئة Compose ────────────────────────────
const badEnvHosts = [];
for (const file of composeFiles) {
  const text = readFileSync(file.path, 'utf8');
  const services = servicesIn(text);

  for (const m of text.matchAll(/https?:\/\/([a-z0-9][a-z0-9_-]*):(\d+)/g)) {
    const host = m[1];
    if (EXTERNAL_HOSTS.has(host) || services.has(host)) continue;
    badEnvHosts.push(`${file.name}: ${host}:${m[2]}`);
  }
}

if (badEnvHosts.length > 0) {
  fail('env-hosts', `مضيفٌ داخليّ لا خدمة له في ملفّه: ${badEnvHosts.join(' · ')}`);
} else {
  pass('env-hosts', 'كلّ مضيفٍ داخليّ في متغيّرات البيئة له خدمة في ملفّه');
}

// ── ٣ — مسارات Dockerfile المُشار إليها من Compose ─────────────────────────
const missingDockerfiles = [];
for (const file of composeFiles) {
  const text = readFileSync(file.path, 'utf8');
  for (const m of text.matchAll(/dockerfile:\s*(\S+)/g)) {
    if (!existsSync(join(ROOT, m[1]))) missingDockerfiles.push(`${file.name}: ${m[1]}`);
  }
}

if (missingDockerfiles.length > 0) {
  fail('dockerfile', `مسارُ بناءٍ غير موجود: ${missingDockerfiles.join(' · ')}`);
} else {
  pass('dockerfile', 'كلّ مسار بناءٍ مُشار إليه موجود');
}

// ── ٤ — standalone في Dockerfile مقابل next.config ──────────────────────────
const standaloneMismatch = [];
for (const app of ['admin', 'storefront']) {
  const dockerfile = read(join(ROOT, `apps/${app}/Dockerfile`));
  const config = read(join(ROOT, `apps/${app}/next.config.ts`));
  if (dockerfile === null || config === null) continue;

  /*
   * أسطر التعليق تُستبعَد: هذا الملفّ نفسه يشرح **لماذا** أُزيل standalone،
   * وفحصٌ نصّيّ ساذج كان يقرأ الشرح تعليماتٍ ويفشل على التوثيق الصحيح.
   */
  const effective = dockerfile
    .split('\n')
    .filter((line) => !/^\s*#/.test(line))
    .join('\n');
  const copiesStandalone = /\.next\/standalone/.test(effective);
  // مُفعَّل فقط إذا وُجد السطر غير مُعلَّق.
  const enablesStandalone = /^\s*output:\s*['"]standalone['"]/m.test(config);

  if (copiesStandalone && !enablesStandalone) {
    standaloneMismatch.push(app);
  }
}

if (standaloneMismatch.length > 0) {
  fail(
    'standalone',
    `Dockerfile ينسخ ‎.next/standalone‎ و‎next.config‎ لا يُفعّله — البناء يفشل عند COPY: ${standaloneMismatch.join('، ')}`,
  );
} else {
  pass('standalone', 'لا تعارض بين نسخ standalone وتفعيله');
}

/* ─── ٥ — الطوابير: المُرسَل ⊆ المُستهلَك = المُقاس ─── */
{
  const list = (csv) => csv.split(',').map((q) => q.trim()).filter(Boolean);
  const LARAVEL = join(ROOT, 'backend/laravel');
  const monitoredSrc = read(join(LARAVEL, 'config/queue.php')) ?? '';
  const monitoredMatch = monitoredSrc.match(/'monitored'\s*=>\s*env\(\s*'QUEUE_MONITORED'\s*,\s*'([^']*)'/);

  // كلّ `onQueue('x')`، ومعه `onQueue((string) config('…', 'x'))` بقيمته الافتراضيّة.
  const dispatched = new Map();
  for (const file of walk(join(LARAVEL, 'app'), ['.php'])) {
    for (const m of readFileSync(file, 'utf8').matchAll(
      /onQueue\(\s*(?:\(string\)\s*)?(?:config\(\s*'[^']+'\s*,\s*)?'([A-Za-z0-9_-]+)'/g,
    )) {
      dispatched.set(m[1], file.slice(ROOT.length + 1));
    }
  }

  const problems = [];
  if (!monitoredMatch) problems.push("config/queue.php بلا `'monitored' => env('QUEUE_MONITORED', '…')`");
  const monitored = monitoredMatch ? list(monitoredMatch[1]) : [];

  let workers = 0;
  for (const f of composeFiles) {
    for (const m of readFileSync(f.path, 'utf8').matchAll(/queue:work\s+--queue=([A-Za-z0-9,_-]+)/g)) {
      workers += 1;
      const consumed = list(m[1]);
      for (const [queue, where] of dispatched) {
        if (!consumed.includes(queue) && queue !== 'default') {
          problems.push(`${f.name}: العامل لا يستهلك «${queue}» (يُرسَل إليه في ${where})`);
        }
      }
      if (monitoredMatch && consumed.join(',') !== monitored.join(',')) {
        problems.push(`${f.name}: العامل يستهلك ${consumed.join(',')} واللوحة تقيس ${monitored.join(',')}`);
      }
    }
  }
  if (workers === 0) problems.push('لا سطر `queue:work --queue=` في أيّ ملفّ compose');

  if (problems.length > 0) fail('queues', problems.join(' · '));
  else pass('queues', `${workers} عاملًا · ${dispatched.size} طابورًا مُرسَلًا · كلّها مُستهلَكة ومُقاسة`);
}

process.exit(report('حارس مراجع البنية التحتية', results));
