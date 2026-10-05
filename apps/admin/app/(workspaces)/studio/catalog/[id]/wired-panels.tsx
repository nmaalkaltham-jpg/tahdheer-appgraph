'use client';
// UNIT: WIRED-PANELS
// FILE: wired-panels.tsx
// STYLE: studio.module.css
// PARENT: STUDIO-CLIENT
// USES: LIB-ADMIN-API, LIB-FORMAT, PRODUCT-TYPES, STUDIO-DATA, _FIELD, _PANEL-VOCABULARY
// API: GET /admin/drive/health, POST /admin/products
// MAP: docs/PROJECT_MAP.md

/**
 * ألواح الاستوديو الموصولة بنقاط النهاية المسجَّلة في routes/api-admin.php.
 *
 * كل لوح هنا كان يعرض لافتة «غير موصول» بينما نقطته مسجَّلة وتعمل. اللافتة
 * على قدرة قائمة كذبة كاملة الأثر: المشغّل يرى بابًا مغلقًا فيتوقّف عن العمل
 * الذي يستطيعه فعلًا.
 *
 * المصادر (تُقرأ من backend/laravel/routes/api-admin.php:119-155):
 *   · الملفّات   ← ProductFileController        (index/store/update/destroy/reorder)
 *   · الوسائط    ← ProductMediaController       (index/store/update/destroy/reorder)
 *   · البحث      ← ProductSeoController         (show/update)
 *   · التغطية    ← ProductLayoutController::coverage
 *   · اللغات     ← ProductTranslationController (index/eligibility/update/publish)
 *   · المساعد    ← ProductLayoutController::suggest
 *
 * ما لا يملكه الخادم يبقى معلَنًا كما هو: المساعد الكتابي يردّ 503 بسببه حين
 * لا يكون مهيَّأً، فتُعرض رسالته هو — لا لافتة ثابتة تسبق الطلب.
 */

import { useCallback, useEffect, useRef, useState } from 'react';
import { api, firstError, type ApiResult } from '../../../../../lib/admin-api';
import { count, minorToRiyals, money, riyalsToMinor } from '../../../../../lib/format';
import styles from './studio.module.css';
import { useProductTypes } from '../product-types';
import { Field } from './_field';
import {
  INHERIT_FIELDS,
  mediaName,
  OFFER_STATUS_LABELS,
  VARIANT_STATUS_LABELS,
  WRITING_FIELDS,
  type VariantRow,
  type WritingField,
} from './_panel-vocabulary';
import {
  bytes,
  COVERAGE_LABELS,
  COVERAGE_STEP,
  cx,
  FILE_ROLES,
  LANGUAGE_LABELS,
  MEDIA_ACCEPT,
  SEO_BUDGET,
  type CoveragePayload,
  type FileRow,
  type LanguageState,
  type MediaRow,
  type SeoDoc,
  type StepKey,
  type SuggestPayload,
  type TranslationRow,
} from '../studio-data';

/* ───── أدوات مشتركة ───── */

type Tone = 'ok' | 'error' | 'info';
type Fail = Extract<ApiResult<unknown>, { ok: false }>;

/** رسالة الحالة تُشتقّ من الاستجابة لا من نيّة الضغط. */
function useNotice() {
  const [notice, setNotice] = useState<{ tone: Tone; text: string } | null>(null);
  return {
    notice,
    clear: useCallback(() => setNotice(null), []),
    ok: useCallback((text: string) => setNotice({ tone: 'ok', text }), []),
    info: useCallback((text: string) => setNotice({ tone: 'info', text }), []),
    /** نصّ الخادم كما وصل: هو وحده يعرف ما رُفض ولماذا. */
    fail: useCallback((result: Fail) => setNotice({ tone: 'error', text: firstError(result) }), []),
  };
}

function Notice({ notice }: { notice: { tone: Tone; text: string } | null }) {
  if (!notice) return null;
  const className = notice.tone === 'ok' ? 'state-ok' : notice.tone === 'error' ? 'state-error' : 'page-note';
  return (
    <p className={className} role={notice.tone === 'error' ? 'alert' : 'status'} aria-live="polite">
      {notice.text}
    </p>
  );
}

/** قراءة مورد فرعي مع حالات صريحة — لا فراغ صامت ولا قيمة احتياطية. */
function useSubResource<T>(path: string) {
  const [data, setData] = useState<T | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [loading, setLoading] = useState(true);

  const fetchResource = useCallback(
    () =>
      api.get<{ data: T }>(path).then((result) => {
        setLoading(false);
        if (!result.ok) {
          setError(firstError(result));
          return;
        }
        setError(null);
        setData(result.data.data);
      }),
    [path],
  );

  // تغيّر المسار يعيد «جارٍ التحميل» أثناء التصيير؛ التحميل الأول يبدأ بها أصلًا.
  const [seenPath, setSeenPath] = useState(path);
  if (seenPath !== path) {
    setSeenPath(path);
    setLoading(true);
  }

  useEffect(() => {
    void fetchResource();
  }, [fetchResource]);

  const reload = useCallback(() => {
    setLoading(true);
    return fetchResource();
  }, [fetchResource]);

  return { data, setData, error, loading, reload };
}

function Loading() {
  return (
    <p className="state-line" role="status" aria-live="polite">
      جارٍ التحميل…
    </p>
  );
}

function LoadError({ message, retry }: { message: string; retry: () => void }) {
  return (
    <div className="state-error" role="alert">
      <p>{message}</p>
      <button type="button" className="btn btn-ghost" onClick={retry}>
        إعادة المحاولة
      </button>
    </div>
  );
}

/* ───── الملفات ───── */

export function FilesPanel({ productId, canEdit }: { productId: number; canEdit: boolean }) {
  const base = `/admin/products/${productId}/files`;
  const { data, setData, error, loading, reload } = useSubResource<FileRow[]>(base);
  const { notice, ok, fail, clear } = useNotice();
  const [busy, setBusy] = useState(false);
  const [role, setRole] = useState('main');
  const input = useRef<HTMLInputElement>(null);

  async function run(action: Promise<ApiResult<{ data: FileRow[] }>>, success: string) {
    setBusy(true);
    clear();
    const result = await action;
    setBusy(false);
    if (!result.ok) {
      fail(result);
      return;
    }
    setData(result.data.data);
    ok(success);
  }

  if (error !== null) return <LoadError message={error} retry={reload} />;
  if (data === null) return <Loading />;

  const rows = data;

  return (
    <>
      <Notice notice={notice} />

      {rows.length === 0 ? (
        <p className={styles.measured}>لا ملفات مرتبطة. المنتج الرقمي بلا ملف لا يمكن تسليمه بعد الشراء.</p>
      ) : null}

      <div className={styles.chipsWrap}>
        {rows.map((row, index) => (
          <div key={row.id} className={styles.fileChip}>
            <span aria-hidden="true" className={styles.fileChipIcon}>
              <svg viewBox="0 0 24 24" focusable="false">
                <path
                  d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8zm0 0v5h5"
                  fill="none"
                  stroke="currentColor"
                  strokeWidth="2"
                  strokeLinecap="round"
                  strokeLinejoin="round"
                />
              </svg>
            </span>
            <span className={styles.fileChipText}>
              <bdi dir="ltr" className={styles.fileChipName}>
                {row.asset?.label ?? row.asset?.originalName ?? '—'}
              </bdi>
              <span className={styles.fileChipSize}>
                {row.asset ? bytes(row.asset.sizeBytes) : 'لا أصل مرتبط'}
                {row.asset?.visibility === 'public' ? ' · عام' : ' · خاصّ يُسلَّم بمنح موقّع'}
              </span>
            </span>

            <label className={styles.chipField}>
              <span className="sr-only">دور الملف {row.asset?.originalName ?? row.id}</span>
              <select
                value={row.role}
                disabled={!canEdit || busy}
                onChange={(event) =>
                  void run(
                    api.patch<{ data: FileRow[] }>(`${base}/${row.id}`, { role: event.target.value }),
                    'حُدّث دور الملف.',
                  )
                }
              >
                {Object.entries(FILE_ROLES).map(([value, label]) => (
                  <option key={value} value={value}>
                    {label}
                  </option>
                ))}
              </select>
            </label>

            <button
              type="button"
              className={styles.chipBtnGhost}
              disabled={!canEdit || busy || index === 0}
              aria-label={`تقديم ${row.asset?.originalName ?? row.id}`}
              onClick={() => {
                const order = rows.map((item) => item.id);
                const previous = order[index - 1];
                const current = order[index];
                if (previous === undefined || current === undefined) return;
                order[index - 1] = current;
                order[index] = previous;
                void run(api.post<{ data: FileRow[] }>(`${base}/reorder`, { order }), 'حُدّث الترتيب.');
              }}
            >
              تقديم
            </button>
            <button
              type="button"
              className={styles.chipBtnGhost}
              disabled={!canEdit || busy}
              onClick={() => void run(api.delete<{ data: FileRow[] }>(`${base}/${row.id}`), 'فُكّ ارتباط الملف.')}
            >
              فكّ الارتباط
            </button>
          </div>
        ))}

        <label className={cx(styles.fileAdd, (!canEdit || busy) && styles.fileAddOff)}>
          <svg aria-hidden="true" viewBox="0 0 24 24" focusable="false">
            <path d="M12 5v14M5 12h14" fill="none" stroke="currentColor" strokeWidth="2.4" strokeLinecap="round" />
          </svg>
          {busy ? 'جارٍ…' : 'أضف ملفًا'}
          <input
            ref={input}
            type="file"
            className="sr-only"
            disabled={!canEdit || busy}
            onChange={(event) => {
              const file = event.target.files?.[0];
              if (!file) return;
              const form = new FormData();
              form.append('file', file);
              form.append('role', role);
              void run(api.upload<{ data: FileRow[] }>(base, form), 'رُفع الملف.').then(() => {
                if (input.current) input.current.value = '';
              });
            }}
          />
        </label>
      </div>

      <div className={styles.fields}>
        <div className={styles.field}>
          <label htmlFor="file-role">دور الملف المرفوع</label>
          <select
            id="file-role"
            value={role}
            disabled={!canEdit || busy}
            onChange={(event) => setRole(event.target.value)}
          >
            {Object.entries(FILE_ROLES).map(([value, label]) => (
              <option key={value} value={value}>
                {label}
              </option>
            ))}
          </select>
          <span className={styles.hint}>
            PDF أو Word أو PowerPoint أو Excel أو ZIP أو صورة، حتى 512 ميغابايت. يُرفع خاصًّا ولا يُصدَّر
            له رابط تحميل من هنا.
          </span>
        </div>
      </div>

      <p className={styles.hint}>
        فكّ الارتباط لا يمحو البايتات من التخزين: قد يكون عملاء اشتروا الملف، وحذفه يكسر تحميلًا مدفوعًا.
      </p>
      {loading ? <span className="sr-only">جارٍ التحديث…</span> : null}
    </>
  );
}

/* ───── مكوّنات الحزمة ───── */



interface BundleRow {
  id: number;
  variantId: number;
  sku: string | null;
  title: string | null;
  nature: string | null;
  quantity: number;
  required: boolean;
  position: number;
}

interface BundleView {
  data: BundleRow[];
  isBundle: boolean;
}

/**
 * مكوّنات الحزمة — ما يُسلَّم حين تُشترى (§٤٣).
 *
 * ─── ما تقوله هذه الشاشة وما لا تقوله ───
 *
 * الحزمة تُشترى مرّةً بثمنٍ واحد، وتُوسَّع عند الدفع إلى بندٍ لكلّ مكوّن —
 * فيسلك كلٌّ منها مسار نوعه: الرقميّ يُنزَّل والماديّ يُشحن. ولذلك تُعرَض
 * طبيعةُ كلّ مكوّن هنا: هي التي تقرّر ما يقع بعد الدفع.
 *
 * والثمن ثمنُ الحزمة من خطوة «السعر»، لا مجموعَ المكوّنات: الحزمة تُباع
 * بخصمٍ عادةً، والمجموع كان سيُحصّل أكثر ممّا عُرض. ويُوزَّع على المكوّنات
 * عند الدفع بحيث يُساوي المجموعُ الثمنَ حرفًا.
 */
export function BundlePanel({ productId, canEdit }: { productId: number; canEdit: boolean }) {
  const base = `/admin/products/${productId}/bundle`;
  const { data, error, reload } = useSubResource<BundleView['data']>(base);
  const { notice, ok, fail, clear } = useNotice();
  const [variantId, setVariantId] = useState('');
  const [quantity, setQuantity] = useState('1');
  const [busy, setBusy] = useState(false);

  /*
   * تسميات الطبائع من الخادم لا من جدولٍ هنا — وهي القاعدة نفسها التي تُبقي
   * المفردات في موضعٍ واحد. والرمز سقوطٌ حتّى يصل التحميل: شاشةٌ فارغة أسوأ
   * من رمزٍ إنجليزيّ يُقرأ.
   */
  const types = useProductTypes();
  const natureLabel = (nature: string) =>
    types.data?.natures.find((item) => item.value === nature)?.label ?? nature;

  async function add() {
    const id = Number(variantId.trim());

    if (!Number.isInteger(id) || id <= 0 || busy) return;

    setBusy(true);
    clear();
    const result = await api.post<{ data: BundleRow[] }>(base, {
      componentVariantId: id,
      quantity: Math.max(1, Number(quantity) || 1),
    });
    setBusy(false);

    if (!result.ok) {
      fail(result);
      return;
    }
    setVariantId('');
    setQuantity('1');
    ok('أُضيف المكوّن — وتسليم الحزمة صار اتّحاد تسليم مكوّناتها.');
    reload();
  }

  async function remove(componentId: number) {
    setBusy(true);
    clear();
    const result = await api.delete<{ data: BundleRow[] }>(`${base}/${componentId}`);
    setBusy(false);

    if (!result.ok) {
      fail(result);
      return;
    }
    ok('حُذف المكوّن.');
    reload();
  }

  if (error !== null) return <LoadError message={error} retry={reload} />;
  if (data === null) return <Loading />;

  return (
    <>
      <Notice notice={notice} />

      {data.length === 0 ? (
        <p className={cx(styles.measured, 'state-error')} role="alert">
          حزمةٌ بلا مكوّنات لا تُسلّم شيئًا — ويرفضها الدفع. أضف مكوّنًا واحدًا على الأقل.
        </p>
      ) : null}

      {data.map((row) => (
        <div key={row.id} className={styles.driveRow}>
          <div className={styles.driveMain}>
            <b>{row.title ?? row.sku ?? `صيغة ${row.variantId}`}</b>
            <bdi dir="ltr" className={styles.driveId}>
              {row.sku ?? `#${row.variantId}`}
            </bdi>
            <span className={styles.hint}>
              {/* الطبيعة تُعرض لأنّها تقرّر ما يقع بعد الدفع لهذا المكوّن. */}
              {row.nature === null ? 'بلا طبيعة' : natureLabel(row.nature)}
              {row.quantity > 1 ? ` · ${count(row.quantity)} نسخ` : ''}
            </span>
          </div>

          {canEdit ? (
            <button
              type="button"
              className="btn btn-ghost"
              disabled={busy}
              onClick={() => void remove(row.id)}
            >
              احذف
            </button>
          ) : null}
        </div>
      ))}

      {canEdit ? (
        <div className={styles.driveAdd}>
          <Field
            id="bundle-variant"
            label="معرّف الصيغة"
            hint="من خطوة «صيغ الملفّ» في المنتج المكوِّن. الحزمة لا تحتوي نفسها ولا حزمةً أخرى."
          >
            <input
              id="bundle-variant"
              dir="ltr"
              inputMode="numeric"
              value={variantId}
              disabled={busy}
              onChange={(event) => setVariantId(event.target.value)}
            />
          </Field>

          <Field id="bundle-qty" label="الكميّة">
            <input
              id="bundle-qty"
              dir="ltr"
              inputMode="numeric"
              value={quantity}
              disabled={busy}
              onChange={(event) => setQuantity(event.target.value)}
            />
          </Field>

          <button
            type="button"
            className="btn btn-primary"
            disabled={busy || variantId.trim() === ''}
            onClick={() => void add()}
          >
            أضف مكوّنًا
          </button>
        </div>
      ) : null}

      <p className={styles.hint}>
        الثمن ثمنُ الحزمة من خطوة «السعر» لا مجموع المكوّنات، ويُوزَّع عليها عند الدفع بحيث يُساوي
        المجموعُ الثمن حرفًا.
      </p>
    </>
  );
}

/* ───── مصادر Google Drive ───── */

/** صفُّ مصدرٍ كما يعيده `GET /admin/products/{id}/drive`. */
interface DriveRow {
  id: number;
  fileId: string;
  fileName: string | null;
  mimeType: string | null;
  sizeBytes: number | null;
  revision: string | null;
  syncState: string;
  lastError: string | null;
  lastVerifiedAt: string | null;
  deliverable: boolean;
  servingFrozenCopy: boolean;
}

interface DriveHealth {
  configured: boolean;
  reachable: boolean;
  detail: string | null;
}

/** تسميات حالات المزامنة — §١٦ يفرّق بينها لأنّ علاج كلٍّ منها مختلف. */
const SYNC_LABELS: Record<string, string> = {
  CURRENT: 'مطابق',
  SOURCE_CHANGED: 'المصدر تغيّر',
  SYNC_PENDING: 'بانتظار المزامنة',
  SYNC_FAILED: 'تعذّرت المزامنة',
  SOURCE_MISSING: 'الملفّ ذهب — استبدل المصدر',
  ACCESS_REVOKED: 'الإذن سُحب — أصلح الاعتماد',
};

/**
 * ربط ملفّات Google Drive بالمنتج.
 *
 * ─── لماذا لوحٌ مستقلّ عن الملفّات ───
 *
 * الملفّ المرفوع بايتاتٌ نملكها. وملفّ Drive **يملكه غيرنا**: يُحذف أو يُسحب
 * إذنه بلا خبر، فله حالةُ مزامنةٍ ليست للمرفوع. وطيُّهما في لوحٍ واحد كان
 * سيُخفي تلك الحالة أو يُظهرها للمرفوع بلا معنى.
 *
 * ─── وما لا يُعرض هنا ───
 *
 * لا رمز وصولٍ ولا اعتمادُ حساب ولا بنيةُ مجلّدات. المشغّل يرى ملفّه وحده،
 * ولا يرى الحساب الذي يقرأ به النظام.
 */
export function DrivePanel({ productId, canEdit }: { productId: number; canEdit: boolean }) {
  const base = `/admin/products/${productId}/drive`;
  const { data, error, reload } = useSubResource<DriveRow[]>(base);
  const { notice, ok, fail, clear } = useNotice();
  const [health, setHealth] = useState<DriveHealth | null>(null);
  const [fileId, setFileId] = useState('');
  const [role, setRole] = useState('main');
  const [replacing, setReplacing] = useState<number | null>(null);
  const [replacement, setReplacement] = useState('');
  const [busy, setBusy] = useState(false);

  useEffect(() => {
    let alive = true;

    void api.get<{ data: DriveHealth }>('/admin/drive/health').then((result) => {
      if (alive && result.ok) setHealth(result.data.data);
    });

    return () => {
      alive = false;
    };
  }, []);

  async function attach() {
    if (fileId.trim() === '' || busy) return;
    setBusy(true);
    clear();
    const result = await api.post<{ data: DriveRow }>(base, { fileId: fileId.trim(), role });
    setBusy(false);
    if (!result.ok) {
      fail(result);
      return;
    }
    setFileId('');
    ok('رُبط الملفّ وسُجّلت مراجعته.');
    reload();
  }

  async function replace(sourceId: number) {
    if (replacement.trim() === '' || busy) return;
    setBusy(true);
    clear();
    /*
     * الدور لا يُرسَل: الخادم يقرؤه من الربط القائم.
     *
     * إرسالُ `role` هنا كان يُرسل دورَ **نموذج الإضافة** — وهو `main`
     * افتراضًا — فيُعاد توصيف ملفٍّ كان «عيّنة مجّانية» إلى «رئيسيّ» بصمت.
     * والاستبدال يُبدّل الملفّ لا معناه.
     */
    const result = await api.patch<{ data: DriveRow }>(`${base}/${sourceId}`, {
      fileId: replacement.trim(),
    });
    setBusy(false);
    if (!result.ok) {
      fail(result);
      return;
    }
    setReplacing(null);
    setReplacement('');
    ok('استُبدل المصدر — والمنتج ومراجعاته وروابطه كما هي.');
    reload();
  }

  if (error !== null) return <LoadError message={error} retry={reload} />;
  if (data === null) return <Loading />;

  return (
    <>
      <Notice notice={notice} />

      {/*
        * صحّة المحوّل تُقال قبل المحاولة: «لا اعتماد مضبوط» جوابٌ يفهمه
        * المشغّل، أمّا فشلُ ربطٍ بلا سبب فيجعله يجرّب معرّفاتٍ سليمة
        * ويظنّها خاطئة.
        */}
      {health !== null && !health.configured ? (
        <p className="state-error" role="alert">
          لا اعتماد Drive مضبوط. اضبطه في «مفاتيح الربط» قبل ربط أيّ ملفّ.
          {health.detail === null ? '' : ` ${health.detail}`}
        </p>
      ) : null}

      {data.length === 0 ? (
        <p className={styles.measured}>لا ملفّات Drive مربوطة بهذا المنتج.</p>
      ) : null}

      {data.map((row) => (
        <div key={row.id} className={styles.driveRow}>
          <div className={styles.driveMain}>
            <b>{row.fileName ?? row.fileId}</b>
            <bdi dir="ltr" className={styles.driveId}>
              {row.fileId}
            </bdi>
            <span className={styles.hint}>
              {SYNC_LABELS[row.syncState] ?? row.syncState}
              {row.revision === null ? '' : ` · مراجعة ${row.revision}`}
              {row.sizeBytes === null ? '' : ` · ${count(row.sizeBytes)} بايت`}
            </span>
            {/* الخطأ يُعرض كما كتبه الخادم — لا يُطوى في «تعذّر». */}
            {row.lastError === null ? null : (
              <span className="state-error">{row.lastError}</span>
            )}
            {row.servingFrozenCopy ? (
              <span className={styles.hint}>
                المصدر تغيّر والنسخة مجمّدة — يُسلَّم المشترى لا الأحدث.
              </span>
            ) : null}
          </div>

          {canEdit ? (
            <button
              type="button"
              className="btn btn-ghost"
              disabled={busy}
              onClick={() => {
                setReplacing(replacing === row.id ? null : row.id);
                setReplacement('');
              }}
            >
              {replacing === row.id ? 'إلغاء' : 'استبدال المصدر'}
            </button>
          ) : null}

          {replacing === row.id ? (
            <div className={styles.driveReplace}>
              <Field id={`drive-replace-${row.id}`} label="معرّف الملفّ البديل أو رابطه">
                <input
                  id={`drive-replace-${row.id}`}
                  dir="ltr"
                  value={replacement}
                  disabled={busy}
                  onChange={(event) => setReplacement(event.target.value)}
                />
              </Field>
              <button
                type="button"
                className="btn btn-primary"
                disabled={busy || replacement.trim() === ''}
                onClick={() => void replace(row.id)}
              >
                استبدل
              </button>
            </div>
          ) : null}
        </div>
      ))}

      {canEdit ? (
        <div className={styles.driveAdd}>
          <Field
            id="drive-file"
            label="معرّف ملفّ Drive أو رابطه"
            hint="الرابط يُقبل تسهيلًا، ويُستخرج منه المعرّف. المحفوظ هو المعرّف وحده — الروابط تنتهي صلاحيّتها."
            wide
          >
            <input
              id="drive-file"
              dir="ltr"
              value={fileId}
              disabled={busy}
              placeholder="https://drive.google.com/file/d/…/view"
              onChange={(event) => setFileId(event.target.value)}
            />
          </Field>

          <Field id="drive-role" label="دور الملفّ">
            <select
              id="drive-role"
              value={role}
              disabled={busy}
              onChange={(event) => setRole(event.target.value)}
            >
              {Object.entries(FILE_ROLES).map(([value, label]) => (
                <option key={value} value={value}>
                  {label}
                </option>
              ))}
            </select>
          </Field>

          <button
            type="button"
            className="btn btn-primary"
            disabled={busy || fileId.trim() === ''}
            onClick={() => void attach()}
          >
            اربط الملفّ
          </button>
        </div>
      ) : null}

      <p className={styles.hint}>
        تُجلب نسخةٌ إلى تخزيننا عند الربط، فتسليمُ ما بِيع لا يتوقّف على بقاء الملفّ عند Drive.
        ويُعاد التحقّق من الإذن لحظة التسليم — فملفٌّ سُحب إذنه لا يُمنح استحقاقًا لا يُنزَّل.
      </p>
    </>
  );
}

/* ───── الوسائط ───── */

export function MediaPanel({ productId, canEdit }: { productId: number; canEdit: boolean }) {
  const base = `/admin/products/${productId}/media`;
  const { data, setData, error, reload } = useSubResource<MediaRow[]>(base);
  const { notice, ok, fail, clear } = useNotice();
  const [busy, setBusy] = useState(false);
  const input = useRef<HTMLInputElement>(null);

  async function run(action: Promise<ApiResult<{ data: MediaRow[] }>>, success: string) {
    setBusy(true);
    clear();
    const result = await action;
    setBusy(false);
    if (!result.ok) {
      fail(result);
      return;
    }
    setData(result.data.data);
    ok(success);
  }

  if (error !== null) return <LoadError message={error} retry={reload} />;
  if (data === null) return <Loading />;

  const rows = data;

  return (
    <>
      <Notice notice={notice} />

      {rows.length === 0 ? (
        <p className={styles.measured}>لا صور لهذا المنتج. الصورة الأولى تصير الغلاف تلقائيًّا.</p>
      ) : (
        <div className={styles.mediaGrid}>
          {rows.map((row, index) => (
            <div key={row.id} className={cx(styles.slot, row.isCover && styles.slotCover)}>
              <div className={styles.slotHead}>
                <span aria-hidden="true" className={styles.slotIcon}>
                  <svg viewBox="0 0 24 24" focusable="false">
                    <path
                      d="M3 5h18v14H3zM3 16l5-5 4 4 3-3 6 6"
                      fill="none"
                      stroke="currentColor"
                      strokeWidth="2"
                      strokeLinejoin="round"
                    />
                  </svg>
                </span>
                <span className={styles.slotMeta}>
                  <bdi dir="ltr" className={styles.slotName}>
                    {mediaName(row.url)}
                  </bdi>
                  <bdi dir="ltr" className={styles.slotDim}>
                    {row.width !== null && row.height !== null
                      ? `${row.width}×${row.height}`
                      : 'أبعاد غير مقروءة'}
                  </bdi>
                </span>
                <span className={cx(styles.slotState, row.isCover && styles.slotStateOn)}>
                  {row.isCover ? 'الغلاف' : 'صورة عرض'}
                </span>
                <button
                  type="button"
                  className={styles.slotBtn}
                  disabled={!canEdit || busy || row.isCover}
                  onClick={() =>
                    void run(api.patch<{ data: MediaRow[] }>(`${base}/${row.id}`, { isCover: true }), 'عُيّن الغلاف.')
                  }
                >
                  {row.isCover ? 'الغلاف الحالي' : 'اجعلها الغلاف'}
                </button>
              </div>

              <label className={styles.slotAltField} htmlFor={`media-alt-${row.id}`}>
                <span className="sr-only">النصّ البديل لصورة {mediaName(row.url)}</span>
                <input
                  id={`media-alt-${row.id}`}
                  type="text"
                  dir="auto"
                  defaultValue={row.alt ?? ''}
                  disabled={!canEdit || busy}
                  placeholder="صف ما في الصورة لقارئ الشاشة"
                  onBlur={(event) => {
                    if (event.target.value === (row.alt ?? '')) return;
                    void run(
                      api.patch<{ data: MediaRow[] }>(`${base}/${row.id}`, { alt: event.target.value }),
                      'حُفظ النصّ البديل.',
                    );
                  }}
                />
              </label>

              <span className={cx(styles.slotHint, row.alt === null && styles.slotHintWarn)}>
                {row.alt === null || row.alt === ''
                  ? 'بلا نصّ بديل — قارئ الشاشة يتخطّى الصورة صامتًا.'
                  : 'يُحفظ عند مغادرة الحقل.'}
              </span>

              <div className={styles.slotActions}>
                <button
                  type="button"
                  className={styles.chipBtnGhost}
                  disabled={!canEdit || busy || index === 0}
                  aria-label={`تقديم صورة ${mediaName(row.url)}`}
                  onClick={() => {
                    const order = rows.map((item) => item.id);
                    const previous = order[index - 1];
                    const current = order[index];
                    if (previous === undefined || current === undefined) return;
                    order[index - 1] = current;
                    order[index] = previous;
                    void run(api.post<{ data: MediaRow[] }>(`${base}/reorder`, { order }), 'حُدّث الترتيب.');
                  }}
                >
                  تقديم
                </button>
                <button
                  type="button"
                  className={styles.chipBtnGhost}
                  disabled={!canEdit || busy}
                  onClick={() => void run(api.delete<{ data: MediaRow[] }>(`${base}/${row.id}`), 'حُذفت الصورة.')}
                >
                  حذف
                </button>
              </div>
            </div>
          ))}
        </div>
      )}

      <div className={styles.fields}>
        <div className={styles.field}>
          <label htmlFor="media-upload">إضافة صورة</label>
          <input
            id="media-upload"
            ref={input}
            type="file"
            accept={MEDIA_ACCEPT}
            disabled={!canEdit || busy}
            onChange={(event) => {
              const file = event.target.files?.[0];
              if (!file) return;
              const form = new FormData();
              form.append('image', file);
              void run(api.upload<{ data: MediaRow[] }>(base, form), 'أُضيفت الصورة.').then(() => {
                if (input.current) input.current.value = '';
              });
            }}
          />
          <span className={styles.hint}>PNG أو JPEG أو WebP أو AVIF أو SVG، حتى 10 ميغابايت.</span>
        </div>
      </div>
    </>
  );
}

/* ───── البحث والوصف ───── */

export type SeoKey = keyof SeoDoc['authored'];

/**
 * وثيقة SEO مرفوعة إلى الاستوديو نفسه لا إلى لوح خطوة واحدة.
 *
 * المسوّدة تعيش هنا لأن مساعد الكتابة (خطوة أخرى) يعتمد اقتراحًا في
 * `seoTitle`/`seoDesc`؛ لو عاشت داخل لوح البحث لضاع الاعتماد بمجرّد تبديل
 * الخطوة — وهو أسوأ من غياب الزرّ لأنه يوهم بإنجاز وقع.
 */
export function useSeoDoc(productId: number) {
  const base = `/admin/products/${productId}/seo`;
  const { data, setData, error, reload } = useSubResource<SeoDoc>(base);
  const [draft, setDraft] = useState<SeoDoc['authored'] | null>(null);
  const [busy, setBusy] = useState(false);

  // أوّل وصولٍ للبيانات يبذر المسوّدة — تعديل حالة عند تغيّر المدخل، لا أثر.
  const [seenData, setSeenData] = useState<SeoDoc | null>(null);
  if (data !== seenData) {
    setSeenData(data);
    if (data !== null) setDraft((current) => current ?? data.authored);
  }

  const setField = useCallback((field: SeoKey, value: string) => {
    setDraft((current) => (current === null ? current : { ...current, [field]: value }));
  }, []);

  const save = useCallback(async (): Promise<ApiResult<{ data: SeoDoc }> | null> => {
    if (draft === null) return null;
    setBusy(true);
    const result = await api.patch<{ data: SeoDoc }>(base, { ...draft });
    setBusy(false);
    if (result.ok) {
      setData(result.data.data);
      setDraft(result.data.data.authored);
    }
    return result;
  }, [base, draft, setData]);

  const dirty = data !== null && draft !== null && JSON.stringify(draft) !== JSON.stringify(data.authored);

  return { doc: data, draft, setField, save, busy, dirty, error, reload };
}

export type SeoDocState = ReturnType<typeof useSeoDoc>;

/**
 * حقول البحث الثلاثة التي ينشرها التصميم، موصولة بمالكيها الحقيقيين.
 *
 * الرابط يعيش في حمولة المنتج ويُحفظ مع «حفظ»؛ العنوان والوصف يعيشان في
 * `product_seo` ويُحفظان بزرّهما. الفصل مقصود: زرّ واحد يوهم أن الحقلين
 * يذهبان إلى المكان نفسه.
 */
export function SearchFields({
  seo,
  canEdit,
  slug,
  onSlugChange,
}: {
  seo: SeoDocState;
  canEdit: boolean;
  slug: string;
  onSlugChange: (value: string) => void;
}) {
  const { notice, ok, fail, clear } = useNotice();

  if (seo.error !== null) return <LoadError message={seo.error} retry={seo.reload} />;
  if (seo.doc === null || seo.draft === null) return <Loading />;

  const current = seo.draft;

  async function save() {
    clear();
    const result = await seo.save();
    if (result === null) return;
    if (!result.ok) {
      fail(result);
      return;
    }
    ok('حُفظت تهيئة البحث. المعروض أدناه هو مخرَج الخادم بعد قواعده.');
  }

  const fields: {
    id: string;
    label: string;
    value: string;
    budget: number | null;
    hint: string;
    long?: boolean;
    onChange: (value: string) => void;
  }[] = [
    {
      id: 's-slug-2',
      label: 'الرابط',
      value: slug,
      budget: null,
      hint: 'تغييره بعد النشر يكسر روابط قائمة ما لم يُضَف تحويل. يُحفظ بزرّ «حفظ» في الأسفل.',
      onChange: onSlugChange,
    },
    {
      id: 's-seo-title',
      label: 'عنوان البحث',
      value: current.seoTitle ?? '',
      budget: SEO_BUDGET.title,
      hint: 'اتركه فارغًا ليشتقّه MetadataService من عنوان المنتج.',
      onChange: (value) => seo.setField('seoTitle', value),
    },
    {
      id: 's-seo-desc',
      label: 'وصف البحث',
      value: current.seoDesc ?? '',
      budget: SEO_BUDGET.description,
      hint: 'حتى 500 حرف على الخادم، والميزانية المعروضة من TextBudget. الفارغ يعود إلى الوصف المختصر.',
      long: true,
      onChange: (value) => seo.setField('seoDesc', value),
    },
  ];

  return (
    <>
      <Notice notice={notice} />

      <div className={styles.searchFields}>
        {fields.map((field) => {
          const over = field.budget !== null && field.value.length > field.budget;
          return (
            <div className={styles.searchField} key={field.id}>
              <span className={styles.searchHead}>
                <label htmlFor={field.id} className={styles.searchLabel}>
                  {field.label}
                </label>
                {field.budget === null ? null : (
                  <bdi dir="ltr" className={cx(styles.searchCount, over && styles.searchCountOver)}>
                    {field.value.length}/{field.budget}
                  </bdi>
                )}
              </span>
              {field.long ? (
                <textarea
                  id={field.id}
                  dir="auto"
                  rows={3}
                  className={cx(styles.searchInput, over && styles.searchInputOver)}
                  value={field.value}
                  disabled={!canEdit || seo.busy}
                  onChange={(event) => field.onChange(event.target.value)}
                />
              ) : (
                <input
                  id={field.id}
                  type="text"
                  dir={field.id === 's-slug-2' ? 'ltr' : 'auto'}
                  className={cx(styles.searchInput, over && styles.searchInputOver)}
                  value={field.value}
                  disabled={!canEdit || seo.busy}
                  onChange={(event) => field.onChange(event.target.value)}
                />
              )}
              <span className={styles.searchHint}>
                {over ? 'فوق ميزانية العرض — سيُقصّ في نتائج البحث. ' : ''}
                {field.hint}
              </span>
            </div>
          );
        })}
      </div>

      <div className={styles.fields}>
        <div className={styles.field}>
          <label htmlFor="s-seo-answer">الإجابة السريعة</label>
          <textarea
            id="s-seo-answer"
            rows={2}
            dir="auto"
            value={current.quickAnswer ?? ''}
            disabled={!canEdit || seo.busy}
            onChange={(event) => seo.setField('quickAnswer', event.target.value)}
          />
          <span className={styles.hint}>جواب مباشر تستعمله نتائج البحث المولَّدة. حتى 1000 حرف.</span>
        </div>
        <div className={styles.field}>
          <label htmlFor="s-seo-syn">المرادفات</label>
          <input
            id="s-seo-syn"
            type="text"
            dir="auto"
            value={current.synonyms ?? ''}
            disabled={!canEdit || seo.busy}
            onChange={(event) => seo.setField('synonyms', event.target.value)}
          />
          <span className={styles.hint}>كلمات بديلة يبحث بها المعلّمون.</span>
        </div>
        <div className={styles.field}>
          <label htmlFor="s-seo-canon">الرابط المعياري</label>
          <input
            id="s-seo-canon"
            type="url"
            dir="ltr"
            value={current.canonicalOverride ?? ''}
            disabled={!canEdit || seo.busy}
            onChange={(event) => seo.setField('canonicalOverride', event.target.value)}
          />
          <span className={styles.hint}>عنوان كامل فقط. اتركه فارغًا ما لم تكن هذه الصفحة نسخة من أخرى.</span>
        </div>
      </div>

      <div className={styles.actions}>
        <button
          type="button"
          className="btn btn-primary"
          disabled={!canEdit || seo.busy || !seo.dirty}
          onClick={() => void save()}
        >
          {seo.busy ? 'جارٍ الحفظ…' : 'حفظ تهيئة البحث'}
        </button>
        {seo.dirty ? <span className="absent">تعديلات غير محفوظة في تهيئة البحث.</span> : null}
      </div>

      <SeoEffective doc={seo.doc} />
    </>
  );
}

/** المخرَج الفعلي بعد قواعد MetadataService — لا ما كُتب فقط. */
export function SeoEffective({ doc }: { doc: SeoDoc }) {
  /*
   * طوق التمرير: القيم عناوين canonical كاملة لا تُكسر، وبلا الطوق يدفع
   * الجدولُ الوثيقةَ كلَّها جانبًا (globals.css §.table-scroll). و`tabIndex`
   * ترميزٌ لا نمط: كروم لا يبئّر منطقة تمرير من تلقائه.
   */
  return (
    <div className="table-scroll" tabIndex={0} role="region" aria-label="جدول المخرَج الفعلي لتهيئة البحث">
      <table className={styles.kv}>
        <caption className={styles.kvCaption}>المخرَج الفعلي من الخادم بعد قواعده</caption>
        <tbody>
          <tr>
            <th scope="row">العنوان المُصيَّر</th>
            <td dir="auto">{doc.effective.renderedTitle}</td>
          </tr>
          <tr>
            <th scope="row">الوصف</th>
            <td dir="auto">{doc.effective.description}</td>
          </tr>
          <tr>
            <th scope="row">مصدر الوصف</th>
            <td>
              <bdi dir="ltr">{doc.effective.descriptionSource}</bdi>
            </td>
          </tr>
          <tr>
            <th scope="row">canonical</th>
            <td>
              <bdi dir="ltr">{doc.effective.canonical}</bdi>
            </td>
          </tr>
          <tr>
            <th scope="row">robots</th>
            <td>
              <bdi dir="ltr">{doc.effective.robots}</bdi>
            </td>
          </tr>
          <tr>
            <th scope="row">hreflang</th>
            <td>
              {doc.effective.alternates.length === 0 ? (
                'لا بديل معلَن — لا لغة أخرى منشورة وقابلة للفهرسة لهذا المنتج.'
              ) : (
                <ul className={styles.plainList}>
                  {doc.effective.alternates.map((alternate) => (
                    <li key={alternate.hreflang}>
                      <bdi dir="ltr">{alternate.hreflang}</bdi> → <bdi dir="ltr">{alternate.href}</bdi>
                      {alternate.isDefault ? ' (الافتراضية)' : ''}
                    </li>
                  ))}
                </ul>
              )}
            </td>
          </tr>
        </tbody>
      </table>
    </div>
  );
}

/* ───── تغطية التخصّصات ───── */

export function CoveragePanel({ productId, onGoTo }: { productId: number; onGoTo: (step: StepKey) => void }) {
  const { data, error, reload } = useSubResource<CoveragePayload>(`/admin/products/${productId}/coverage`);

  if (error !== null) return <LoadError message={error} retry={reload} />;
  if (data === null) return <Loading />;

  const dimensions = Object.entries(data.dimensions);

  return (
    <>
      <p className={styles.measured}>
        الحكم: <b>{COVERAGE_LABELS[data.status] ?? data.status}</b> —{' '}
        <bdi dir="ltr">{count(data.covered.length)}</bdi> من{' '}
        <bdi dir="ltr">{count(dimensions.length)}</bdi> أبعاد مستوفاة.
      </p>

      <ul className={styles.coverList}>
        {dimensions.map(([key, dimension]) => (
          <li key={key} className={cx(styles.coverRow, !dimension.present && styles.coverRowMissing)}>
            <span aria-hidden="true" className={styles.coverMark}>
              {dimension.present ? '✓' : '×'}
            </span>
            <span className={styles.coverLabel}>{dimension.label}</span>
            <span className={styles.coverValue} dir="auto">
              {dimension.present ? dimension.value : dimension.blocking ? 'ناقص وحاجب للحكم' : 'ناقص'}
            </span>
            <span className="sr-only">{dimension.present ? 'مستوفى' : 'ناقص'}</span>
            {dimension.present || COVERAGE_STEP[key] === undefined ? null : (
              <button
                type="button"
                className={styles.chipBtnGhost}
                onClick={() => onGoTo(COVERAGE_STEP[key] as StepKey)}
              >
                أكمله من التصنيف
              </button>
            )}
          </li>
        ))}
      </ul>

      {data.siblings === null ? (
        <p className={styles.hint}>
          الجيران لا يُحسبون قبل استيفاء الأبعاد الحاجبة: عدٌّ بلا مرحلة أو مادة رقم بلا معنى.
        </p>
      ) : (
        <p className={styles.hint}>
          منتجات منشورة تشترك في المادة والصفّ: <bdi dir="ltr">{count(data.siblings.sameSubjectGrade)}</bdi> ·
          وفي المادة والمرحلة: <bdi dir="ltr">{count(data.siblings.sameSubjectStage)}</bdi>. إشارة إلى اتّساع
          التغطية لا حكم عليها.
        </p>
      )}

      {data.reasonCodes.length > 0 ? (
        <p className={styles.hint}>
          رموز السبب من الخادم:{' '}
          <bdi dir="ltr" className="mono">
            {data.reasonCodes.join(' · ')}
          </bdi>
        </p>
      ) : null}
    </>
  );
}

/* ───── الانتشار الدولي ───── */

export function IntlPanel({
  productId,
  canEdit,
  canPublish,
}: {
  productId: number;
  canEdit: boolean;
  canPublish: boolean;
}) {
  const eligibility = useSubResource<LanguageState[]>(`/admin/products/${productId}/language-eligibility`);
  const translations = useSubResource<TranslationRow[]>(`/admin/products/${productId}/translations`);
  const { notice, ok, fail, clear } = useNotice();
  const [selected, setSelected] = useState<string | null>(null);
  const [draft, setDraft] = useState<TranslationRow['content'] | null>(null);
  const [busy, setBusy] = useState(false);
  const [unmet, setUnmet] = useState<{ label: string; detail: string | null }[]>([]);

  const rows = translations.data;
  const states = eligibility.data;

  // أوّل لغة قابلة للتحرير تُختار أثناء التصيير حين لا اختيار بعد.
  if (rows !== null && selected === null) {
    const first = rows.find((row) => row.editable);
    if (first !== undefined) {
      setSelected(first.locale);
      setDraft(first.content);
    }
  }

  if (eligibility.error !== null) return <LoadError message={eligibility.error} retry={eligibility.reload} />;
  if (translations.error !== null) return <LoadError message={translations.error} retry={translations.reload} />;
  if (states === null || rows === null) return <Loading />;

  const total = states.length;
  const eligible = states.filter((state) => state.canPublish).length;
  const cards: { key: LanguageState['status']; n: number }[] = (
    ['published', 'ready', 'incomplete', 'blocked'] as LanguageState['status'][]
  ).map((key) => ({ key, n: states.filter((state) => state.status === key).length }));

  const source = rows.find((row) => row.isDefault) ?? null;
  const active = selected === null ? null : (rows.find((row) => row.locale === selected) ?? null);
  const activeState = selected === null ? null : (states.find((state) => state.locale.code === selected) ?? null);

  function choose(locale: string) {
    const row = rows?.find((item) => item.locale === locale);
    if (row === undefined) return;
    setSelected(locale);
    setDraft(row.content);
    setUnmet([]);
    clear();
  }

  async function saveTranslation() {
    if (selected === null || draft === null) return;
    setBusy(true);
    clear();
    setUnmet([]);
    const result = await api.patch(`/admin/products/${productId}/translations/${selected}`, { ...draft });
    setBusy(false);
    if (!result.ok) {
      fail(result);
      return;
    }
    ok('حُفظت الترجمة. النشر خطوة مستقلّة.');
    void translations.reload();
    void eligibility.reload();
  }

  async function publishLocale() {
    if (selected === null) return;
    setBusy(true);
    clear();
    setUnmet([]);
    const result = await api.post(`/admin/products/${productId}/translations/${selected}/publish`);
    setBusy(false);
    if (!result.ok) {
      const body = result.body as { requirements?: { label: string; detail: string | null }[] } | null;
      if (body?.requirements) setUnmet(body.requirements);
      fail(result);
      return;
    }
    ok('نُشرت هذه اللغة.');
    void translations.reload();
    void eligibility.reload();
  }

  return (
    <>
      <Notice notice={notice} />

      <div className={styles.panelHead}>
        <h3 className={styles.panelTitle}>الانتشار العربي والدولي</h3>
        <span className={styles.intlBadge}>
          <bdi dir="ltr">{count(eligible)}</bdi> لغة مؤهَّلة من <bdi dir="ltr">{count(total)}</bdi>
        </span>
      </div>

      <div className={styles.intlCards}>
        {cards.map((card) => (
          <div key={card.key} className={cx(styles.intlCard, styles[`intl_${card.key}`])}>
            <bdi dir="ltr" className={styles.intlCardN}>
              {count(card.n)}
            </bdi>
            <span className={styles.intlCardLabel}>{LANGUAGE_LABELS[card.key]}</span>
          </div>
        ))}
      </div>

      <div className={styles.fields}>
        <div className={styles.field}>
          <label htmlFor="intl-locale">اللغة المعروضة</label>
          <select
            id="intl-locale"
            value={selected ?? ''}
            disabled={busy}
            onChange={(event) => choose(event.target.value)}
          >
            {rows
              .filter((row) => row.editable)
              .map((row) => {
                const state = states.find((item) => item.locale.code === row.locale);
                return (
                  <option key={row.locale} value={row.locale}>
                    {state?.locale.nameAr ?? row.locale} — {LANGUAGE_LABELS[state?.status ?? 'incomplete']}
                  </option>
                );
              })}
          </select>
          <span className={styles.hint}>
            العربية تُحرَّر من «أساسيات المنتج» لا من هنا: بابان لنصّ واحد يعني أن آخر من كتب يمحو الآخر صامتًا.
          </span>
        </div>
      </div>

      {active === null || draft === null ? (
        <p className={styles.measured}>لا لغة قابلة للترجمة مفعّلة في المنصّة بعد.</p>
      ) : (
        <>
          {/* طوق التمرير: أربعة أعمدة أحدها حقل إدخال، وهو أعرض ما في هذا اللوح
              (globals.css §.table-scroll). و`tabIndex` ترميزٌ لا نمط. */}
          <div className="table-scroll" tabIndex={0} role="region" aria-label="جدول وراثة حقول الترجمة">
            <table className={styles.kv}>
              <caption className={styles.kvCaption}>
                وراثة الحقول — الفارغ يرث النصّ العربي، والمكتوب يحلّ محلّه
              </caption>
              <thead>
                <tr>
                  <th scope="col">الحقل</th>
                  <th scope="col">القيمة النهائية</th>
                  <th scope="col">المصدر</th>
                  <th scope="col">الحالة</th>
                </tr>
              </thead>
              <tbody>
                {INHERIT_FIELDS.map((field) => {
                  const own = draft[field.key];
                  const inherited = source?.content[field.key] ?? null;
                  const translated = own !== null && own.trim() !== '';
                  return (
                    <tr key={field.key}>
                      <th scope="row">
                        <label htmlFor={`intl-${field.key}`}>{field.label}</label>
                      </th>
                      <td>
                        {field.long ? (
                          <textarea
                            id={`intl-${field.key}`}
                            rows={2}
                            dir="auto"
                            className={styles.searchInput}
                            value={own ?? ''}
                            disabled={!canEdit || busy}
                            placeholder={inherited ?? 'لا نصّ عربي يُورَث'}
                            onChange={(event) => setDraft({ ...draft, [field.key]: event.target.value })}
                          />
                        ) : (
                          <input
                            id={`intl-${field.key}`}
                            type="text"
                            dir={field.key === 'slug' ? 'ltr' : 'auto'}
                            className={styles.searchInput}
                            value={own ?? ''}
                            disabled={!canEdit || busy}
                            placeholder={inherited ?? 'لا نصّ عربي يُورَث'}
                            onChange={(event) => setDraft({ ...draft, [field.key]: event.target.value })}
                          />
                        )}
                      </td>
                      <td>{translated ? 'ترجمة هذه اللغة' : 'موروث من العربية'}</td>
                      <td>{translated ? 'مترجَم' : inherited === null ? 'لا مصدر' : 'وراثة'}</td>
                    </tr>
                  );
                })}
              </tbody>
            </table>
          </div>

          {activeState !== null ? (
            <ul className={styles.coverList}>
              {activeState.requirements.map((requirement) => (
                <li
                  key={requirement.key}
                  className={cx(styles.coverRow, !requirement.satisfied && styles.coverRowMissing)}
                >
                  <span aria-hidden="true" className={styles.coverMark}>
                    {requirement.satisfied ? '✓' : '×'}
                  </span>
                  <span className={styles.coverLabel}>{requirement.label}</span>
                  <span className={styles.coverValue}>
                    {requirement.detail ??
                      (requirement.satisfied ? 'مستوفى' : requirement.blocking ? 'حاجب للنشر' : 'غير حاجب')}
                  </span>
                  <span className="sr-only">{requirement.satisfied ? 'مستوفى' : 'ناقص'}</span>
                </li>
              ))}
            </ul>
          ) : null}

          {unmet.length > 0 ? (
            <div className="state-error" role="alert">
              <p>النشر مرفوض حتى يُستوفى:</p>
              <ul className={styles.plainList}>
                {unmet.map((requirement) => (
                  <li key={requirement.label}>
                    {requirement.label}
                    {requirement.detail === null ? '' : ` — ${requirement.detail}`}
                  </li>
                ))}
              </ul>
            </div>
          ) : null}

          <div className={styles.actions}>
            <button
              type="button"
              className="btn btn-primary"
              disabled={!canEdit || busy}
              onClick={() => void saveTranslation()}
            >
              {busy ? 'جارٍ…' : 'حفظ الترجمة'}
            </button>
            <button
              type="button"
              className="btn btn-ghost"
              disabled={!canPublish || busy || activeState === null || !activeState.canPublish}
              onClick={() => void publishLocale()}
            >
              نشر هذه اللغة
            </button>
            {!canPublish ? <span className="absent">دورك لا يملك صلاحية النشر.</span> : null}
            {activeState !== null && !activeState.hreflangEmitted ? (
              <span className="absent">
                لا يُعلَن <bdi dir="ltr">hreflang</bdi> لهذه اللغة بعد — الوسم لا يُكتب بلا نظير منشور يخدمه المسار.
              </span>
            ) : null}
          </div>
        </>
      )}
    </>
  );
}

/* ───── مساعد الكتابة ───── */

/**
 * لوح المساعد — «TahQwenPanel».
 *
 * ثلاثة التزامات: لا يُكتب شيء في قاعدة البيانات (الخادم يردّ `applied:false`
 * والاعتماد يملأ المسوّدة فقط)، والتعطّل يُعلَن بردّ الخادم نفسه (٥٠٣ مع سببه)
 * لا بتخمين الواجهة، ولا درجة ثقة ولا زمن استجابة — الخادم لا يُصدرهما.
 */
export function WritingAssistant({
  productId,
  canEdit,
  online,
  currentValue,
  onAccept,
}: {
  productId: number;
  canEdit: boolean;
  online: boolean;
  currentValue: (field: WritingField) => string;
  onAccept: (field: WritingField, value: string) => void;
}) {
  const [busy, setBusy] = useState<string | null>(null);
  const [result, setResult] = useState<{ field: WritingField; label: string; payload: SuggestPayload } | null>(null);
  const [blocked, setBlocked] = useState<{ message: string; provider: string | null } | null>(null);
  const [accepted, setAccepted] = useState<string | null>(null);

  if (!online) {
    return (
      <p className={styles.measured} role="status">
        المساعد متوقّف من مفتاح «النشر». تشغيله يُتيح طلب اقتراح؛ وإيقافه لا يمنع الكتابة اليدوية ولا الحفظ ولا النشر.
      </p>
    );
  }

  async function suggest(field: WritingField, label: string) {
    setBusy(field);
    setAccepted(null);
    setBlocked(null);
    const response = await api.post<{ data: SuggestPayload }>(`/admin/products/${productId}/writing/suggest`, {
      field,
      count: 3,
    });
    setBusy(null);

    if (!response.ok) {
      /* ‏503 حالة معروفة لا عطل: المزوّد غير مهيَّأ أو غير متاح، والخادم يقول أيّهما. */
      const body = response.body as { provider?: string } | null;
      setBlocked({ message: response.message, provider: body?.provider ?? null });
      setResult(null);
      return;
    }

    setResult({ field, label, payload: response.data.data });
  }

  return (
    <>
      <p className={styles.panelNote}>
        اقتراحات فقط: الخادم يردّ <code dir="ltr">applied: false</code> ولا يكتب شيئًا. «اعتمد» يملأ حقل
        المسوّدة، والحفظ يبقى بيدك.
      </p>

      <div className={styles.chips}>
        {WRITING_FIELDS.map((entry) => (
          <button
            key={entry.field}
            type="button"
            className={styles.chipBtn}
            disabled={!canEdit || busy !== null}
            onClick={() => void suggest(entry.field, entry.label)}
          >
            {busy === entry.field ? 'جارٍ…' : `اقترح ${entry.label}`}
          </button>
        ))}
      </div>

      {blocked !== null ? (
        <div className="state-line" role="status">
          <b>المساعد غير متاح الآن — وهذا لا يمنع شيئًا.</b> {blocked.message} الكتابة اليدوية والحفظ والنشر
          تعمل كما هي، ولا طابور ولا محاولة تلقائية بلا إذنك.
          {blocked.provider === null ? null : (
            <>
              {' '}
              المزوّد: <bdi dir="ltr">{blocked.provider}</bdi>.
            </>
          )}
        </div>
      ) : null}

      {accepted !== null ? (
        <p className="state-ok" role="status">
          نُقل المقترح إلى «{accepted}» ولم يُحفظ بعد — عدّله إن شئت ثم اضغط «حفظ».
        </p>
      ) : null}

      {result !== null ? (
        <>
          {/* طوق التمرير: قيمة الحقل قد تطول سطرًا كاملًا، ولا يجرّ الجدولُ
              الوثيقةَ معه على الشاشات الضيّقة (globals.css §.table-scroll). */}
          <div className="table-scroll" tabIndex={0} role="region" aria-label="جدول نتيجة مساعد الكتابة">
            <table className={styles.kv}>
              <tbody>
                <tr>
                  <th scope="row">القيمة الحالية</th>
                  <td dir="auto">{currentValue(result.field) || 'فارغ'}</td>
                </tr>
                <tr>
                  <th scope="row">المزوّد</th>
                  <td>
                    <bdi dir="ltr">{result.payload.provider}</bdi>
                  </td>
                </tr>
                <tr>
                  <th scope="row">حالة الكتابة</th>
                  <td>لم يُكتب شيء في قاعدة البيانات.</td>
                </tr>
              </tbody>
            </table>
          </div>

          <ul className={styles.suggestList}>
            {result.payload.suggestions.map((suggestion, index) => (
              <li key={index} className={styles.suggestItem}>
                <p dir="auto" className={styles.suggestText}>
                  {suggestion}
                </p>
                {/*
                  زرّ اعتماد واحد: التصميم يرسم «اعتمد» و«عدّل ثم اعتمد»، وهما
                  هنا الفعل نفسه — المقترح يهبط في حقل قابل للتحرير ثم يُحفظ
                  بيدك. زرّان بسلوك واحد وتسميتين يَعِدان بفرق لا وجود له.
                */}
                <button
                  type="button"
                  className={styles.chipBtn}
                  disabled={!canEdit}
                  onClick={() => {
                    onAccept(result.field, suggestion);
                    setAccepted(result.label);
                  }}
                >
                  اعتمد في الحقل
                </button>
              </li>
            ))}
          </ul>

          <div className={styles.actions}>
            <button type="button" className="btn btn-ghost" onClick={() => setResult(null)}>
              أهمِل المقترحات
            </button>
          </div>
        </>
      ) : null}
    </>
  );
}

/* ───── صيغ المنتج ───── */

/**
 * صيغ المنتج — Word وPowerPoint وPDF للملفّ نفسه، ولكلٍّ سعرها وملفّها.
 *
 * صفحة المتجر تعرض المُبدِّل منذ ٢٠٢٦-٠٩-١٧، ولم يكن في اللوحة ما يؤلّف
 * صيغةً ثانية — فبقي قدرةً لا تُرى.
 *
 * واللوحة تُظهر تحذيرين لا تُخفيهما، لأنّهما بالضبط ما يجعل المُبدِّل صادقًا:
 * صيغةٌ منشورة بلا ملفٍّ مربوطٍ بها، وملفّاتٌ عامّة باقية بعد تعدّد الصيغ.
 */
export function VariantsPanel({ productId, canEdit }: { productId: number; canEdit: boolean }) {
  const base = `/admin/products/${productId}/variants`;
  const { data, setData, error, loading, reload } = useSubResource<VariantRow[]>(base);
  const { notice, ok, info, fail, clear } = useNotice();
  const [busy, setBusy] = useState(false);
  const [draft, setDraft] = useState({
    sku: '',
    slug: '',
    title: '',
    format: '',
    regular: '',
    sale: '',
  });

  async function run(action: Promise<ApiResult<{ data: VariantRow[] }>>, success: string) {
    setBusy(true);
    clear();
    const result = await action;
    setBusy(false);
    if (!result.ok) {
      fail(result);
      return;
    }
    setData(result.data.data);
    ok(success);
  }

  if (error !== null) return <LoadError message={error} retry={reload} />;
  if (data === null) return <Loading />;

  const rows = data;
  /*
   * الملفّات العامّة تُقرأ من أيّ صفّ: الخادم يحسبها للمنتج لا للصيغة،
   * فقيمتها واحدة في كلّ الصفوف.
   */
  const generic = rows[0]?.genericDeliverableFiles ?? 0;
  const published = rows.filter((row) => row.status === 'published');
  const missingFiles = rows.filter((row) => row.status === 'published' && row.deliverableFiles === 0);

  function submit() {
    /*
     * فحصٌ محلّيّ قبل الإرسال — و`info` لا `fail`: الأخيرة مخصّصة لنصّ
     * الخادم («هو وحده يعرف ما رُفض ولماذا»)، وتلفيق نتيجةٍ فاشلة لتمريره
     * يجعل رسالةً من هنا تبدو كأنّها منه.
     */
    const regular = riyalsToMinor(draft.regular);
    if (regular === null) {
      info('أدخل السعر الأصليّ بالريالات.');
      return;
    }
    const sale = draft.sale.trim() === '' ? null : riyalsToMinor(draft.sale);
    if (draft.sale.trim() !== '' && sale === null) {
      info('سعر التخفيض غير صالح.');
      return;
    }

    void run(
      api.post<{ data: VariantRow[] }>(base, {
        sku: draft.sku.trim(),
        slug: draft.slug.trim(),
        title: draft.title.trim() === '' ? null : draft.title.trim(),
        format: draft.format.trim() === '' ? null : draft.format.trim(),
        status: 'draft',
        sellingModel: 'ONE_TIME',
        offerStatus: 'active',
        regularMinor: regular,
        saleMinor: sale,
      }),
      'أُضيفت الصيغة مسوّدةً — انشرها بعد ربط ملفّها.',
    ).then(() => setDraft({ sku: '', slug: '', title: '', format: '', regular: '', sale: '' }));
  }

  return (
    <>
      <Notice notice={notice} />

      {/*
        المعيار هو ما يعدّه المتجر: الصيغ **المنشورة** وحدها
        (`ProductGroup::publishedVariants`). ولوحةُ التأليف تنشئ الصيغة
        مسوّدةً عمدًا، فعدُّ كلّ الصفوف كان يُخفي التحذير فور الإضافة ويزعم
        أنّ المُبدِّل ظهر — وهو لم يظهر، ولا إشارة للمحرّر أنّ النشر باقٍ.
      */}
      {published.length <= 1 ? (
        <p className={styles.measured}>
          {rows.length > 1
            ? 'المُبدِّل لا يظهر في المتجر بعد: صيغةٌ منشورة واحدة. انشر صيغةً أخرى ليظهر.'
            : 'صيغةٌ واحدة: مُبدِّل الصيغ لا يظهر في المتجر — «اختر من بين واحد» ليس اختيارًا. أضف صيغةً ثانية ليظهر.'}
        </p>
      ) : null}

      {generic > 0 && published.length > 1 ? (
        <p className={cx(styles.measured, 'state-error')} role="alert">
          {count(generic)} ملفًّا قابلًا للتسليم غير مربوطٍ بصيغة، فيُسلَّم لمشتري كلّ صيغة. اربطه
          بصيغته من خطوة «الملفات».
        </p>
      ) : null}

      {missingFiles.length > 0 ? (
        <p className={cx(styles.measured, 'state-error')} role="alert">
          صيغٌ منشورة بلا ملفٍّ مربوط: {missingFiles.map((row) => row.sku).join(' · ')} — تُباع ولا
          يُسلَّم لها شيء.
        </p>
      ) : null}

      <div className={styles.chipsWrap}>
        {rows.map((row) => (
          <div className={styles.fileChip} key={row.id}>
            <span className={styles.fileChipMain}>
              <bdi dir="ltr" className={styles.fileChipName}>
                {row.title ?? row.format ?? row.sku}
              </bdi>
              <span className={styles.fileChipSize}>
                <bdi dir="ltr">{row.sku}</bdi>
                {row.offer === null
                  ? ' · بلا عرض — لا تُباع'
                  : ` · ${money(row.offer.effectiveMinor)}`}
                {row.isBridge ? ' · الصيغة الأصليّة' : ''}
                {` · ${count(row.deliverableFiles)} ملفًّا`}
              </span>
            </span>

            <label className={styles.chipField}>
              <span className="sr-only">حالة الصيغة {row.sku}</span>
              <select
                value={row.status}
                disabled={!canEdit || busy}
                onChange={(event) =>
                  void run(
                    api.patch<{ data: VariantRow[] }>(`${base}/${row.id}`, {
                      status: event.target.value,
                    }),
                    'حُدّثت حالة الصيغة.',
                  )
                }
              >
                {Object.entries(VARIANT_STATUS_LABELS).map(([value, label]) => (
                  <option key={value} value={value}>
                    {label}
                  </option>
                ))}
              </select>
            </label>

            {row.offer !== null ? (
              <label className={styles.chipField}>
                <span className="sr-only">حالة عرض {row.sku}</span>
                <select
                  value={row.offer.status}
                  disabled={!canEdit || busy}
                  onChange={(event) =>
                    void run(
                      api.patch<{ data: VariantRow[] }>(`${base}/${row.id}`, {
                        offerStatus: event.target.value,
                      }),
                      'حُدّثت حالة العرض.',
                    )
                  }
                >
                  {Object.entries(OFFER_STATUS_LABELS).map(([value, label]) => (
                    <option key={value} value={value}>
                      {label}
                    </option>
                  ))}
                </select>
              </label>
            ) : null}

            {row.offer !== null ? (
              <label className={styles.chipField}>
                <span className="sr-only">السعر الأصليّ لـ{row.sku} بالريالات</span>
                <input
                  type="text"
                  inputMode="decimal"
                  dir="ltr"
                  defaultValue={minorToRiyals(row.offer.regularMinor)}
                  disabled={!canEdit || busy}
                  onBlur={(event) => {
                    const next = riyalsToMinor(event.target.value);
                    if (next === null || next === row.offer?.regularMinor) return;
                    void run(
                      api.patch<{ data: VariantRow[] }>(`${base}/${row.id}`, { regularMinor: next }),
                      'حُدّث السعر.',
                    );
                  }}
                />
              </label>
            ) : null}

            {/*
              * §٢٧ — وزن الصيغة بالغرامات.
              *
              * بوّابة النشر تطلبه من المنتج الماديّ و`ShipmentExecutor` يرفض
              * الشحن بدونه، ولم يكن في اللوحة مدخلٌ واحد يكتبه: يُطالَب
              * المشغّل ببندٍ لا سبيل له إلى إكماله. والوحدة في التسمية لا في
              * النصّ — «٢ كغ» في حقلٍ يُقرأ غرامات يُسعّر الشحن خطأً.
              */}
            <label className={styles.chipField}>
              <span className="sr-only">وزن {row.sku} بالغرامات</span>
              <input
                type="text"
                inputMode="numeric"
                dir="ltr"
                placeholder="الوزن/غم"
                defaultValue={row.weightGrams === null ? '' : String(row.weightGrams)}
                disabled={!canEdit || busy}
                onBlur={(event) => {
                  const raw = event.target.value.trim();
                  /* فارغٌ يعني «امحُ القياس» لا «صفر غرام». */
                  const next = raw === '' ? null : Number(raw);

                  if (next !== null && (!Number.isInteger(next) || next < 0)) {
                    info('الوزن عددٌ صحيح بالغرامات.');
                    return;
                  }
                  if (next === row.weightGrams) return;

                  void run(
                    api.patch<{ data: VariantRow[] }>(`${base}/${row.id}`, { weightGrams: next }),
                    next === null ? 'مُحي وزن الصيغة.' : 'حُدّث الوزن.',
                  );
                }}
              />
            </label>

            <button
              type="button"
              className={styles.chipBtnGhost}
              disabled={!canEdit || busy || row.isBridge || rows.length <= 1}
              onClick={() => void run(api.delete<{ data: VariantRow[] }>(`${base}/${row.id}`), 'حُذفت الصيغة.')}
            >
              حذف
            </button>
          </div>
        ))}
      </div>

      {canEdit ? (
        <div className={styles.fields}>
          <Field id="variant-sku" label="رمز الصيغة (SKU)" hint="فريدٌ في المتجر كلّه.">
            <input
              id="variant-sku"
              dir="ltr"
              value={draft.sku}
              disabled={busy}
              onChange={(event) => setDraft({ ...draft, sku: event.target.value })}
            />
          </Field>
          <Field id="variant-slug" label="المُعرِّف اللفظيّ" hint="فريدٌ كذلك.">
            <input
              id="variant-slug"
              dir="ltr"
              value={draft.slug}
              disabled={busy}
              onChange={(event) => setDraft({ ...draft, slug: event.target.value })}
            />
          </Field>
          <Field id="variant-title" label="الاسم المعروض" hint="مثل «PowerPoint» — يظهر في المُبدِّل.">
            <input
              id="variant-title"
              value={draft.title}
              disabled={busy}
              onChange={(event) => setDraft({ ...draft, title: event.target.value })}
            />
          </Field>
          <Field id="variant-format" label="الصيغة" hint="مثل PPTX — تظهر في جدول المواصفات.">
            <input
              id="variant-format"
              dir="ltr"
              value={draft.format}
              disabled={busy}
              onChange={(event) => setDraft({ ...draft, format: event.target.value })}
            />
          </Field>
          <Field id="variant-regular" label="السعر الأصليّ" hint="بالريالات.">
            <input
              id="variant-regular"
              dir="ltr"
              inputMode="decimal"
              value={draft.regular}
              disabled={busy}
              onChange={(event) => setDraft({ ...draft, regular: event.target.value })}
            />
          </Field>
          <Field id="variant-sale" label="سعر التخفيض" hint="اتركه فارغًا إن لا تخفيض.">
            <input
              id="variant-sale"
              dir="ltr"
              inputMode="decimal"
              value={draft.sale}
              disabled={busy}
              onChange={(event) => setDraft({ ...draft, sale: event.target.value })}
            />
          </Field>
          <div className={styles.fieldWide}>
            <button
              type="button"
              className="btn btn-primary"
              disabled={busy || draft.sku.trim() === '' || draft.slug.trim() === '' || draft.regular.trim() === ''}
              onClick={submit}
            >
              أضف صيغة
            </button>
          </div>
        </div>
      ) : null}

      {loading ? <Loading /> : null}
    </>
  );
}
