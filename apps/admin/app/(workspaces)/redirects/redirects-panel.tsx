'use client';
// UNIT: REDIRECTS-PANEL
// FILE: redirects-panel.tsx
// STYLE: redirects.module.css
// PARENT: PAGE-SEO
// USES: ADMIN-SESSION, LIB-ADMIN-API, LIB-FORMAT, LIB-IDEMPOTENCY, RESOURCE
// API: DELETE /admin/redirects
// MAP: docs/PROJECT_MAP.md

/**
 * قواعد التحويل — ‎/redirects‎.
 *
 * الخدمة (`RedirectService`) مبنيّة ومُختبَرة بثمانية عشر اختبارًا: كشف
 * الحلقة، وطيّ السلسلة إلى قفزة واحدة، وحرف البدل، والمصدر المكرَّر، والهدف
 * المسجَّل ٤٠٤. ولم يكن لها مستدعٍ واحد في الإنتاج — فجدول `redirect_rules`
 * لا سبيل إلى ملئه، بينما وسيط المتجر يجلب `/api/v1/redirects/rules` في كلّ
 * طلب ليطبّق جدولًا لا يستطيع أحد أن يكتب فيه صفًّا. هذه الشاشة هي الباب.
 *
 * ثلاثة قرارات فيها:
 *
 *  · **لا حكم ثانٍ على القاعدة.** الحلقة والتكرار والهدف الميّت كلّها قرار
 *    الخادم؛ الشاشة ترسل وتعرض ما رُدّ. تحقّقٌ محلّيّ يقلّد الخدمة يفترق عنها
 *    أوّل ما تتغيّر إحداهما، فتقول اللوحة «صالحة» ويقول الخادم «حلقة».
 *
 *  · **رمزان لا أربعة.** ٣٠١ و٣٠٢ وحدهما، لأنّ الخدمة ترفض ما عداهما
 *    (`RedirectRuleError::invalidStatus`). ولا تُعرض ٣٠٧/٣٠٨ في قائمةٍ ثمّ
 *    يرفضهما الخادم: خيارٌ يُعرض ولا يعمل أسوأ من خيارٍ غائب.
 *
 *  · **الدائم يُحذَّر منه عند اختياره.** ٣٠١ — كـ٣٠٨ تمامًا — يُخزَّن في
 *    المتصفّح بلا سبيل إلى إبطاله، ولهذا يفضّل المستودع ٣٠٧ على ٣٠٨ في
 *    `next.config.ts`. التحذير يظهر لحظة الاختيار لا في وثيقةٍ تُقرأ بعد
 *    الحفظ.
 */

import { useCallback, useMemo, useState } from 'react';
import { useSession } from '../../../components/admin-session';
import { EmptyRows, ResourceView, useResource, type ResourceState } from '../../../components/resource';
import { idempotencyKey } from '../../../lib/idempotency';
import { api, firstError } from '../../../lib/admin-api';
import { count, dateTime } from '../../../lib/format';
import styles from './redirects.module.css';

/* ───── الأنواع: معلَنة هنا، لا في طبقة الوصول المشتركة ───── */

/** صفّ كما يقدّمه `RedirectController::present`. */
interface RedirectRow {
  id: number;
  fromPath: string;
  toPath: string;
  statusCode: number;
  enabled: boolean;
  hits: number;
  reason: string | null;
  isWildcard: boolean;
  /** نسخة الصفّ — تعود في التحرير التالي فتمنع السحق الصامت. */
  version: number;
  createdAt: string | null;
  updatedAt: string | null;
}

interface RedirectsPayload {
  data: RedirectRow[];
  counts: { all: number; enabled: number; fired: number };
}

/** الصلاحيتان كما يفرضهما `routes/api-admin.php` — تُسمّيان للمشغّل حرفيًّا. */
const READ_PERMISSION = 'analytics.read';
const WRITE_PERMISSION = 'search.index.manage';

/**
 * الرمزان اللذان تقبلهما الخدمة، ولا ثالث.
 *
 * ٣٠٧ و٣٠٨ غائبان لأنّ `RedirectService::save()` يرفضهما بقائمة سماح صريحة،
 * لا لأنّ الشاشة اختارت إخفاءهما.
 */
const STATUS_CHOICES = [
  { value: 302, label: '٣٠٢ — مؤقّت', hint: 'الزائر يُحوَّل الآن، ولك أن تتراجع غدًا.' },
  { value: 301, label: '٣٠١ — دائم', hint: 'محرّكات البحث تنقل ترتيب الصفحة القديمة إلى الجديدة.' },
] as const;

const EMPTY = {
  fromPath: '',
  toPath: '',
  statusCode: 302,
  enabled: true,
  reason: '',
};

type Form = typeof EMPTY;

/**
 * حالات القراءة الأربع صراحةً — و٤٠٣ تسمّي الصلاحية الناقصة.
 *
 * الوسيط يردّ الرسالة العامّة نفسها لكلّ منع، والمشغّل الذي فُتحت له الشاشة
 * ولم تُعرض بياناتها يحتاج اسم الصلاحية ليطلبها من المالك — لا «لا تملك
 * صلاحية» وحدها.
 */
function RedirectsState({
  state,
  reload,
  children,
}: {
  state: ResourceState<RedirectsPayload>;
  reload: () => void;
  children: (payload: RedirectsPayload) => React.ReactNode;
}) {
  if (state.status === 'error' && state.code === 403) {
    return (
      <div className="state-error" role="alert">
        <p>
          لا تملك صلاحية <bdi dir="ltr">{READ_PERMISSION}</bdi> لقراءة جدول التحويلات.
        </p>
      </div>
    );
  }

  return (
    <ResourceView state={state} reload={reload}>
      {(payload) => children(payload)}
    </ResourceView>
  );
}

export function RedirectsPanel() {
  const { can } = useSession();
  const mayWrite = can(WRITE_PERMISSION);
  const { state, reload } = useResource<RedirectsPayload>('/admin/redirects');

  const [form, setForm] = useState<Form>(EMPTY);
  const [editing, setEditing] = useState<RedirectRow | null>(null);
  const [busy, setBusy] = useState(false);
  const [rowBusy, setRowBusy] = useState<number | null>(null);
  const [failure, setFailure] = useState<string | null>(null);
  const [done, setDone] = useState<React.ReactNode>(null);

  const ready = form.fromPath.trim() !== '' && form.toPath.trim() !== '';
  const chosen = useMemo(
    () => STATUS_CHOICES.find((choice) => choice.value === form.statusCode) ?? STATUS_CHOICES[0],
    [form.statusCode],
  );

  const cancelEdit = useCallback(() => {
    setEditing(null);
    setForm(EMPTY);
    setFailure(null);
    setDone(null);
  }, []);

  const save = useCallback(async () => {
    if (!ready) return;

    setBusy(true);
    setFailure(null);
    setDone(null);

    const payload = {
      fromPath: form.fromPath.trim(),
      toPath: form.toPath.trim(),
      statusCode: form.statusCode,
      enabled: form.enabled,
      reason: form.reason.trim() === '' ? null : form.reason.trim(),
    };

    /*
     * النسخة تُرسَل مع كلّ تحرير. غيابُها يعني «اكتب فوق ما لا أعرفه»،
     * وهو ما كان يمحو تصحيح محرِّرٍ آخر بلا رسالة.
     */
    const body =
      editing === null
        ? { ...payload, idempotencyKey: idempotencyKey('redirects', payload) }
        : {
            ...payload,
            expectedVersion: editing.version,
            idempotencyKey: idempotencyKey('redirects', { ...payload, id: editing.id, v: editing.version }),
          };

    const result =
      editing === null
        ? await api.post<{ data: RedirectRow }>('/admin/redirects', body)
        : await api.patch<{ data: RedirectRow }>(`/admin/redirects/${editing.id}`, body);
    setBusy(false);

    /*
     * لا «حُفظت» قبل أن يقبل الخادم. رفضُ الخدمة (حلقة · مصدر مكرَّر · هدف
     * ٤٠٤) يصل تحت اسم الحقل، و`firstError` تلتقطه — فيقرأ المشغّل السبب
     * المكتوب لا «فشل الحفظ».
     */
    if (!result.ok) {
      /*
       * التعارض ليس خطأ إدخال: المشغّل لم يُخطئ، بل سبقه آخر. فيُقال له ذلك
       * ويُعاد تحميل الجدول ليرى الحاليّ — لا «أعد المحاولة» التي تعني هنا
       * «اكتب فوق تصحيح زميلك».
       */
      if (result.code === 'STALE_VERSION') {
        setFailure(`${result.message} حُدِّث الجدول بالحاليّ.`);
        setEditing(null);
        reload();

        return;
      }

      setFailure(firstError(result));

      return;
    }

    const saved = result.data.data;
    // الهدف المحفوظ قد يخالف المكتوب: الخدمة تطوي السلسلة إلى هدفها النهائي.
    const collapsed = saved.toPath !== payload.toPath;
    setDone(
      <>
        {editing === null ? 'أُضيفت القاعدة' : 'حُدّثت القاعدة'} <bdi dir="ltr">{saved.fromPath}</bdi> ←{' '}
        <bdi dir="ltr">{saved.toPath}</bdi>.
        {collapsed ? (
          <>
            {' '}
            طُويت السلسلة: كتبتَ <bdi dir="ltr">{payload.toPath}</bdi> وهو نفسه محوَّل، فحُفظ الهدف
            النهائي مباشرةً كي تبقى القفزة واحدة.
          </>
        ) : null}
      </>,
    );
    setForm(EMPTY);
    setEditing(null);
    reload();
  }, [form, editing, ready, reload]);

  const startEdit = useCallback((row: RedirectRow) => {
    setEditing(row);
    setForm({
      fromPath: row.fromPath,
      toPath: row.toPath,
      statusCode: row.statusCode,
      enabled: row.enabled,
      reason: row.reason ?? '',
    });
    setFailure(null);
    setDone(null);
  }, []);

  const remove = useCallback(
    async (row: RedirectRow) => {
      setRowBusy(row.id);
      setFailure(null);
      setDone(null);

      const result = await api.delete(`/admin/redirects/${row.id}`, {
        expectedVersion: row.version,
      });
      setRowBusy(null);

      if (!result.ok) {
        if (result.code === 'STALE_VERSION') {
          setFailure(`${result.message} حُدِّث الجدول بالحاليّ.`);
          reload();

          return;
        }

        setFailure(firstError(result));

        return;
      }

      if (editing?.id === row.id) cancelEdit();
      setDone(
        <>
          حُذفت القاعدة <bdi dir="ltr">{row.fromPath}</bdi> — المسار يعود إلى ما كان عليه قبلها.
        </>,
      );
      reload();
    },
    [reload, editing, cancelEdit],
  );

  return (
    <section className={styles.workspace}>
      <p className="state-line">
        وسيط المتجر يقرأ هذا الجدول في كلّ طلب ويطبّقه قبل التوجيه: القاعدة تعمل فور حفظها بلا
        نشر ولا بناء. والسلسلة تُطوى عند الحفظ لا عند الطلب، فزائرُ المسار القديم يُحوَّل قفزةً
        واحدة مهما تعدّدت النقلات قبله.
      </p>

      <RedirectsState state={state} reload={reload}>
        {(payload) => (
          <>
            {mayWrite ? (
              <section className="panel" aria-labelledby="redirect-editor-title">
                <h2 id="redirect-editor-title">
                  {editing === null ? 'أضف قاعدة تحويل' : 'عدّل قاعدة قائمة'}
                </h2>

                {editing !== null ? (
                  <p className={styles.editing}>
                    <span>
                      تُعدّل الآن <bdi dir="ltr">{editing.fromPath}</bdi> — يُحفظ فوق الصفّ نفسه.
                    </span>
                    <button type="button" className="btn btn-ghost" onClick={cancelEdit}>
                      ألغِ التعديل
                    </button>
                  </p>
                ) : null}

                <div className="form-grid">
                  <label className="field" htmlFor="redirect-from">
                    <span>المسار القديم</span>
                    <input
                      id="redirect-from"
                      dir="ltr"
                      required
                      aria-required="true"
                      className={styles.path}
                      value={form.fromPath}
                      placeholder="/old-shop/*"
                      onChange={(event) => setForm({ ...form, fromPath: event.target.value })}
                    />
                    <span className="absent">
                      مسار داخليّ يبدأ بشرطة مائلة. النجمة في آخره وحدها تلتقط ما بعده:{' '}
                      <bdi dir="ltr">/old-shop/*</bdi> تلتقط كلّ صفحةٍ تحته.
                    </span>
                  </label>

                  <label className="field" htmlFor="redirect-to">
                    <span>المسار الجديد</span>
                    <input
                      id="redirect-to"
                      dir="ltr"
                      required
                      aria-required="true"
                      className={styles.path}
                      value={form.toPath}
                      placeholder="/products/*"
                      onChange={(event) => setForm({ ...form, toPath: event.target.value })}
                    />
                    <span className="absent">
                      نجمةٌ في الطرفين تنقل الملتقَط كما هو (<bdi dir="ltr">/old-shop/a</bdi> ←{' '}
                      <bdi dir="ltr">/products/a</bdi>)، وهدفٌ بلا نجمة وجهةٌ ثابتة. الوجهات خارج
                      الموقع مرفوضة ما لم يكن مضيفها معلَنًا في إعدادات الخادم.
                    </span>
                  </label>

                  <label className="field" htmlFor="redirect-status">
                    <span>نوع التحويل</span>
                    <select
                      id="redirect-status"
                      value={form.statusCode}
                      onChange={(event) => setForm({ ...form, statusCode: Number(event.target.value) })}
                    >
                      {STATUS_CHOICES.map((choice) => (
                        <option key={choice.value} value={choice.value}>
                          {choice.label}
                        </option>
                      ))}
                    </select>
                    <span className="absent">{chosen.hint}</span>
                  </label>

                  <label className="field" htmlFor="redirect-reason">
                    <span>السبب</span>
                    <input
                      id="redirect-reason"
                      value={form.reason}
                      placeholder="نُقلت صفحة المرحلة إلى مسار الفصول"
                      onChange={(event) => setForm({ ...form, reason: event.target.value })}
                    />
                    <span className="absent">
                      لمن يقرأ الجدول بعد سنة — القاعدة بلا سبب لا يجرؤ أحد على حذفها.
                    </span>
                  </label>

                  <label className="field field-inline" htmlFor="redirect-enabled">
                    <input
                      id="redirect-enabled"
                      type="checkbox"
                      checked={form.enabled}
                      onChange={(event) => setForm({ ...form, enabled: event.target.checked })}
                    />
                    <span>مفعَّلة</span>
                  </label>
                </div>

                {/*
                  التحذير عند الاختيار لا بعده: ٣٠١ يُخزَّن في المتصفّح دائمًا،
                  وهو سبب تفضيل المستودع ٣٠٧ على ٣٠٨ في تحويلات `next.config.ts`.
                */}
                {form.statusCode === 301 ? (
                  <p className={styles.permanentNote} role="note">
                    <b>الدائم لا يُبطَل من عندك.</b> المتصفّح يخزّن{' '}
                    <bdi dir="ltr">301</bdi> ويطبّقه من ذاكرته دون أن يسأل الخادم مرّة أخرى — تمامًا
                    كـ<bdi dir="ltr">308</bdi>. فحذفُ القاعدة هنا لاحقًا لا يُعيد زائرًا زار المسار
                    القديم مرّة واحدة؛ سيبقى يُحوَّل حتى ينظّف ذاكرته بيده. ولهذا تُكتب تحويلات{' '}
                    <bdi dir="ltr">next.config.ts</bdi> في هذا المستودع{' '}
                    <bdi dir="ltr">307</bdi> لا <bdi dir="ltr">308</bdi>. اختر{' '}
                    <bdi dir="ltr">301</bdi> حين تكون النقلة نهائية فعلًا وتريد انتقال ترتيب البحث
                    معها؛ وما دون ذلك فـ<bdi dir="ltr">302</bdi> قابل للتراجع.
                  </p>
                ) : null}

                {failure ? (
                  <p className="form-error" role="alert">
                    {failure}
                  </p>
                ) : null}

                {done ? (
                  <p className="state-line" role="status" aria-live="polite">
                    {done}
                  </p>
                ) : null}

                <div className="form-actions">
                  <button
                    type="button"
                    className={`btn btn-primary ${styles.action}`}
                    disabled={busy || !ready}
                    onClick={() => void save()}
                  >
                    {busy ? 'جارٍ الحفظ…' : editing === null ? 'أضف القاعدة' : 'احفظ التعديل'}
                  </button>
                </div>
              </section>
            ) : (
              /* المنع يُعلَن ولا يُخفى صامتًا: من يرى الجدول يعرف لماذا لا يعدّله. */
              <p className="state-line" role="note">
                تقرأ الجدول ولا تعدّله: الكتابة تحتاج صلاحية{' '}
                <bdi dir="ltr">{WRITE_PERMISSION}</bdi>.
              </p>
            )}

            <p className="state-line">
              <bdi dir="ltr">{count(payload.counts.all)}</bdi> قاعدة، منها{' '}
              <bdi dir="ltr">{count(payload.counts.enabled)}</bdi> مفعَّلة و
              <bdi dir="ltr">{count(payload.counts.fired)}</bdi> أطلقت فعلًا.
            </p>

            <div className="table-scroll" tabIndex={0} role="region" aria-label="قواعد التحويل المخزَّنة">
              <table className="data-table">
                <caption className="sr-only">قواعد التحويل المخزَّنة</caption>
                <thead>
                  <tr>
                    <th scope="col">من</th>
                    <th scope="col">إلى</th>
                    <th scope="col">النوع</th>
                    <th scope="col">الحالة</th>
                    <th scope="col">الإطلاقات</th>
                    <th scope="col">آخر تعديل</th>
                    <th scope="col">إجراءات</th>
                  </tr>
                </thead>
                <tbody>
                  {payload.data.length === 0 ? (
                    <EmptyRows
                      colSpan={7}
                      message="لا قاعدة تحويل بعد. كلّ مسار قديم يُطلب اليوم يردّ ٤٠٤ — أضف قاعدة أعلاه لتحويله."
                    />
                  ) : (
                    payload.data.map((row) => (
                      <tr key={row.id}>
                        <td>
                          <bdi dir="ltr" className={styles.path}>
                            {row.fromPath}
                          </bdi>
                          {row.isWildcard ? <div className="absent">حرف بدل — يلتقط ما تحته</div> : null}
                        </td>
                        <td>
                          <bdi dir="ltr" className={styles.path}>
                            {row.toPath}
                          </bdi>
                          {row.reason ? (
                            <div className={styles.reason} title={row.reason}>
                              {row.reason}
                            </div>
                          ) : (
                            <div className="absent">بلا سبب مكتوب</div>
                          )}
                        </td>
                        <td>
                          <bdi dir="ltr">{row.statusCode}</bdi>
                          <div className="absent">{row.statusCode === 301 ? 'دائم' : 'مؤقّت'}</div>
                        </td>
                        <td>
                          {row.enabled ? (
                            <span className="chip chip-active">مفعَّلة</span>
                          ) : (
                            <span className="chip chip-retired">معطَّلة</span>
                          )}
                        </td>
                        <td>
                          <bdi dir="ltr">{count(row.hits)}</bdi>
                        </td>
                        <td>
                          <bdi>{dateTime(row.updatedAt)}</bdi>
                        </td>
                        <td>
                          {mayWrite ? (
                            <div className={styles.rowActions}>
                              <button type="button" className="btn" onClick={() => startEdit(row)}>
                                عدّل
                              </button>
                              <button
                                type="button"
                                className="btn btn-ghost"
                                disabled={rowBusy === row.id}
                                onClick={() => void remove(row)}
                              >
                                {rowBusy === row.id ? '…' : 'احذف'}
                              </button>
                            </div>
                          ) : (
                            <span className="absent">—</span>
                          )}
                        </td>
                      </tr>
                    ))
                  )}
                </tbody>
              </table>
            </div>
          </>
        )}
      </RedirectsState>
    </section>
  );
}
