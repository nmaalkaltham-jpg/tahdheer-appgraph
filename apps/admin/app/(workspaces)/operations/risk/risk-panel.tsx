'use client';
// UNIT: RISK-PANEL
// FILE: risk-panel.tsx
// STYLE: risk.module.css
// PARENT: PAGE-OPERATIONS-FULFILLMENT
// USES: ADMIN-SESSION, LIB-ADMIN-API, LIB-FORMAT, OPS, RESOURCE
// API: /admin/operations/risk/clusters, PATCH /admin/operations/risk
// MAP: docs/PROJECT_MAP.md

/**
 * مراجعة المخاطر (§١٠٣ §١٠٥).
 *
 * ─── لماذا طابورٌ لا تقرير ───
 *
 * الجدول كلّه تقريرٌ لا يُقرأ. والمرفوع لعينٍ بشريّة قائمةُ عمل: ما قرّر
 * النظامُ أنّه لا يستطيع البتّ فيه وحده.
 *
 * ─── لماذا الإشارات معروضة لا الدرجة ───
 *
 * القرار يُتّخذ بقراءة ما قيس لا بقراءة رقم. «‏٧٠ تنزيلًا في ٢٤ ساعة»
 * يقرّر بها المراجع؛ و«درجة ٤٥» لا تقرّر شيئًا وتدفعه إلى الموافقة الآليّة
 * — وهي أسوأ نتيجةٍ لنظام مراجعة.
 *
 * ─── ما لا يفعله هذا السطح ───
 *
 * لا يمنع تنزيلًا ولا يفكّ منعًا: التقييم سُجّل وقت التنزيل، والقرار هنا
 * يُغلق الحالة ويُعلّم القاعدة بأنّها أصابت أو أخطأت. طمسُ التقييم بالمراجعة
 * كان يُفقد القدرة على قياس دقّة القواعد — كم مرّةً رفعت ما لا يستحقّ.
 *
 * ─── ولماذا صار للطابور مخرج ───
 *
 * كان السطح قراءةً محضة: «بانتظار مراجعة» عدّادٌ يصعد ولا ينزل، وما رُفع
 * لعينٍ بشريّة يبقى مرفوعًا أبدًا. وطابورٌ بلا إغلاق يُهجَر بعد أسبوع لأنّ
 * قراءته لا تغيّر شيئًا — ويُفقَد معه ما سُجّل التقييم من أجله أصلًا: معرفةُ
 * أيّ القواعد ترفع ما لا يستحقّ.
 *
 * والقرار **عمودٌ ثانٍ** لا استبدالٌ للأوّل. كان `reviewNote` يُعرض تحت حبّة
 * قرار النظام فيُقرأ الاثنان سطرًا واحدًا: لا يُعرف حينها أيّ «أُخلي» ردٌّ
 * على أيّ «مراجعة يدويّة»، وهو عين ما يمنعه القانون الحاكم للجدول — ما رآه
 * النظام وما قرّره إنسان لا يطمس أحدهما الآخر. فصار للنظام عموده بسببه،
 * وللمراجعة عمودها بملاحظتها.
 *
 * ─── ولماذا تُلزَم الملاحظة قبل أن يعمل الزرّ ───
 *
 * الخادم يُلزمها (ثلاثة أحرف فأكثر) لأنّ «أُخلي» بلا سبب لا يُراجَع ولا
 * يُشرح بعد شهر. وإلزامُها هنا ليس تكرارًا: زرٌّ يُنقر فيردّ الخادم ٤٢٢
 * يُعلّم المشغّل أنّ الرفض عشوائيّ، والزرُّ المعطَّل يقول ما ينقص قبل النقر.
 *
 * ─── والعناقيد تحت الطابور لا في شاشةٍ ثانية ───
 *
 * الصفّ المفرد لا يكشف نمطًا: «عنوانٌ واحد لعشرين عميلًا» و«استحقاقٌ يُنزَّل
 * من اثني عشر عنوانًا» لا يظهران في أيّ صفٍّ مهما قُرئ، وهما بالضبط ما يفرّق
 * العائلةَ خلف عنوانٍ واحد عن حلقة إساءة. ولذلك حملها الخادم بصلاحية القراءة
 * نفسها: من يراجع تقييمًا مفردًا يحتاج أن يرى نمطه.
 *
 * وهي **اقتراح نظرٍ لا حكم**: يبثّها الخادم موسومةً `advisory`، ولا تُعلَّق
 * بها حالةٌ ولا يُحظر بها أحد.
 */

import { useCallback, useId, useState, type FormEvent } from 'react';
import { useQueryClient } from '@tanstack/react-query';
import { CountBar, OpsTable, ReasonLine, StatusPill } from '../_shared/ops';
import { EmptyRows, ResourceView, useResource } from '../../../../components/resource';
import { useSession } from '../../../../components/admin-session';
import { api } from '../../../../lib/admin-api';
import { count, dateTime, money } from '../../../../lib/format';
import styles from './risk.module.css';

interface Signal {
  code: string;
  weight: number;
  description: string;
}

interface AssessmentRow {
  id: number;
  subjectType: string;
  customerEmail: string | null;
  decision: string;
  score: number;
  signals: Signal[];
  reason: string | null;
  reviewOutcome: string | null;
  reviewNote: string | null;
  createdAt: string | null;
}

interface RiskView {
  data: AssessmentRow[];
  awaiting: number;
}

const DECISION_LABELS: Record<string, string> = {
  ALLOW: 'سُمح',
  CHALLENGE: 'يحتاج تحقّقًا',
  MANUAL_REVIEW: 'مراجعة يدويّة',
  DENY: 'مُنع',
};

/*
 * مفردتا القرار لا آلةُ حالات: الخادم يتحقّق من قائمةٍ من قيمتين في
 * `reviewRisk`، وليس ثمّة انتقالٌ مسموح يُبثّ مع الصفّ. فكتابتهما هنا لا
 * تنسخ خريطةً تعيش في النطاق — لا خريطة أصلًا، بل مفرداتُ حكمٍ بشريّ.
 *
 * وهما مصدرٌ واحد: القائمةُ تُصيَّر منها، والحبّةُ تُترجَم بها. نسختان
 * تتباعدان فتُعرض القيمة الخام بعد الحفظ.
 */
const OUTCOMES: readonly { value: string; label: string }[] = [
  { value: 'CLEARED', label: 'أُخلي' },
  { value: 'CONFIRMED_ABUSE', label: 'ثبتت الإساءة' },
];

function outcomeLabel(value: string): string {
  return OUTCOMES.find((outcome) => outcome.value === value)?.label ?? value;
}

/**
 * هل بتّ فيها إنسان؟
 *
 * ─── ولماذا ليست `!== null` ───
 *
 * `review_outcome` ثلاثيّة لا ثنائيّة: `PENDING` تعني «رُفعت وتنتظر»،
 * و`null` تعني «لم تُرفع أصلًا»، والقراران البشريّان هما `CLEARED` و
 * `CONFIRMED_ABUSE` (الهجرة تُعرّف الثلاثة، و`scopeAwaitingReview` ترشّح
 * بـ`PENDING`). فاختبارُ `!== null` يصنّف **كلّ** صفٍّ في هذا الطابور
 * مبتوتًا فيه — الطابور لا يعيد إلّا `PENDING` — فلا يظهر نموذج القرار
 * أبدًا، وتُعرض الحالة حبّةً خضراء مكتوبًا عليها «PENDING» حرفيًّا. أي أنّ
 * المخرج الذي بُني للطابور يختفي، وتقول الشاشة عكس الحقيقة في الوقت نفسه.
 *
 * والمفردتان تأتيان من `OUTCOMES` نفسها لا من نصٍّ ثانٍ: مصدرٌ واحد للقائمة
 * والحبّة والحارس معًا.
 */
function decided(outcome: string | null): outcome is string {
  return outcome !== null && OUTCOMES.some((option) => option.value === outcome);
}

/* حدّا الخادم نفسه (`min:3` و`max:1000`) — لا رقمٌ يُخترع في الواجهة. */
const NOTE_MIN = 3;
const NOTE_MAX = 1000;

const AWAITING_PATH = '/admin/operations/risk';
/* نفس الطريق: وجودُ `all` وحده يقلب الاستعلام إلى ما رُوجع. */
const REVIEWED_PATH = '/admin/operations/risk?all=1';

/**
 * قرار المراجعة على صفّ.
 *
 * ثلاثة حقولٍ لا نافذة: المشغّل يقرّر وهو يقرأ الإشارات في الصفّ نفسه،
 * ونافذةٌ تُفتح تحجب ما بُني عليه القرار لحظةَ اتّخاذه.
 *
 * وخطأ الخادم يُعرض بنصّه وبأخطاء حقوله: «الملاحظة أقصر من ثلاثة أحرف»
 * تقول ما يُصلَح، و«تعذّر إتمام العمليّة» تُرسل المشغّل يخمّن.
 */
function ReviewDecision({ id, onDone }: { id: number; onDone: () => void }) {
  const [outcome, setOutcome] = useState('');
  const [note, setNote] = useState('');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [fieldErrors, setFieldErrors] = useState<Record<string, string[]> | null>(null);
  const outcomeId = useId();
  const noteId = useId();
  const hintId = useId();

  /* الفراغُ المحيط لا يُحسب حرفًا: ثلاثُ مسافاتٍ تمرّ من الواجهة ويردّها الخادم. */
  const trimmed = note.trim();
  const ready = outcome !== '' && trimmed.length >= NOTE_MIN;

  async function submit(event: FormEvent) {
    event.preventDefault();
    if (!ready) return;

    setBusy(true);
    setError(null);
    setFieldErrors(null);

    const result = await api.patch<{ reviewOutcome: string }>(`/admin/operations/risk/${id}`, {
      outcome,
      note: trimmed,
    });

    setBusy(false);

    if (result.ok) {
      onDone();

      return;
    }

    setError(result.message);
    setFieldErrors(result.errors);
  }

  return (
    <form className={styles.decide} onSubmit={submit}>
      <label className="sr-only" htmlFor={outcomeId}>
        نتيجة المراجعة
      </label>
      {/* الحقلان يُقفلان أثناء التنفيذ: تعديلٌ بعد الإرسال يُظهر غير المُرسَل. */}
      <select id={outcomeId} value={outcome} disabled={busy} onChange={(event) => setOutcome(event.target.value)}>
        <option value="">اختر النتيجة</option>
        {OUTCOMES.map((option) => (
          <option key={option.value} value={option.value}>
            {option.label}
          </option>
        ))}
      </select>

      <label className="sr-only" htmlFor={noteId}>
        ملاحظة المراجعة
      </label>
      <textarea
        id={noteId}
        rows={2}
        value={note}
        minLength={NOTE_MIN}
        maxLength={NOTE_MAX}
        disabled={busy}
        required
        aria-describedby={hintId}
        placeholder="لماذا هذا القرار؟"
        onChange={(event) => setNote(event.target.value)}
      />

      <p id={hintId} className={styles.hint}>
        الملاحظة تُحفظ في سجلّ التدقيق باسمك ووقتك، وهي ما يُقرأ حين تُقاس دقّة القاعدة. ثلاثة أحرف فأكثر.
      </p>

      <button type="submit" className="btn btn-ghost" disabled={busy || !ready}>
        {busy ? 'يُسجَّل…' : 'أغلِق المراجعة'}
      </button>

      {/*
        * سطرُ خطأٍ واحد لا سطران.
        *
        * لارافيل يضع أوّل رسالة تحقّقٍ في `message` أيضًا، فعرضُ الاثنين
        * يطبع النصّ نفسه مرّتين تحت الزرّ — فيُقرأ خطأين، ويُقرأ الثاني
        * تكرارًا لا معنى له. فإن كانت للحقول رسائل فهي الأدقّ (تسمّي حقلها)،
        * وإلّا فرسالة الخادم العامّة.
        */}
      {fieldErrors !== null && Object.keys(fieldErrors).length > 0 ? (
        Object.entries(fieldErrors).map(([field, messages]) => (
          <p key={field} className={styles.error} role="alert">
            {messages.join(' · ')}
          </p>
        ))
      ) : error === null ? null : (
        <p className={styles.error} role="alert">
          {error}
        </p>
      )}
    </form>
  );
}

export function RiskReviewPanel() {
  const { can } = useSession();
  const client = useQueryClient();
  const [reviewed, setReviewed] = useState(false);
  const path = reviewed ? REVIEWED_PATH : AWAITING_PATH;
  const { state, reload } = useResource<RiskView>(path);

  /*
   * الصلاحية صلاحيةُ المسار على الخادم (`operations.manage`)، لا صلاحية
   * القراءة التي فُتح بها التبويب. وحقلٌ يظهر ثمّ يردّ الخادمُ كتابته بـ٤٠٣
   * يُعلّم المشغّل ألّا يثق بما تعرضه الشاشة — وهو ضررٌ أبقى من غياب الحقل.
   */
  const mayReview = can('operations.manage');

  /*
   * الكتابةُ واحدة والقائمتان وجهاها: الصفّ يغادر «بانتظار مراجعة» ويدخل
   * «ما رُوجع» في اللحظة نفسها. وإبطالُ المعروض وحده يترك الوجه الآخر على
   * نسخةٍ سابقة ثلاثين ثانية (زمنُ الطزاجة في مزوّد الاستعلام) — فيبدّل
   * المشغّل النطاق فلا يجد قراره الذي نجح للتوّ.
   */
  const afterReview = useCallback(() => {
    for (const key of [AWAITING_PATH, REVIEWED_PATH]) {
      void client.invalidateQueries({ queryKey: ['admin-resource', key] });
    }
  }, [client]);

  return (
    <>
      <div className={styles.scope}>
        <button
          type="button"
          className={reviewed ? 'btn btn-ghost' : 'btn btn-primary'}
          aria-pressed={!reviewed}
          onClick={() => setReviewed(false)}
        >
          بانتظار مراجعة
        </button>
        <button
          type="button"
          className={reviewed ? 'btn btn-primary' : 'btn btn-ghost'}
          aria-pressed={reviewed}
          onClick={() => setReviewed(true)}
        >
          {/*
            * التسمية تقول ما يعيده الخادم لا ما نتمنّاه.
            *
            * الاستعلام الثاني `whereNotNull('review_outcome')`، و`PENDING`
            * قيمةٌ غير عدميّة — فالقائمة تضمّ ما ينتظر مع ما بُتّ فيه.
            * وتسميتُها «ما رُوجع» تجعل المشغّل يقرأ صفًّا مفتوحًا مغلقًا.
            */}
          كلّ ما رُفع للمراجعة
        </button>
      </div>

      <ResourceView
        state={state}
        reload={reload}
        forbidden={<p>لا تملك صلاحية قراءة الطلبات، ومراجعة المخاطر تُقرأ بها.</p>}
      >
        {(view) => (
          <>
            <CountBar
              items={[
                { label: 'بانتظار مراجعة', value: view.awaiting, tone: view.awaiting > 0 ? 'danger' : 'done' },
              ]}
            />

            <OpsTable columns={['الزبون', 'قرار النظام', 'الإشارات', 'الدرجة', 'منذ', 'المراجعة']}>
              {view.data.length === 0 ? (
                <EmptyRows
                  colSpan={6}
                  message={reviewed ? 'لا حالة رُوجعت بعد.' : 'لا حالة تنتظر مراجعة.'}
                />
              ) : (
                view.data.map((row) => (
                  <tr key={row.id}>
                    <td>{row.customerEmail ?? '—'}</td>
                    <td>
                      <StatusPill
                        tone={row.decision === 'DENY' ? 'danger' : row.decision === 'ALLOW' ? 'done' : 'wait'}
                        label={DECISION_LABELS[row.decision] ?? row.decision}
                      />
                      {/* سببُ النظام لا ملاحظةُ المراجع: العمودان لا يتبادلان محتواهما. */}
                      <ReasonLine>{row.reason}</ReasonLine>
                    </td>
                    <td>
                      {/* ما قيس، لا تفسيرٌ له: المراجع يقرّر بقراءته. */}
                      {row.signals.length === 0 ? (
                        <span>—</span>
                      ) : (
                        row.signals.map((signal) => (
                          <ReasonLine key={signal.code}>{signal.description}</ReasonLine>
                        ))
                      )}
                    </td>
                    <td>{row.score}</td>
                    <td>{row.createdAt ? dateTime(row.createdAt) : '—'}</td>
                    <td>
                      {decided(row.reviewOutcome) ? (
                        <>
                          <StatusPill
                            tone={row.reviewOutcome === 'CONFIRMED_ABUSE' ? 'danger' : 'done'}
                            label={outcomeLabel(row.reviewOutcome)}
                          />
                          <ReasonLine>{row.reviewNote}</ReasonLine>
                        </>
                      ) : mayReview ? (
                        <ReviewDecision id={row.id} onDone={afterReview} />
                      ) : (
                        /* لا زرٌّ يُعرض لمن لا يملكه — ويُقال لماذا، وإلّا قُرئ الفراغ عطلًا. */
                        <p className={styles.hint}>بانتظار مراجعٍ يملك «إدارة العمليّات».</p>
                      )}
                    </td>
                  </tr>
                ))
              )}
            </OpsTable>
          </>
        )}
      </ResourceView>

      <RiskClusters />
    </>
  );
}

/* ─────────────────────────────────────────────── العناقيد */

interface IpCluster {
  ipHash: string;
  customers: number;
  assessments: number;
}

interface DeviceCluster {
  deviceHash: string;
  customers: number;
  assessments: number;
}

interface RefundCluster {
  customerId: number;
  orders: number;
  refundedOrders: number;
  refundedMinor: number;
  refundRate: number;
}

interface DownloadCluster {
  entitlementId: number;
  customerId: number | null;
  distinctIps: number;
  attempts: number;
  peakScore: number;
}

interface ClusterView {
  windowDays: number;
  sharedIp: IpCluster[];
  refundAbuse: RefundCluster[];
  downloadAbuse: DownloadCluster[];
  sharedDevice: DeviceCluster[];
}

/**
 * أنماطٌ عابرة للصفوف.
 *
 * لا تُعرض إلّا المجموعة التي تجاوزت عتبتها في الخادم: أربعةُ جداول فارغة
 * تحت كلّ طابورٍ نظيف تُعلّم العينَ أن تتخطّى الكتلة كلّها — فلا تُرى يوم
 * تمتلئ. وإن خلت الأربع، قيل ذلك سطرًا واحدًا صريحًا.
 *
 * والنسبةُ تُعرض مئويّةً من الكسر الذي يبثّه الخادم، لا تُحسب من عددٍ آخر:
 * «٠٫٦٧» في عمودٍ اسمه «النسبة» يُقرأ ولا يُفهم.
 */
function RiskClusters() {
  const { state, reload } = useResource<ClusterView>('/admin/operations/risk/clusters');

  return (
    <section className={styles.clusters}>
      <h3 className={styles.clustersTitle}>أنماطٌ عابرة للصفوف</h3>

      <ResourceView
        state={state}
        reload={reload}
        forbidden={<p>لا تملك صلاحية قراءة الطلبات، والعناقيد تُقرأ بها.</p>}
      >
        {(view) => {
          const empty =
            view.sharedIp.length === 0 &&
            view.sharedDevice.length === 0 &&
            view.downloadAbuse.length === 0 &&
            view.refundAbuse.length === 0;

          return (
            <>
              <p className={styles.clustersNote}>
                نافذةُ التحليل {count(view.windowDays)} يومًا. وهذه قراءةٌ استدلاليّة لا حكم: لا تُعلَّق بها
                حالةٌ ولا يُحظر بها أحد، وإنّما تُقرأ مع الصفّ لتفرّق العائلةَ خلف عنوانٍ واحد عن حلقة
                إساءة. ولا تظهر مجموعةٌ لم تتجاوز عتبتها.
              </p>

              {empty ? (
                <p className={styles.clustersNote}>لا عنقود تجاوز عتبته في هذه النافذة.</p>
              ) : null}

              {view.sharedIp.length === 0 ? null : (
                <div className={styles.group}>
                  <h4 className={styles.groupTitle}>عنوانٌ واحد لعملاء كثيرين</h4>
                  <OpsTable columns={['تجزئة العنوان', 'عملاء', 'تقييمات']}>
                    {view.sharedIp.map((cluster) => (
                      <tr key={cluster.ipHash}>
                        {/* التجزئة لا العنوان: الخادم يقتطعها عمدًا، والكشف لا يحتاج أكثر. */}
                        <td>
                          <bdi dir="ltr">{cluster.ipHash}</bdi>
                        </td>
                        <td>{count(cluster.customers)}</td>
                        <td>{count(cluster.assessments)}</td>
                      </tr>
                    ))}
                  </OpsTable>
                </div>
              )}

              {view.sharedDevice.length === 0 ? null : (
                <div className={styles.group}>
                  <h4 className={styles.groupTitle}>بصمةُ جهازٍ واحدة لعملاء كثيرين</h4>
                  <OpsTable columns={['تجزئة الجهاز', 'عملاء', 'تقييمات']}>
                    {view.sharedDevice.map((cluster) => (
                      <tr key={cluster.deviceHash}>
                        <td>
                          <bdi dir="ltr">{cluster.deviceHash}</bdi>
                        </td>
                        <td>{count(cluster.customers)}</td>
                        <td>{count(cluster.assessments)}</td>
                      </tr>
                    ))}
                  </OpsTable>
                </div>
              )}

              {view.downloadAbuse.length === 0 ? null : (
                <div className={styles.group}>
                  <h4 className={styles.groupTitle}>استحقاقٌ يُنزَّل من عناوين كثيرة</h4>
                  <OpsTable columns={['الاستحقاق', 'العميل', 'عناوين مختلفة', 'محاولات', 'أعلى درجة']}>
                    {view.downloadAbuse.map((cluster) => (
                      <tr key={`${cluster.entitlementId}:${cluster.customerId ?? 'none'}`}>
                        <td>
                          <bdi dir="ltr">{cluster.entitlementId}</bdi>
                        </td>
                        <td>
                          {cluster.customerId === null ? (
                            <span className="absent">—</span>
                          ) : (
                            <bdi dir="ltr">{cluster.customerId}</bdi>
                          )}
                        </td>
                        <td>{count(cluster.distinctIps)}</td>
                        <td>{count(cluster.attempts)}</td>
                        <td>{count(cluster.peakScore)}</td>
                      </tr>
                    ))}
                  </OpsTable>
                </div>
              )}

              {view.refundAbuse.length === 0 ? null : (
                <div className={styles.group}>
                  <h4 className={styles.groupTitle}>عميلٌ يُسترد أكثر ممّا يُبقي</h4>
                  <OpsTable columns={['العميل', 'طلبات', 'رُدّ منها', 'النسبة', 'المردود']}>
                    {view.refundAbuse.map((cluster) => (
                      <tr key={cluster.customerId}>
                        <td>
                          <bdi dir="ltr">{cluster.customerId}</bdi>
                        </td>
                        <td>{count(cluster.orders)}</td>
                        <td>{count(cluster.refundedOrders)}</td>
                        <td>
                          <bdi dir="ltr">{`${Math.round(cluster.refundRate * 100)}%`}</bdi>
                        </td>
                        <td>
                          <bdi dir="ltr">{money(cluster.refundedMinor)}</bdi>
                        </td>
                      </tr>
                    ))}
                  </OpsTable>
                </div>
              )}
            </>
          );
        }}
      </ResourceView>
    </section>
  );
}
