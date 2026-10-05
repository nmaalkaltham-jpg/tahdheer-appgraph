'use client';
// UNIT: AUDIT-PANEL
// FILE: audit-panel.tsx
// STYLE: page.module.css
// PARENT: PAGE-SYSTEM
// USES: ADMIN-SESSION, LIB-FORMAT, RESOURCE
// API: /admin/audit
// MAP: docs/PROJECT_MAP.md

/**
 * سجلّ التدقيق — الباب الأوّل على سجلٍّ كان يُكتب ولا يُقرأ.
 *
 * `GET /admin/audit` مبنيّة ومحروسة بـ`audit.read` ومُختبَرة، وقارئها الوحيد
 * حتّى اليوم كان بطاقةً في «نظام تشغيل النمو» تختصرها إلى عددٍ واحد لخمسة
 * أفعال بحث. أمّا الاستردادات وسحب الصلاحيات وتغيير الأدوار فمكتوبةٌ كلّها
 * ولا يقرؤها بشر. هذه الشاشة تقرأ الصفوف كما تعود من الخادم: الفاعل ووقته
 * وعنوانه، وما تغيّر فعلًا بين لقطتَي «قبل» و«بعد».
 *
 * ─── ما لا تعرضه هذه الشاشة، ولماذا ───
 *
 * • لا صفحة تفصيلٍ لصفّ: لا مسار `‎/admin/audit/{id}‎` في الخلفيّة أصلًا،
 *   والصفّ يعود كاملًا في القائمة — فالكشف هنا داخل الصفّ لا خلف رابط.
 * • لا بحث حرّ ولا فرز ولا تصدير ولا أفعال جماعيّة: المتحكّم يقبل خمسة
 *   مرشِّحات لا سادس لها (`action`, `entityType`, `from`, `to`) مع
 *   `page`/`perPage`، ويفرز `orderByDesc('id')` ثابتًا. زرٌّ بلا نقطةٍ
 *   خلفه وعدٌ كاذب.
 * • لا مرشِّح بالفاعل ولا بالكيان المعيَّن: الحقلان يعودان في كلّ صفّ
 *   (`actorEmail`, `entityId`) لكنّ الاستعلام لا يرشِّح بهما.
 * • لا تحرير ولا حذف: السجلّ append-only بحكم البنية، ولا مسار كتابةٍ إليه
 *   من اللوحة — ولا من غيرها.
 * • لا ترجمة عربيّة لأسماء الأفعال وأنواع الكيانات: هي معرّفات يكتبها
 *   `AdminAuditLogger` من عشرات المواضع (`order.refunded`, `product.published`,
 *   …) ولا قاموس لها في الخادم. اختراع قاموسٍ هنا يعني عرض اسمٍ لا يطابق
 *   ما في القاعدة، فتُعرض كما هي.
 */

import { useMemo, useState } from 'react';
import { useSession } from '../../../components/admin-session';
import { EmptyRows, ResourceView, useResource, type ResourceState } from '../../../components/resource';
import { count, dateTime } from '../../../lib/format';

import styles from './page.module.css';

/* ───── عقد النقطة كما يعيده `AuditController::index` حرفًا بحرف ───── */

/** `before`/`after` عمودا JSON بقالب `array` — كائنٌ أو قائمة أو غياب. */
type AuditJson = Record<string, unknown> | unknown[] | null;

/** الأنواع محلّيّة لأنّ `admin-api.ts` ليست ملكًا لهذه الشاشة (سابقة `customers/[id]`). */
interface AuditRow {
  id: number;
  action: string;
  entityType: string;
  /** نصّ لا رقم: العمود `string` في المخطّط ليحمل معرّفات غير عدديّة. */
  entityId: string | null;
  actorEmail: string | null;
  before: AuditJson;
  after: AuditJson;
  reason: string | null;
  ip: string | null;
  createdAt: string | null;
}

/** `AdminValidation::meta` — أربعة حقول لا خامس لها، ولا `filters` في هذه النقطة. */
interface AuditPayload {
  data: AuditRow[];
  meta: { page: number; perPage: number; lastPage: number; total: number };
}

const PER_PAGE_OPTIONS = [20, 50, 100] as const;

/* ───── قراءة الفرق: ما تغيّر فعلًا بين اللقطتين ───── */

/**
 * تسطيح اللقطة إلى مساراتٍ ورقيّة (`price.minor`, `roles[0]`).
 *
 * الغرض أن يُقرأ الفرق حقلًا حقلًا لا كتلتَي JSON متجاورتين. العمق محدود:
 * ما تحت الحدّ يُقارن ككتلةٍ واحدة، وهذا أصدق من ادّعاء فرقٍ دقيق في بنيةٍ
 * لا تُعرف حدودها سلفًا.
 */
const MAX_DEPTH = 4;
const ROOT_KEY = '(القيمة)';

/**
 * علامة الحجب التي يرسلها الخادم — تُقرأ ولا تُطبع خامًا.
 *
 * `AuditController` يستبدل هويّات صفوف `admin_user` بنصٍّ يبدأ بـ«⋯ محجوب»
 * لمن لا يملك `users.manage`. والبادئة هي العقد: الخادم يضيف بعدها «(قيمة
 * سابقة)» أو «(قيمة جديدة)» ليبقى تصنيف الفرق (أُضيف/أُزيل/تغيّر) صحيحًا،
 * فالمطابقة ببادئةٍ لا بمساواةٍ تامّة.
 */
const REDACTED_PREFIX = '⋯ محجوب';

function isRedacted(value: string): boolean {
  return value.startsWith(REDACTED_PREFIX);
}

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null && !Array.isArray(value);
}

function stringify(value: unknown): string {
  try {
    return JSON.stringify(value) ?? String(value);
  } catch {
    return String(value);
  }
}

function flatten(value: unknown, prefix: string, out: Map<string, unknown>, depth: number): Map<string, unknown> {
  const key = prefix === '' ? ROOT_KEY : prefix;
  const nested = isRecord(value) || Array.isArray(value);

  if (!nested || depth >= MAX_DEPTH) {
    out.set(key, value);
    return out;
  }

  const entries: Array<[string, unknown]> = Array.isArray(value)
    ? value.map((item, index): [string, unknown] => [`${prefix}[${index}]`, item])
    : Object.entries(value).map(([name, item]): [string, unknown] => [prefix === '' ? name : `${prefix}.${name}`, item]);

  // كائنٌ فارغ ليس «لا شيء»: يُسجَّل ورقةً حتّى يُرى اختفاؤه أو ظهوره.
  if (entries.length === 0) {
    out.set(key, value);
    return out;
  }

  for (const [name, item] of entries) flatten(item, name, out, depth + 1);
  return out;
}

function leaves(value: AuditJson): Map<string, unknown> {
  if (value === null) return new Map<string, unknown>();
  return flatten(value, '', new Map<string, unknown>(), 0);
}

function sameValue(a: unknown, b: unknown): boolean {
  if (Object.is(a, b)) return true;
  if (typeof a === 'object' && typeof b === 'object') return stringify(a) === stringify(b);
  return false;
}

type FieldState = 'added' | 'removed' | 'changed' | 'same';

interface FieldChange {
  path: string;
  state: FieldState;
  before: unknown;
  after: unknown;
  inBefore: boolean;
  inAfter: boolean;
}

function diffFields(before: AuditJson, after: AuditJson): FieldChange[] {
  const a = leaves(before);
  const b = leaves(after);
  const paths = [...new Set([...a.keys(), ...b.keys()])].sort((x, y) => x.localeCompare(y, 'en'));

  return paths.map((path) => {
    const inBefore = a.has(path);
    const inAfter = b.has(path);
    const beforeValue = a.get(path);
    const afterValue = b.get(path);
    const state: FieldState = !inBefore
      ? 'added'
      : !inAfter
        ? 'removed'
        : sameValue(beforeValue, afterValue)
          ? 'same'
          : 'changed';
    return { path, state, before: beforeValue, after: afterValue, inBefore, inAfter };
  });
}

const MARK: Record<Exclude<FieldState, 'same'>, { label: string; className: string }> = {
  added: { label: 'أُضيف', className: styles.markAdded ?? '' },
  removed: { label: 'أُزيل', className: styles.markRemoved ?? '' },
  changed: { label: 'تغيّر', className: styles.markChanged ?? '' },
};

function clip(text: string): string {
  return text.length > 220 ? `${text.slice(0, 220)}…` : text;
}

/** قيمة ورقة واحدة. الغياب والفراغ يُقالان صراحةً ولا يُعرضان صندوقًا خاليًا. */
function Val({ value, present }: { value: unknown; present: boolean }) {
  if (!present) return <span className="absent">لا حقل</span>;
  if (value === null) return <span className="absent">فارغ</span>;
  if (typeof value === 'boolean') return <>{value ? 'نعم' : 'لا'}</>;
  if (typeof value === 'number') return <bdi dir="ltr">{String(value)}</bdi>;
  if (typeof value === 'string') {
    if (value === '') return <span className="absent">نصّ فارغ</span>;
    // نصٌّ قد يكون عربيًّا (عنوان منتج) أو لاتينيًّا (شريحة): `bdi` بلا `dir` يعزل باتجاهه.
    return <bdi>{clip(value)}</bdi>;
  }
  return (
    <bdi dir="ltr">
      <code>{clip(stringify(value))}</code>
    </bdi>
  );
}

function AuditDiff({ row }: { row: AuditRow }) {
  const fields = useMemo(() => diffFields(row.before, row.after), [row]);

  if (fields.length === 0) {
    return (
      <p className={styles.note}>
        لا لقطة «قبل» ولا «بعد» لهذا الفعل — سُجّل بفاعله ووقته وكيانه دون حقولٍ متغيّرة.
      </p>
    );
  }

  const changed = fields.filter((field) => field.state !== 'same');
  const same = fields.filter((field) => field.state === 'same');

  return (
    <>
      {changed.length === 0 ? (
        <p className={styles.note}>
          اللقطتان متطابقتان: <bdi dir="ltr">{count(same.length)}</bdi> حقلًا بلا تغيير.
        </p>
      ) : (
        <div className="table-scroll" tabIndex={0} role="region" aria-label="الحقول التي تغيّرت في هذا السجلّ">
          <table className={styles.diffTable}>
            <caption className="sr-only">
              الحقول التي تغيّرت في السجلّ رقم <bdi dir="ltr">{String(row.id)}</bdi>
            </caption>
            <thead>
              <tr>
                <th scope="col">الحقل</th>
                <th scope="col">قبل</th>
                <th scope="col">بعد</th>
                <th scope="col">الحالة</th>
              </tr>
            </thead>
            <tbody>
              {changed.map((field) => {
                const mark = MARK[field.state as Exclude<FieldState, 'same'>];
                return (
                  <tr key={field.path}>
                    <th scope="row" className={styles.path}>
                      <bdi dir="ltr">{field.path}</bdi>
                    </th>
                    <td className={styles.value}>
                      <Val value={field.before} present={field.inBefore} />
                    </td>
                    <td className={styles.value}>
                      <Val value={field.after} present={field.inAfter} />
                    </td>
                    <td className={mark.className}>{mark.label}</td>
                  </tr>
                );
              })}
            </tbody>
          </table>
        </div>
      )}

      {/* الحقول التي لم تتغيّر ضجيجٌ افتراضًا، وحضورها يثبت أنّ الفرق لم يُقتطع. */}
      {changed.length > 0 && same.length > 0 ? (
        <details className={styles.unchanged}>
          <summary>
            و<bdi dir="ltr">{count(same.length)}</bdi> حقلًا لم يتغيّر
          </summary>
          <ul className={styles.note}>
            {same.map((field) => (
              <li key={field.path}>
                <bdi dir="ltr">{field.path}</bdi>: <Val value={field.after} present />
              </li>
            ))}
          </ul>
        </details>
      ) : null}

      {row.before === null || row.after === null ? (
        <p className={styles.note}>
          {row.before === null ? 'لا لقطة «قبل» — الفعل سجّل الحالة الجديدة وحدها.' : 'لا لقطة «بعد» — الفعل سجّل الحالة السابقة وحدها.'}
        </p>
      ) : null}
    </>
  );
}

/* ───── الشاشة ───── */

/**
 * نصّ المنع مرّةً واحدة: الجلسة تمنعه قبل الطلب، والخادم يمنعه بعده، ويقرأ
 * المشغّل الجملة نفسها في الحالين — بالشكل الذي تكتبه كلّ شاشات اللوحة
 * (`quotes/[id]` · `orders` · `customers`): صلاحيةٌ مسمّاة وجملةٌ واحدة.
 */
function AuditForbidden() {
  return (
    <div className="state-error" role="alert">
      <p>لا تملك صلاحية <bdi dir="ltr">audit.read</bdi> لقراءة سجلّ التدقيق.</p>
    </div>
  );
}

/**
 * الخطأ يُقرأ برمزه: 403 يعني صلاحيةً ناقصة لا عطلًا، وإعادة المحاولة عليه
 * تكرار الرفض نفسه. الجلسة تُفحص قبل الطلب، وهذا الفرع للحالة التي تتغيّر
 * فيها الصلاحيّة بين تحميل الجلسة والطلب.
 */
function AuditState({
  state,
  reload,
  children,
}: {
  state: ResourceState<AuditPayload>;
  reload: () => void;
  children: (payload: AuditPayload) => React.ReactNode;
}) {
  if (state.status === 'error' && state.code === 403) {
    return <AuditForbidden />;
  }

  return (
    <ResourceView state={state} reload={reload}>
      {(payload) => children(payload)}
    </ResourceView>
  );
}

export function AuditPanel() {
  const { can } = useSession();

  return (
    <section>

      {can('audit.read') ? <AuditBoard /> : <AuditForbidden />}
    </section>
  );
}

interface Applied {
  action: string;
  entityType: string;
  from: string;
  to: string;
  perPage: number;
  page: number;
}

const INITIAL: Applied = { action: '', entityType: '', from: '', to: '', perPage: 20, page: 1 };

function AuditBoard() {
  const [form, setForm] = useState<Applied>(INITIAL);
  const [applied, setApplied] = useState<Applied>(INITIAL);
  const [open, setOpen] = useState<ReadonlySet<number>>(new Set<number>());

  const path = useMemo(() => {
    const params = new URLSearchParams();
    if (applied.action) params.set('action', applied.action);
    if (applied.entityType) params.set('entityType', applied.entityType);
    if (applied.from) params.set('from', applied.from);
    if (applied.to) params.set('to', applied.to);
    params.set('perPage', String(applied.perPage));
    params.set('page', String(applied.page));
    return `/admin/audit?${params.toString()}`;
  }, [applied]);

  const { state, reload } = useResource<AuditPayload>(path);

  /** المسار نفسه بلا ترقيم: التصدير يشمل المطابِق كلَّه لا الصفحة المعروضة. */
  const exportHref = useMemo(() => {
    const params = new URLSearchParams();
    if (applied.action) params.set('action', applied.action);
    if (applied.entityType) params.set('entityType', applied.entityType);
    if (applied.from) params.set('from', applied.from);
    if (applied.to) params.set('to', applied.to);

    return `/api/v1/admin/audit/export?${params.toString()}`;
  }, [applied]);

  const toggle = (id: number) => {
    setOpen((current) => {
      const next = new Set(current);
      if (next.has(id)) next.delete(id);
      else next.add(id);
      return next;
    });
  };

  const goTo = (page: number) => setApplied((current) => ({ ...current, page }));

  return (
    <AuditState state={state} reload={reload}>
      {(payload) => {
        /*
         * قائمتا الاقتراح مبنيّتان من صفوف هذه الصفحة وحدها — لا كتالوج أفعالٍ
         * في الخادم يُقرأ منه، والمرشِّح مطابقةٌ تامّة (`where('action', …)`)،
         * فالاقتراح يمنع الخطأ الإملائيّ ولا يدّعي حصر الأفعال.
         */
        const actions = [...new Set(payload.data.map((row) => row.action))].sort();
        const entityTypes = [...new Set(payload.data.map((row) => row.entityType))].sort();

        return (
          <>
            <form
              className="filters list-toolbar"
              onSubmit={(event) => {
                event.preventDefault();
                setApplied({ ...form, page: 1 });
              }}
            >
              <label className="field field-inline" htmlFor="audit-action">
                <span>الفعل</span>
                <input
                  id="audit-action"
                  type="text"
                  list="audit-actions"
                  dir="ltr"
                  value={form.action}
                  placeholder="order.refunded"
                  onChange={(event) => setForm((current) => ({ ...current, action: event.target.value }))}
                />
              </label>
              <datalist id="audit-actions">
                {actions.map((value) => (
                  <option key={value} value={value} />
                ))}
              </datalist>

              <label className="field field-inline" htmlFor="audit-entity">
                <span>نوع الكيان</span>
                <input
                  id="audit-entity"
                  type="text"
                  list="audit-entities"
                  dir="ltr"
                  value={form.entityType}
                  placeholder="order"
                  onChange={(event) => setForm((current) => ({ ...current, entityType: event.target.value }))}
                />
              </label>
              <datalist id="audit-entities">
                {entityTypes.map((value) => (
                  <option key={value} value={value} />
                ))}
              </datalist>

              <label className="field field-inline" htmlFor="audit-from">
                <span>من</span>
                <input
                  id="audit-from"
                  type="date"
                  value={form.from}
                  onChange={(event) => setForm((current) => ({ ...current, from: event.target.value }))}
                />
              </label>

              <label className="field field-inline" htmlFor="audit-to">
                <span>إلى</span>
                <input
                  id="audit-to"
                  type="date"
                  value={form.to}
                  onChange={(event) => setForm((current) => ({ ...current, to: event.target.value }))}
                />
              </label>

              <label className="field field-inline" htmlFor="audit-per-page">
                <span>في الصفحة</span>
                <select
                  id="audit-per-page"
                  value={String(form.perPage)}
                  onChange={(event) => setForm((current) => ({ ...current, perPage: Number(event.target.value) }))}
                >
                  {PER_PAGE_OPTIONS.map((value) => (
                    <option key={value} value={value}>
                      {count(value)}
                    </option>
                  ))}
                </select>
              </label>

              <button type="submit" className="btn btn-primary">
                تطبيق
              </button>

              {/*
                التصدير يحمل **المرشّحات المطبَّقة** لا المكتوبة في الحقول:
                زرٌّ يصدّر شيئًا غير الذي على الشاشة يُسلّم دليلًا يخالف ما
                رآه المشغّل — وهو أسوأ من غياب التصدير.

                ورابطٌ لا زرّ: التنزيل بثٌّ من الخادم، وجلبُه عبر طبقة
                النداء يُحمّله في الذاكرة ثمّ يعيد بناء ملفٍّ بلا سبب.
              */}
              <a className="btn btn-ghost" href={exportHref} download>
                تصدير CSV
              </a>

              <span className="list-toolbar-end">
                <bdi dir="ltr">{count(payload.data.length)}</bdi> من <bdi dir="ltr">{count(payload.meta.total)}</bdi> سجلًّا
              </span>
            </form>

            {/* اليومان طرفان شاملان: الخادم يوسّع «من» إلى أوّل اليوم و«إلى» إلى آخره. */}
            <p className="page-note">المرشِّحان «من» و«إلى» يشملان اليومَين كاملَين، والترتيب من الأحدث إلى الأقدم دائمًا.</p>

            {/* الطوق منطقةُ بلوغٍ بلوحة المفاتيح: كروم لا يبئّر منطقة تمرير من تلقائه (globals.css). */}
            <div
              className={`table-scroll ${styles.scroller ?? ''}`}
              tabIndex={0}
              role="region"
              aria-label="سجلّ التدقيق"
            >
              <table className="data-table">
                <caption className="sr-only">سجلّ التدقيق: الفاعل والفعل والكيان وما تغيّر</caption>
                <thead>
                  <tr>
                    <th scope="col">الوقت</th>
                    <th scope="col">الفعل</th>
                    <th scope="col">الكيان</th>
                    <th scope="col">المشغّل</th>
                    <th scope="col">العنوان</th>
                    <th scope="col">التغيير</th>
                  </tr>
                </thead>
                <tbody>
                  {payload.data.length === 0 ? (
                    <EmptyRows colSpan={6} message="لا سجلّات بهذا المرشِّح." />
                  ) : (
                    payload.data.map((row) => {
                      const expanded = open.has(row.id);
                      const hasSnapshot = row.before !== null || row.after !== null;
                      return [
                        <tr key={row.id}>
                          <td className={styles.when}>
                            <bdi>{dateTime(row.createdAt)}</bdi>
                          </td>
                          <td>
                            <div className={styles.action}>
                              <bdi dir="ltr">{row.action}</bdi>
                            </div>
                            {row.reason ? <div className={styles.sub}>السبب: {row.reason}</div> : null}
                          </td>
                          <td>
                            <bdi dir="ltr">{row.entityType}</bdi>
                            <div className={styles.sub}>
                              {row.entityId === null ? (
                                <span className="absent">بلا معرّف</span>
                              ) : (
                                <bdi dir="ltr">{row.entityId}</bdi>
                              )}
                            </div>
                          </td>
                          <td>
                            {row.actorEmail === null ? (
                              /* الفاعل يُحفَظ بريدًا لا مفتاحًا أجنبيًّا، فغيابه يعني فعلًا بلا مشغّل (نظام أو مهمّة). */
                              <span className="absent">بلا مشغّل</span>
                            ) : isRedacted(row.actorEmail) ? (
                              /*
                               * المحجوب يُسمّى محجوبًا، لا يُترك رمزًا غامضًا.
                               *
                               * الخادم يحجب هويّات صفوف `admin_user` عمّن لا يملك
                               * `users.manage` (`AuditController`) — وهو صواب: سجلّ
                               * تغيير الأدوار كان يسلّم طاقم اللوحة كاملًا بأسمائهم
                               * وبُرُدهم لكلّ من يملك `audit.read` وحدها.
                               *
                               * لكنّ الواجهة كانت تطبع النصّ المُقنَّع كما هو، فيقرأ
                               * المشغّل رمزًا لا يفهمه ولا يعرف أَعُطلٌ هو أم منعٌ
                               * مقصود. والحجب بلا تفسيره يبدو خللًا.
                               */
                              <span className="absent" title="الهويّات في سجلّ حسابات اللوحة محجوبة عمّن لا يملك صلاحية إدارة المستخدمين">
                                محجوب — يحتاج <bdi dir="ltr">users.manage</bdi>
                              </span>
                            ) : (
                              <bdi dir="ltr">{row.actorEmail}</bdi>
                            )}
                          </td>
                          <td>
                            {row.ip === null ? <span className="absent">—</span> : <bdi dir="ltr">{row.ip}</bdi>}
                          </td>
                          <td>
                            {hasSnapshot ? (
                              <button
                                type="button"
                                className={styles.toggle}
                                aria-expanded={expanded}
                                aria-controls={`audit-diff-${row.id}`}
                                onClick={() => toggle(row.id)}
                              >
                                {expanded ? 'أخفِ الفرق' : 'اعرض الفرق'}
                              </button>
                            ) : (
                              <span className="absent">لا لقطة</span>
                            )}
                          </td>
                        </tr>,
                        expanded ? (
                          <tr key={`${row.id}-diff`} id={`audit-diff-${row.id}`}>
                            <td className={styles.diffCell} colSpan={6}>
                              <AuditDiff row={row} />
                            </td>
                          </tr>
                        ) : null,
                      ];
                    })
                  )}
                </tbody>
              </table>
            </div>

            <div className="list-foot">
              <p className="page-note">
                صفحة <bdi dir="ltr">{count(payload.meta.page)}</bdi> من <bdi dir="ltr">{count(payload.meta.lastPage)}</bdi>
              </p>
              <div className={styles.pager}>
                <button
                  type="button"
                  className="btn btn-ghost"
                  disabled={payload.meta.page <= 1}
                  onClick={() => goTo(payload.meta.page - 1)}
                >
                  الأحدث قبلها
                </button>
                <button
                  type="button"
                  className="btn btn-ghost"
                  disabled={payload.meta.page >= payload.meta.lastPage}
                  onClick={() => goTo(payload.meta.page + 1)}
                >
                  الأقدم بعدها
                </button>
              </div>
            </div>
          </>
        );
      }}
    </AuditState>
  );
}
