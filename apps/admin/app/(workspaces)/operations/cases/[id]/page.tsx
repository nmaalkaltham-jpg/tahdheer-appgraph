'use client';
// UNIT: PAGE-OPERATIONS-CASES-ID
// FILE: page.tsx
// ROUTE: /operations/cases/[id]
// STYLE: none
// PARENT: none
// USES: ADMIN-SESSION, LIB-ADMIN-API, LIB-CONTRACT, LIB-FORMAT, LIB-IDEMPOTENCY, OPS +1
// API: /admin/cases
// MAP: docs/PROJECT_MAP.md

/**
 * قضيّة واحدة: خيطُها، وما يُفعل بها.
 *
 * ─── لماذا صفحةٌ مستقلّة ───
 *
 * الإغلاق يشترط رمز قرار، ورمزٌ يُنتقى من قائمةٍ في صفٍّ ضيّق يُنتقى بالخطأ.
 * والقرار هنا يُقرأ بعد شهرٍ حين تتكرّر القضيّة نفسها — فيستحقّ سطحًا يُرى
 * فيه الخيط كاملًا قبل أن يُضغط زرّ.
 *
 * ─── لماذا الرموز تأتي من الخادم ───
 *
 * القائمة مغلقةٌ في النطاق. ونسخُها هنا يعني أنّ رمزًا يُضاف في الخادم لا
 * يظهر، ورمزًا يُحذف يبقى معروضًا فيُرفض عند الحفظ بلا سببٍ مفهوم.
 */

import { use, useState } from 'react';
import { OpsTable, ReasonLine, StatusPill } from '../../_shared/ops';
import type { Tone } from '../../_shared/ops';
import { EmptyRows, PageHeader, ResourceView, useResource } from '../../../../../components/resource';
import { useSession } from '../../../../../components/admin-session';
import { proxyPath } from '../../../../../lib/contract';
import { api } from '../../../../../lib/admin-api';
import { idempotencyKey } from '../../../../../lib/idempotency';
import { dateTime } from '../../../../../lib/format';

type CaseState = 'OPEN' | 'INVESTIGATING' | 'ACTION_REQUIRED' | 'RESOLVED' | 'FALSE_POSITIVE';

interface TimelineEntry {
  id: number;
  action: string;
  fromState: string | null;
  toState: string | null;
  note: string | null;
  evidence: Record<string, unknown> | null;
  actorId: number | null;
  at: string | null;
}

interface CaseDetail {
  id: number;
  kind: 'FRAUD' | 'INCIDENT' | 'SUPPORT';
  state: CaseState;
  severity: 'INFO' | 'WARNING' | 'HIGH' | 'CRITICAL';
  title: string;
  subject: string | null;
  customerId: number | null;
  assignedTo: number | null;
  reasonCodes: string[];
  resolutionCode: string | null;
  openedAt: string | null;
  resolvedAt: string | null;
  allowedNext: CaseState[];
  timeline: TimelineEntry[];
  resolutionCodes: Record<string, string>;
  /** الأفعال المتاحة — محسوبةٌ في الخادم من الموضوع ورموز السبب. */
  remediations: { action: string; label: string; executable: boolean }[];
  rootCause: string | null;
  preventiveAction: string | null;
}

const STATE_LABEL: Record<CaseState, string> = {
  OPEN: 'مفتوحة',
  INVESTIGATING: 'قيد التحقيق',
  ACTION_REQUIRED: 'تحتاج إجراء',
  RESOLVED: 'حُسمت',
  FALSE_POSITIVE: 'إنذار كاذب',
};

const ACTION_LABEL: Record<string, string> = {
  opened: 'فُتحت',
  transitioned: 'انتقلت',
  noted: 'ملاحظة',
  assigned: 'أُسندت',
  recurred: 'تكرّر الشرط',
};

const TERMINAL: CaseState[] = ['RESOLVED', 'FALSE_POSITIVE'];

function stateTone(state: CaseState): Tone {
  if (state === 'ACTION_REQUIRED') return 'danger';
  if (state === 'OPEN' || state === 'INVESTIGATING') return 'wait';

  return 'done';
}

/**
 * الإسناد: من يعمل على القضيّة الآن.
 *
 * ─── لماذا فعلان لا منتقي مشغّلين ───
 *
 * قائمة المشغّلين محروسةٌ بـ«إدارة المستخدمين»، ولا يملكها إلّا المالك — لأنّ
 * معرفة من يملك الوصول بابٌ في ذاتها. فمنتقٍ مبنيٌّ عليها يردّ ٤٠٣ في وجه كلّ
 * من يفرز القضايا فعلًا، وهو أكثر من يحتاجه. والفرز لا يحتاج أكثر ممّا يَصدُق
 * هنا: أن يأخذها من فتحها، وأن تُنزع حين يتوقّف العمل فتعود إلى «بلا مسؤول»
 * في القائمة ويلتقطها غيره.
 *
 * ─── ولماذا يُرسل الحقل دائمًا ───
 *
 * شرط الخادم `present|nullable`: العدم قيمةٌ تعني «انزع الإسناد»، لا مفتاحًا
 * يُحذف. وحذفه عند الإطلاق يردّ خطأ تحقّقٍ بدل أن ينزع شيئًا.
 */
function Assignee({ detail, onDone }: { detail: CaseDetail; onDone: () => void }) {
  const { can, state } = useSession();
  /*
   * أيّ الزرّين يعمل، لا «هل يعمل أحدهما».
   *
   * رايةٌ منطقيّة واحدة كانت تقلب لفظ الزرّين معًا إلى «جارٍ…»، فيرى المشغّل
   * فعلين جاريين وقد طلب واحدًا — ويقرأ الآخر مُنفَّذًا بلا أن يطلبه.
   */
  const [busy, setBusy] = useState<'take' | 'release' | null>(null);
  const [error, setError] = useState<string | null>(null);

  const me = state.status === 'authenticated' ? state.identity.user : null;
  const mine = me !== null && detail.assignedTo === me.id;

  async function assign(adminUserId: number | null) {
    setBusy(adminUserId === null ? 'release' : 'take');
    setError(null);

    const result = await api.patch<unknown>(proxyPath('adminCaseAssign', { id: detail.id }), { adminUserId });

    setBusy(null);

    if (!result.ok) {
      setError(result.message);

      return;
    }

    onDone();
  }

  return (
    <>
      {/*
        رقمٌ لا اسم: الاسم يأتي من القائمة التي لا تُقرأ بصلاحية التشغيل. والرقم
        كما هو أصدق من اسمٍ مُخمَّن، ويكفي للسؤال المطروح هنا: أهي لي أم لا؟
      */}
      <p className="page-note">
        المسؤول:{' '}
        {detail.assignedTo === null ? (
          'بلا مسؤول'
        ) : mine ? (
          'أنت'
        ) : (
          <>
            مشغّل <bdi dir="ltr">#{detail.assignedTo}</bdi>
          </>
        )}
      </p>

      {!can('operations.manage') || me === null ? null : (
        <div className="list-toolbar">
          {mine ? null : (
            <button
              type="button"
              className="btn btn-primary"
              disabled={busy !== null}
              onClick={() => void assign(me.id)}
            >
              {/* اللفظ يقول ما يقع: النقل ينزعها عمّن هي عنده الآن. */}
              {busy === 'take' ? 'جارٍ…' : detail.assignedTo === null ? 'خذها' : 'انقلها إليك'}
            </button>
          )}

          {detail.assignedTo === null ? null : (
            <button
              type="button"
              className="btn btn-ghost"
              disabled={busy !== null}
              onClick={() => void assign(null)}
            >
              {busy === 'release' ? 'جارٍ…' : mine ? 'أطلقها' : 'انزع الإسناد'}
            </button>
          )}
        </div>
      )}

      {/*
        * ─── الأثر يُقال قبل النقر لا بعده ───
        *
        * الإسناد ليس مفتاحًا يُقلَب. `CaseService::assign` يفعل ثلاثةً في
        * معاملةٍ واحدة: يُسند، ويختم «أوّل استجابة» إن لم تكن مختومة، ويرفع
        * «جديدة» إلى «مُسنَدة». والإطلاق يردّ الأوّل وحده — الختم يبقى
        * (`?? ` في الخدمة) والحالة تبقى «مُسنَدة».
        *
        * وختمُ أوّل استجابة يُقاس عليه اتّفاق الخدمة وخرقُه، فزرٌّ يبدو
        * قابلًا للتراجع وهو يحرّك ساعةً لا تعود يجعل المشغّل يجرّب ليرى —
        * والتجريب هنا يكتب في السجلّ الذي يُحاسَب عليه لاحقًا.
        */}
      {!can('operations.manage') || me === null ? null : (
        <p className="page-note">
          أخذُ القضيّة يختم «أوّل استجابة» الآن ويرفع «جديدة» إلى «مُسنَدة». والإطلاق يردّ الإسناد
          وحده — الختم والحالة لا يعودان.
        </p>
      )}

      {error === null ? null : (
        <p className="form-error" role="alert">
          {error}
        </p>
      )}
    </>
  );
}

/** نقلٌ واحد: يطلب رمز قرارٍ حين يكون نهائيًّا، ولا يطلبه حين لا يكون. */
function TransitionForm({ detail, onDone }: { detail: CaseDetail; onDone: () => void }) {
  const { can } = useSession();
  const [target, setTarget] = useState<CaseState | ''>('');
  const [code, setCode] = useState('');
  const [note, setNote] = useState('');
  const [rootCause, setRootCause] = useState('');
  const [preventive, setPreventive] = useState('');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  if (!can('operations.manage')) {
    return <p className="page-note">نقل القضايا يتطلّب صلاحية «إدارة التنفيذ والشحن والإرجاع».</p>;
  }

  if (detail.allowedNext.length === 0) {
    // النهائيّة لا تُعاد: العودة تُفتح قضيّةً جديدة بخيطها.
    return <p className="page-note">القضيّة في حالة نهائيّة — لا انتقال منها.</p>;
  }

  const closing = target !== '' && TERMINAL.includes(target);

  async function submit(event: React.FormEvent) {
    event.preventDefault();

    if (target === '') return;

    setBusy(true);
    setError(null);

    const result = await api.patch<unknown>(proxyPath('adminCaseTransition', { id: detail.id }), {
      state: target,
      note: note.trim() === '' ? null : note.trim(),
      resolutionCode: closing ? code : null,
      // خطوة التعلّم: تُطلب عند الحسم لا عند الإغلاق — هناك يُعرف السبب.
      rootCause: target === 'RESOLVED' && rootCause.trim() !== '' ? rootCause.trim() : null,
      preventiveAction: target === 'RESOLVED' && preventive.trim() !== '' ? preventive.trim() : null,
    });

    setBusy(false);

    if (!result.ok) {
      setError(result.message);

      return;
    }

    setTarget('');
    setCode('');
    setNote('');
    setRootCause('');
    setPreventive('');
    onDone();
  }

  return (
    <form className="filters list-toolbar" onSubmit={submit}>
      <label className="field field-inline">
        <span>انقل إلى</span>
        <select value={target} onChange={(e) => setTarget(e.target.value as CaseState)} required>
          <option value="">اختر…</option>
          {detail.allowedNext.map((next) => (
            <option key={next} value={next}>
              {STATE_LABEL[next]}
            </option>
          ))}
        </select>
      </label>

      {/* الرمز يُطلب حين يُغلق فقط — وقائمته من الخادم لا من هنا. */}
      {closing ? (
        <label className="field field-inline">
          <span>رمز القرار</span>
          <select value={code} onChange={(e) => setCode(e.target.value)} required>
            <option value="">اختر…</option>
            {Object.entries(detail.resolutionCodes).map(([value, label]) => (
              <option key={value} value={value}>
                {label}
              </option>
            ))}
          </select>
        </label>
      ) : null}

      <label className="field field-inline">
        <span>ملاحظة</span>
        <input value={note} onChange={(e) => setNote(e.target.value)} maxLength={2000} />
      </label>

      {/*
        قضيّةٌ تُغلق بلا سببٍ جذريّ تُغلق الحادثة ولا تُغلق البابَ الذي دخلت
        منه. ولذلك يُطلبان عند الحسم حيث يكون السبب معروفًا.
      */}
      {target === 'RESOLVED' ? (
        <>
          <label className="field field-inline">
            <span>السبب الجذريّ</span>
            <input value={rootCause} onChange={(e) => setRootCause(e.target.value)} maxLength={1000} />
          </label>
          <label className="field field-inline">
            <span>ما يمنع التكرار</span>
            <input value={preventive} onChange={(e) => setPreventive(e.target.value)} maxLength={1000} />
          </label>
        </>
      ) : null}

      <button type="submit" className="btn btn-primary" disabled={busy}>
        {busy ? 'جارٍ…' : 'نفّذ'}
      </button>

      {error === null ? null : (
        <p className="form-error" role="alert">
          {error}
        </p>
      )}
    </form>
  );
}

/**
 * أفعال الإصلاح.
 *
 * ─── لماذا الإرشاديّ يُعرض ولا يُنقَر ───
 *
 * «مراجعة مع المزوّد» محادثةٌ لا زرّ. وعرضُه زرًّا يكذب: يُنقر فلا يقع شيء،
 * ثمّ لا يُصدَّق أيّ زرٍّ بعده.
 *
 * ─── ولماذا مفتاح خمولٍ لكلّ نقرة ───
 *
 * الفعل أثرٌ خارجيّ. مفتاحٌ يُولَّد عند النقر ويثبت خلال إعادة الإرسال
 * يجعل ردًّا ضائعًا لا يُضاعف العطل.
 */
function Remediations({ detail, onDone }: { detail: CaseDetail; onDone: () => void }) {
  const { can } = useSession();
  const [busy, setBusy] = useState<string | null>(null);
  const [message, setMessage] = useState<string | null>(null);

  if (!can('operations.manage') || detail.remediations.length === 0) return null;

  async function run(action: string) {
    setBusy(action);
    setMessage(null);

    /*
     * المفتاح مشتقٌّ من الفعل لا مولَّدٌ لكلّ نقرة.
     *
     * كان `rem-${crypto.randomUUID()}` — مفتاحٌ جديد في كلّ ضغطة، وهو إبطال
     * الخمول من جهة العميل: النقرة المكرّرة أو الإعادة بعد مهلةٍ عند الوكيل
     * تحمل مفتاحًا آخر فيراها الخادم فعلًا جديدًا وينفّذه مرّتين — وهو ذاته
     * العطل الذي وُجد الحقل ليمنعه. والرسالة أدناه («نُفِّذ سلفًا بالمفتاح
     * نفسه») كانت تعِد بسلوكٍ لا يقع أبدًا.
     *
     * والبدائيّة القائمة في `lib/idempotency.ts` تعطي ما يلزم: هويّة التبويب
     * + بصمة الجسم. فالفعل نفسه من التبويب نفسه على القضيّة نفسها مفتاحٌ
     * واحد، وفعلٌ آخر مفتاحٌ آخر.
     */
    const result = await api.post<{ duplicate?: boolean }>(
      proxyPath('adminCaseRemediate', { id: detail.id }),
      { action, idempotencyKey: idempotencyKey('cases', { caseId: detail.id, action }) },
    );

    setBusy(null);
    setMessage(
      result.ok
        ? result.data.duplicate === true
          ? 'نُفِّذ سلفًا بالمفتاح نفسه — لم يتكرّر الأثر.'
          : 'أُرسل الفعل.'
        : result.message,
    );

    if (result.ok) onDone();
  }

  return (
    <section className="panel">
      <h2>أفعال الإصلاح</h2>
      <div className={'list-toolbar'}>
        {detail.remediations.map((item) =>
          item.executable ? (
            <button
              key={item.action}
              type="button"
              className="btn btn-ghost"
              disabled={busy !== null}
              onClick={() => void run(item.action)}
            >
              {busy === item.action ? 'جارٍ…' : item.label}
            </button>
          ) : (
            <span key={item.action} className="absent">
              {item.label} — إرشاديّ لا يُنفَّذ آليًّا
            </span>
          ),
        )}
      </div>
      {message === null ? null : (
        <p className="page-note" role="status">
          {message}
        </p>
      )}
    </section>
  );
}

/** ملاحظةٌ تُلحق بالخيط بلا نقل حالة: التوثيق ليس تقدّمًا. */
function NoteForm({ caseId, onDone }: { caseId: number; onDone: () => void }) {
  const { can } = useSession();
  const [note, setNote] = useState('');
  const [busy, setBusy] = useState(false);

  if (!can('operations.manage')) return null;

  async function submit(event: React.FormEvent) {
    event.preventDefault();

    if (note.trim() === '') return;

    setBusy(true);
    await api.post<unknown>(proxyPath('adminCaseNote', { id: caseId }), { note: note.trim() });
    setBusy(false);
    setNote('');
    onDone();
  }

  return (
    <form className="filters list-toolbar" onSubmit={submit}>
      <label className="field field-inline">
        <span>أضف ملاحظة</span>
        <input value={note} onChange={(e) => setNote(e.target.value)} maxLength={2000} />
      </label>
      <button type="submit" className="btn btn-ghost" disabled={busy || note.trim() === ''}>
        {busy ? 'جارٍ…' : 'أضف'}
      </button>
    </form>
  );
}

export default function CaseDetailPage({ params }: { params: Promise<{ id: string }> }) {
  const { id } = use(params);
  const { state, reload } = useResource<{ data: CaseDetail }>(`/admin/cases/${encodeURIComponent(id)}`);

  return (
    <>
      <PageHeader trail="العمليّات · القضايا" title={`قضيّة ${id}`} />

      <ResourceView
        state={state}
        reload={reload}
        forbidden={<p>لا تملك صلاحية قراءة الطلبات، والقضايا تُقرأ بها.</p>}
      >
        {({ data }) => (
          <>
            <section className="panel">
              <h2>{data.title}</h2>
              <p>
                <StatusPill tone={stateTone(data.state)} label={STATE_LABEL[data.state]} />{' '}
                <bdi dir="ltr">{data.subject ?? '—'}</bdi>
              </p>
              {data.reasonCodes.length === 0 ? null : (
                <ReasonLine>{data.reasonCodes.join(' · ')}</ReasonLine>
              )}
              {data.resolutionCode === null ? null : (
                <p className="page-note">
                  أُغلقت بـ«{data.resolutionCodes[data.resolutionCode] ?? data.resolutionCode}»
                  {data.resolvedAt === null ? '' : ` · ${dateTime(data.resolvedAt)}`}
                </p>
              )}

              <Assignee detail={data} onDone={reload} />

              <TransitionForm detail={data} onDone={reload} />
              <NoteForm caseId={data.id} onDone={reload} />

              {data.rootCause === null ? null : (
                <p className="page-note">السبب الجذريّ: {data.rootCause}</p>
              )}
              {data.preventiveAction === null ? null : (
                <p className="page-note">ما يمنع التكرار: {data.preventiveAction}</p>
              )}
            </section>

            <Remediations detail={data} onDone={reload} />

            <h2>الخيط</h2>
            <OpsTable columns={['الفعل', 'من', 'إلى', 'الملاحظة', 'متى']}>
              {data.timeline.length === 0 ? (
                <EmptyRows colSpan={5} message="لا أحداث — وهذا لا يقع: الفتح نفسه حدث." />
              ) : (
                data.timeline.map((entry) => (
                  <tr key={entry.id}>
                    <td>{ACTION_LABEL[entry.action] ?? entry.action}</td>
                    <td>{entry.fromState === null ? '—' : (STATE_LABEL[entry.fromState as CaseState] ?? entry.fromState)}</td>
                    <td>{entry.toState === null ? '—' : (STATE_LABEL[entry.toState as CaseState] ?? entry.toState)}</td>
                    <td>{entry.note ?? '—'}</td>
                    <td>{entry.at === null ? '—' : dateTime(entry.at)}</td>
                  </tr>
                ))
              )}
            </OpsTable>
          </>
        )}
      </ResourceView>
    </>
  );
}
