'use client';
// UNIT: PAGE-OPERATIONS-CASES
// FILE: page.tsx
// ROUTE: /operations/cases
// STYLE: none
// PARENT: none
// USES: ADMIN-SESSION, LIB-ADMIN-API, LIB-CONTRACT, LIB-FORMAT, OPS, RESOURCE
// API: /admin/cases
// MAP: docs/PROJECT_MAP.md

/**
 * القضايا: احتيالٌ وحوادثُ ودعم في سطحٍ واحد.
 *
 * ─── لماذا سطحٌ واحد ───
 *
 * «أرِني كلّ ما هو مفتوح» سؤالٌ لا يُجاب حين تكون الشاشات ثلاثًا. والمشغّل
 * في بداية نوبته يسأل هذا السؤال لا «أرِني قضايا الاحتيال».
 *
 * ─── الترتيب ───
 *
 * الأشدّ أوّلًا يأتي من الخادم لا من هنا: الترتيب قرارُ نطاقٍ يجب أن يكون
 * واحدًا لكلّ من يقرأ، وإعادةُ ترتيبه في الواجهة تجعل التصفّح يخالف التقرير.
 *
 * ─── لماذا لا يُغلق شيءٌ من القائمة ───
 *
 * الإغلاق يشترط رمز قرار، ورمزُ قرارٍ يُختار من قائمةٍ في صفٍّ ضيّق يُنتقى
 * بالخطأ. القائمة تُظهر وتنقل إلى التحقيق، والإغلاق من صفحة القضيّة.
 */

import Link from 'next/link';
import { useState } from 'react';
import { CountBar, OpsTable, ReasonLine, StatusPill } from '../_shared/ops';
import type { Tone } from '../_shared/ops';
import { EmptyRows, PageHeader, ResourceView, useResource } from '../../../../components/resource';
import { useSession } from '../../../../components/admin-session';
import { proxyPath } from '../../../../lib/contract';
import { api } from '../../../../lib/admin-api';
import { dateTime } from '../../../../lib/format';

type CaseState = 'OPEN' | 'INVESTIGATING' | 'ACTION_REQUIRED' | 'RESOLVED' | 'FALSE_POSITIVE';
type Severity = 'INFO' | 'WARNING' | 'HIGH' | 'CRITICAL';

interface CaseRow {
  id: number;
  kind: 'FRAUD' | 'INCIDENT' | 'SUPPORT';
  state: CaseState;
  severity: Severity;
  title: string;
  subject: string | null;
  customerId: number | null;
  assignedTo: number | null;
  reasonCodes: string[];
  resolutionCode: string | null;
  openedAt: string | null;
  resolvedAt: string | null;
  allowedNext: CaseState[];
}

interface CasesView {
  data: CaseRow[];
  open: number;
  critical: number;
  unassigned: number;
}

const STATE_LABEL: Record<CaseState, string> = {
  OPEN: 'مفتوحة',
  INVESTIGATING: 'قيد التحقيق',
  ACTION_REQUIRED: 'تحتاج إجراء',
  RESOLVED: 'حُسمت',
  FALSE_POSITIVE: 'إنذار كاذب',
};

const KIND_LABEL: Record<CaseRow['kind'], string> = {
  FRAUD: 'احتيال',
  INCIDENT: 'حادثة',
  SUPPORT: 'دعم',
};

const SEVERITY_LABEL: Record<Severity, string> = {
  CRITICAL: 'حرجة',
  HIGH: 'عالية',
  WARNING: 'تحذير',
  INFO: 'معلومة',
};

/** الحدّة لونٌ يقرّر ما يُنظر فيه الآن — لا زينة. */
function severityTone(severity: Severity): Tone {
  if (severity === 'CRITICAL' || severity === 'HIGH') return 'danger';
  if (severity === 'WARNING') return 'wait';

  return 'done';
}

function stateTone(state: CaseState): Tone {
  if (state === 'ACTION_REQUIRED') return 'danger';
  if (state === 'OPEN' || state === 'INVESTIGATING') return 'wait';

  return 'done';
}

/** نقلٌ واحد من القائمة: إلى التحقيق. وما بعده يحتاج قرارًا مكتوبًا. */
function InvestigateButton({ row, onDone }: { row: CaseRow; onDone: () => void }) {
  const { can } = useSession();
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  if (!can('operations.manage') || !row.allowedNext.includes('INVESTIGATING')) {
    return <span className="absent">—</span>;
  }

  async function investigate() {
    setBusy(true);
    setError(null);

    const result = await api.patch<unknown>(proxyPath('adminCaseTransition', { id: row.id }), {
      state: 'INVESTIGATING',
    });

    setBusy(false);

    if (!result.ok) {
      setError(result.message);

      return;
    }

    onDone();
  }

  return (
    <>
      <button type="button" className="btn btn-ghost" disabled={busy} onClick={investigate}>
        {busy ? 'جارٍ…' : 'ابدأ التحقيق'}
      </button>
      {error === null ? null : <ReasonLine>{error}</ReasonLine>}
    </>
  );
}

export default function CasesPage() {
  const { state, reload } = useResource<CasesView>('/admin/cases');

  return (
    <>
      <PageHeader trail="العمليّات" title="القضايا" />

      <ResourceView
        state={state}
        reload={reload}
        forbidden={<p>لا تملك صلاحية قراءة الطلبات، والقضايا تُقرأ بها.</p>}
      >
        {(view) => (
          <>
            <CountBar
              items={[
                { label: 'حرجة مفتوحة', value: view.critical, tone: view.critical > 0 ? 'danger' : 'done' },
                { label: 'بلا مسؤول', value: view.unassigned, tone: view.unassigned > 0 ? 'wait' : 'done' },
                { label: 'مفتوحة', value: view.open },
                { label: 'معروضة', value: view.data.length },
              ]}
            />

            <p className="page-note">
              القضايا تُفتح آليًّا من التسوية وتحليل المخاطرة كل ربع ساعة. ولا يُغلق شيء آليًّا: زوال
              الشرط من المسح لا يعني أن العيب عولج.
            </p>

            <OpsTable columns={['النوع', 'الحدّة', 'العنوان', 'الحالة', 'الموضوع', 'فُتحت', '']}>
              {view.data.length === 0 ? (
                <EmptyRows colSpan={7} message="لا قضايا — لا فرق مالي ولا عنقود مخاطرة." />
              ) : (
                view.data.map((row) => (
                  <tr key={row.id}>
                    <td>{KIND_LABEL[row.kind]}</td>
                    <td>
                      <StatusPill tone={severityTone(row.severity)} label={SEVERITY_LABEL[row.severity]} />
                    </td>
                    <td>
                      {/* العنوان رابط: الخيط هو ما يُقرأ قبل أيّ قرار. */}
                      <Link href={`/operations/cases/${row.id}`}>{row.title}</Link>
                      {row.reasonCodes.length === 0 ? null : (
                        <ReasonLine>{row.reasonCodes.join(' · ')}</ReasonLine>
                      )}
                    </td>
                    <td>
                      <StatusPill tone={stateTone(row.state)} label={STATE_LABEL[row.state]} />
                      {row.resolutionCode === null ? null : <ReasonLine>{row.resolutionCode}</ReasonLine>}
                    </td>
                    <td>
                      <bdi dir="ltr">{row.subject ?? '—'}</bdi>
                    </td>
                    <td>{row.openedAt === null ? '—' : dateTime(row.openedAt)}</td>
                    <td>
                      <InvestigateButton row={row} onDone={reload} />
                    </td>
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
