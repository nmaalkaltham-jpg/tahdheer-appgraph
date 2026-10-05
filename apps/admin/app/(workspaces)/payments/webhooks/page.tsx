'use client';
// UNIT: PAGE-PAYMENTS-WEBHOOKS
// FILE: page.tsx
// ROUTE: /payments/webhooks
// STYLE: none
// PARENT: none
// USES: ADMIN-SESSION, LIB-ADMIN-API, LIB-CONTRACT, LIB-FORMAT, OPS, RESOURCE
// API: /admin/payments/webhooks
// MAP: docs/PROJECT_MAP.md

/**
 * أحداث الدفع الواردة — وما جرى لكلٍّ منها.
 *
 * ─── لماذا هذه الشاشة ───
 *
 * «هل وصلنا هذا الحدث؟» سؤالُ كلّ نزاعٍ مع مزوّد دفع، وكان بلا جواب لأنّ
 * الأحداث لم تكن تُحفظ. والأسوأ أنّ حدثًا فشلت معالجته لعطلٍ عابر كان
 * يحتاج تدخّلًا يدويًّا في قاعدة البيانات — خارج التدقيق وبلا صلاحية.
 *
 * ─── الترتيب: الفاشل أوّلًا ───
 *
 * الشاشة تُفتح عند مشكلة لا للتصفّح. عدّادا «فاشل» و«بلا طلبٍ مطابق» في
 * الأعلى لأنّهما وحدهما ما يستدعي فعلًا.
 *
 * ─── لماذا إعادة التشغيل مسموحة على الناجح ───
 *
 * `OrderFinalizer` خاملُ التكرار بمفتاحٍ فريد في المحرّك، فإعادةُ حدثٍ
 * عولج تُعيد «مكرّر» ولا تُنشئ دفعةً ثانية. وهي الطريقة الوحيدة للتأكّد من
 * أنّ الأثر ما يزال قائمًا بعد إصلاحٍ يدويّ.
 */

import { useState } from 'react';
import { CountBar, OpsTable, ReasonLine, StatusPill } from '../../operations/_shared/ops';
import type { Tone } from '../../operations/_shared/ops';
import { EmptyRows, PageHeader, ResourceView, useResource } from '../../../../components/resource';
import { useSession } from '../../../../components/admin-session';
import { proxyPath } from '../../../../lib/contract';
import { api } from '../../../../lib/admin-api';
import { dateTime } from '../../../../lib/format';

interface DeliveryRow {
  id: number;
  provider: string;
  status: 'RECEIVED' | 'PROCESSED' | 'IGNORED' | 'UNMATCHED' | 'FAILED';
  succeeded: boolean;
  chargeId: string | null;
  orderNumber: string | null;
  attempts: number;
  replayCount: number;
  lastError: string | null;
  receivedAt: string | null;
  processedAt: string | null;
  replayable: boolean;
}

interface WebhookView {
  data: DeliveryRow[];
  failed: number;
  unmatched: number;
}

/** الحالة ليست لونًا تجميليًّا: «بلا طلبٍ مطابق» تنتظر فعلًا، و«متجاهَل» لا. */
function statusTone(status: DeliveryRow['status']): Tone {
  if (status === 'FAILED' || status === 'UNMATCHED') return 'danger';
  if (status === 'RECEIVED') return 'wait';

  return 'done';
}

const STATUS_LABEL: Record<DeliveryRow['status'], string> = {
  RECEIVED: 'وصل',
  PROCESSED: 'عولج',
  IGNORED: 'متجاهَل',
  UNMATCHED: 'بلا طلبٍ مطابق',
  FAILED: 'فشل',
};

function ReplayButton({ row, onDone }: { row: DeliveryRow; onDone: () => void }) {
  const { can } = useSession();
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);

  if (!can('payments.manage')) {
    // لا يُعرض فعلٌ لمن لا يملكه ثمّ يُردّ ٤٠٣ — ذلك يُقرأ عطلًا لا صلاحية.
    return <span className="absent">—</span>;
  }

  if (!row.replayable) return <span className="absent">—</span>;

  async function replay() {
    setBusy(true);
    setError(null);
    setNotice(null);

    const result = await api.post<{ duplicate?: boolean }>(
      proxyPath('adminPaymentOperationsReplayWebhook', { id: row.id }),
    );

    setBusy(false);

    if (!result.ok) {
      setError(result.message);

      return;
    }

    setNotice(result.data.duplicate === true ? 'مُرسَلٌ سلفًا — لم تُضَف مهمّة.' : 'أُرسل إلى الطابور.');
    onDone();
  }

  return (
    <>
      <button type="button" className="btn btn-ghost" disabled={busy} onClick={replay}>
        {busy ? 'جارٍ…' : 'إعادة تشغيل'}
      </button>
      {error === null ? null : <ReasonLine>{error}</ReasonLine>}
      {notice === null ? null : <ReasonLine>{notice}</ReasonLine>}
    </>
  );
}

export default function WebhooksPage() {
  const { state, reload } = useResource<WebhookView>('/admin/payments/webhooks');

  return (
    <>
      <PageHeader trail="المدفوعات" title="الأحداث الواردة" />

      <ResourceView
        state={state}
        reload={reload}
        forbidden={<p>لا تملك صلاحية قراءة الطلبات، وأحداث الدفع تُقرأ بها.</p>}
      >
        {(view) => (
          <>
            <CountBar
              items={[
                { label: 'فشلت معالجته', value: view.failed, tone: view.failed > 0 ? 'danger' : 'done' },
                {
                  label: 'بلا طلبٍ مطابق',
                  value: view.unmatched,
                  tone: view.unmatched > 0 ? 'danger' : 'done',
                },
                { label: 'أحداث معروضة', value: view.data.length },
              ]}
            />

            <OpsTable
              columns={['المزوّد', 'الحالة', 'الرسوم', 'الطلب', 'محاولات', 'وصل', 'عولج', '']}
            >
              {view.data.length === 0 ? (
                <EmptyRows colSpan={8} message="لا أحداث واردة — لم يصل خطّافٌ بعد." />
              ) : (
                view.data.map((row) => (
                  <tr key={row.id}>
                    <td>{row.provider}</td>
                    <td>
                      <StatusPill tone={statusTone(row.status)} label={STATUS_LABEL[row.status]} />
                      {row.lastError === null ? null : <ReasonLine>{row.lastError}</ReasonLine>}
                    </td>
                    <td>{row.chargeId ?? '—'}</td>
                    <td>{row.orderNumber ?? '—'}</td>
                    <td>
                      {row.attempts}
                      {row.replayCount > 0 ? <ReasonLine>أُعيد {row.replayCount}</ReasonLine> : null}
                    </td>
                    <td>{row.receivedAt === null ? '—' : dateTime(row.receivedAt)}</td>
                    <td>{row.processedAt === null ? '—' : dateTime(row.processedAt)}</td>
                    <td>
                      <ReplayButton row={row} onDone={reload} />
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
