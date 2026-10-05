'use client';
// UNIT: REPORTS
// FILE: reports.tsx
// STYLE: diagnostics.module.css, shared.module.css
// PARENT: DIAGNOSTICS-PANEL
// USES: LIB-ADMIN-API, SHARED-ICONS, UI
// API: GET /admin/dashboard, GET /admin/search/queries
// MAP: docs/PROJECT_MAP.md

/**
 * التقارير — ما يمكن تصديره فعلًا، وما لا يمكن ولماذا.
 *
 * تقريران يعملان: كلاهما يُنادي مساره الحقيقي عند الضغط ويبني الملف من
 * الاستجابة نفسها في المتصفّح. لا مولّد على الخادم ولا مجدول، فبقيّة البطاقات
 * تظهر معطّلة وبجانبها سبب التعطيل — لا زرّ يكتفي بإشعار.
 *
 * الملف المُصدَّر يحمل مصدر كل رقم وتاريخ قراءته، حتى لا يُقتطع الرقم من
 * سياقه بمجرّد خروجه من الشاشة.
 */

import { useState } from 'react';
import { api, firstError, type Dashboard, type SearchQueriesReport } from '../../../../lib/admin-api';
import { Panel } from '../../analytics/_shared/ui';
import { Icon } from '../../analytics/_shared/icons';
import s from '../../analytics/_shared/shared.module.css';
import d from '../diagnostics.module.css';

const NO_GENERATOR = 'لا مولّد PDF أو XLSX في هذه الحزمة، ولا تُضاف تبعية رسم أو تنسيق لأجل زرّ.';
const NO_SCHEDULER = 'لا مسار يحفظ جدولة ولا مجدول ينفّذها ولا مرسل بريد — الجدولة هنا وعد لا تنفيذ.';

/** اقتباس حقل CSV: كل قيمة بين علامتَي اقتباس والمزدوجة تُضاعَف. */
function cell(value: string | number): string {
  return `"${String(value).replace(/"/g, '""')}"`;
}

function toCsv(rows: (string | number)[][]): string {
  return rows.map((row) => row.map(cell).join(',')).join('\r\n');
}

/** تنزيل نصّي من المتصفّح — بعلامة ترتيب البايتات ليفتح العربي سليمًا. */
function download(name: string, csv: string): void {
  const blob = new Blob(['﻿', csv], { type: 'text/csv;charset=utf-8' });
  const url = URL.createObjectURL(blob);
  const anchor = document.createElement('a');
  anchor.href = url;
  anchor.download = name;
  document.body.append(anchor);
  anchor.click();
  anchor.remove();
  URL.revokeObjectURL(url);
}

function today(): string {
  return new Date().toISOString().slice(0, 10);
}

interface BlockedReport {
  id: string;
  title: string;
  body: string;
  blocked: string;
}

const BLOCKED: BlockedReport[] = [
  {
    id: 'executive',
    title: 'التقرير التنفيذي',
    body: 'صحة المتجر والإيراد وأهم المخاطر والفرص في صفحة واحدة.',
    blocked: 'يحتاج اتجاهًا عبر الزمن ومقارنة بفترة سابقة، ولا لقطات تاريخية محفوظة.',
  },
  {
    id: 'crawl',
    title: 'التقني الشامل',
    body: 'الزحف والفهرسة وسرعة الصفحات والبيانات المنظَّمة.',
    blocked: 'لا زحف داخلي ولا مصدر بيانات ميدانية للسرعة.',
  },
  {
    id: 'content',
    title: 'المحتوى',
    body: 'التدهور والفجوات والتنافس الذاتي وتقويم التحديث.',
    blocked: 'يحتاج أداءً تاريخيًا لكل صفحة، وهو غير مرصود.',
  },
  {
    id: 'keywords',
    title: 'الكلمات والمراكز',
    body: 'المسافة القريبة والرابحة والخاسرة وحركة المراكز.',
    blocked: 'لا ربط بأي مصدر بحث خارجي.',
  },
  {
    id: 'links',
    title: 'الروابط والسلطة',
    body: 'الروابط الجديدة والمفقودة والاستصلاح.',
    blocked: 'لا مصدر لروابط خارجية ولا لرسم الروابط الداخلية.',
  },
  {
    id: 'before-after',
    title: 'ما قبل/بعد الإصلاحات',
    body: 'كل تغيير مُنفَّذ ونتيجته المقاسة بعده.',
    blocked: 'يحتاج قياسًا قبل التغيير وبعده، وكلاهما يحتاج سلسلة زمنية غير موجودة.',
  },
  {
    id: 'audit',
    title: 'سجلّ التدقيق الكامل',
    body: 'كل فعل إداري: مَن ومتى وماذا.',
    blocked: 'السجلّ يُكتب على الخادم، ولا مسار قراءة له في واجهة اللوحة بعد.',
  },
];

export default function ReportsScreen() {
  const [busy, setBusy] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [done, setDone] = useState<string | null>(null);

  async function exportDashboard(): Promise<void> {
    setBusy('dashboard');
    setError(null);
    setDone(null);
    const result = await api.get<Dashboard>('/admin/dashboard');
    setBusy(null);
    if (!result.ok) {
      setError(firstError(result));
      return;
    }
    const m = result.data.measured;
    const rows: (string | number)[][] = [
      ['المقياس', 'القيمة', 'المصدر', 'وقت القراءة'],
      ['إجمالي المنتجات', m.products.total, 'products', result.data.generatedAt],
      ...Object.entries(m.products.byStatus).map(([key, value]): (string | number)[] => [
        `منتجات — ${key}`,
        value,
        'products.status',
        result.data.generatedAt,
      ]),
      ['إجمالي الطلبات', m.orders.total, 'orders', result.data.generatedAt],
      ...Object.entries(m.orders.byStatus).map(([key, value]): (string | number)[] => [
        `طلبات — ${key}`,
        value,
        'orders.status',
        result.data.generatedAt,
      ]),
      ['الطلبات المحتسَبة للإيراد', m.revenue.ordersCount, m.revenue.paidStatuses.join(' | '), result.data.generatedAt],
      ['الإيراد (هللة)', m.revenue.totalMinor, 'orders.total_minor', result.data.generatedAt],
      ['الضريبة (هللة)', m.revenue.vatMinor, 'orders.vat_minor', result.data.generatedAt],
      ['إجمالي الصلاحيات', m.entitlements.total, 'entitlements', result.data.generatedAt],
      ...Object.entries(m.entitlements.byStatus).map(([key, value]): (string | number)[] => [
        `صلاحيات — ${key}`,
        value,
        'entitlements.status',
        result.data.generatedAt,
      ]),
      ['استعلامات مسجَّلة', m.search.queriesLogged, 'search_queries', result.data.generatedAt],
      ['استعلامات بلا نتائج', m.search.zeroResultQueries, 'search_queries.zero_result', result.data.generatedAt],
      ...result.data.notConfigured.map((metric): (string | number)[] => [
        metric.label,
        '—',
        `${metric.state} — ${metric.reason}`,
        result.data.generatedAt,
      ]),
    ];
    download(`tahdheer-dashboard-${today()}.csv`, toCsv(rows));
    setDone('صُدِّر تقرير عدّادات اللوحة — كل سطر يحمل مصدره ووقت قراءته، وغير المُقاس مكتوب «—» لا صفرًا.');
  }

  async function exportQueries(): Promise<void> {
    setBusy('queries');
    setError(null);
    setDone(null);
    const result = await api.get<SearchQueriesReport>('/admin/search/queries?days=90&limit=100');
    setBusy(null);
    if (!result.ok) {
      setError(firstError(result));
      return;
    }
    const w = result.data.window;
    const rows: (string | number)[][] = [
      ['النوع', 'العبارة', 'التكرار', 'منها بلا نتائج', 'آخر ظهور', 'النافذة'],
      ...result.data.topQueries.map((row): (string | number)[] => [
        'الأكثر تكرارًا',
        row.query,
        row.count,
        row.zeroResultCount,
        row.lastSeenAt ?? '—',
        `${w.days} يومًا: ${w.from} → ${w.to}`,
      ]),
      ...result.data.zeroResultQueries.map((row): (string | number)[] => [
        'بلا نتائج',
        row.query,
        row.count,
        row.count,
        row.lastSeenAt ?? '—',
        `${w.days} يومًا: ${w.from} → ${w.to}`,
      ]),
    ];
    download(`tahdheer-search-queries-${today()}.csv`, toCsv(rows));
    setDone(
      `صُدِّر تقرير استعلامات البحث لنافذة ${w.days} يومًا — الصفوف كما ردّها الخادم بلا إعادة ترتيب.`,
    );
  }

  return (
    <>
      <div className={d.mapHead}>
        <h2 className={d.mapTitle}>التقارير</h2>
        <span className={d.mapSub}>ما يُصدَّر فعلًا، وما لا يُصدَّر ولماذا</span>
      </div>

      {error !== null ? (
        <div className={s.alertBox} role="alert">
          {error}
        </div>
      ) : null}
      {done !== null ? (
        <div className={s.okBox} role="status" aria-live="polite">
          {done}
        </div>
      ) : null}

      <Panel
        title="تقارير تعمل"
        sub="كلاهما يُنادي مساره عند الضغط ويبني الملف من الاستجابة نفسها. لا نسخة مخزَّنة ولا رقم مكتوب مسبقًا."
      >
        <div className={d.reportGrid}>
          <section className={d.report}>
            <h4 className={d.reportTitle}>عدّادات اللوحة</h4>
            <p className={d.reportBody}>
              كل عدّاد مقيس مع الجدول الذي احتُسب منه، ومعه ما يعلنه الخادم «غير مُهيأ» بقيمة «—» وسببه
              كاملًا.
            </p>
            <div className={d.reportActions}>
              <button
                type="button"
                className={[s.btn, s.btnCompact, s.btnPrimary].filter(Boolean).join(' ')}
                onClick={() => void exportDashboard()}
                disabled={busy !== null}
              >
                <Icon name="download" size={12} strokeWidth={2.2} />
                {busy === 'dashboard' ? 'جارٍ التصدير…' : 'تصدير CSV'}
              </button>
              <button type="button" className={[s.btn, s.btnCompact].filter(Boolean).join(' ')} disabled title={NO_GENERATOR}>
                PDF
              </button>
              <button type="button" className={[s.btn, s.btnCompact].filter(Boolean).join(' ')} disabled title={NO_GENERATOR}>
                XLSX
              </button>
              <button type="button" className={[s.btn, s.btnCompact].filter(Boolean).join(' ')} disabled title={NO_SCHEDULER}>
                جدولة أسبوعية
              </button>
            </div>
          </section>

          <section className={d.report}>
            <h4 className={d.reportTitle}>استعلامات البحث</h4>
            <p className={d.reportBody}>
              الأكثر تكرارًا وما لم يُعِد نتيجة، لنافذة ٩٠ يومًا، بحدود ما يُرجعه المسار (١٠٠ صفًّا لكل
              قائمة).
            </p>
            <div className={d.reportActions}>
              <button
                type="button"
                className={[s.btn, s.btnCompact, s.btnPrimary].filter(Boolean).join(' ')}
                onClick={() => void exportQueries()}
                disabled={busy !== null}
              >
                <Icon name="download" size={12} strokeWidth={2.2} />
                {busy === 'queries' ? 'جارٍ التصدير…' : 'تصدير CSV'}
              </button>
              <button type="button" className={[s.btn, s.btnCompact].filter(Boolean).join(' ')} disabled title={NO_GENERATOR}>
                PDF
              </button>
              <button type="button" className={[s.btn, s.btnCompact].filter(Boolean).join(' ')} disabled title={NO_GENERATOR}>
                XLSX
              </button>
              <button type="button" className={[s.btn, s.btnCompact].filter(Boolean).join(' ')} disabled title={NO_SCHEDULER}>
                جدولة أسبوعية
              </button>
            </div>
          </section>
        </div>
      </Panel>

      <div className={s.stackTop}>
        <Panel
          title="تقارير لا يمكن إصدارها"
          sub="ليست «قريبًا»: كل بطاقة هنا تفتقد بيانات بعينها، وسبب افتقادها مكتوب عليها."
        >
          <div className={d.reportGrid}>
            {BLOCKED.map((report) => (
              <section className={d.report} key={report.id}>
                <h4 className={d.reportTitle}>{report.title}</h4>
                <p className={d.reportBody}>{report.body}</p>
                <span className={d.reportBlocked}>{report.blocked}</span>
                <div className={d.reportActions}>
                  <button
                    type="button"
                    className={[s.btn, s.btnCompact].filter(Boolean).join(' ')}
                    disabled
                    title={report.blocked}
                  >
                    <Icon name="download" size={12} strokeWidth={2.2} />
                    تصدير
                  </button>
                  <button
                    type="button"
                    className={[s.btn, s.btnCompact].filter(Boolean).join(' ')}
                    disabled
                    title={NO_SCHEDULER}
                  >
                    جدولة
                  </button>
                </div>
              </section>
            ))}
          </div>
        </Panel>
      </div>
    </>
  );
}
