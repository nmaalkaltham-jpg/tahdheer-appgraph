'use client';
// UNIT: _SECTION-CONTENT-PRODUCTS
// FILE: _section-content-products.tsx
// STYLE: seo.module.css
// PARENT: SEO-OVERVIEW-PANEL
// USES: AI-INDEX, LIB-ADMIN-API, LIB-FORMAT, RESOURCE, _KIT, _SECTION-OVERVIEW
// API: none
// API-VIA: _SECTION-OVERVIEW
// MAP: docs/PROJECT_MAP.md

/**
 * القسمان ٥ و٦: المحتوى والروابط، وذكاء المنتجات.
 *
 * ما هو حقيقي هنا: حمولة SEO لصفحة المنتج (الوسوم + المحتوى المرئي +
 * الرسم البياني) وقائمة جاهزية النشر لكل منتج. معاينة SERP تُبنى من
 * العنوان والوصف والقانوني الحقيقيين — لا نصّ عيّنة.
 *
 * والروابط الداخلية مقيسة من مخزن الزحف الموصول (GET /admin/crawl): الصفحات
 * اليتيمة والمكسورة وعمق النقر أرقام حقيقية. ما لا يوجد هو وزن العقد
 * والعناقيد الموضوعية ومحرك التقادم — تحتاج نقاط نهاية وسلسلة زمنية مفقودة.
 */

import { useMemo, useState } from 'react';
import { AiAssistPanel, type AiFact } from '../../../components/ai';
import { EmptyRows, ResourceView } from '../../../components/resource';
import { api, type ProductRow } from '../../../lib/admin-api';
import type { SeoSignals } from './_section-overview';
import { PRODUCTS_PERMISSION } from './_section-overview';
import { PRODUCT_STATUS_LABELS } from '../../../lib/format';
import {
  Box,
  CrawlMeasures,
  Forbidden,
  Icon,
  Kpi,
  Meter,
  NotConfigured,
  VERDICT_LABELS,
  isForbidden,
  type ProductSeoPayload,
} from './_kit';
import styles from './seo.module.css';

/*
 * صفحة المنتجات تُقرأ مرة واحدة على مستوى المساحة وتُمرَّر إلى القسمين، بدل
 * نداء مستقلّ لكل قسم على المسار نفسه.
 */
function useProductSeo(products: SeoSignals['products']) {
  const [slug, setSlug] = useState<string | null>(null);
  const [payload, setPayload] = useState<ProductSeoPayload | null>(null);
  const [pending, setPending] = useState(false);
  const [error, setError] = useState<string | null>(null);

  async function select(next: string) {
    setSlug(next);
    setPayload(null);
    setError(null);
    if (next === '') return;
    setPending(true);
    const response = await api.get<ProductSeoPayload>(`/seo/products/${encodeURIComponent(next)}`);
    setPending(false);
    if (!response.ok) {
      setError(
        response.status === 404
          ? 'هذا المنتج غير منشور، وحمولة SEO لا تُبنى إلا للمنشور — الوسوم تصف صفحة حيّة أو لا تصف شيئًا.'
          : response.message,
      );
      return;
    }
    setPayload(response.data);
  }

  return { products, slug, payload, pending, error, select };
}

function ProductPicker({
  products,
  slug,
  pending,
  onSelect,
}: {
  products: ReturnType<typeof useProductSeo>['products'];
  slug: string | null;
  pending: boolean;
  onSelect: (slug: string) => void;
}) {
  /*
   * المُحدِّد بلا قائمة منتجات ليس مُحدِّدًا: قائمةٌ خاوية تُقرأ «لا منتجات
   * منشورة» وهي في الحقيقة «لا قراءة». فيحلّ محلَّه إعلان القفل باسم مفتاحه.
   */
  if (isForbidden(products.state)) {
    return (
      <Forbidden
        title="اختيار منتج لقراءة حمولته"
        permission={PRODUCTS_PERMISSION}
        reads="قائمة المنتجات المنشورة هي ما يُبنى منه هذا المُحدِّد"
      />
    );
  }

  return (
    <div className={styles.inspect}>
      <label htmlFor="seo-product">
        <span>اختر منتجًا منشورًا لقراءة حمولته الحقيقية</span>
        <ResourceView state={products.state} reload={products.reload}>
          {(page) => (
            <select
              id="seo-product"
              value={slug ?? ''}
              onChange={(event) => void onSelect(event.target.value)}
              disabled={pending}
            >
              <option value="">— لم يُختر منتج —</option>
              {page.data
                .filter((product) => product.status === 'published')
                .map((product) => (
                  <option key={product.id} value={product.slug}>
                    {product.title}
                  </option>
                ))}
            </select>
          )}
        </ResourceView>
      </label>
      {pending ? (
        <p className="state-line" role="status" aria-live="polite">
          جارٍ قراءة حمولة SEO…
        </p>
      ) : null}
    </div>
  );
}

/* ───────────── القسم ٥: المحتوى والروابط ───────────── */

export function ContentSection({ signals }: { signals: SeoSignals }) {
  const seo = useProductSeo(signals.products);
  const metadata = seo.payload?.metadata ?? null;
  const visible = seo.payload?.visible ?? null;

  return (
    <section className={styles.section}>
      <div className={styles.grid2}>
        <Box
          title="معاينة SERP وذكاء العناوين"
          icon="eye"
          note="من الوسوم الحقيقية للصفحة"
          foot="معاينة تقريبية: محرّك البحث قد يعيد كتابة العنوان والمقتطف. الميزانيتان مختلفتان ولا تُخلطان — ميزانية المحرّر للعنوان المكتوب، وإنذار القطع للعنوان بعد إلحاق اسم العلامة."
        >
          <ProductPicker products={seo.products} slug={seo.slug} pending={seo.pending} onSelect={seo.select} />

          {seo.error ? (
            <p className="form-error" role="alert" style={{ marginBlock: 0, marginInline: 13 }}>
              {seo.error}
            </p>
          ) : null}

          {metadata === null ? (
            <p className={styles.boxbody}>
              <span className={styles.rowsub}>اختر منتجًا لعرض عنوانه ووصفه ورابطه القانوني كما يصدرها الخادم.</span>
            </p>
          ) : (
            <>
              <div className={styles.serp}>
                <bdi dir="ltr" className={styles.serpurl}>
                  {metadata.canonical}
                </bdi>
                <span className={styles.serptitle}>{metadata.renderedTitle}</span>
                <span className={styles.serpdesc}>{metadata.description ?? 'لا وصف تعريفي مسجَّل لهذه الصفحة.'}</span>
                <span className={styles.serpnote}>
                  مصدر الوصف: {metadata.descriptionSource} · القانوني{' '}
                  {metadata.canonicalIsSelf ? 'ذاتي' : 'مُتجاوَز يدويًا'}
                </span>
              </div>

              <div className={styles.gridkpi} style={{ paddingInline: 13, paddingBlockEnd: 12 }}>
                <Kpi label="طول العنوان" value={String(metadata.titleLength)} source="metadata.titleLength" />
                <Kpi
                  label="ميزانية المحرّر"
                  value={metadata.overEditorBudget ? 'تجاوَز' : 'ضمن الحد'}
                  source="metadata.overEditorBudget"
                />
                <Kpi
                  label="إنذار قطع العنوان المعروض"
                  value={metadata.renderedTitleAlarm ? 'محتمل القطع' : 'سليم'}
                  source="metadata.renderedTitleAlarm"
                />
                <Kpi label="توجيه الروبوتات" value={metadata.robots} source="metadata.robots" />
              </div>

              <div className={`${styles.verdict} ${metadata.indexability.verdict === 'eligible' ? styles.eligible : styles.monitor}`}>
                <span className={styles.absenthead}>
                  <Icon name="globe" />
                  <span className={styles.absenttitle}>
                    {VERDICT_LABELS[metadata.indexability.verdict] ?? metadata.indexability.verdict}
                  </span>
                </span>
                <span className={styles.verdictline}>{metadata.indexability.reason}</span>
                <code className={styles.codeline} dir="ltr">
                  rule: {metadata.indexability.rule}
                </code>
              </div>
            </>
          )}
        </Box>

        <Box
          title="المحتوى المرئي الذي يسنُد الوسوم"
          icon="doc"
          note="لا وسم لغير ظاهر"
          foot="الرسم البياني يُبنى من هذه المصفوفات نفسها، فلا يمكن أن يصف الوسم شيئًا لا تعرضه الصفحة — وهذا حاجز أمان لا خيار عرض."
        >
          {visible === null ? (
            <p className={styles.boxbody}>
              <span className={styles.rowsub}>بانتظار اختيار منتج.</span>
            </p>
          ) : (
            <div className={styles.boxbody}>
              <Meter name="فتات المسار" done={visible.breadcrumbs.length} total={Math.max(visible.breadcrumbs.length, 1)} />
              <p className={styles.lede} style={{ marginBlockStart: 8 }}>
                {visible.breadcrumbs.map((crumb) => crumb.name).join(' › ')}
              </p>

              <div className={styles.gridkpi} style={{ marginBlockStart: 12 }}>
                <Kpi label="أسئلة شائعة ظاهرة" value={String(visible.faqs.length)} source="visible.faqs" />
                <Kpi
                  label="حقائق ظاهرة"
                  value={String(Object.keys(visible.facts).length)}
                  source="visible.facts"
                />
                <Kpi label="المؤلف" value={visible.author?.name ?? null} source="visible.author" />
                <Kpi
                  label="مصدر صورة المشاركة"
                  value={metadata?.openGraph.imageSource ?? null}
                  source="metadata.openGraph.imageSource"
                />
                <Kpi
                  label="صورة ظاهرة للمنتج"
                  value={visible.image}
                  source="visible.image"
                  absentLabel="لا صورة ظاهرة"
                />
              </div>
            </div>
          )}
        </Box>
      </div>

      <AiAssistPanel
        field="الوصف التعريفي"
        currentValue={metadata?.description ?? null}
        facts={
          visible === null
            ? []
            : [
                ...Object.entries(visible.facts)
                  .filter(([, value]) => value !== null)
                  .map<AiFact>(([key, value]) => ({ key, label: key, value: String(value) })),
                ...(metadata === null
                  ? []
                  : [
                      { key: 'canonical', label: 'الرابط القانوني', value: metadata.canonical, locked: true },
                      { key: 'robots', label: 'توجيه الروبوتات', value: metadata.robots, locked: true },
                    ]),
              ]
        }
      />

      <div className={styles.grid2}>
        <CrawlMeasures state={signals.crawl.state} title="قياسات الروابط الداخلية" />
        <NotConfigured
          title="وزن العقد الداخلي والعناقيد الموضوعية"
          reason="الروابط الواردة وعمق النقر والصفحات اليتيمة مقيسة فعلًا من مخزن الزحف (بجواره). ما لا يوجد هو حساب وزن كل عقدة وتجميع الصفحات في عناقيد موضوعية واقتراح مراسٍ: لا نقطة نهاية تُخرج أيًّا منها، ورسم مخطط بعقد اسمية سيكون صورة لا قياسًا."
          needs={['GET /admin/seo/links/graph', 'GET /admin/seo/clusters']}
        />
        <NotConfigured
          title="محرك تقادم المحتوى وحارس المحتوى المفيد"
          reason="تقادم المحتوى يُقاس بانحدار النقرات عبر الزمن مقارنةً بأساس متحرك، ويحتاج سلسلة زمنية لأداء كل صفحة. لا Search Console ولا مخزن تاريخي للأداء، فلا درجة تقادم ولا «نقرات مفقودة» ولا «إيراد مفقود»."
          needs={['Google Search Console', 'مخزن سلاسل زمنية للأداء', 'GET /admin/seo/decay']}
        />
      </div>
    </section>
  );
}

/* ───────────── القسم ٦: ذكاء المنتجات ───────────── */

export function ProductsSection({ signals }: { signals: SeoSignals }) {
  const seo = useProductSeo(signals.products);

  return (
    <section className={styles.section}>
      <ReadinessBoard products={seo.products} />

      <div className={styles.grid2}>
        <Box
          title="الرسم البياني المنشور لصفحة المنتج"
          icon="layers"
          note="رسم واحد لكل صفحة"
          foot="رسم بياني واحد لكل صفحة، وكل عقدة فيه تصف عنصرًا ظاهرًا. لا تُضاف عقدة تقييمات ولا عروض غير موجودة، ولا يوجد كاتب JSON-LD ثانٍ يخالف هذا."
        >
          <ProductPicker products={seo.products} slug={seo.slug} pending={seo.pending} onSelect={seo.select} />
          {seo.error ? (
            <p className="form-error" role="alert" style={{ marginBlock: 0, marginInline: 13 }}>
              {seo.error}
            </p>
          ) : null}
          {seo.payload === null ? (
            <p className={styles.boxbody}>
              <span className={styles.rowsub}>اختر منتجًا لعرض أنواع العقد المنشورة فعلًا في رسمه البياني.</span>
            </p>
          ) : (
            <div className={styles.boxbody}>
              <ul className={styles.absentneeds}>
                {seo.payload.schema['@graph'].map((node, index) => (
                  <li key={`${String(node['@type'])}-${index}`}>
                    <bdi dir="ltr">
                      {Array.isArray(node['@type']) ? node['@type'].join(' + ') : (node['@type'] ?? 'بلا نوع')}
                    </bdi>
                  </li>
                ))}
              </ul>
              <p className={styles.footnote} style={{ marginBlockStart: 8 }}>
                <bdi dir="ltr">{seo.payload.schema['@graph'].length}</bdi> عقدة في رسم واحد ·{' '}
                <bdi dir="ltr">{seo.payload.schema['@context']}</bdi>
              </p>
            </div>
          )}
        </Box>

        <NotConfigured
          title="فاحص اتساق بيانات التاجر وتغطية الـFeed"
          reason="مطابقة الحقول بين المتجر وHTML وSchema وFeed تحتاج مصدرًا رابعًا: تغذية منتجات (Merchant Feed). لا مولّد Feed ولا تكامل Merchant Center في هذا النظام، فلا عمود Feed يُقارن به ولا نسب تغطية لكل خاصية."
          needs={['Google Merchant Center', 'GET /admin/seo/feed/coverage', 'GET /admin/seo/feed/mismatches']}
        />
      </div>

      <NotConfigured
        title="SEO الصور والفيديو والسِمات المحلية"
        reason="تغطية النص البديل وأسماء الملفات الوصفية وخريطة الصور وVideoObject والبيانات المحلية (NAP والفروع) كلها تحتاج جرد وسائط وحقول عنوان تجاري لا وجود لهما في هذا النظام. القيمة الوحيدة الحقيقية المتاحة اليوم هي مصدر صورة الغلاف المعروض في قسم المحتوى."
        needs={['جرد وسائط بحقول alt', 'GET /admin/seo/media/audit', 'حقول العنوان التجاري (NAP)']}
      />
    </section>
  );
}

/**
 * جاهزية النشر مجمَّعة: كل بند من قائمة الخادم يُعدّ عبر كل المنتجات.
 * هذه أرقام حقيقية — مصدرها readiness في ردّ /admin/products، وهي نفسها
 * القائمة التي يفرضها مسار النشر.
 */
function ReadinessBoard({ products }: { products: ReturnType<typeof useProductSeo>['products'] }) {
  /* صفر منتجٍ جاهز وصفر منتجٍ أصلًا يُرسمان سواءً — والفرق بينهما هو المفتاح. */
  if (isForbidden(products.state)) {
    return (
      <Forbidden
        title="اكتمال SEO المنتجات — قائمة الجاهزية"
        permission={PRODUCTS_PERMISSION}
        reads="بنود الجاهزية تأتي داخل ردّ ‎/admin/products‎ نفسه، فلا مصدر ثانيًا لها"
      />
    );
  }

  return (
    <ResourceView state={products.state} reload={products.reload}>
      {(page) => <ReadinessTable rows={page.data} total={page.meta.total} />}
    </ResourceView>
  );
}

function ReadinessTable({ rows, total }: { rows: ProductRow[]; total: number }) {
  const checks = useMemo(() => {
    const map = new Map<string, { label: string; ok: number }>();
    for (const product of rows) {
      for (const item of product.readiness.items) {
        const entry = map.get(item.key) ?? { label: item.label, ok: 0 };
        if (item.ok) entry.ok += 1;
        map.set(item.key, entry);
      }
    }
    return [...map.entries()].map(([key, value]) => ({ key, ...value }));
  }, [rows]);

  const ready = rows.filter((product) => product.readiness.ready).length;

  return (
    <div className={styles.grid2}>
      <Box
        title="اكتمال SEO المنتجات — قائمة الجاهزية الحقيقية"
        icon="cart"
        note={`${rows.length} منتجًا من ${total}`}
        foot="هذه هي القائمة نفسها التي يفرضها مسار النشر في الخادم؛ لا نسخة ثانية منها في الواجهة، ولا بند يُعدّ «مكتملًا» هنا ويرفضه الخادم هناك."
      >
        <div className="table-scroll" tabIndex={0} role="region" aria-label="جاهزية SEO لكل منتج">
          <table className={styles.table}>
            <caption>كل منتج ونسبة اكتمال قائمته</caption>
            <thead>
              <tr>
                <th scope="col">المنتج</th>
                <th scope="col">الحالة</th>
                <th scope="col">مكتمل</th>
                <th scope="col">الناقص</th>
              </tr>
            </thead>
            <tbody>
              {rows.length === 0 ? (
                <EmptyRows colSpan={4} message="لا منتجات مسجَّلة بعد." />
              ) : (
                rows.map((product) => (
                  <tr key={product.id}>
                    <th scope="row">
                      {product.title}
                      <span className={styles.rowsub}>
                        <bdi dir="ltr">{product.slug}</bdi>
                      </span>
                    </th>
                    <td>
                      <span className={`chip chip-${product.status}`}>
                        {PRODUCT_STATUS_LABELS[product.status] ?? product.status}
                      </span>
                    </td>
                    <td className={styles.num}>
                      <bdi dir="ltr">
                        {product.readiness.completedCount}/{product.readiness.totalCount}
                      </bdi>
                    </td>
                    <td>
                      {product.readiness.missingLabels.length === 0 ? (
                        <span className={`${styles.tag} ${styles.ok}`}>مكتمل</span>
                      ) : (
                        product.readiness.missingLabels.join(' · ')
                      )}
                    </td>
                  </tr>
                ))
              )}
            </tbody>
          </table>
        </div>
      </Box>

      <Box title="أكثر البنود تعطيلًا للنشر" icon="chart" note="عدّ حقيقي عبر المنتجات المقروءة">
        <div className={styles.boxbody}>
          <Meter name="منتجات جاهزة" done={ready} total={rows.length} />
          {checks.length === 0 ? (
            <p className={styles.footnote} style={{ marginBlockStart: 10 }}>
              لا قائمة جاهزية في الردّ — لا شيء يُعدّ.
            </p>
          ) : (
            <div style={{ marginBlockStart: 10 }}>
              {checks.map((check) => (
                <Meter key={check.key} name={check.label} done={check.ok} total={rows.length} />
              ))}
            </div>
          )}
        </div>
        <div className={styles.boxfoot}>
          العدّ يشمل الصفحة المقروءة من قائمة المنتجات فقط ({rows.length} من {total}) — لا استقراء لبقية
          الصفحات.
        </div>
      </Box>
    </div>
  );
}
