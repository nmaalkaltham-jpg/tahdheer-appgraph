'use client';
// UNIT: SOURCES-PANEL
// FILE: sources-panel.tsx
// STYLE: none
// PARENT: PAGE-OPERATIONS-FULFILLMENT
// USES: LIB-FORMAT, OPS, RESOURCE
// API: /admin/operations/asset-sources
// MAP: docs/PROJECT_MAP.md

/**
 * مصادر الأصول — ما انقطع عن مصدره (§١٦).
 *
 * المحجوب يتصدّر لأنّه أسوأ ما يبقى غير مرئيّ: منتجٌ يُباع ولا يُسلَّم.
 * والحالات الثلاث لا تُطوى في «خطأ» واحد لأنّ كلًّا منها يطلب عملًا مختلفًا:
 *
 *   الملفّ مفقود   → استبدل المصدر
 *   الإذن مسحوب    → أصلح الاعتماد، والملفّ سليم
 *   فشل المزامنة   → انتظر وأعد المحاولة
 *
 * و«المصدر تغيّر» ليس عطلًا: نسخةٌ أحدث تنتظر قرارًا. وإن كانت النسخة
 * مجمّدة فالمشترى يُسلَّم كما اشتُري، وذاك سلوكٌ صحيح لا تأخّر.
 */

import { ActionButton, CountBar, OpsTable, ReasonLine, StatusPill } from '../_shared/ops';
import { EmptyRows, ResourceView, useResource } from '../../../../components/resource';
import { dateTime } from '../../../../lib/format';

interface SourceRow {
  id: number;
  provider: string;
  deliveryMode: string;
  fileName: string | null;
  syncState: string;
  /**
   * هل هذه الحالة تمنع التسليم؟ يحسبها الخادم من `AssetSource::BLOCKING_STATES`.
   *
   * كانت اللوحة تحمل نسختها من الثلاثيّ — وهي أبعد نسخةٍ عن القيد في المحرّك
   * وأقلُّها حراسة. غيابُ المفتاح من ردٍّ أقدم يُقرأ «غير حاجب» لا يُفترض.
   */
  blocking?: boolean;
  lastError: string | null;
  versionFrozen: boolean;
  lastVerifiedAt: string | null;
  deliverable: boolean;
}

interface SourcesView {
  data: SourceRow[];
  blocking: number;
}

const LABELS: Record<string, string> = {
  CURRENT: 'محدَّث',
  SOURCE_CHANGED: 'المصدر تغيّر',
  SYNC_PENDING: 'بانتظار مزامنة',
  SYNC_FAILED: 'فشلت المزامنة',
  SOURCE_MISSING: 'الملفّ مفقود',
  ACCESS_REVOKED: 'الإذن مسحوب',
};


export function AssetSourcesPanel() {
  const { state, reload } = useResource<SourcesView>('/admin/operations/asset-sources');

  return (
    <>

      <ResourceView
        state={state}
        reload={reload}
        forbidden={<p>لا تملك صلاحية قراءة المنتجات، ومصادر الأصول تُقرأ بها.</p>}
      >
        {(view) => (
          <>
            <CountBar
              items={[
                { label: 'يمنع التسليم', value: view.blocking, tone: view.blocking > 0 ? 'danger' : 'done' },
                { label: 'مصادر مسجّلة', value: view.data.length },
              ]}
            />

            <OpsTable columns={['الملفّ', 'المزوّد', 'نمط التسليم', 'الحالة', 'آخر تحقّق', '']}>
              {view.data.length === 0 ? (
                <EmptyRows colSpan={6} message="لا مصدر أصلٍ مسجَّل." />
              ) : (
                view.data.map((row) => (
                  <tr key={row.id}>
                    <td>{row.fileName ?? '—'}</td>
                    <td>{row.provider}</td>
                    <td>
                      {row.deliveryMode}
                      {/* نسخةٌ مجمّدة: المشترى يُسلَّم كما اشتُري — سلوكٌ صحيح لا تأخّر. */}
                      {row.versionFrozen ? <ReasonLine>النسخة مجمّدة</ReasonLine> : null}
                    </td>
                    <td>
                      <StatusPill
                        /*
                          * الحجب يأتي من الخادم لا من نسخةٍ هنا: كانت هذه
                          * أبعدَ نسخةٍ عن القيد في المحرّك وبلا اختبار، فحالةٌ
                          * حاجبةٌ رابعة كانت تُطلى خضراءَ «انتظار» على الشاشة
                          * المبنيّة خصّيصًا لإظهار ما لا يُسلَّم.
                          */
                        tone={row.blocking ? 'danger' : row.syncState === 'CURRENT' ? 'done' : 'wait'}
                        label={LABELS[row.syncState] ?? row.syncState}
                      />
                      <ReasonLine>{row.lastError}</ReasonLine>
                    </td>
                    <td>{row.lastVerifiedAt ? dateTime(row.lastVerifiedAt) : '—'}</td>
                    <td>
                      {/* فحصٌ حقيقيّ عند المزوّد، لا تعليمُ حالةٍ محلّيّة. */}
                      <ActionButton
                        path={`/admin/operations/asset-sources/${row.id}/verify`}
                        label="إعادة التحقّق"
                        busyLabel="جارٍ…"
                        onDone={reload}
                      />
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
