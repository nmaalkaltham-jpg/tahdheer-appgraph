'use client';
// UNIT: RETURNS-PANEL
// FILE: returns-panel.tsx
// STYLE: returns.module.css
// PARENT: PAGE-OPERATIONS-FULFILLMENT
// USES: ADMIN-SESSION, LIB-CONTRACT, LIB-FORMAT, OPS, RESOURCE
// API: /admin/operations/returns
// MAP: docs/PROJECT_MAP.md

/**
 * طلبات الإرجاع — من الطلب إلى ردّ المال.
 *
 * بين «طلب الزبون» و«رُدّ المال» خطواتٌ تُراجَع: موافقةٌ، ثمّ وصولُ البضاعة،
 * ثمّ فحصُها. عرضُها حالةً واحدة يُخفي أين وقف الطلب فعلًا، ويجعل «لماذا
 * تأخّر ردّ مالي؟» سؤالًا بلا إجابة في اللوحة.
 *
 * وسبب الرفض يُعرض مع الصفّ: مرفوضٌ بلا سبب لا يُشرح للزبون ولا يُراجَع
 * لاحقًا — ولذلك يمنعه قيدٌ في المحرّك أصلًا.
 *
 * ─── ولماذا صار للجدول عمود نقل ───
 *
 * كانت الشاشة تقول أين وقف الطلب ولا تملك تحريكه: يرى المشغّل «طُلب» منذ
 * ثلاثة أيّام فيخرج من اللوحة إلى أداةٍ أخرى ليوافق. طابورٌ يُقرأ هنا
 * ويُعالَج هناك يُقرأ مرّتين ويتأخّر مرّةً، وردُّ المال آخر ما يُحتمل فيه
 * التأخّر. والنقل حيث تُقرأ الحالة لا في صفحةٍ تُفتح صفًّا صفًّا.
 *
 * والخيارات المعروضة هي `allowedNext` الذي يبثّه الخادم مع الصفّ:
 * `ReturnTransitionService` صاحبة الخريطة وحدها، ونسخُها هنا يُنتج خريطتين
 * تتباعدان — وأوّل تباعدٍ خيارٌ يَعِد المشغّل بانتقالٍ يرفضه الخادم.
 *
 * والعمود لا يُرسم إلّا لمن يملك `operations.manage` — صلاحية المسار نفسها:
 * عمودٌ يُعرض ثمّ يُردّ فعلُه بـ٤٠٣ يعلّم المشغّل ألّا يثق بما تعرضه الشاشة،
 * وهو ضررٌ أبقى من غياب العمود.
 *
 * ─── والملاحظة تُعرض غير مُلزَمة ───
 *
 * الرفض وحده يشترط سببًا، والخادم هو مَن يشترطه (فحصٌ في خدمة النطاق وقيدٌ
 * في المحرّك خلفه). فإلزامُها على كلّ انتقالٍ يمنع موافقةً لا سبب لها أصلًا،
 * ويعلّم المشغّل أنّ اللوحة تشترط ما لا يشترطه الخادم. الشرط يبقى لصاحبه،
 * ونصّ رفضه يُعرض كما هو تحت الصفّ.
 */

import { OpsTable, ReasonLine, StatusPill, TransitionForm } from '../_shared/ops';
import { EmptyRows, ResourceView, useResource } from '../../../../components/resource';
import { useSession } from '../../../../components/admin-session';
import { proxyPath } from '../../../../lib/contract';
import { dateTime, money } from '../../../../lib/format';
import styles from './returns.module.css';

interface ReturnRow {
  id: number;
  number: string;
  orderNumber: string | null;
  status: string;
  customerReason: string | null;
  rejectionReason: string | null;
  refundMinor: number | null;
  requestedAt: string | null;
  /* ما يجوز من هنا — يبثّه الخادم مع كلّ صفّ، ولا تشتقّه الواجهة. */
  allowedNext: string[];
}

const LABELS: Record<string, string> = {
  REQUESTED: 'طُلب',
  APPROVED: 'ووفق عليه',
  REJECTED: 'رُفض',
  IN_TRANSIT: 'في الطريق إلينا',
  RECEIVED: 'وصل',
  INSPECTED: 'فُحص',
  REFUNDED: 'رُدّ المال',
  CLOSED: 'أُغلق',
};

const COLUMNS = ['الرقم', 'الطلب', 'الحالة', 'سبب الزبون', 'المبلغ', 'طُلب في'];

export function ReturnsPanel() {
  const { can } = useSession();
  const { state, reload } = useResource<{ data: ReturnRow[] }>('/admin/operations/returns');

  const mayManage = can('operations.manage');
  /* رأسُ الجدول وعرضُ «لا صفوف» من مصدرٍ واحد: عمودٌ يُضاف ولا يُضاف إلى
     `colSpan` يترك سطر الفراغ أقصر من الجدول فيبدو مكسورًا. */
  const columns = mayManage ? [...COLUMNS, 'نقل الحالة'] : COLUMNS;

  return (
    <>

      <ResourceView
        state={state}
        reload={reload}
        forbidden={<p>لا تملك صلاحية قراءة الطلبات، وطلبات الإرجاع تُقرأ بها.</p>}
      >
        {(view) => (
          <OpsTable columns={columns}>
            {view.data.length === 0 ? (
              <EmptyRows colSpan={columns.length} message="لا طلب إرجاع." />
            ) : (
              view.data.map((row) => (
                <tr key={row.id}>
                  <td>{row.number}</td>
                  <td>{row.orderNumber ?? '—'}</td>
                  <td>
                    <StatusPill
                      tone={row.status === 'REJECTED' ? 'danger' : row.status === 'REFUNDED' || row.status === 'CLOSED' ? 'done' : 'wait'}
                      label={LABELS[row.status] ?? row.status}
                    />
                    {/* مرفوضٌ بلا سبب يمنعه قيدٌ في المحرّك — فالسبب موجود دائمًا. */}
                    <ReasonLine>{row.rejectionReason}</ReasonLine>
                  </td>
                  <td>{row.customerReason ?? '—'}</td>
                  <td>{row.refundMinor === null ? '—' : money(row.refundMinor)}</td>
                  <td>{row.requestedAt ? dateTime(row.requestedAt) : '—'}</td>
                  {mayManage ? (
                    <td className={styles.transitionCell}>
                      {/* «أُغلق» نهايةٌ بلغت نهايتها: شرطةٌ كبقيّة الغياب، لا خليّةٌ
                          بيضاء تُقرأ عطلًا في التصيير. */}
                      {row.allowedNext.length === 0 ? (
                        <span className="absent">—</span>
                      ) : (
                        <TransitionForm
                          path={proxyPath('adminOperationsActionUpdateReturn', { id: row.id })}
                          options={row.allowedNext}
                          labels={LABELS}
                          label="نقل الحالة"
                          subject={row.number}
                          text={{ name: 'note', placeholder: 'ملاحظة — وسببٌ لازمٌ للرفض', maxLength: 1000 }}
                          onDone={reload}
                        />
                      )}
                    </td>
                  ) : null}
                </tr>
              ))
            )}
          </OpsTable>
        )}
      </ResourceView>
    </>
  );
}
