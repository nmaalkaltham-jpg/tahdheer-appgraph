'use client';
// UNIT: PAGE-SEO-INTERNATIONAL
// FILE: page.tsx
// ROUTE: /seo/international
// STYLE: none
// PARENT: none
// USES: ADMIN-SESSION, HREFLANG-PANEL, OPPORTUNITIES-PANEL, PAGE-TABS, PREVIEW-PANEL, REGISTRY-PANEL +1
// API: none
// API-VIA: ADMIN-SESSION, HREFLANG-PANEL, OPPORTUNITIES-PANEL, PREVIEW-PANEL, REGISTRY-PANEL
// MAP: docs/PROJECT_MAP.md

/**
 * SEO الدولي — سطحٌ واحد بأربعة تبويبات.
 *
 * كانت أربع صفحاتٍ خلف وجهةٍ واحدة في الشريط: يفتح المشغّل «السجلّ» ثمّ لا
 * يجد ما يقوده إلى «المدقّق» إلّا رابطًا في متن النصّ. والأربع تقرأ نقطتَي
 * نهايةٍ اثنتين لا غير — أي أنّها وجهُ نظرٍ واحد في أربع صفحات.
 *
 * وكلّ لوحٍ ملفّه القديم حرفيًّا؛ ما تغيّر التصدير وموضع الرأس.
 */

import Link from 'next/link';
import { Suspense } from 'react';
import { useSession } from '../../../../components/admin-session';
import { PageHeader } from '../../../../components/resource';
import { PageTabs, type PageTab } from '../../../../components/ui/page-tabs';
import { RegistryPanel } from './registry-panel';
import { HreflangPanel } from './hreflang/hreflang-panel';
import { OpportunitiesPanel } from './opportunities/opportunities-panel';
import { PreviewPanel } from './preview/preview-panel';

const TABS: readonly PageTab[] = [
  { id: 'registry', label: 'سجلّ المواقع', permission: 'analytics.read', render: () => <RegistryPanel /> },
  { id: 'hreflang', label: 'مدقّق hreflang', permission: 'analytics.read', render: () => <HreflangPanel /> },
  { id: 'opps', label: 'الفرص الإقليميّة', permission: 'analytics.read', render: () => <OpportunitiesPanel /> },
  { id: 'preview', label: 'المعاينة والوراثة', permission: 'analytics.read', render: () => <PreviewPanel /> },
];

export default function InternationalPage() {
  return (
    <Suspense fallback={null}>
      <International />
    </Suspense>
  );
}

function International() {
  const { can } = useSession();

  return (
    <section>
      <PageHeader
        trail="الظهور والبحث › SEO الدولي"
        title="SEO الدولي"
        actions={
          <Link className="btn btn-ghost" href="/seo">
            ‹ الظهور والبحث
          </Link>
        }
      />

      <PageTabs tabs={TABS} can={can} label="أقسام SEO الدولي" scope="tabs:international" />
    </section>
  );
}
