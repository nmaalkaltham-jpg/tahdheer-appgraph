'use client';
// UNIT: COMPOSER-CLIENT
// FILE: composer-client.tsx
// STYLE: composer.module.css
// PARENT: PAGE-STUDIO-CATALOG-ID-LAYOUT
// USES: ADMIN-SESSION, LIB-ADMIN-API, LIB-FORMAT, RESOURCE, STUDIO-DATA, WIDGET-REGISTRY
// API: /admin/products
// MAP: docs/PROJECT_MAP.md

/**
 * منشئ صفحة المنتج — سجلّ الودجات الخمسين، وستة قوالب، وكثافة، ومعاينة جهاز،
 * فوق وثيقة تخطيط تُحفظ وتُنشر فعلًا.
 *
 * التخطيط المحفوظ يعيش في `product_layouts` ويُقرأ ويُكتب عبر
 * GET/PATCH /admin/products/{id}/layout ثم POST …/layout/publish. الوثيقة
 * تُرسل بالشكل الذي يتحقّق منه الخادم حرفيًّا: `{ layout: { sections }, version }`
 * — إرسال `sections` عاريةً يعيد 422 «وثيقة التخطيط مطلوبة».
 *
 * ‏`version` تفاؤلية: الخادم يعيد 409 برمز VERSION_CONFLICT ونسخته الحالية إن
 * حفظ محرّر آخر بعد قراءتنا. لا نكتب فوقه صامتين — نعيد التحميل ونُعلن التعارض.
 *
 * قائمة السماح مغلقة على الخادم (App\Domain\Design\ProductLayout::WIDGETS):
 * ودجة خارجها تُسقَط في التطبيع. لذلك يُفصَل السطحان بصراحة — ما يُحفظ هو
 * أقسام الخادم بأسمائها هي، وسجلّ الخمسين معاينة تصميم لا تُخزَّن. زرّ حفظ
 * على الخمسين يَعِد بما يمحوه التطبيع.
 *
 * لا ودجة تعرض قيمة لا مصدر لها — كل بطاقة تُظهر إمّا بيانات المنتج الحقيقية
 * وإمّا اسم ما ينقص لتشتغل (bindWidget في widget-registry.ts).
 */

import Link from 'next/link';
import { useCallback, useEffect, useMemo, useState, type ReactNode } from 'react';
import { useSession } from '../../../../../../components/admin-session';
import {
  PageHeader,
  ResourceView,
  useResource,
  type ResourceState,
} from '../../../../../../components/resource';
import { api, firstError } from '../../../../../../lib/admin-api';
import { count } from '../../../../../../lib/format';
import { cx, type LayoutPayload, type LayoutSection, type StudioProduct } from '../../studio-data';
import styles from './composer.module.css';
import {
  bindWidget,
  GROUPS,
  kindOf,
  PRESETS,
  relevanceReason,
  TYPE_LABELS,
  widgetById,
  WIDGETS,
  type Binding,
  type ProductKind,
} from './widget-registry';

type Density = 'compact' | 'balanced' | 'rich';
type Device = 'desktop' | 'mobile';

const DENSITY_LABELS: Record<Density, string> = {
  compact: 'مضغوطة',
  balanced: 'متوازنة',
  rich: 'غنية',
};

const DENSITY_ROWS: Record<Density, number> = { compact: 2, balanced: 4, rich: 6 };

const STATE_LABELS: Record<Binding['state'], string> = {
  data: 'بيانات حقيقية',
  empty: 'لا قيمة لهذا المنتج',
  behaviour: 'سلوك واجهة',
  unwired: 'لا مصدر',
};

/**
 * الخطأ يُقرأ برمزه لا بنصّ الخادم وحده — كما في شاشة الاستوديو نفسها.
 *
 * `findOrFail` يردّ 404 و`AdminAuthorize` يردّ 403. المنشئ يُفتح من رابطٍ يحمل
 * معرّف المنتج، ورابطٌ قديم كان يعرض «إعادة المحاولة» على منتجٍ لن يعود.
 */
function ProductState({ state, reload, children }: {
  state: ResourceState<{ data: StudioProduct }>;
  reload: () => void;
  children: (product: StudioProduct) => ReactNode;
}) {
  if (state.status === 'error' && state.code === 404) {
    return (
      <div className="state-error" role="alert">
        <p>لا منتج بهذا المعرّف — لا صفحة تُبنى له. قد يكون حُذف، أو يكون الرابط قديمًا.</p>
        <Link className="btn btn-ghost" href="/studio/catalog">
          العودة إلى قائمة المنتجات
        </Link>
      </div>
    );
  }

  if (state.status === 'error' && state.code === 403) {
    return (
      <div className="state-error" role="alert">
        <p>
          لا تملك صلاحية <bdi dir="ltr">products.read</bdi> لقراءة المنتجات.
        </p>
      </div>
    );
  }

  return (
    <ResourceView state={state} reload={reload}>
      {(payload) => children(payload.data)}
    </ResourceView>
  );
}

export default function ComposerClient({ productId }: { productId: string }) {
  const { can } = useSession();
  const { state, reload } = useResource<{ data: StudioProduct }>(`/admin/products/${productId}`);

  return (
    <section>
      <PageHeader
        trail="المنتجات › استوديو المنتج › منشئ الصفحة"
        title="منشئ صفحة المنتج"
        actions={
          <Link className="btn btn-ghost" href={`/studio/catalog/${productId}`}>
            رجوع للاستوديو
          </Link>
        }
      />
      <ProductState state={state} reload={reload}>
        {(product) => (
          <Composer
            product={product}
            canEdit={can('products.update')}
            canPublish={can('products.publish')}
          />
        )}
      </ProductState>
    </section>
  );
}

function Composer({
  product,
  canEdit,
  canPublish,
}: {
  product: StudioProduct;
  canEdit: boolean;
  canPublish: boolean;
}) {
  const kind = kindOf(product);
  const saved = useSavedLayout(product.id);
  const defaultPreset = useMemo(() => PRESETS.find((preset) => preset.type === kind) ?? PRESETS[0], [kind]);

  const [presetKey, setPresetKey] = useState<string>(defaultPreset?.key ?? '');
  const [order, setOrder] = useState<number[]>(() => [...(defaultPreset?.widgets ?? [])]);
  const [density, setDensity] = useState<Density>('balanced');
  const [device, setDevice] = useState<Device>('desktop');
  const [toast, setToast] = useState<{ id: number; tone: 'ok' | 'error'; text: string } | null>(null);

  const say = useCallback((text: string, tone: 'ok' | 'error' = 'ok') => {
    setToast({ id: Date.now(), tone, text });
  }, []);

  /* الإشعار يختفي بنفسه؛ المؤقّت يُلغى عند تبديله فلا يبتلع الرسالة التالية. */
  useEffect(() => {
    if (toast === null) return;
    const timer = window.setTimeout(() => setToast(null), 6000);
    return () => window.clearTimeout(timer);
  }, [toast]);

  /** الودجات غير الملائمة لنوع المنتج لا تُركَّب أصلًا. */
  const active = useMemo(
    () =>
      order
        .map((id) => widgetById(id))
        .filter((widget): widget is NonNullable<typeof widget> => widget !== undefined)
        .filter((widget) => widget.types.includes(kind)),
    [order, kind],
  );

  const activeNames = active.map((widget) => widget.name);

  function applyPreset(key: string) {
    const preset = PRESETS.find((candidate) => candidate.key === key);
    if (!preset) return;
    setPresetKey(key);
    setOrder([...preset.widgets]);
    const usable = preset.widgets
      .map((id) => widgetById(id))
      .filter((widget) => widget !== undefined && widget.types.includes(kind)).length;
    say(
      `طُبِّق قالب «${preset.name}» — ${usable} ودجة ملائمة لنوع هذا المنتج من ${preset.widgets.length} في القالب. المعاينة وحدها؛ المحفوظ أقسام الخادم.`,
    );
  }

  function toggle(id: number) {
    setOrder((current) => (current.includes(id) ? current.filter((item) => item !== id) : [...current, id]));
  }

  function move(id: number, delta: number) {
    setOrder((current) => {
      const index = current.indexOf(id);
      const target = index + delta;
      if (index === -1 || target < 0 || target >= current.length) return current;
      const next = [...current];
      const [moved] = next.splice(index, 1);
      if (moved === undefined) return current;
      next.splice(target, 0, moved);
      return next;
    });
  }

  const rowCap = DENSITY_ROWS[density];

  return (
    <>
      <div className={styles.bar}>
        <span className={styles.barBrand}>
          <b>منشئ صفحة المنتج</b>
          <span className={styles.barCount}>
            <bdi dir="ltr">{count(WIDGETS.length)}</bdi> ودجة
          </span>
        </span>

        <span className={styles.divider} aria-hidden="true" />

        {/*
          عرضٌ للقراءة، ومبدّلُه في «أساسيات المنتج» لا هنا.

          كان النصّ يقول «لا نقطة نهاية تغيّره»، وصار ذلك غير صحيح: النوع
          يُكتب عند الإنشاء ويُبدَّل بـ`PATCH /admin/products/{id}`. لكنّ
          تبديله يُعيد كتابة استراتيجيّات التسليم واستراتيجيّة المخزون معًا،
          فمحلُّه حيث تُرى تلك الآثار — لا في شريط أدوات منشئ الصفحة.
        */}
        <span className={styles.types} role="group" aria-label="نوع المنتج — يُبدَّل من أساسيات المنتج">
          {(Object.keys(TYPE_LABELS) as ProductKind[]).map((type) => {
            const isCurrent = type === kind;
            return (
              <span key={type} className={styles.typeWrap}>
                <span className={cx(styles.type, isCurrent && styles.typeOn)}>
                  {TYPE_LABELS[type]}
                  <span className="sr-only">{isCurrent ? ' — النوع الحالي' : ' — ليس نوع هذا المنتج'}</span>
                </span>
                {isCurrent ? null : <span className={styles.typeWhy}>ليس نوعه</span>}
              </span>
            );
          })}
        </span>

        <label className={styles.control}>
          قالب جاهز
          <select value={presetKey} onChange={(event) => applyPreset(event.target.value)}>
            {PRESETS.map((preset) => (
              <option key={preset.key} value={preset.key} disabled={preset.type !== kind}>
                {preset.name}
                {preset.type === kind ? '' : ` — لنوع ${TYPE_LABELS[preset.type]}`}
              </option>
            ))}
          </select>
        </label>

        <label className={styles.control}>
          الكثافة
          <select value={density} onChange={(event) => setDensity(event.target.value as Density)}>
            {(Object.keys(DENSITY_LABELS) as Density[]).map((value) => (
              <option key={value} value={value}>
                {DENSITY_LABELS[value]}
              </option>
            ))}
          </select>
        </label>

        <span className={styles.devices} role="group" aria-label="معاينة الجهاز">
          {(['desktop', 'mobile'] as Device[]).map((value) => (
            <button
              key={value}
              type="button"
              className={cx(styles.device, device === value && styles.deviceOn)}
              aria-pressed={device === value}
              onClick={() => setDevice(value)}
            >
              {value === 'desktop' ? 'كمبيوتر' : 'جوال'}
            </button>
          ))}
        </span>

        <span className={styles.tally}>
          <bdi dir="ltr">{count(active.length)}</bdi> من <bdi dir="ltr">{count(WIDGETS.length)}</bdi> نشطة
        </span>
      </div>

      <SavedLayout
        state={saved}
        canEdit={canEdit}
        canPublish={canPublish}
        onSay={say}
      />

      <p className={styles.typeNote}>
        نوع المنتج مقروء من <code dir="ltr">productNature</code> وهو <b>{TYPE_LABELS[kind]}</b>. يُبدَّل
        من «أساسيات المنتج» لا من هنا، لأنّ تبديله يُعيد كتابة طريقة التسليم واستراتيجيّة المخزون
        معًا — وأثرُه يُرى هناك. وقوالب الأنواع الأخرى وودجاتها معطّلة لأنّها ليست نوع هذا المنتج.
      </p>

      <p className={styles.previewNote}>
        <span className={styles.previewTag}>معاينة</span>
        <span>
          سجلّ الودجات أدناه معاينة لقائمة التصميم الخمسين ولا يُخزَّن: قائمة السماح على الخادم
          (<code dir="ltr">ProductLayout::WIDGETS</code>) تُسقط ما ليس فيها عند التطبيع، وزرّ حفظ عليها
          يَعِد بما يُمحى. ما يُحفظ ويُنشر هو أقسام «التخطيط المحفوظ» أعلاه.
        </span>
      </p>

      <div className={styles.grid}>
        <aside className={styles.manager}>
          <div className={styles.managerHead}>
            <span className={styles.managerTitle}>مدير الودجات</span>
            <span className={styles.managerNote}>
              فعّل، عطّل، ورتّب. ما لا يلائم نوع هذا المنتج معطّل بسببه لا مخفيًّا.
            </span>
          </div>
          <div className={styles.managerBody}>
            {GROUPS.map((group) => {
              const rows = WIDGETS.filter((widget) => widget.group === group.key);
              return (
                <div key={group.key} className={styles.group}>
                  <span className={styles.groupHead}>
                    <span aria-hidden="true" className={styles.groupDot} style={{ background: group.color }} />
                    {group.name}
                    <span className={styles.groupCount}>
                      <bdi dir="ltr">{count(rows.length)}</bdi>
                    </span>
                  </span>
                  <ul className={styles.rows}>
                    {rows.map((widget) => {
                      const relevant = widget.types.includes(kind);
                      const on = relevant && order.includes(widget.id);
                      return (
                        <li key={widget.id} className={cx(styles.row, !relevant && styles.rowOff)}>
                          <button
                            type="button"
                            role="switch"
                            aria-checked={on}
                            aria-label={widget.name}
                            className={cx(styles.switch, on && styles.switchOn)}
                            disabled={!relevant}
                            onClick={() => toggle(widget.id)}
                          >
                            <span aria-hidden="true" className={styles.knob} />
                          </button>
                          <span className={styles.rowName}>
                            <bdi dir="ltr">{widget.id}</bdi>. {widget.name}
                          </span>
                          {relevant ? null : (
                            <span className={styles.rowWhy}>{relevanceReason(widget)}</span>
                          )}
                          {on ? (
                            <>
                              <button
                                type="button"
                                className={styles.moveBtn}
                                aria-label={`تحريك ${widget.name} لأعلى`}
                                onClick={() => move(widget.id, -1)}
                              >
                                <Chevron up />
                              </button>
                              <button
                                type="button"
                                className={styles.moveBtn}
                                aria-label={`تحريك ${widget.name} لأسفل`}
                                onClick={() => move(widget.id, 1)}
                              >
                                <Chevron />
                              </button>
                            </>
                          ) : null}
                        </li>
                      );
                    })}
                  </ul>
                </div>
              );
            })}
          </div>
        </aside>

        <div className={styles.stage}>
          <div className={cx(styles.frame, device === 'mobile' && styles.frameMobile)}>
            <div className={styles.frameHead}>
              <span className={styles.crumb}>
                الرئيسية › المتجر › <b>{product.title}</b>
              </span>
              <bdi dir="ltr" className={styles.crumbSku}>
                {product.sku}
              </bdi>
            </div>

            <div className={cx(styles.canvas, styles[`gap_${density}`])}>
              {active.length === 0 ? (
                <p className={styles.emptyStage}>
                  لا ودجة نشطة. فعّل ودجة من المدير، أو اختر قالبًا جاهزًا.
                </p>
              ) : null}

              {active.map((widget) => {
                const binding = bindWidget(widget.id, product, activeNames);
                return (
                  <section key={widget.id} className={styles.widget} aria-label={widget.name}>
                    <div className={styles.widgetHead}>
                      <span className={styles.widgetNum}>
                        <bdi dir="ltr">{widget.id}</bdi>
                      </span>
                      <h2 className={styles.widgetName}>{widget.name}</h2>
                      <span className={cx(styles.widgetState, styles[`st_${binding.state}`])}>
                        {STATE_LABELS[binding.state]}
                      </span>
                    </div>
                    <div className={styles.widgetBody}>
                      <WidgetBody binding={binding} rowCap={rowCap} />
                    </div>
                  </section>
                );
              })}
            </div>
          </div>
        </div>
      </div>

      {/*
        إشعار مرئي ومنطوق معًا: الإعلان لقارئ الشاشة وحده يترك المُبصر بلا
        تأكيد على فعل وقع فعلًا.
      */}
      <div className={styles.toastViewport} role="status" aria-live="polite">
        {toast === null ? null : (
          <div key={toast.id} className={cx(styles.toast, toast.tone === 'error' && styles.toastError)}>
            {toast.text}
          </div>
        )}
      </div>
    </>
  );
}

/* ───── وثيقة التخطيط المحفوظة ───── */

interface SavedLayoutState {
  data: LayoutPayload | null;
  sections: LayoutSection[] | null;
  error: string | null;
  busy: boolean;
  setSections: (next: LayoutSection[]) => void;
  save: () => Promise<{ ok: true } | { ok: false; message: string }>;
  publish: () => Promise<{ ok: true } | { ok: false; message: string }>;
  reload: () => void;
}

function useSavedLayout(productId: number): SavedLayoutState {
  const base = `/admin/products/${productId}/layout`;
  const [data, setData] = useState<LayoutPayload | null>(null);
  const [sections, setSections] = useState<LayoutSection[] | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const [nonce, setNonce] = useState(0);

  useEffect(() => {
    let cancelled = false;
    void api.get<{ data: LayoutPayload }>(base).then((result) => {
      if (cancelled) return;
      if (!result.ok) {
        setError(firstError(result));
        return;
      }
      setError(null);
      setData(result.data.data);
      setSections(result.data.data.draft.sections);
    });
    return () => {
      cancelled = true;
    };
  }, [base, nonce]);

  const save = useCallback(async () => {
    if (data === null || sections === null) return { ok: false as const, message: 'لم تُقرأ الوثيقة بعد.' };
    setBusy(true);
    /* الشكل حرفيّ من ProductLayoutController::update — `layout` مطلوب لا `sections`. */
    const result = await api.patch<{ data: LayoutPayload }>(base, {
      layout: { version: data.draft.version, sections },
      version: data.version,
    });
    setBusy(false);

    if (!result.ok) {
      if (result.code === 'VERSION_CONFLICT') {
        /*
         * محرّر آخر حفظ بعد قراءتنا. الكتابة فوقه تمحو عمله صامتةً، فنعيد
         * التحميل ونترك القرار للمشرف بدل حسمه نيابةً عنه.
         */
        setNonce((current) => current + 1);
        return { ok: false as const, message: `${result.message} أُعيد تحميل النسخة الحالية — راجع التغيير ثم احفظ.` };
      }
      return { ok: false as const, message: firstError(result) };
    }

    setData(result.data.data);
    setSections(result.data.data.draft.sections);
    return { ok: true as const };
  }, [base, data, sections]);

  const publish = useCallback(async () => {
    setBusy(true);
    const result = await api.post<{ data: LayoutPayload }>(`${base}/publish`);
    setBusy(false);
    if (!result.ok) return { ok: false as const, message: firstError(result) };
    setData(result.data.data);
    setSections(result.data.data.draft.sections);
    return { ok: true as const };
  }, [base]);

  return {
    data,
    sections,
    error,
    busy,
    setSections,
    save,
    publish,
    reload: useCallback(() => setNonce((current) => current + 1), []),
  };
}

function SavedLayout({
  state,
  canEdit,
  canPublish,
  onSay,
}: {
  state: SavedLayoutState;
  canEdit: boolean;
  canPublish: boolean;
  onSay: (text: string, tone?: 'ok' | 'error') => void;
}) {
  const { data, sections } = state;

  if (state.error !== null) {
    return (
      <div className="state-error" role="alert">
        <p>{state.error}</p>
        <button type="button" className="btn btn-ghost" onClick={state.reload}>
          إعادة المحاولة
        </button>
      </div>
    );
  }

  if (data === null || sections === null) {
    return (
      <p className="state-line" role="status" aria-live="polite">
        جارٍ قراءة التخطيط المحفوظ…
      </p>
    );
  }

  const dirty = JSON.stringify(sections) !== JSON.stringify(data.draft.sections);
  const unpublished = data.publishedVersion === null || data.publishedVersion !== data.version;

  /* التبديل داخل المنطقة فقط: الترتيب عبر المناطق مستحيل بنيويًّا في الشبكة. */
  function swap(a: number, b: number) {
    const next = [...sections!];
    const first = next[a];
    const second = next[b];
    if (first === undefined || second === undefined) return;
    next[a] = second;
    next[b] = first;
    state.setSections(next);
  }

  async function run(action: () => Promise<{ ok: true } | { ok: false; message: string }>, success: string) {
    const result = await action();
    onSay(result.ok ? success : result.message, result.ok ? 'ok' : 'error');
  }

  return (
    <section className={styles.saved}>
      <div className={styles.savedHead}>
        <h2 className={styles.savedTitle}>التخطيط المحفوظ</h2>
        <span className={styles.savedMeta}>
          نسخة <bdi dir="ltr">{count(data.version)}</bdi>
          {data.publishedVersion === null
            ? ' — لم يُنشر بعد'
            : ` · المنشورة ${data.publishedVersion}`}
        </span>
        <button
          type="button"
          className={styles.savedSave}
          disabled={!canEdit || state.busy || !dirty}
          onClick={() => void run(state.save, 'حُفظت مسوّدة التخطيط.')}
        >
          {state.busy ? 'جارٍ…' : 'حفظ المسوّدة'}
        </button>
        <button
          type="button"
          className={styles.savedPublish}
          disabled={!canPublish || state.busy || dirty || !unpublished}
          onClick={() => void run(state.publish, 'نُشر التخطيط.')}
        >
          نشر التخطيط
        </button>
      </div>

      {!canEdit ? <p className={styles.savedNote}>دورك لا يملك صلاحية تعديل التخطيط.</p> : null}
      {dirty ? <p className={styles.savedNote}>تعديلات غير محفوظة — النشر يحتاج حفظًا أولًا.</p> : null}
      {!dirty && !unpublished ? (
        <p className={styles.savedNote}>المنشور مطابق للمسوّدة.</p>
      ) : null}

      {Object.entries(data.zones).map(([zone, zoneLabel]) => {
        const entries = sections
          .map((section, index) => ({ section, index }))
          .filter((entry) => data.widgets[entry.section.widget]?.zone === zone);
        if (entries.length === 0) return null;

        return (
          <div key={zone} className={styles.zone}>
            <h3 className={styles.zoneTitle}>{zoneLabel}</h3>
            <ul className={styles.zoneRows}>
              {entries.map((entry, positionInZone) => {
                const widget = data.widgets[entry.section.widget];
                const label = widget?.label ?? entry.section.widget;
                const required = data.required.includes(entry.section.widget);
                return (
                  <li key={entry.section.widget} className={styles.zoneRow}>
                    <label className={styles.zoneCheck}>
                      <input
                        type="checkbox"
                        checked={entry.section.visible}
                        /* الإلزامي لا يُخفى: صفحة بلا سعر أو شراء ليست صفحة منتج. */
                        disabled={!canEdit || state.busy || required}
                        onChange={(event) =>
                          state.setSections(
                            sections.map((section, index) =>
                              index === entry.index ? { ...section, visible: event.target.checked } : section,
                            ),
                          )
                        }
                      />
                      <span>{label}</span>
                    </label>
                    {required ? <span className={styles.zoneRequired}>قسم إلزامي</span> : null}
                    <button
                      type="button"
                      className={styles.moveBtn}
                      aria-label={`تحريك ${label} لأعلى`}
                      disabled={!canEdit || state.busy || positionInZone === 0}
                      onClick={() => swap(entry.index, entries[positionInZone - 1]!.index)}
                    >
                      <Chevron up />
                    </button>
                    <button
                      type="button"
                      className={styles.moveBtn}
                      aria-label={`تحريك ${label} لأسفل`}
                      disabled={!canEdit || state.busy || positionInZone === entries.length - 1}
                      onClick={() => swap(entry.index, entries[positionInZone + 1]!.index)}
                    >
                      <Chevron />
                    </button>
                  </li>
                );
              })}
            </ul>
          </div>
        );
      })}
    </section>
  );
}

function WidgetBody({ binding, rowCap }: { binding: Binding; rowCap: number }) {
  if (binding.state === 'data') {
    const shown = binding.rows.slice(0, rowCap);
    return (
      <>
        {/* طوق التمرير: قيمة السطر نصّ حرّ من المنتج قد يطول
            (globals.css §.table-scroll). و`tabIndex` ترميزٌ لا نمط. */}
        <div className="table-scroll" tabIndex={0} role="region" aria-label="جدول قيم الودجة من بيانات المنتج">
          <table className={styles.kv}>
            <tbody>
              {shown.map((row) => (
                <tr key={row.label}>
                  <th scope="row">{row.label}</th>
                  <td dir="auto">{row.value}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
        {binding.rows.length > shown.length ? (
          <p className={styles.more}>
            <bdi dir="ltr">{binding.rows.length - shown.length}</bdi> سطرًا إضافيًا مخفيًّا بالكثافة
            الحالية.
          </p>
        ) : null}
      </>
    );
  }

  if (binding.state === 'behaviour') {
    return <p className={styles.behaviour}>{binding.note}</p>;
  }

  return (
    <p className={cx(styles.missing, binding.state === 'empty' && styles.missingEmpty)}>
      <b>{binding.state === 'empty' ? 'لا قيمة:' : 'ينقص:'}</b> {binding.missing}
      {binding.endpoint ? <code dir="ltr">{binding.endpoint}</code> : null}
    </p>
  );
}

function Chevron({ up }: { up?: boolean }) {
  return (
    <svg aria-hidden="true" viewBox="0 0 24 24" className={styles.icon}>
      <path
        d={up ? 'M6 15l6-6 6 6' : 'M6 9l6 6 6-6'}
        fill="none"
        stroke="currentColor"
        strokeWidth="2.4"
        strokeLinecap="round"
        strokeLinejoin="round"
      />
    </svg>
  );
}
