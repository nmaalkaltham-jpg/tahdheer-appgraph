'use client';
// UNIT: PAGE-PROMOTIONS
// FILE: page.tsx
// ROUTE: /promotions
// STYLE: promotions.module.css
// PARENT: none
// USES: EXPORT-CSV, LIB-ADMIN-API, LIB-FORMAT, RESOURCE, _COUPON-RULES
// API: DELETE /admin/coupons
// MAP: docs/PROJECT_MAP.md

/**
 * رموز الخصم — /promotions.
 *
 * الجدول والقواعد وتطبيق السلة وإعادة الحساب عند إنشاء الطلب كلّها مبنيّة منذ
 * بناء النطاق، ولم يكن لمالك المتجر سبيل إلى إصدار رمز واحد إلا بكتابة صفٍّ
 * بيده في قاعدة البيانات.
 *
 * الحالة هنا محسوبة من الخادم بنفس دالّة السلة، ولا تُعاد حسابتها في المتصفّح:
 * حسابٌ ثانٍ في الواجهة يصير رأيًا ثانيًا، وأوّل ما يفترقان تعرض اللوحة «ساري»
 * ويقول المتجر «غير صالح».
 *
 * ثلاثة تصحيحات لسلوك مكسور:
 *
 *  · **التعديل.** `PATCH /admin/coupons/{id}` مبنيّ ومُدقَّق ومسجَّل في سجلّ
 *    التدقيق (CouponController::update) ولم يكن في اللوحة ما يستدعيه، فمشغّل
 *    أخطأ في حدّ الاستعمال كان عليه أن يُنهي الرمز ويصدر غيره.
 *
 *  · **لحظة الانتهاء لا يومها.** `expires_at` عمود وقت (cast: datetime)،
 *    وحقل `type="date"` كان يرسل «٢٠٢٦-٠٩-١٠» فتُقرأ منتصفَ ليلِ ذلك اليوم:
 *    رمزٌ «ينتهي ١٠ سبتمبر» كان يموت قبل أن يبدأ العاشر. الحقل الآن لحظة
 *    كاملة تُرسَل ISO بإزاحة صريحة، فلا فرق بين ما يقرؤه المشغّل وما يخزّنه
 *    الخادم.
 *
 *  · **تصادم حالة الأحرف.** السلة تبحث بـ`LOWER(code)` (CartService.php:97)
 *    بينما فرادة العمود في قاعدة البيانات حسّاسة للحالة، فرمزان يفترقان في
 *    الحالة وحدها يقبلهما الخادم ولا تصل السلة إلا إلى أحدهما. اللوحة تمنع
 *    التصادم قبل الإصدار بدل أن تُنتج رمزًا لا يعمل.
 *
 * ─── حالة ٤٠٣ على مستوى الصفحة لا اللوح ───
 *
 * البادئة كلّها — القائمة والإصدار والتعديل والإنهاء والحذف — خلف صلاحية
 * واحدة (`routes/api-admin.php:334-336` — `Route::prefix('coupons')`
 * `->middleware(AdminAuthorize::class.':promotions.manage')`). فمن مُنع مُنع
 * من كلّ شيء هنا، والباب واحد. والمحرّر يسبق الجدول في الشجرة، فلو حُرس
 * الجدول وحده لبقي نموذج إصدارٍ كامل معروضًا لمن كلّ ضغطةٍ فيه تردّ ٤٠٣:
 * تعبئةُ ستّة حقول ثمّ رفضٌ عند الحفظ. لذلك تُقرأ الحالة قبل التصيير كلّه.
 */

import { useCallback, useMemo, useRef, useState } from 'react';
import { EmptyRows, PageHeader, ResourceView, useResource } from '../../../components/resource';
import { api, firstError } from '../../../lib/admin-api';
import { count, dateTime, isoToLocalInput, localInputToIso, minorToRiyals } from '../../../lib/format';
import { ExportCsv } from '../../../components/export-csv';
import styles from './promotions.module.css';
import {
  COUPON_CSV,
  conditionsOf,
  EMPTY,
  inspect,
  outgoingValue,
  type CouponRow,
  type CouponsPayload,
  type Form,
} from './_coupon-rules';

export default function PromotionsPage() {
  const { state, reload } = useResource<CouponsPayload>('/admin/coupons');
  const [form, setForm] = useState<Form>(EMPTY);
  const [editing, setEditing] = useState<CouponRow | null>(null);
  const [busy, setBusy] = useState(false);
  const [rowBusy, setRowBusy] = useState<number | null>(null);
  const [failure, setFailure] = useState<string | null>(null);
  const [done, setDone] = useState<string | null>(null);
  const codeRef = useRef<HTMLInputElement>(null);

  /* هويّة ثابتة للصفوف: مصفوفة فارغة جديدة كلّ تصيير كانت تُبطل ‎useMemo‎ أدناه. */
  const rows = useMemo(() => (state.status === 'ready' ? state.data.data : []), [state]);
  const checks = useMemo(() => inspect(form, rows, editing), [form, rows, editing]);
  const blocking = checks.filter((check) => check.level === 'blocking');
  const conditions = useMemo(() => conditionsOf(form), [form]);

  const save = useCallback(async () => {
    const value = outgoingValue(form);
    // نفس الحارس الذي يعطّل الزرّ — لا مسار ثانٍ يرسل قيمة غير مفحوصة.
    if (value === null) return;
    const expiresAt = localInputToIso(form.expiresAt);
    if (expiresAt === false) return;

    setBusy(true);
    setFailure(null);
    setDone(null);

    const payload = {
      code: form.code.trim(),
      type: form.type,
      value,
      usageLimit: form.usageLimit.trim() === '' ? null : Number(form.usageLimit),
      expiresAt,
      firstOrderPerAccount: form.firstOrderPerAccount,
    };

    const result =
      editing === null
        ? await api.post<{ data: CouponRow }>('/admin/coupons', payload)
        : await api.patch<{ data: CouponRow }>(`/admin/coupons/${editing.id}`, payload);
    setBusy(false);

    // لا يظهر «أُصدر» قبل أن يقبل الخادم: رمزٌ يظنّه المشغّل حيًّا ولم يُنشأ
    // يعني حملةً تُعلَن برمز لا يعمل.
    if (!result.ok) {
      setFailure(firstError(result));

      return;
    }

    const saved = result.data.data;
    setForm(EMPTY);
    setEditing(null);
    setDone(
      editing === null
        ? `أُصدر الرمز ${saved.code} ويعمل في السلة الآن.`
        : `حُدّث الرمز ${saved.code} — قواعده الجديدة تسري في السلة فورًا وسُجّل التغيير في سجلّ التدقيق.`,
    );
    reload();
  }, [form, editing, reload]);

  /** يحمّل صفًّا قائمًا في المحرّر نفسه — لا نموذج ثانٍ يفترق عن الأوّل. */
  const startEdit = useCallback((row: CouponRow) => {
    setEditing(row);
    setForm({
      code: row.code,
      type: row.type,
      // المبلغ الثابت مخزَّن بالهللات والحقل يعرض ريالات — التحويل هنا مرّة واحدة.
      value: row.type === 'fixed' ? minorToRiyals(row.value) : String(row.value),
      usageLimit: row.usageLimit === null ? '' : String(row.usageLimit),
      expiresAt: isoToLocalInput(row.expiresAt),
      firstOrderPerAccount: row.firstOrderPerAccount,
    });
    setFailure(null);
    setDone(null);
    codeRef.current?.focus();
  }, []);

  const cancelEdit = useCallback(() => {
    setEditing(null);
    setForm(EMPTY);
    setFailure(null);
    setDone(null);
  }, []);

  const act = useCallback(
    async (row: CouponRow, action: 'expire' | 'delete') => {
      setRowBusy(row.id);
      setFailure(null);
      setDone(null);

      const result =
        action === 'expire'
          ? await api.post(`/admin/coupons/${row.id}/expire`)
          : await api.delete(`/admin/coupons/${row.id}`);
      setRowBusy(null);

      if (!result.ok) {
        setFailure(firstError(result));

        return;
      }

      // الصفّ المعروض في المحرّر قد يكون هو المحذوف — لا يبقى محرّر بلا صفّ.
      if (action === 'delete' && editing?.id === row.id) cancelEdit();
      reload();
    },
    [reload, editing, cancelEdit],
  );

  /*
   * الباب قبل الشاشة — وبعد كلّ الخطّافات، فترتيبها لا يتغيّر بين تصييرَين.
   *
   * ٤٠٣ هنا يعني أنّ كلّ فعلٍ في هذه الصفحة مردود، فلا يُصيَّر محرّرٌ ولا جدول
   * ولا يُعرض زرّ «إعادة المحاولة»: تكرار طلبٍ محروس لا يمنح صلاحية، والاسم
   * الصريح يفرق بين منعٍ مقصود وعطبٍ في الشاشة.
   */
  if (state.status === 'error' && state.code === 403) {
    return (
      <section className={styles.workspace}>
        <PageHeader trail="المتجر" title="رموز الخصم" />
        <div className="state-error" role="alert">
          <p>
            لا تملك صلاحية <bdi dir="ltr">promotions.manage</bdi> لقراءة رموز الخصم وإصدارها.
            القراءة والإصدار تحتها معًا — عرضُ الرموز بلا قدرة على إصدارها ليس دورًا.
          </p>
        </div>
      </section>
    );
  }

  return (
    <section className={styles.workspace}>
      <PageHeader trail="المتجر" title="رموز الخصم" />

      <p className="state-line">
        الرمز يعمل في السلة فور إصداره، وتُعاد قواعده عند إنشاء الطلب — فلا خصم يُقبل بلا إعادة
        حساب. حالة كل رمز أدناه هي نفسها التي يراها المشتري، لا تقديرًا ثانيًا لها.
      </p>

      <div className={styles.split}>
        <section className="panel" aria-labelledby="coupon-editor-title">
          <h2 id="coupon-editor-title">{editing === null ? 'أصدر رمزًا' : 'عدّل رمزًا قائمًا'}</h2>

          {editing !== null ? (
            <p className={styles.editing}>
              <span>
                تُعدّل الآن <bdi dir="ltr">{editing.code}</bdi> — يُحفظ فوق الصفّ نفسه.
              </span>
              <button type="button" className={`btn btn-ghost ${styles.action}`} onClick={cancelEdit}>
                ألغِ التعديل
              </button>
            </p>
          ) : null}

          <div className="form-grid">
            <label className="field" htmlFor="coupon-code">
              <span>
                الرمز
                <span className={styles.required}>إلزامي</span>
              </span>
              <input
                id="coupon-code"
                ref={codeRef}
                dir="ltr"
                required
                aria-required="true"
                value={form.code}
                placeholder="BACK2SCHOOL"
                onChange={(event) => setForm({ ...form, code: event.target.value })}
              />
              <span className="absent">حروف وأرقام وشرطات فقط — يُكتب بيد المشتري.</span>
            </label>

            <label className="field" htmlFor="coupon-type">
              <span>النوع</span>
              <select
                id="coupon-type"
                value={form.type}
                onChange={(event) =>
                  setForm({ ...form, type: event.target.value as 'percent' | 'fixed', value: '' })
                }
              >
                <option value="percent">نسبة مئوية</option>
                <option value="fixed">مبلغ ثابت</option>
              </select>
            </label>

            <label className="field" htmlFor="coupon-value">
              <span>
                {form.type === 'percent' ? 'النسبة (٪)' : 'المبلغ (ريال)'}
                <span className={styles.required}>إلزامي</span>
              </span>
              <input
                id="coupon-value"
                type="number"
                min="1"
                max={form.type === 'percent' ? 100 : undefined}
                step={form.type === 'percent' ? 1 : 0.01}
                required
                aria-required="true"
                value={form.value}
                onChange={(event) => setForm({ ...form, value: event.target.value })}
              />
            </label>

            <label className="field" htmlFor="coupon-limit">
              <span>حدّ الاستعمال</span>
              <input
                id="coupon-limit"
                type="number"
                min="1"
                value={form.usageLimit}
                onChange={(event) => setForm({ ...form, usageLimit: event.target.value })}
              />
              <span className="absent">اتركه فارغًا لرمز بلا حدّ.</span>
            </label>

            <label className="field" htmlFor="coupon-expires">
              <span>ينتهي في</span>
              <input
                id="coupon-expires"
                type="datetime-local"
                value={form.expiresAt}
                onChange={(event) => setForm({ ...form, expiresAt: event.target.value })}
              />
              <span className="absent">
                لحظة كاملة لا يومًا: الرمز يتوقّف عند هذه الدقيقة بالضبط. اتركه فارغًا لرمز بلا
                تاريخ انتهاء.
              </span>
            </label>

            <label className="field field-inline" htmlFor="coupon-first">
              <input
                id="coupon-first"
                type="checkbox"
                checked={form.firstOrderPerAccount}
                onChange={(event) =>
                  setForm({ ...form, firstOrderPerAccount: event.target.checked })
                }
              />
              <span>للطلب الأوّل لكل حساب فقط</span>
            </label>
          </div>

          <div className="form-actions">
            <button
              type="button"
              className={`btn btn-primary ${styles.action}`}
              disabled={busy || blocking.length > 0}
              title={blocking.length > 0 ? 'الحفظ مقفل حتى حلّ التعارض الحاجز' : undefined}
              onClick={() => void save()}
            >
              {busy
                ? 'جارٍ الحفظ…'
                : editing === null
                  ? 'أصدر الرمز'
                  : 'احفظ التعديل'}
            </button>
          </div>

          {failure ? (
            <div className="state-error" role="alert">
              <p>{failure}</p>
            </div>
          ) : null}
          {done ? (
            <p className="state-ok" role="status">
              {done}
            </p>
          ) : null}
        </section>

        <div className={styles.rail}>
          <section className={styles.side} aria-labelledby="coupon-rules-title">
            <h2 className={styles.sideHead} id="coupon-rules-title">
              باني القواعد
            </h2>

            <ul className={styles.rules}>
              {conditions.map((condition) => (
                <li
                  key={condition.op}
                  className={`${styles.rule} ${condition.on ? '' : styles.ruleOff}`}
                >
                  <span className={`${styles.op} ${condition.on ? '' : styles.opOff}`}>
                    {condition.op}
                  </span>
                  <span className={styles.ruleText}>{condition.text}</span>
                </li>
              ))}
            </ul>

            <p className={styles.sideNote}>
              هذه الشروط الثلاثة وحدها هي ما تُقيّمه السلة وإعادة الحساب عند إنشاء الطلب. لا يوجد
              شرط رابع يُضاف من هنا حتى يُقيّمه محرّك السلة — شرطٌ تتجاهله السلة أسوأ من شرط غائب.
            </p>
          </section>

          {checks.length > 0 ? (
            <section className={styles.conflicts} aria-labelledby="coupon-conflicts-title">
              <h2 className={styles.conflictsHead} id="coupon-conflicts-title">
                <span className={styles.conflictsMark} aria-hidden="true">
                  !
                </span>
                {blocking.length > 0 ? 'تعارض قبل النشر' : 'تنبيهات قبل النشر'}
              </h2>

              <ul className={styles.conflictList}>
                {checks.map(({ id, level, title, body, field }) => (
                  <li
                    key={id}
                    className={`${styles.conflict} ${level === 'advisory' ? styles.conflictAdvisory : ''}`}
                  >
                    <div className={styles.conflictTop}>
                      <span
                        className={`${styles.level} ${level === 'blocking' ? styles.levelBlocking : styles.levelAdvisory}`}
                      >
                        {level === 'blocking' ? 'حاجز' : 'تنبيه'}
                      </span>
                      <b>{title}</b>
                    </div>
                    <span className={styles.conflictBody}>{body}</span>
                    {field !== undefined ? (
                      <button
                        type="button"
                        className={styles.jump}
                        onClick={() => document.getElementById(field)?.focus()}
                      >
                        أصلح التعارض
                      </button>
                    ) : null}
                  </li>
                ))}
              </ul>

              <p className={styles.sideNote}>
                {blocking.length > 0
                  ? 'الحفظ مقفل حتى حلّ التعارض الحاجز. البنود الحاجزة إمّا يرفضها الخادم وإمّا تُنتج رمزًا لا يعمل في السلة.'
                  : 'لا بند حاجز — التنبيهات تصف أثرًا حقيقيًّا ولا تمنع الحفظ.'}
              </p>
            </section>
          ) : null}
        </div>
      </div>

      <ResourceView state={state} reload={reload}>
        {(payload) => (
          <>
            <p className="state-line">
              {count(payload.counts.active)} رمزًا ساريًا من {count(payload.counts.all)}.
            </p>

            <div className="table-scroll" tabIndex={0} role="region" aria-label="رموز الخصم وحالتها">
              <table className="data-table">
                <caption className="sr-only">رموز الخصم</caption>
                <thead>
                  <tr>
                    <th scope="col">الرمز</th>
                    <th scope="col">الخصم</th>
                    <th scope="col">الاستعمال</th>
                    <th scope="col">ينتهي</th>
                    <th scope="col">الحالة</th>
                    <th scope="col">إجراء</th>
                  </tr>
                </thead>
                <tbody>
                  {payload.data.length === 0 ? (
                    <EmptyRows colSpan={6} message="لا رموز خصم بعد. أصدر أوّل رمز من النموذج أعلاه." />
                  ) : (
                    payload.data.map((row) => (
                      <tr key={row.id}>
                        <th scope="row">
                          <bdi dir="ltr">{row.code}</bdi>
                          {row.firstOrderPerAccount ? <small>للطلب الأوّل لكل حساب</small> : null}
                        </th>
                        <td>{row.label}</td>
                        <td>
                          {count(row.usageCount)}
                          {row.usageLimit === null ? ' (بلا حدّ)' : ` من ${count(row.usageLimit)}`}
                        </td>
                        <td>{row.expiresAt ? dateTime(row.expiresAt) : 'بلا تاريخ'}</td>
                        <td>{row.usable ? 'ساري' : (row.reason ?? 'متوقّف')}</td>
                        <td>
                          <div className="row-actions">
                            {/* التعديل يمرّ على PATCH /admin/coupons/{id} — نفس التحقّق
                                الذي يحكم الإنشاء، ومسجَّل قبلًا وبعدًا في سجلّ التدقيق. */}
                            <button
                              type="button"
                              className={`btn btn-ghost ${styles.action}`}
                              disabled={rowBusy === row.id}
                              aria-pressed={editing?.id === row.id}
                              onClick={() => startEdit(row)}
                            >
                              عدّل
                            </button>
                            {row.usable ? (
                              <button
                                type="button"
                                className={`btn btn-ghost ${styles.action}`}
                                disabled={rowBusy === row.id}
                                onClick={() => void act(row, 'expire')}
                              >
                                أنهِ الآن
                              </button>
                            ) : null}
                            {/* الحذف لغير المستعمَل وحده — المستعمَل يبقى تفسيرًا لخصمٍ في السجل. */}
                            {row.usageCount === 0 ? (
                              <button
                                type="button"
                                className={`btn btn-ghost ${styles.action}`}
                                disabled={rowBusy === row.id}
                                onClick={() => void act(row, 'delete')}
                              >
                                احذف
                              </button>
                            ) : null}
                          </div>
                        </td>
                      </tr>
                    ))
                  )}
                </tbody>
              </table>
            </div>

            <div className="list-foot">
              <p className="page-note">
                <bdi dir="ltr">{count(payload.data.length)}</bdi> قسيمة
              </p>
              <ExportCsv filename="coupons" columns={COUPON_CSV} source={{ kind: 'rows', rows: payload.data }} />
            </div>
          </>
        )}
      </ResourceView>
    </section>
  );
}
