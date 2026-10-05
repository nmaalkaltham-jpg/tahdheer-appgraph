'use client';
// UNIT: PAGE-ANALYTICS-OS-FLOWS
// FILE: page.tsx
// ROUTE: /analytics/os/flows
// STYLE: none
// PARENT: none
// USES: DERIVE, LOOPS-PANEL, RESOURCE, _GOVERNANCE
// API: none
// API-VIA: DERIVE, LOOPS-PANEL
// MAP: docs/PROJECT_MAP.md

/**
 * الأتمتة — Workflows بلا مشغّل: الحاكمية الحقيقية بدل مسرح التشغيل.
 *
 * لا مشغّل أتمتة في هذا النظام (growth/_governance.ts): كل مفتاح هنا
 * معطَّل ظاهرًا وبجانبه ما ينقص حلقته بالاسم — «مفتاح لا يشغّل شيئًا أسوأ
 * من غيابه». منشئ القواعد مثال توضيحي معلَن لا محرّك، وعمودا التقدم وROI
 * «لا سجل تشغيل» لأن لا شيء شُغِّل. الحقيقة المجدولة الوحيدة موثَّقة:
 * sitemap:audit يوميًا 03:40 (backend/laravel/routes/console.php) — جدولة
 * خادم لا مفتاح لوحة.
 */

import type { CSSProperties } from 'react';
import { PageHeader } from '../../../../../components/resource';
import { FORBIDDEN, LOOP_GROUPS, TECH_LIMITS, TIER_LABELS, type Tier } from '../../../growth/_governance';
import { OsNav } from '../_lib/derive';
import { GrowthLoopsPanel } from '../../../growth/loops-panel';

const S: Record<string, CSSProperties> = {
  page: { display: 'flex', flexDirection: 'column', gap: 13 },
  row: { display: 'flex', alignItems: 'center', gap: 9, flexWrap: 'wrap' },
  h1: { margin: 0, fontSize: 20, fontWeight: 900, color: 'var(--ink)' },
  h2: { margin: 0, fontSize: 12.5, fontWeight: 900, color: 'var(--ink)' },
  dim: { fontSize: 11.5, color: 'var(--apb-dim)' },
  card: {
    border: '1px solid var(--apb-border)',
    borderRadius: 14,
    background: 'var(--surface)',
    paddingBlock: 12,
    paddingInline: 16,
  },
  dark: {
    border: '1px solid var(--apb-border)',
    borderRadius: 15,
    background: 'var(--chrome)',
    color: 'var(--chrome-text)',
    paddingBlock: 15,
    paddingInline: 18,
  },
  ruleChipKey: {
    paddingBlock: 5,
    paddingInline: 11,
    borderRadius: 8,
    background: 'var(--tint-gold-16)',
    color: 'var(--apb-gold-chip-text)',
  },
  ruleChipVal: {
    paddingBlock: 5,
    paddingInline: 11,
    borderRadius: 8,
    background: 'var(--chrome-08)',
  },
  ruleChipThen: {
    paddingBlock: 5,
    paddingInline: 11,
    borderRadius: 8,
    background: 'var(--apb-green-chip-border)',
    color: 'color-mix(in srgb, var(--apb-green-chip-text) 75%, var(--cc-text))',
  },
  ruleChipTail: {
    paddingBlock: 5,
    paddingInline: 11,
    borderRadius: 8,
    background: 'var(--apb-green-chip-bg)',
    color: 'var(--apb-green-chip-text)',
  },
  exampleChip: {
    paddingBlock: 2,
    paddingInline: 9,
    borderRadius: 6,
    background: 'var(--tint-gold-16)',
    border: '1px solid var(--apb-gold-chip-border)',
    color: 'var(--apb-gold-chip-text)',
    fontSize: 11.5,
    fontWeight: 900,
  },
  switchBtn: {
    display: 'inline-flex',
    alignItems: 'center',
    justifyContent: 'center',
    minInlineSize: 44,
    minBlockSize: 44,
    border: 0,
    background: 'transparent',
    cursor: 'not-allowed',
    paddingBlock: 0,
    paddingInline: 0,
  },
  track: {
    position: 'relative',
    inlineSize: 32,
    blockSize: 17,
    borderRadius: 999,
    background: 'var(--apb-switch-track)',
    display: 'inline-block',
  },
  knob: {
    position: 'absolute',
    insetBlockStart: 2,
    insetInlineStart: 2,
    inlineSize: 13,
    blockSize: 13,
    borderRadius: '50%',
    background: 'var(--cc-on-tone)',
    boxShadow: 'var(--e-1)',
  },
  noLog: {
    paddingBlock: 2,
    paddingInline: 9,
    borderRadius: 999,
    background: 'var(--apb-neutral)',
    color: 'var(--text-muted)',
    fontSize: 11.5,
    fontWeight: 900,
  },
  reason: { fontSize: 11.5, lineHeight: 1.7, color: 'var(--warning-text)', fontWeight: 800 },
  list: { margin: 0, marginBlockStart: 6, paddingInlineStart: 18, fontSize: 11.5, lineHeight: 1.95, color: 'var(--text-muted)' },
  blockedBtn: {
    minBlockSize: 44,
    paddingInline: 14,
    border: '1px solid var(--chrome-14)',
    borderRadius: 9,
    background: 'var(--chrome-08)',
    color: 'var(--apb-chrome-faint)',
    fontSize: 11.5,
    fontWeight: 900,
    cursor: 'not-allowed',
  },
};

const TIER_CHIP: Record<Tier['key'], CSSProperties> = {
  auto: { background: 'var(--success-chip-bg)', color: 'var(--success-chip-fg)' },
  threshold: { background: 'var(--warning-bg)', color: 'var(--warning-text)' },
  proposal: { background: 'var(--info-bg)', color: 'var(--info)' },
};

const RULE_EXAMPLE: { kind: 'key' | 'val' | 'then' | 'tail'; text: string }[] = [
  { kind: 'key', text: 'IF' },
  { kind: 'val', text: 'الانطباعات > ١٠٬٠٠٠' },
  { kind: 'key', text: 'AND' },
  { kind: 'val', text: 'CTR < ١٪' },
  { kind: 'key', text: 'AND' },
  { kind: 'val', text: 'المركز ≤ ٨' },
  { kind: 'then', text: 'THEN' },
  { kind: 'tail', text: 'Workflow تحسين CTR (بموافقة)' },
];

export default function FlowsPage() {
  return (
    <div style={S.page}>
      <PageHeader trail="Analytics Pro — الأتمتة" title="الأتمتة" />
      <OsNav current="flows" />

      <div style={S.row}>
        <h1 style={S.h1}>محرك الأتمتة — Workflows</h1>
        <span style={{ ...S.dim, marginInlineStart: 'auto' }}>
          لا مشغّل أتمتة في هذا النظام — كل مفتاح معطَّل وبجانبه ما ينقص حلقته بالاسم
        </span>
      </div>

      <section style={S.dark} aria-labelledby="rule-builder-head">
        <div style={S.row}>
          <h2 id="rule-builder-head" style={{ margin: 0, fontSize: 12.5, fontWeight: 900, color: 'var(--gold-text)' }}>
            منشئ القواعد
          </h2>
          <span style={S.exampleChip}>صيغة القاعدة المعتمدة — مثال توضيحي؛ لا محرّك قواعد بعد</span>
        </div>
        <div
          style={{
            display: 'flex',
            gap: 6,
            flexWrap: 'wrap',
            alignItems: 'center',
            marginBlockStart: 8,
            fontSize: 12,
            fontWeight: 800,
            fontFamily: 'ui-monospace, monospace',
          }}
        >
          {RULE_EXAMPLE.map((chip, index) => (
            <span
              key={`${chip.text}-${index}`}
              style={
                chip.kind === 'key'
                  ? S.ruleChipKey
                  : chip.kind === 'val'
                    ? S.ruleChipVal
                    : chip.kind === 'then'
                      ? S.ruleChipThen
                      : S.ruleChipTail
              }
            >
              {chip.text}
            </span>
          ))}
        </div>
        <div style={{ ...S.row, marginBlockStart: 10 }}>
          <button type="button" style={S.blockedBtn} disabled aria-describedby="rule-save-why">
            احفظ القاعدة
          </button>
          <span id="rule-save-why" style={{ fontSize: 11.5, fontWeight: 800, color: 'var(--apb-gold-chip-text)' }}>
            الحفظ معطَّل — لا محرّك قواعد يقرأ المحفوظ ولا مجدول ينفّذه، والأرقام أعلاه أمثلة صياغة لا عتبات
            مقيسة (مصدر الانطباعات نفسه غير موصول).
          </span>
        </div>
      </section>

      <section style={S.card}>
        <div style={S.row}>
          <h2 style={S.h2}>الجدولة الحقيقية الوحيدة في الخادم</h2>
          <span style={S.noLog}>جدولة خادم — ليست قابلة للتبديل من هنا</span>
        </div>
        <p style={{ margin: 0, marginBlockStart: 6, fontSize: 11.5, lineHeight: 1.8, color: 'var(--text-muted)' }}>
          <bdi dir="ltr">sitemap:audit</bdi> يعمل يوميًا الساعة <bdi dir="ltr">03:40</bdi> — مهمة مجدولة معرَّفة في{' '}
          <bdi dir="ltr">backend/laravel/routes/console.php</bdi>. هذه حقيقة توثَّق لا مفتاح يُقلب: لا نقطة نهاية
          تبدّل جدولتها من اللوحة.
        </p>
      </section>

      {LOOP_GROUPS.map((group) => (
        <div key={group.name} style={{ display: 'flex', flexDirection: 'column', gap: 9 }}>
          <h2 style={{ ...S.h2, fontSize: 13 }}>{group.name}</h2>
          {group.loops.map((loop) => {
            const reasonId = `flow-reason-${loop.key}`;
            return (
              <section key={loop.key} style={S.card}>
                <div style={S.row}>
                  <button
                    type="button"
                    role="switch"
                    aria-checked={false}
                    aria-disabled={true}
                    aria-label={loop.name}
                    aria-describedby={reasonId}
                    style={S.switchBtn}
                  >
                    <span style={S.track} aria-hidden="true">
                      <span style={S.knob} />
                    </span>
                  </button>
                  <b style={{ fontSize: 12.5, color: 'var(--ink)' }}>{loop.name}</b>
                  <span
                    style={{
                      paddingBlock: 2,
                      paddingInline: 9,
                      borderRadius: 999,
                      fontSize: 11.5,
                      fontWeight: 900,
                      ...TIER_CHIP[loop.tier],
                    }}
                  >
                    {TIER_LABELS[loop.tier]}
                  </span>
                  <span style={{ ...S.noLog, marginInlineStart: 'auto' }}>لا سجل تشغيل</span>
                  <span style={S.noLog}>ROI: لا سجل تشغيل</span>
                </div>
                <p style={{ margin: 0, marginBlockStart: 6, fontSize: 11.5, lineHeight: 1.8, color: 'var(--apb-soft)' }}>
                  {loop.description}
                </p>
                <p id={reasonId} style={{ ...S.reason, margin: 0, marginBlockStart: 4 }}>
                  ينقصها: {loop.blockedBy}
                </p>
              </section>
            );
          })}
        </div>
      ))}

      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(280px, 1fr))', gap: 11 }}>
        <section style={S.card}>
          <h2 style={S.h2}>ممنوع على الأتمتة — بلا استثناء</h2>
          <ul style={S.list}>
            {FORBIDDEN.map((item) => (
              <li key={item}>{item}</li>
            ))}
          </ul>
        </section>
        <section style={S.card}>
          <h2 style={S.h2}>حدود التشغيل التقنية — شرط قبل أي حلقة</h2>
          <ul style={S.list}>
            {TECH_LIMITS.map((item) => (
              <li key={item}>{item}</li>
            ))}
          </ul>
        </section>
      </div>
      {/*
        * حلقات النمو والحاكميّة — كانت وجهةً مستقلّة.
        *
        * وهي والأتمتة موضوعٌ واحد: «ما الذي يعمل بلا تدخّل، وبأيّ إذن؟».
        * والصفحة نفسها كانت تُعلن أنّ **لا حلقة مفعَّلة ولا مشغّل أتمتة في
        * النظام** — أي وجهتان في الشريط لشيءٍ واحد لم يُبنَ بعد.
        */}
      <GrowthLoopsPanel />

    </div>
  );
}
