import { useState } from 'react';
import { AdminShell, type AdminNavLayout } from '@tahdheer/ui';

/*
 * بيانات ملاحةٍ واحدة تخدم التخطيطات الخمسة — منقولةٌ عن `apps/admin/lib/nav.ts`
 * بعد دمج ٢٠٢٦-٠٩-١٥ (تسع مجموعات، اثنتان وعشرون وجهة). تُختصر هنا إلى ما
 * يسع بطاقةً واحدة، والأعداد مأخوذة من عدّادات التشغيل الحقيقيّة لا مخترعة.
 */
const GROUPS = [
  {
    id: 'home',
    label: 'الرئيسيّة',
    icon: 'M3 9.5L12 2l9 7.5V21H3zM9 21v-7h6v7',
    items: [{ id: 'dashboard', label: 'لوحة التشغيل', icon: 'M3 9.5L12 2l9 7.5V21H3zM9 21v-7h6v7' }],
  },
  {
    id: 'sales',
    label: 'المبيعات والعملاء',
    icon: 'M9 22a1 1 0 1 0 0-2 1 1 0 0 0 0 2zm11 0a1 1 0 1 0 0-2 1 1 0 0 0 0 2zM1 1h4l2.7 13.4a2 2 0 0 0 2 1.6h9.7a2 2 0 0 0 2-1.6L23 6H6',
    items: [
      { id: 'orders', label: 'الطلبات', icon: 'M9 22a1 1 0 1 0 0-2 1 1 0 0 0 0 2zm11 0a1 1 0 1 0 0-2 1 1 0 0 0 0 2zM1 1h4l2.7 13.4a2 2 0 0 0 2 1.6h9.7a2 2 0 0 0 2-1.6L23 6H6', badge: 3 },
      { id: 'cases', label: 'القضايا', icon: 'M10.3 3.9L1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0zM12 9v4m0 4h.01', badge: 2, alert: true },
      { id: 'customers', label: 'العملاء', icon: 'M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2M9 3a4 4 0 1 1 0 8 4 4 0 0 1 0-8z' },
    ],
  },
  {
    id: 'ops',
    label: 'العمليّات',
    icon: 'M12 2L2 7l10 5 10-5zM2 17l10 5 10-5M2 12l10 5 10-5',
    items: [
      { id: 'queue', label: 'طابور العمليّات', icon: 'M12 2L2 7l10 5 10-5zM2 17l10 5 10-5M2 12l10 5 10-5' },
      { id: 'stock', label: 'المخزون', icon: 'M3 3h7v7H3zm11 0h7v7h-7zM3 14h7v7H3zm11 0h7v7h-7z' },
    ],
  },
  {
    id: 'visibility',
    label: 'الظهور والبحث',
    icon: 'M11 19a8 8 0 1 0 0-16 8 8 0 0 0 0 16zm10 2l-4.3-4.3',
    items: [
      { id: 'seo', label: 'الظهور والبحث', icon: 'M11 19a8 8 0 1 0 0-16 8 8 0 0 0 0 16zm10 2l-4.3-4.3' },
      { id: 'intl', label: 'SEO الدولي', icon: 'M12 22a10 10 0 1 0 0-20 10 10 0 0 0 0 20zM2 12h20M12 2a15 15 0 0 1 0 20 15 15 0 0 1 0-20z' },
    ],
  },
];

const TABS = [
  { id: 'queue', label: 'طابور التنفيذ' },
  { id: 'shipments', label: 'الشحنات' },
  { id: 'returns', label: 'الإرجاع' },
  { id: 'risk', label: 'مراجعة المخاطر', badge: 2 },
  { id: 'sources', label: 'مصادر الأصول' },
];

/*
 * محتوى اللوح: شرائح مؤشّرات وجدول. الأرقام من لقطة بيانات التطوير
 * (١٥٣ طلبًا · ٢٦ منتجًا · ٦٩ عميلًا) لا مخترعة — والمعاينة تُصيَّر بلا شبكة،
 * فلا صورة بعيدة ولا خطّ يُجلب وقت العرض.
 */
function Body() {
  const stats = [
    { label: 'طلبات تنتظر معالجة', value: '٣', tone: '#b23a3a' },
    { label: 'وحدات تنفيذٍ معلّقة', value: '٧', tone: '#9a6a10' },
    { label: 'شحنات في الطريق', value: '١٢', tone: '#2a5f96' },
    { label: 'مصادر منقطعة', value: '٠', tone: '#2e7d52' },
  ];

  const rows = [
    ['TD-10241', 'قيد التنفيذ', '٣٤٠٫٠٠', 'رقميّ'],
    ['TD-10240', 'بانتظار الدفع', '١٢٠٫٠٠', 'رقميّ'],
    ['TD-10238', 'مكتمل', '٩٨٠٫٠٠', 'ماديّ'],
    ['TD-10237', 'مكتمل', '٤٥٠٫٠٠', 'رقميّ'],
  ];

  return (
    <div style={{ display: 'grid', gap: 14 }}>
      {/* أربعة أعمدة حيث تسع، وعمودان حيث لا تسع — `auto-fit` لا رقمٌ ثابت. */}
      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(110px, 1fr))', gap: 10 }}>
        {stats.map((stat) => (
          <div
            key={stat.label}
            style={{
              background: '#fff',
              border: '1px solid #e6e9e2',
              borderRadius: 10,
              padding: '10px 12px',
            }}
          >
            <div style={{ fontSize: 11, fontWeight: 700, color: '#5f7167' }}>{stat.label}</div>
            <div style={{ fontSize: 22, fontWeight: 800, color: stat.tone, lineHeight: 1.2 }}>
              {stat.value}
            </div>
          </div>
        ))}
      </div>

      {/*
        * الجدول يمرّر داخل إطاره لا يُقصّ.
        *
        * `overflow: hidden` كان يقطع العمود الأخير في التخطيطات ذات العمود
        * الجانبيّ — قِسته في اللقطة. والتمرير هنا مسؤوليّة الحاوية الفائضة
        * نفسها لا القشرة: قشرةٌ تمرّر أفقيًّا تُحرّك الملاحة مع الأعمدة.
        */}
      <div
        style={{
          background: '#fff',
          border: '1px solid #e6e9e2',
          borderRadius: 10,
          overflowX: 'auto',
        }}
      >
        <table style={{ inlineSize: '100%', borderCollapse: 'collapse', fontSize: 12.5 }}>
          <thead>
            <tr>
              {['رقم الطلب', 'الحالة', 'الإجمالي', 'النوع'].map((header) => (
                <th
                  key={header}
                  style={{
                    textAlign: 'start',
                    fontSize: 11,
                    fontWeight: 800,
                    color: '#5f7167',
                    padding: '8px 12px',
                    borderBlockEnd: '1px solid #e6e9e2',
                    whiteSpace: 'nowrap',
                  }}
                >
                  {header}
                </th>
              ))}
            </tr>
          </thead>
          <tbody>
            {rows.map((row) => (
              <tr key={row[0]}>
                {row.map((cell, index) => (
                  <td
                    key={index}
                    style={{
                      padding: '8px 12px',
                      borderBlockEnd: '1px solid #eef1ec',
                      whiteSpace: 'nowrap',
                    }}
                  >
                    {index === 0 ? <bdi dir="ltr">{cell}</bdi> : cell}
                  </td>
                ))}
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </div>
  );
}

/**
 * التخطيطات الخمسة — القصص تختلف في `nav` وحدها.
 *
 * وهذا هو برهان القانون: لو احتاج تخطيطٌ ترميزًا خاصًّا لكانت القصّة ستُظهره.
 */
function Design({ nav, title, trail }: { nav: AdminNavLayout; title: string; trail: string[] }) {
  const [tab, setTab] = useState('queue');
  const [hiddenTabs, setHiddenTabs] = useState<string[]>([]);
  const [tabOrder, setTabOrder] = useState<string[]>([]);
  const [hiddenGroups, setHiddenGroups] = useState<string[]>([]);

  return (
    <AdminShell
      nav={nav}
      brand={{ name: 'التحاضير الحديثة', tagline: 'لوحة التشغيل' }}
      groups={GROUPS}
      activeItem="queue"
      title={title}
      trail={trail}
      tabs={TABS}
      activeTab={tab}
      onSelectTab={setTab}
      hiddenTabs={hiddenTabs}
      onHiddenTabsChange={setHiddenTabs}
      tabOrder={tabOrder}
      onTabOrderChange={setTabOrder}
      hiddenGroups={hiddenGroups}
      onHiddenGroupsChange={setHiddenGroups}
      actions={
        <button
          type="button"
          style={{
            minBlockSize: 34,
            paddingInline: 14,
            border: 0,
            borderRadius: 8,
            background: '#3f7f61',
            color: '#fff',
            fontWeight: 700,
            fontSize: 13,
            cursor: 'pointer',
          }}
        >
          تحديث
        </button>
      }
    >
      <Body />
    </AdminShell>
  );
}

/** ١ — الشريط الجانبيّ الكامل: أعلى كثافةٍ للملاحة. */
export function SidebarLayout() {
  return <Design nav="sidebar" title="طابور العمليّات" trail={['العمليّات']} />;
}

/** ٢ — الشريط المصغّر: أقصى عرضٍ للجدول، والتسمية في `title`. */
export function RailLayout() {
  return <Design nav="rail" title="طابور العمليّات" trail={['العمليّات']} />;
}

/** ٣ — التنقّل العلويّ: أقصى **ارتفاع** للمحتوى على شاشةٍ عريضةٍ قصيرة. */
export function TopbarLayout() {
  return <Design nav="topbar" title="طابور العمليّات" trail={['العمليّات']} />;
}

/** ٤ — العمودان: المجموعة تُختار فتنفتح وجهاتُها وحدها. */
export function SplitLayout() {
  return <Design nav="split" title="طابور العمليّات" trail={['العمليّات']} />;
}

/** ٥ — التركيز: بلا ملاحةٍ دائمة — التبويبات وحدها. */
export function FocusLayout() {
  return <Design nav="focus" title="طابور العمليّات" trail={['العمليّات']} />;
}
