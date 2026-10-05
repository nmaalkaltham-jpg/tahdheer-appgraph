#!/usr/bin/env node
/**
 * حارس خطوط نظام التصميم — لا وزنَ لا يشحنه الخطّ.
 *
 * أصلُ الخطّ يسكن **داخل الحزمة** (`packages/ui/fonts/tajawal.css`). الحارس يتحقّق
 * من وجوده بوجوهه كاملةً، ثمّ من أنّ كلّ `font-weight` في أوراق `packages/ui`
 * و`packages/design-system` وزنٌ يشحنه الخطّ فعلًا — فالوزن غير المشحون يصطنعه
 * المتصفّح.
 *
 * التشغيل: node scripts/guards/guard-design-fonts.mjs · الخروج: 0 سليم · 1 فشل.
 */

import { existsSync, readdirSync } from 'node:fs';
import { join } from 'node:path';
import { ROOT, read, rel, makeResults, report } from './_lib.mjs';

const { results, pass, fail } = makeResults();

/** الأوزان التي يشحنها Tajawal فعلًا — مشتقّة من الأصل لا مكتوبة هنا. */
const FONT_SRC = join(ROOT, 'packages/ui/fonts/tajawal.css');
/** عدد وجوه الخطّ المتوقَّع في المصدر. */
const EXPECTED_FACES = 10;
const FAMILY = 'Tajawal';

const facesOf = (css) => css.match(/@font-face\s*\{[^}]*\}/gs) ?? [];
const weightsOf = (css) =>
  new Set(facesOf(css).map((f) => f.match(/font-weight:\s*(\d+)/)?.[1]).filter(Boolean));

/* ─── ١: مصدر الخطّ موجود داخل الحزمة ─── */
let shipped = new Set();
{
  if (!existsSync(FONT_SRC)) {
    fail('FONT_SOURCE', `${rel(FONT_SRC)} مفقود — الأصل يجب أن يسكن داخل الحزمة`);
  } else {
    shipped = weightsOf(read(FONT_SRC));
    const faces = facesOf(read(FONT_SRC)).length;
    if (faces !== EXPECTED_FACES) fail('FONT_SOURCE', `المصدر يحمل ${faces} وجهًا لا ${EXPECTED_FACES}`);
    else pass('FONT_SOURCE', `${rel(FONT_SRC)} · ${faces} وجهًا · أوزان ${[...shipped].sort().join('، ')}`);
  }
}

/* ─── ٢: كل وزنٍ مطلوبٍ في المصدر الحيّ يشحنه الخطّ ─── */
{
  const roots = ['packages/ui/src', 'packages/design-system/src'].map((d) => join(ROOT, d)).filter(existsSync);
  const files = [];
  const walkCss = (d) => {
    for (const e of readdirSync(d, { withFileTypes: true })) {
      const p = join(d, e.name);
      if (e.isDirectory()) walkCss(p);
      else if (/\.(css|scss)$/.test(e.name)) files.push(p);
    }
  };
  roots.forEach(walkCss);

  const offenders = [];
  for (const f of files) {
    const src = read(f);
    for (const m of src.matchAll(/font-weight:\s*(\d{3})/g)) {
      if (shipped.size && !shipped.has(m[1])) {
        offenders.push(`${rel(f)}:${src.slice(0, m.index).split('\n').length} → ${m[1]}`);
      }
    }
  }
  if (!shipped.size) fail('TYPOGRAPHY', 'تعذّر اشتقاق الأوزان المشحونة — لا حكم');
  else if (offenders.length) {
    fail('TYPOGRAPHY', `وزنٌ لا يشحنه ${FAMILY} (يُصطنع في المتصفّح): ${offenders.join(' · ')}`);
  } else {
    pass('TYPOGRAPHY', `${files.length} ملفًّا · كل وزنٍ ضمن ${[...shipped].sort().join('، ')}`);
  }
}

process.exit(report('حارس خطوط نظام التصميم', results));
