'use client';
// UNIT: PAGE-OPERATIONS-FULFILLMENT
// FILE: page.tsx
// ROUTE: /operations/fulfillment
// STYLE: none
// PARENT: none
// USES: ADMIN-SESSION, PAGE-TABS, QUEUE-PANEL, RESOURCE, RETURNS-PANEL, RISK-PANEL +2
// API: none
// API-VIA: ADMIN-SESSION, QUEUE-PANEL, RETURNS-PANEL, RISK-PANEL, SHIPMENTS-PANEL, SOURCES-PANEL
// MAP: docs/PROJECT_MAP.md

/**
 * طابور العمليّات — سطحٌ واحد بخمسة تبويبات.
 *
 * ─── لماذا دُمجت ───
 *
 * خمس وجهاتٍ في الشريط، مجموع سطورها أقلّ من صفحةٍ واحدة في اللوحة، وكلٌّ
 * منها **جدولٌ واحد** يجيب السؤال نفسه: «ما العالق؟». والمشغّل في نوبته
 * يفتحها الخمس بالترتيب في كلّ صباح — أي أنّها مهمّةٌ واحدة قُسّمت خمسًا
 * على الشريط.
 *
 * ─── وما بقي خارجها ───
 *
 * «المخزون» يبقى مستقلًّا: فيه نموذج حركةٍ **يكتب**، وليس قراءةً لما علق.
 * و«القضايا» مستقلّة لأنّها آلة حالاتٍ لا جدول انتظار.
 *
 * وكلّ لوحٍ هو ملفّه القديم حرفيًّا: المنطق والنداءات والصلاحيات كما كانت.
 */

import { Suspense } from 'react';
import { useSession } from '../../../../components/admin-session';
import { PageHeader } from '../../../../components/resource';
import { PageTabs, type PageTab } from '../../../../components/ui/page-tabs';
import { FulfillmentQueuePanel } from './queue-panel';
import { ShipmentsPanel } from '../shipments/shipments-panel';
import { ReturnsPanel } from '../returns/returns-panel';
import { RiskReviewPanel } from '../risk/risk-panel';
import { AssetSourcesPanel } from '../asset-sources/sources-panel';

const TABS: readonly PageTab[] = [
  { id: 'queue', label: 'طابور التنفيذ', permission: 'orders.read', render: () => <FulfillmentQueuePanel /> },
  { id: 'shipments', label: 'الشحنات', permission: 'orders.read', render: () => <ShipmentsPanel /> },
  { id: 'returns', label: 'الإرجاع', permission: 'orders.read', render: () => <ReturnsPanel /> },
  { id: 'risk', label: 'مراجعة المخاطر', permission: 'orders.read', render: () => <RiskReviewPanel /> },
  { id: 'sources', label: 'مصادر الأصول', permission: 'products.read', render: () => <AssetSourcesPanel /> },
];

export default function OperationsQueuePage() {
  return (
    <Suspense fallback={null}>
      <OperationsQueue />
    </Suspense>
  );
}

function OperationsQueue() {
  const { can } = useSession();

  return (
    <section>
      <PageHeader trail="العمليّات" title="طابور العمليّات" />
      <PageTabs tabs={TABS} can={can} label="أقسام طابور العمليّات" scope="tabs:operations" />
    </section>
  );
}
