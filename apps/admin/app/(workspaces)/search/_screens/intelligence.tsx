'use client';
/**
 * ذكاءُ البحث — ما يبحث عنه الناس، وما يفشل، وأين يضيع الإيراد.
 *
 * كلُّ رقمٍ من `GET /admin/search/intelligence` (أحداثٌ حقيقيّة: بحث، ظهور، نقرة،
 * سلّة، طلب)، وكلُّ نسبةٍ ببسطها ومقامها. القمعُ والإيراد مقامُهما البحوثُ الموافِقة
 * على القياس وحدها — الإسناد لا يُكتب بلا موافقة — وتُعلَن حصّتُها بجانبها.
 */
import { useMemo, useState } from 'react';
import { ResourceView, useResource } from '../../../../components/resource';
import { DateRangePicker, useDateRange } from '../../../../components/ui/date-range';
import { count, money } from '../../../../lib/format';
import { formatRate, recoveryLabel, type ProductRow, type QueryRow, type SearchIntelligenceReport } from '../../../../lib/search-intelligence';

function Kpi({ label, value, hint }: { label: string; value: string; hint?: string }) {
  return (
    <div className="card">
      <div className="label">{label}</div>
      <div className="value">
        <bdi dir="ltr">{value}</bdi>
      </div>
      {hint ? <div className="hint">{hint}</div> : null}
    </div>
  );
}

function QueryTable({ title, rows, empty }: { title: string; rows: QueryRow[]; empty: string }) {
  return (
    <>
      <h3>{title}</h3>
      <div className="table-scroll" tabIndex={0} role="region" aria-label={title}>
        <table className="data-table">
          <thead>
            <tr>
              <th scope="col">الاستعلام</th>
              <th scope="col">البحوث</th>
              <th scope="col">بلا نتائج</th>
              <th scope="col">CTR</th>
              <th scope="col">إلى السلّة</th>
              <th scope="col">إلى الشراء</th>
              <th scope="col">الإيراد</th>
            </tr>
          </thead>
          <tbody>
            {rows.length === 0 ? (
              <tr>
                <td colSpan={7}>{empty}</td>
              </tr>
            ) : (
              rows.map((r) => (
                <tr key={r.query}>
                  <td>{r.query}</td>
                  <td>
                    <bdi dir="ltr">{count(r.searches)}</bdi>
                  </td>
                  <td>
                    <bdi dir="ltr">{formatRate(r.zeroResult)}</bdi>
                  </td>
                  <td>
                    <bdi dir="ltr">{formatRate(r.ctr)}</bdi>
                  </td>
                  <td>
                    <bdi dir="ltr">{formatRate(r.toCart)}</bdi>
                  </td>
                  <td>
                    <bdi dir="ltr">{formatRate(r.toPurchase)}</bdi>
                  </td>
                  <td>
                    <bdi dir="ltr">{money(r.revenueMinor)}</bdi>
                  </td>
                </tr>
              ))
            )}
          </tbody>
        </table>
      </div>
    </>
  );
}

function ProductTable({ title, rows, empty }: { title: string; rows: ProductRow[]; empty: string }) {
  return (
    <>
      <h3>{title}</h3>
      <div className="table-scroll" tabIndex={0} role="region" aria-label={title}>
        <table className="data-table">
          <thead>
            <tr>
              <th scope="col">المنتج</th>
              <th scope="col">الظهور</th>
              <th scope="col">متوسّط الرتبة</th>
              <th scope="col">CTR</th>
              <th scope="col">نقرة ← سلّة</th>
              <th scope="col">نقرة ← شراء</th>
            </tr>
          </thead>
          <tbody>
            {rows.length === 0 ? (
              <tr>
                <td colSpan={6}>{empty}</td>
              </tr>
            ) : (
              rows.map((r) => (
                <tr key={r.productId}>
                  <td>{r.title}</td>
                  <td>
                    <bdi dir="ltr">{count(r.impressions)}</bdi>
                  </td>
                  <td>
                    <bdi dir="ltr">{r.averageRank ?? '—'}</bdi>
                  </td>
                  <td>
                    <bdi dir="ltr">{formatRate(r.ctr)}</bdi>
                  </td>
                  <td>
                    <bdi dir="ltr">{formatRate(r.clickToCart)}</bdi>
                  </td>
                  <td>
                    <bdi dir="ltr">{formatRate(r.clickToPurchase)}</bdi>
                  </td>
                </tr>
              ))
            )}
          </tbody>
        </table>
      </div>
    </>
  );
}

export default function SearchIntelligenceScreen() {
  const { range, activePreset, setRange } = useDateRange();
  const [source, setSource] = useState('');
  const [device, setDevice] = useState('');
  const path = useMemo(() => {
    const qs = new URLSearchParams({ from: range.from, to: range.to });
    if (source) qs.set('source', source);
    if (device) qs.set('device', device);
    return `/admin/search/intelligence?${qs.toString()}`;
  }, [range.from, range.to, source, device]);
  const report = useResource<{ data: SearchIntelligenceReport }>(path);

  return (
    <div>
      <section className="panel">
        <h2>ذكاء البحث</h2>
        <DateRangePicker range={range} activePreset={activePreset} onChange={setRange} onRefresh={report.reload} generatedAt={null} />
        <div className="field-row">
          <label className="field field-inline" htmlFor="si-source">
            <span>المصدر</span>
            <select id="si-source" value={source} onChange={(e) => setSource(e.target.value)}>
              <option value="">الكلّ</option>
              <option value="search">بحث مكتوب</option>
              <option value="browse">تصفّح بالأوجه</option>
              <option value="autocomplete">إكمال تلقائيّ</option>
            </select>
          </label>
          <label className="field field-inline" htmlFor="si-device">
            <span>الجهاز</span>
            <select id="si-device" value={device} onChange={(e) => setDevice(e.target.value)}>
              <option value="">الكلّ</option>
              <option value="desktop">حاسوب</option>
              <option value="mobile">جوّال</option>
              <option value="tablet">لوحيّ</option>
            </select>
          </label>
        </div>
      </section>

      <ResourceView state={report.state} reload={report.reload}>
        {({ data: r }) => (
          <>
            <section className="panel" aria-labelledby="si-overview">
              <h3 id="si-overview">نظرة عامّة</h3>
              <div className="cards">
                <Kpi label="البحوث" value={count(r.overview.searches)} hint={`جلسات: ${count(r.overview.uniqueSessions)}`} />
                <Kpi label="بلا نتائج" value={formatRate(r.overview.zeroResult)} />
                <Kpi label="CTR (بحوثٌ لها ظهور)" value={formatRate(r.overview.ctr)} />
                <Kpi label="بحثٌ ← صفحة منتج" value={formatRate(r.overview.searchToPdp)} />
                <Kpi label="بحوثٌ بلا نقرة" value={formatRate(r.overview.noClickSearches)} />
                <Kpi label="استُعمل التعافي" value={formatRate(r.overview.recoveryUsage)} />
                <Kpi label="إعادة صياغة" value={formatRate(r.overview.reformulation)} hint="بحثٌ تالٍ خلال دقيقتين بلا نقرة" />
                <Kpi label="ترك" value={formatRate(r.overview.abandonment)} hint="ظهورٌ بلا نقرة ولا بحثٍ تالٍ خلال ٣٠ دقيقة" />
                <Kpi label="زمن الاستجابة p50 / p95 / p99" value={[r.overview.latencyMs.p50, r.overview.latencyMs.p95, r.overview.latencyMs.p99].map((v) => (v === null ? '—' : `${v}ms`)).join(' / ')} />
                <Kpi label="حصّة الموافِقين على القياس" value={formatRate(r.overview.consentedShare)} hint="مقامُ القمع والإيراد أدناه" />
              </div>
            </section>

            <section className="panel" aria-labelledby="si-funnel">
              <h3 id="si-funnel">القمع (بحوثٌ موافِقة: {count(r.funnel.consentedSearches)})</h3>
              <div className="cards">
                <Kpi label="بحث ← نقرة" value={formatRate(r.funnel.searchToClick)} />
                <Kpi label="بحث ← سلّة" value={formatRate(r.funnel.searchToCart)} hint={`إزالات: ${count(r.funnel.cartRemovals)}`} />
                <Kpi label="بحث ← طلب" value={formatRate(r.funnel.searchToCheckout)} />
                <Kpi label="بحث ← شراء مدفوع" value={formatRate(r.funnel.searchToPurchase)} />
                <Kpi label="إيرادٌ ساهم فيه البحث" value={money(r.funnel.revenueInfluencedMinor)} hint={`مسترَدّ: ${money(r.funnel.revenueRefundedMinor)}`} />
                <Kpi label="إيرادٌ لكلّ بحثٍ موافِق" value={r.funnel.revenuePerConsentedSearchMinor === null ? 'لا بيانات' : money(r.funnel.revenuePerConsentedSearchMinor)} />
              </div>
            </section>

            <section className="panel">
              <QueryTable title="أكثر الاستعلامات" rows={r.queries.top} empty="لا بحوث في هذه النافذة." />
              <QueryTable title="استعلامات بلا نتائج" rows={r.queries.zeroResult} empty="لا استعلام بلا نتائج." />
              <QueryTable title="استعلامات رُئيت نتائجها ولم يُنقر شيء" rows={r.queries.noClick} empty="لا استعلام بلا نقرة." />
              <QueryTable title="الأعلى تحويلًا" rows={r.queries.topConverting} empty="لا شراء مُسند إلى البحث بعد." />
              <QueryTable title="كثيرٌ البحث قليلٌ الشراء" rows={r.queries.highVolumeLowConversion} empty="لا استعلام كثيرُ الحجم بلا شراء." />
            </section>

            <section className="panel">
              <ProductTable title="ظهورٌ كثير ونقرٌ قليل" rows={r.products.highImpressionLowCtr} empty="لا ظهور مسجّل." />
              <ProductTable title="نقرٌ كثير وشراءٌ معدوم" rows={r.products.highClickLowPurchase} empty="لا منتج بهذا النمط." />
            </section>

            <section className="panel">
              <h3>CTR بالرتبة</h3>
              <div className="table-scroll" tabIndex={0} role="region" aria-label="CTR بالرتبة">
                <table className="data-table">
                  <thead>
                    <tr>
                      <th scope="col">الرتبة</th>
                      <th scope="col">CTR (نقرات / ظهور)</th>
                    </tr>
                  </thead>
                  <tbody>
                    {r.rankCtr.length === 0 ? (
                      <tr>
                        <td colSpan={2}>لا ظهور مسجّل.</td>
                      </tr>
                    ) : (
                      r.rankCtr.map((row) => (
                        <tr key={row.rank}>
                          <td>
                            <bdi dir="ltr">{row.rank}</bdi>
                          </td>
                          <td>
                            <bdi dir="ltr">{formatRate(row)}</bdi>
                          </td>
                        </tr>
                      ))
                    )}
                  </tbody>
                </table>
              </div>

              <h3>المرشِّحات المستعملة</h3>
              <ul className="plain-list">
                {r.filtersUsed.map((f) => (
                  <li key={f.dimension}>
                    {f.dimension}: <bdi dir="ltr">{formatRate(f)}</bdi>
                  </li>
                ))}
              </ul>

              <h3>التعافي</h3>
              <ul className="plain-list">
                {r.recovery.map((m) => (
                  <li key={m.mode}>
                    {recoveryLabel(m.mode)} — <bdi dir="ltr">{count(m.searches)}</bdi> · بلا نتائج <bdi dir="ltr">{formatRate(m.zeroResult)}</bdi> · CTR{' '}
                    <bdi dir="ltr">{formatRate(m.ctr)}</bdi>
                  </li>
                ))}
              </ul>

              <h3>المحرّكات</h3>
              <ul className="plain-list">
                {r.engines.map((e) => (
                  <li key={e.provider}>
                    <bdi dir="ltr">{e.provider}</bdi> — <bdi dir="ltr">{count(e.searches)}</bdi> بحث · p50/p95{' '}
                    <bdi dir="ltr">{`${e.p50 ?? '—'} / ${e.p95 ?? '—'} ms`}</bdi> · متدهور <bdi dir="ltr">{formatRate(e.degraded)}</bdi>
                  </li>
                ))}
              </ul>

              <h3>توزيع زمن الاستجابة</h3>
              <ul className="plain-list">
                {r.latency.map((b) => (
                  <li key={b.bucketMs}>
                    <bdi dir="ltr">{`${b.bucketMs} ms`}</bdi>: <bdi dir="ltr">{count(b.searches)}</bdi>
                  </li>
                ))}
              </ul>
            </section>
          </>
        )}
      </ResourceView>
    </div>
  );
}
