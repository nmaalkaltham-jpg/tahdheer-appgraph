// UNIT: _LIBRARY-RAIL
// FILE: _library-rail.tsx
// STYLE: inspector.module.css
// PARENT: INSPECTOR
// USES: LIB-ADMIN-API, MODEL
// API: /admin/products, GET /admin/navigation/pages
// MAP: docs/PROJECT_MAP.md

import { useCallback, useEffect, useMemo, useState } from 'react';
import { api, firstError, type CatalogTerm, type Paginated, type ProductRow } from '../../../lib/admin-api';
import { byId, type MenuNode, type NodeType } from './model';
import styles from './inspector.module.css';

/* ═══════════════ مكتبة المحتوى الحقيقي ═══════════════ */

type LibrarySource = 'products' | 'stages' | 'subjects' | 'custom' | 'pages';

export type InsertPosition = 'child' | 'after' | 'root';

interface Candidate {
  key: string;
  title: string;
  url: string;
  type: NodeType;
  sourceId: number | null;
  sourceApi: string;
  note?: string;
}

type LibraryState =
  | { status: 'idle' }
  | { status: 'loading' }
  | { status: 'error'; message: string }
  | { status: 'ready'; items: Candidate[] };

export interface LibraryRailProps {
  nodes: MenuNode[];
  selection: string[];
  onAdd(candidate: { label: string; url: string; type: NodeType; sourceId: number | null; sourceApi: string | null }, position: InsertPosition): void;
}

interface StaticPage {
  url: string;
  label: string;
}

/**
 * جرد الصفحات الثابتة من الخادم.
 *
 * المصدر نقطة نهاية حقيقية لا قائمة مكتوبة في الواجهة: قائمة الواجهة تنحرف
 * عن المسارات المخدومة فعلًا وتُدخل 404 في القائمة المنشورة.
 */
function StaticPages({
  usedUrls,
  onPick,
}: {
  /** المسار المُطبَّع ⇒ عدد مرّات استعماله في القائمة الحيّة. */
  usedUrls: Map<string, number>;
  onPick(page: StaticPage): void;
}) {
  const [pages, setPages] = useState<StaticPage[] | null>(null);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    let cancelled = false;
    void api.get<{ data: StaticPage[] }>('/admin/navigation/pages').then((result) => {
      if (cancelled) return;
      if (!result.ok) {
        setError(firstError(result));
        return;
      }
      setPages(result.data.data);
    });
    return () => {
      cancelled = true;
    };
  }, []);

  if (error) return <p role="alert">{error}</p>;
  if (pages === null) return <p>جارٍ قراءة جرد الصفحات…</p>;

  return (
    <div className={styles.libraryList}>
      {pages.map((page) => {
        // المستعمَل يُعطَّل لا يُخفى: الاختفاء يبدو نقصًا في الجرد.
        const used = usedUrls.has(page.url.replace(/\/+$/, '') || page.url);
        return (
          <button
            key={page.url}
            type="button"
            className={styles.libraryItem}
            disabled={used}
            onClick={() => onPick(page)}
          >
            <span>{page.label}</span>
            <bdi dir="ltr" className={styles.libraryMeta}>
              {page.url}
              {used ? ' · مُضاف' : ''}
            </bdi>
          </button>
        );
      })}
    </div>
  );
}

export function LibraryRail({ nodes, selection, onAdd }: LibraryRailProps) {
  const [source, setSource] = useState<LibrarySource>('products');
  const [query, setQuery] = useState('');
  const [applied, setApplied] = useState('');
  /*
   * الحالة تُشتقّ من مفتاح الطلب: ما لم يُحمَّل للمفتاح الحالي فهو «جارٍ التحميل»،
   * فلا حاجة لتصفير متزامن داخل الأثر.
   */
  const request = source === 'custom' || source === 'pages' ? null : `${source}:${applied}`;
  const [loaded, setLoaded] = useState<{ request: string; state: LibraryState } | null>(null);
  const state: LibraryState =
    request === null ? { status: 'idle' } : loaded?.request === request ? loaded.state : { status: 'loading' };
  const [position, setPosition] = useState<InsertPosition>('root');
  const [customUrl, setCustomUrl] = useState('');
  const [customLabel, setCustomLabel] = useState('');

  const selected = selection.length === 1 ? byId(nodes, selection[0] ?? null) : null;
  const usedUrls = useMemo(() => {
    const counts = new Map<string, number>();
    for (const node of nodes) {
      if (node.deleted) continue;
      const key = node.url.replace(/\/+$/, '');
      if (key) counts.set(key, (counts.get(key) ?? 0) + 1);
    }
    return counts;
  }, [nodes]);

  useEffect(() => {
    if (request === null) return;
    let cancelled = false;
    const setState = (next: LibraryState) => setLoaded({ request, state: next });

    const load = async (): Promise<void> => {
      if (source === 'products') {
        const search = applied.trim() === '' ? '' : `&q=${encodeURIComponent(applied.trim())}`;
        const result = await api.get<Paginated<ProductRow>>(`/admin/products?perPage=20${search}`);
        if (cancelled) return;
        if (!result.ok) {
          setState({ status: 'error', message: result.message });
          return;
        }
        setState({
          status: 'ready',
          items: result.data.data.map((product) => ({
            key: `product-${product.id}`,
            title: product.title,
            url: `/products/${product.slug}`,
            type: 'product' as NodeType,
            sourceId: product.id,
            sourceApi: 'GET /api/v1/admin/products',
            note: product.status === 'published' ? undefined : 'غير منشور — الزائر لن يصل إليه',
          })),
        });
        return;
      }

      const dimension = source === 'stages' ? 'stages' : 'subjects';
      const result = await api.get<{ data: CatalogTerm[] }>(`/catalog/${dimension}`);
      if (cancelled) return;
      if (!result.ok) {
        setState({ status: 'error', message: result.message });
        return;
      }
      setState({
        status: 'ready',
        items: result.data.data.map((term) => ({
          key: `${dimension}-${term.id}`,
          title: term.name,
          url: `/${dimension}/${term.slug}`,
          type: 'category' as NodeType,
          sourceId: term.id,
          sourceApi: `GET /api/v1/catalog/${dimension}`,
        })),
      });
    };

    void load();
    return () => {
      cancelled = true;
    };
  }, [request, source, applied]);

  const add = useCallback(
    (candidate: Candidate) => {
      onAdd(
        {
          label: candidate.title,
          url: candidate.url,
          type: candidate.type,
          sourceId: candidate.sourceId,
          sourceApi: candidate.sourceApi,
        },
        position,
      );
    },
    [onAdd, position],
  );

  return (
    <div className={styles.card}>
      <header className={styles.cardHead}>
        <span className={styles.cardTitle}>مكتبة المحتوى الحقيقي</span>
      </header>

      <div className={styles.libraryTop}>
        <div className={styles.segRow} role="group" aria-label="مصدر المحتوى">
          {(
            [
              ['products', 'منتجات'],
              ['stages', 'مراحل'],
              ['subjects', 'مواد'],
              ['pages', 'صفحات'],
              ['custom', 'رابط مخصص'],
            ] as [LibrarySource, string][]
          ).map(([key, label]) => (
            <button
              key={key}
              type="button"
              className={styles.segBtn}
              aria-pressed={source === key}
              onClick={() => setSource(key)}
            >
              {label}
            </button>
          ))}
        </div>

        <fieldset className={styles.fieldset}>
          <legend>موضع الإدراج</legend>
          <div className={styles.segRow}>
            <button
              type="button"
              className={styles.segBtn}
              aria-pressed={position === 'root'}
              onClick={() => setPosition('root')}
            >
              نهاية المستوى الأعلى
            </button>
            <button
              type="button"
              className={styles.segBtn}
              aria-pressed={position === 'after'}
              disabled={!selected}
              onClick={() => setPosition('after')}
            >
              بعد المحدد
            </button>
            <button
              type="button"
              className={styles.segBtn}
              aria-pressed={position === 'child'}
              disabled={!selected}
              onClick={() => setPosition('child')}
            >
              داخل المحدد
            </button>
          </div>
          {selected ? (
            <p className={styles.libraryHint}>بالنسبة للعنصر: «{selected.label || '⟨بلا نص⟩'}»</p>
          ) : (
            <p className={styles.libraryHint}>لا عنصر محدد — الإدراج في نهاية المستوى الأعلى.</p>
          )}
        </fieldset>
      </div>

      {source === 'custom' ? (
        <div className={styles.customLink}>
          <label className={styles.field}>
            <span>الرابط</span>
            <input
              type="text"
              dir="ltr"
              className={styles.mono}
              placeholder="/offers"
              value={customUrl}
              onChange={(event) => setCustomUrl(event.target.value)}
            />
          </label>
          <label className={styles.field}>
            <span>النص الظاهر</span>
            <input
              type="text"
              value={customLabel}
              onChange={(event) => setCustomLabel(event.target.value)}
            />
          </label>
          {/^\s*javascript:/i.test(customUrl) ? (
            <p className={styles.fieldError} role="alert">
              javascript: مرفوض — لن يُضاف.
            </p>
          ) : null}
          <button
            type="button"
            className={styles.primaryBtn}
            disabled={customUrl.trim() === '' || customLabel.trim() === '' || /^\s*javascript:/i.test(customUrl)}
            onClick={() => {
              onAdd(
                {
                  label: customLabel.trim(),
                  url: customUrl.trim(),
                  type: 'custom',
                  sourceId: null,
                  sourceApi: null,
                },
                position,
              );
              setCustomUrl('');
              setCustomLabel('');
            }}
          >
            إضافة الرابط
          </button>
        </div>
      ) : source === 'pages' ? (
        <StaticPages usedUrls={usedUrls} onPick={(page) =>
          onAdd(
            { label: page.label, url: page.url, type: 'page', sourceId: null, sourceApi: 'GET /api/v1/admin/navigation/pages' },
            position,
          )
        } />
      ) : (
        <>
          <form
            className={styles.searchRow}
            onSubmit={(event) => {
              event.preventDefault();
              setApplied(query);
            }}
          >
            <label className={styles.field}>
              <span className={styles.srOnly}>بحث في المحتوى</span>
              <input
                type="search"
                value={query}
                placeholder="ابحث بالعنوان أو الرمز…"
                onChange={(event) => setQuery(event.target.value)}
                disabled={source !== 'products'}
              />
            </label>
            <button type="submit" className={styles.softBtn} disabled={source !== 'products'}>
              بحث
            </button>
          </form>

          <div className={styles.libraryList}>
            {state.status === 'loading' ? (
              <p className={styles.railStatus} role="status" aria-live="polite">
                جارٍ التحميل…
              </p>
            ) : state.status === 'error' ? (
              <p className={styles.railError} role="alert">
                {state.message}
              </p>
            ) : state.status === 'ready' && state.items.length === 0 ? (
              <p className={styles.railStatus}>لا نتائج مطابقة في المحتوى الحقيقي.</p>
            ) : state.status === 'ready' ? (
              state.items.map((candidate) => {
                const used = usedUrls.get(candidate.url.replace(/\/+$/, '')) ?? 0;
                return (
                  <div key={candidate.key} className={styles.libraryRow}>
                    <div className={styles.libraryRowBody}>
                      <span className={styles.libraryTitle}>
                        {candidate.title}
                        {used > 0 ? (
                          <span className={styles.usedChip}>
                            مستخدم <bdi dir="ltr">×{used}</bdi>
                          </span>
                        ) : null}
                        {candidate.note ? <span className={styles.warnChip}>{candidate.note}</span> : null}
                      </span>
                      <bdi dir="ltr" className={styles.libraryUrl}>
                        {candidate.url}
                      </bdi>
                    </div>
                    <button type="button" className={styles.addBtn} onClick={() => add(candidate)}>
                      إضافة
                    </button>
                  </div>
                );
              })
            ) : null}
          </div>
        </>
      )}
    </div>
  );
}
