'use client';
// UNIT: QUEUE-PANEL
// FILE: queue-panel.tsx
// STYLE: none
// PARENT: PAGE-OPERATIONS-FULFILLMENT
// USES: LIB-FORMAT, OPS, PRODUCT-TYPES, RESOURCE
// API: /admin/operations/fulfillment
// MAP: docs/PROJECT_MAP.md

/**
 * طابور التنفيذ — ما بين «دُفع» و«وصل».
 *
 * ─── الفجوة التي تسدّها ───
 *
 * اللوحة كانت مبنيّةً لمتجرٍ رقميّ بحت: طلبٌ يُدفع فيُمنح استحقاقٌ وينتهي
 * الأمر، فلا شيء «قيد التنفيذ» أصلًا. وبعد بناء الشحن والرخص والحجوزات صار
 * بين الدفع والتسليم عملٌ حقيقيّ بلا سطح — يبقى غير مرئيّ حتّى يسأل زبون
 * عن طلبٍ لم يصله.
 *
 * ─── لماذا السبب في الجدول لا خلف نقرة ───
 *
 * الطابور يُمسح بالعين ليُقرّر ما يُبدأ به. «معلّق» وحدها تُوجب فتح كلّ صفٍّ
 * لمعرفة أيّها يستحقّ الفتح؛ و«لا عنوان شحن على الطلب» تُخبر المشغّل بما
 * يفعل قبل أن ينقر.
 *
 * والمعطوب يتصدّر لا الأقدم: وحدةٌ فشلت تحتاج قرارًا، ووحدةٌ تنتظر عنوانًا
 * تحتاج انتظارًا. الترتيب بالأقدميّة كان يدفن الأولى تحت عشرات الثانية.
 */

import { ActionButton, CountBar, OpsTable, ReasonLine, StatusPill, unitTone } from '../_shared/ops';
import { useProductTypes } from '../../studio/catalog/product-types';
import { EmptyRows, ResourceView, useResource } from '../../../../components/resource';
import { dateTime } from '../../../../lib/format';

interface FulfillmentUnitRow {
  id: number;
  orderId: number;
  orderNumber: string | null;
  customerEmail: string | null;
  title: string | null;
  sku: string | null;
  strategy: string;
  status: string;
  reason: string | null;
  attempts: number;
  createdAt: string | null;
}

interface FulfillmentQueue {
  data: FulfillmentUnitRow[];
  summary: Record<string, number>;
}

const STATUS_LABELS: Record<string, string> = {
  PENDING: 'بانتظار',
  READY: 'جاهزة',
  PROCESSING: 'قيد التنفيذ',
  FULFILLED: 'نُفّذت',
  PARTIALLY_FULFILLED: 'نُفّذت جزئيًّا',
  FAILED: 'فشلت',
  CANCELLED: 'أُلغيت',
  REVOKED: 'سُحبت',
};


export function FulfillmentQueuePanel() {
  const { state, reload } = useResource<FulfillmentQueue>('/admin/operations/fulfillment');

  /*
   * ─── التسميات من الخادم لا من جدولٍ هنا ───
   *
   * كانت تسع عشرة تسميةً مكتوبةً بيدها في هذا الملفّ، بلا أيّ رابطٍ نوعيّ
   * بمفردات الخادم. فاستراتيجيّةٌ تُضاف هناك تظهر للمشغّل برمزها الإنجليزيّ
   * الخام هنا — ولا اختبارَ يشكو، لأنّ السقوط إلى الرمز «يعمل».
   *
   * والخادم يَنشر التسميات في `GET /admin/product-types` منذ صار للنوع سجلّ.
   * والسقوط إلى الرمز يبقى: تحميلٌ لم يصل بعد أفضلُ من شاشةٍ فارغة.
   */
  const types = useProductTypes();

  const label = (strategy: string) =>
    types.data?.strategies.find((item) => item.value === strategy)?.label ?? strategy;

  return (
    <>

      <ResourceView
        state={state}
        reload={reload}
        forbidden={<p>لا تملك صلاحية قراءة الطلبات، وطابور التنفيذ يُقرأ بها.</p>}
      >
        {(queue) => (
          <>
            <CountBar
              items={[
                { label: 'فشلت', value: queue.summary.FAILED ?? 0, tone: 'danger' },
                { label: 'بانتظار', value: queue.summary.PENDING ?? 0, tone: 'wait' },
                { label: 'قيد التنفيذ', value: queue.summary.PROCESSING ?? 0, tone: 'wait' },
                { label: 'نُفّذت', value: queue.summary.FULFILLED ?? 0, tone: 'done' },
              ]}
            />

            <OpsTable columns={['الطلب', 'البند', 'طريقة التسليم', 'الحالة', 'المحاولات', 'منذ', '']}>
              {queue.data.length === 0 ? (
                <EmptyRows colSpan={7} message="لا وحدة تنفيذٍ مفتوحة — كلّ ما دُفع سُلّم." />
              ) : (
                queue.data.map((unit) => (
                  <tr key={unit.id}>
                    <td>
                      <a href={`/orders/${unit.orderId}`}>{unit.orderNumber ?? `#${unit.orderId}`}</a>
                      <ReasonLine>{unit.customerEmail}</ReasonLine>
                    </td>
                    <td>
                      {unit.title ?? '—'}
                      <ReasonLine>{unit.sku}</ReasonLine>
                    </td>
                    <td>{label(unit.strategy)}</td>
                    <td>
                      <StatusPill tone={unitTone(unit.status)} label={STATUS_LABELS[unit.status] ?? unit.status} />
                      {/* السبب مكتوبٌ في المحرّك بقيد: لا حالة معلّقة أو فاشلة بلا نصّ. */}
                      <ReasonLine>{unit.reason}</ReasonLine>
                    </td>
                    <td>{unit.attempts}</td>
                    <td>{unit.createdAt ? dateTime(unit.createdAt) : '—'}</td>
                    <td>
                      {/*
                        * إعادة المحاولة تُشغّل المنفّذ فعلًا ولا تُعلّم الوحدة
                        * منفَّذة. وإن بقي السبب قائمًا عادت إلى الانتظار بالسبب
                        * نفسه — وذاك صحيحٌ لا فشل.
                        */}
                      <ActionButton
                        path={`/admin/operations/fulfillment/${unit.id}/retry`}
                        label="إعادة المحاولة"
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
