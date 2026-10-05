'use client';
// UNIT: ASSET-LIBRARY
// FILE: asset-library.tsx
// STYLE: none
// PARENT: LIBRARY-PANEL, _SCAN-PANELS
// USES: LIB-ADMIN-API, LIB-FORMAT, RESOURCE
// API: /admin/media
// MAP: docs/PROJECT_MAP.md

/**
 * مكتبة الأصول — ما لا تراه صفحة منتج.
 *
 * ─── لماذا فوق الشاشة القائمة لا بدلًا منها ───
 *
 * عرض الأغلفة أسفل الشاشة يشتقّ صفوفه من المنتجات المنشورة: لا حجم ولا
 * بصمة ولا يتيم — لأنّ مصدره يعرض المستخدَم وحده بحكم بنائه. هذه اللوحة هي
 * المكتبة نفسها، وهي مصدر الحجم والبصمة واليتيم لكلّ لوحٍ في الشاشة، ولا
 * تهدم ما يعمل.
 *
 * ─── الرقمان اللذان يستحقّان الصدارة ───
 *
 * **اليتيم**: أصلٌ بلا صفّ استعمال يشغل مساحةً ولا يخدم شيئًا، ولا يظهر
 * في أيّ صفحة منتج بحكم التعريف. و**المشترَك**: أصلٌ يخدم منتجَين، وحذفه
 * من أحدهما لا يعني حذفه — وهي الحالة التي يخطئ فيها الحذف.
 *
 * ولا مفتاح مخزن ولا بصمة كاملة تصل هنا: الخادم يقتطع ويحجب قبل الإرسال.
 */

import { useState, type ReactNode } from 'react';
import { EmptyRows, ResourceView, useResource } from '../../../components/resource';
import type { AssetLibrary, AssetUsageRow, AssetRow } from '../../../lib/admin-api';
import { bytes, count, dateTime } from '../../../lib/format';

export { bytes } from '../../../lib/format';

/**
 * قراءة أيّ مسار في مكتبة الأصول بفرع منعٍ صريح.
 *
 * `GET /admin/media` و`GET /admin/media/{id}` كلاهما خلف `products.read`
 * (`routes/api-admin.php:191-195`)، والصلاحية نفسها التي تحرس وسائط المنتج
 * الواحد. الردّ يصل رسالةً عامّة، ومن لا يملك الحقّ يحتاج اسمه لا زرّ «إعادة
 * المحاولة» — الإعادة لن تُغيّر شيئًا، وعرضها وعدٌ كاذب.
 *
 * وهو أيضًا المدخل الوحيد لكلّ من يقرأ المكتبة في هذه الشاشة: رسالة منعٍ
 * واحدة لا أربع نسخ تتباعد.
 */
export function AssetsView<T>({ path, children }: { path: string; children: (data: T) => ReactNode }) {
  const { state, reload } = useResource<T>(path);

  if (state.status === 'error' && state.code === 403) {
    return (
      <div className="state-error" role="alert">
        <p>
          لا تملك صلاحية <bdi dir="ltr">products.read</bdi> لقراءة مكتبة الأصول. كلّ مسارات{' '}
          <bdi dir="ltr">/admin/media</bdi> خلفها.
        </p>
      </div>
    );
  }

  return (
    <ResourceView state={state} reload={reload}>
      {children}
    </ResourceView>
  );
}

const KIND_LABELS: Record<string, string> = {
  image: 'صور',
  document: 'مستندات',
  archive: 'أرشيف',
  other: 'أخرى',
};

const VISIBILITY_LABELS: Record<string, string> = {
  public_sample: 'عيّنة عامّة',
  paid_private: 'مدفوع خاصّ',
};

const USAGE_FILTERS = [
  { value: '', label: 'الكلّ' },
  { value: 'used', label: 'مستعمَل' },
  { value: 'orphan', label: 'يتيم' },
];

export function AssetLibraryPanel() {
  const [kind, setKind] = useState('');
  const [usage, setUsage] = useState('');
  const [query, setQuery] = useState('');
  const [applied, setApplied] = useState({ kind: '', usage: '', q: '' });
  const [openId, setOpenId] = useState<number | null>(null);

  const params = new URLSearchParams();
  if (applied.kind) params.set('kind', applied.kind);
  if (applied.usage) params.set('usage', applied.usage);
  if (applied.q) params.set('q', applied.q);
  const suffix = params.toString();

  return (
    <section className="panel">
      <h2>مكتبة الأصول</h2>
      <p className="page-note">
        كلّ أصل مسجَّل، لا أغلفة المنتجات المنشورة وحدها. من هنا وحده يُرى اليتيم — أصلٌ لا يستعمله
        منتج، فلا يظهر في أيّ صفحة منتج بحكم التعريف.
      </p>

      <AssetsView<AssetLibrary> path={`/admin/media${suffix ? `?${suffix}` : ''}`}>
        {(page) => (
          <>
            <div className="cards">
              <article className="card">
                <div className="label">أصول</div>
                <div className="value">
                  <bdi dir="ltr">{count(page.summary.assets)}</bdi>
                </div>
                <p className="card-reason">{bytes(page.summary.bytes)} إجمالًا</p>
              </article>
              <article className={page.summary.orphans > 0 ? 'card card-alert' : 'card'}>
                <div className="label">يتيمة</div>
                <div className="value">
                  <bdi dir="ltr">{count(page.summary.orphans)}</bdi>
                </div>
                <p className="card-reason">{bytes(page.summary.orphanBytes)} لا تخدم شيئًا</p>
              </article>
              <article className="card">
                <div className="label">مشترَكة</div>
                <div className="value">
                  <bdi dir="ltr">{count(page.summary.shared)}</bdi>
                </div>
                <p className="card-reason">تخدم منتجَين فأكثر — الحذف هنا يخطئ</p>
              </article>
              <article className="card">
                <div className="label">مدفوعة خاصّة</div>
                <div className="value">
                  <bdi dir="ltr">{count(page.summary.paidPrivate)}</bdi>
                </div>
                <p className="card-reason">لا تُقدَّم إلّا لمن يملك حقًّا</p>
              </article>
            </div>

            <form
              className="filters list-toolbar"
              onSubmit={(event) => {
                event.preventDefault();
                setApplied({ kind, usage, q: query });
              }}
            >
              <label className="field field-inline" htmlFor="asset-kind">
                <span>النوع</span>
                <select id="asset-kind" value={kind} onChange={(event) => setKind(event.target.value)}>
                  <option value="">الكلّ</option>
                  {page.filters.kinds.map((value) => (
                    <option key={value} value={value}>
                      {KIND_LABELS[value] ?? value} ({count(page.summary.byKind[value] ?? 0)})
                    </option>
                  ))}
                </select>
              </label>
              <label className="field field-inline" htmlFor="asset-usage">
                <span>الاستعمال</span>
                <select id="asset-usage" value={usage} onChange={(event) => setUsage(event.target.value)}>
                  {USAGE_FILTERS.map((option) => (
                    <option key={option.value} value={option.value}>
                      {option.label}
                    </option>
                  ))}
                </select>
              </label>
              <label className="field field-inline" htmlFor="asset-q">
                <span>بحث</span>
                <input
                  id="asset-q"
                  type="search"
                  value={query}
                  placeholder="اسم الملفّ"
                  onChange={(event) => setQuery(event.target.value)}
                />
              </label>
              <button type="submit" className="btn btn-primary">
                تطبيق
              </button>
              <span className="list-toolbar-end">
                <bdi dir="ltr">{count(page.data.length)}</bdi> من{' '}
                <bdi dir="ltr">{count(page.meta.total)}</bdi>
              </span>
            </form>

            {/* سبعة أعمدة: طوقٌ يُمرَّر ويُبأَّر بلوحة المفاتيح دون سحب المستند. */}
            <div className="table-scroll" tabIndex={0} role="region" aria-label="مكتبة الأصول">
            <table className="data-table">
              <caption className="sr-only">مكتبة الأصول</caption>
              <thead>
                <tr>
                  <th scope="col">الملفّ</th>
                  <th scope="col">النوع</th>
                  <th scope="col">الحجم</th>
                  <th scope="col">الظهور</th>
                  <th scope="col">يستعمله</th>
                  <th scope="col">أُضيف</th>
                  <th scope="col">الاستعمال</th>
                </tr>
              </thead>
              <tbody>
                {page.data.length === 0 ? (
                  <EmptyRows colSpan={7} message="لا أصل بهذا المرشِّح." />
                ) : (
                  page.data.map((row) => <Row key={row.id} row={row} open={openId} onOpen={setOpenId} />)
                )}
              </tbody>
            </table>
            </div>
          </>
        )}
      </AssetsView>

      {openId === null ? null : <Usage assetId={openId} />}
    </section>
  );
}

function Row({
  row,
  open,
  onOpen,
}: {
  row: AssetRow;
  open: number | null;
  onOpen: (id: number | null) => void;
}) {
  return (
    <tr>
      <th scope="row">
        {row.name}
        <span className="sourceNote" style={{ display: 'block', color: 'var(--text-muted)', fontSize: 12 }}>
          <bdi dir="ltr">{row.fingerprint}</bdi>
          {row.counterDrift ? ' · عدّاد الاستعمال منحرف' : ''}
        </span>
      </th>
      <td>
        <bdi dir="ltr">{row.mime}</bdi>
      </td>
      <td>{bytes(row.sizeBytes)}</td>
      <td>{VISIBILITY_LABELS[row.visibility] ?? row.visibility}</td>
      <td>
        {row.orphan ? (
          <b className="chip chip-failed">يتيم</b>
        ) : (
          <bdi dir="ltr">{count(row.usedBy)}</bdi>
        )}
      </td>
      <td>{row.at ? dateTime(row.at) : <span className="absent">—</span>}</td>
      <td>
        {row.orphan ? (
          <span className="absent">—</span>
        ) : (
          <button type="button" className="btn btn-ghost" onClick={() => onOpen(open === row.id ? null : row.id)}>
            {open === row.id ? 'إخفاء' : 'عرض'}
          </button>
        )}
      </td>
    </tr>
  );
}

/** مواضع استعمال أصلٍ واحد — بأيّ منتج وبأيّ دور. */
function Usage({ assetId }: { assetId: number }) {
  return (
    <AssetsView<{ data: { name: string; usage: AssetUsageRow[] } }> path={`/admin/media/${assetId}`}>
      {(payload) => (
        <>
          <h3>مواضع استعمال «{payload.data.name}»</h3>
          {/* طوقٌ يُمرَّر ويُبأَّر بلوحة المفاتيح. */}
          <div className="table-scroll" tabIndex={0} role="region" aria-label="مواضع الاستعمال">
          <table className="data-table">
            <caption className="sr-only">مواضع الاستعمال</caption>
            <thead>
              <tr>
                <th scope="col">المنتج</th>
                <th scope="col">الرمز</th>
                <th scope="col">الدور</th>
                <th scope="col">الحالة</th>
              </tr>
            </thead>
            <tbody>
              {payload.data.usage.length === 0 ? (
                <EmptyRows colSpan={4} message="لا استعمال مسجَّل." />
              ) : (
                payload.data.usage.map((use) => (
                  <tr key={`${use.productId}-${use.role}`}>
                    <td>
                      <a href={`/studio/catalog/${use.productId}`}>
                        {use.productTitle ?? `منتج #${use.productId}`}
                      </a>
                    </td>
                    <td>
                      <bdi dir="ltr">{use.productSku ?? '—'}</bdi>
                    </td>
                    <td>
                      <bdi dir="ltr">{use.role}</bdi>
                    </td>
                    <td>{use.productStatus ?? '—'}</td>
                  </tr>
                ))
              )}
            </tbody>
          </table>
          </div>
        </>
      )}
    </AssetsView>
  );
}
