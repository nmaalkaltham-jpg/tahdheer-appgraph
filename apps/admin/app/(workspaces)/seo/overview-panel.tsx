'use client';
// UNIT: SEO-OVERVIEW-PANEL
// FILE: overview-panel.tsx
// STYLE: seo.module.css
// PARENT: PAGE-SEO
// USES: _KIT, _SECTION-CONTENT-PRODUCTS, _SECTION-OVERVIEW, _SECTION-SAFETY-TASKS, _SECTION-SEARCH-INDEX
// API: none
// API-VIA: _SECTION-OVERVIEW, _SECTION-SEARCH-INDEX
// MAP: docs/PROJECT_MAP.md

/**
 * منصة SEO — ثمانية أقسام فوق نقاط النهاية القائمة فعلًا.
 *
 * الموصول حقًّا: خرائط الموقع (لكل مزوّد حالته وسبب سُباته)، فهرس الكتالوج
 * للنماذج اللغوية، سياسة robots، مفتّش قابلية الفهرسة، حلّال التحويلات،
 * حمولة SEO لصفحة المنتج، وسجل البحث الداخلي مع المرادفات.
 *
 * وما يحتاج Search Console أو مخزن زحف أو سلسلة زمنية للأداء لا يُرسم ولا
 * يُصفَّر: يُعلن «غير مُهيأ» ويُسمّى التكامل الناقص ونقطة النهاية المطلوبة.
 */

import { useState } from 'react';
import { Icon, type IconName, type SeoSectionKey } from './_kit';
import { ContentSection, ProductsSection } from './_section-content-products';
import { SafetySection, TasksSection } from './_section-safety-tasks';
import { ExecSection, OpportunitiesSection, useSeoSignals } from './_section-overview';
import { IndexationSection, SearchSection } from './_section-search-index';
import styles from './seo.module.css';

const SECTIONS: { key: SeoSectionKey; label: string; icon: IconName }[] = [
  { key: 'exec', label: 'اللوحة التنفيذية', icon: 'gauge' },
  { key: 'opps', label: 'محرك الفرص', icon: 'rocket' },
  { key: 'search', label: 'ذكاء البحث', icon: 'search' },
  { key: 'index', label: 'الفهرسة والزحف', icon: 'globe' },
  { key: 'content', label: 'المحتوى والروابط', icon: 'doc' },
  { key: 'products', label: 'ذكاء المنتجات', icon: 'cart' },
  { key: 'safety', label: 'المراقبة والحماية', icon: 'shield' },
  { key: 'tasks', label: 'المهام والتحقق', icon: 'task' },
];

/**
 * تكاملات خارجية مطلوبة لهذه المساحة، وحالتها الحقيقية.
 *
 * لا OAuth ولا رمز تجديد ولا نقطة نهاية لأيّ منهما في هذا النظام، فالحالتان
 * «غير مربوط» بصدق. وزرّ «كيف يُربَط؟» ليس زخرفة: يكشف ما ينقص بالاسم — وهو
 * كلّ ما تستطيع اللوحة فعله دون أن تدّعي ربطًا لا تملكه.
 */
const CONNECTIONS: { key: string; name: string; note: string; needs: string[] }[] = [
  {
    key: 'search-console',
    name: 'Google Search Console',
    note: 'يحتاج بيانات OAuth ثم موافقة. حتى ذلك: لا نقرات ولا ظهور ولا موضع — ولا صفر يحلّ محلّها.',
    needs: [
      'بيانات OAuth لحساب Search Console',
      'رمز تجديد صالح مخزّن في الخادم',
      'GET /admin/seo/search-console — غير موجود',
    ],
  },
  {
    key: 'merchant-center',
    name: 'Merchant Center',
    note: 'لا تكامل مع Merchant في هذا النظام. بنود «أخطاء Merchant Listing» و«أهلية النتائج الغنية للتاجر» معطّلة — لا تُعرَض بصفر لأن الصفر يُقرأ «لا أخطاء» والحقيقة «لا بيانات».',
    needs: ['حساب Merchant Center', 'بيانات OAuth للتاجر', 'نقطة نهاية تقرأ حالة العروض — غير موجودة'],
  },
];

function Connections() {
  const [open, setOpen] = useState<string | null>(null);

  return (
    <section className={styles.connections} aria-label="حالة التكاملات الخارجية">
      {CONNECTIONS.map((connection) => {
        const expanded = open === connection.key;
        const needsId = `seo-connection-${connection.key}`;

        return (
          <article className={styles.connection} key={connection.key}>
            <div className={styles.connectionhead}>
              <span className={styles.connectiondot} aria-hidden="true" />
              <b className={styles.connectionname}>{connection.name}</b>
              <span className={styles.connectionstate}>غير مربوط</span>
            </div>
            <p className={styles.connectionnote}>{connection.note}</p>
            <button
              type="button"
              className={`${styles.btn} ${styles.ghost} ${styles.small}`}
              aria-expanded={expanded}
              aria-controls={needsId}
              onClick={() => setOpen((current) => (current === connection.key ? null : connection.key))}
            >
              <Icon name="info" />
              كيف يُربَط؟
            </button>
            <ul className={styles.connectionneeds} id={needsId} hidden={!expanded}>
              {connection.needs.map((need) => (
                <li key={need}>
                  <bdi dir="ltr">{need}</bdi>
                </li>
              ))}
            </ul>
          </article>
        );
      })}
    </section>
  );
}

export function SeoOverviewPanel() {
  const [section, setSection] = useState<SeoSectionKey>('exec');
  const [days, setDays] = useState(28);
  const signals = useSeoSignals(days);

  return (
    <div className={styles.workspace}>

      <header className={styles.topbar}>
        <span className={styles.topmark} aria-hidden="true">
          <Icon name="rocket" />
        </span>
        <div style={{ minInlineSize: 0 }}>
          <div style={{ display: 'flex', alignItems: 'center', gap: 8, flexWrap: 'wrap' }}>
            <span className={styles.toptitle}>محرك نمو SEO</span>
            <span className={styles.topgold}>التحاضير الحديثة</span>
          </div>
          <bdi dir="ltr" className={styles.topsub}>
            sitemaps · llms.txt · robots · indexability · redirects · internal search
          </bdi>
        </div>
        <div className={styles.topspacer} />
      </header>

      <nav className={styles.tabs} aria-label="أقسام منصة SEO">
        {SECTIONS.map((item) => (
          <button
            key={item.key}
            type="button"
            className={styles.tab}
            aria-current={section === item.key ? 'true' : undefined}
            onClick={() => setSection(item.key)}
          >
            <Icon name={item.icon} />
            {item.label}
          </button>
        ))}
      </nav>

      <div className={styles.panelwrap}>
        <Connections />
        {section === 'exec' ? <ExecSection signals={signals} /> : null}
        {section === 'opps' ? <OpportunitiesSection signals={signals} onSection={setSection} /> : null}
        {section === 'search' ? <SearchSection signals={signals} days={days} onDays={setDays} /> : null}
        {section === 'index' ? <IndexationSection signals={signals} /> : null}
        {section === 'content' ? <ContentSection signals={signals} /> : null}
        {section === 'products' ? <ProductsSection signals={signals} /> : null}
        {section === 'safety' ? <SafetySection /> : null}
        {section === 'tasks' ? <TasksSection /> : null}
      </div>
    </div>
  );
}
