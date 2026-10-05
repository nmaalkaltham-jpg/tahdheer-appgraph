'use client';
// UNIT: PAGE-STUDIO-CATALOG
// FILE: page.tsx
// ROUTE: /studio/catalog
// STYLE: none
// PARENT: none
// USES: ADMIN-SESSION, LIST-PANEL, PAGE-TABS, QUALITY-PANEL, RESOURCE
// API: none
// API-VIA: ADMIN-SESSION, LIST-PANEL, QUALITY-PANEL
// MAP: docs/PROJECT_MAP.md

/**
 * الكتالوج — سطحٌ واحد بتبويبين.
 *
 * «اكتمال البيانات» كانت وجهةً مستقلّة تقرأ المنتجات وتجيب «ما الناقص قبل
 * النشر؟» — وهو سؤالُ من يقف أمام قائمة المنتجات نفسها. ووجهتان لمصدرٍ
 * واحد تجعلان المشغّل يتنقّل بينهما ليقرّر في صفٍّ واحد.
 */

import Link from 'next/link';
import { Suspense } from 'react';
import { useSession } from '../../../../components/admin-session';
import { PageHeader } from '../../../../components/resource';
import { PageTabs, type PageTab } from '../../../../components/ui/page-tabs';
import { CatalogListPanel } from './list-panel';
import { CatalogQualityPanel } from '../../catalog/quality/quality-panel';

const TABS: readonly PageTab[] = [
  { id: 'products', label: 'المنتجات', permission: 'products.read', render: () => <CatalogListPanel /> },
  { id: 'quality', label: 'اكتمال البيانات', permission: 'products.read', render: () => <CatalogQualityPanel /> },
];

export default function CatalogWorkspacePage() {
  return (
    <Suspense fallback={null}>
      <CatalogWorkspace />
    </Suspense>
  );
}

function CatalogWorkspace() {
  const { can } = useSession();

  return (
    <section>
      <PageHeader
        trail="المتجر › استوديو المنتج"
        title="استوديو المنتج"
        actions={
          /* مدخل الإنشاء لمن يملك الصلاحية وحده — زرٌّ يقود إلى رفضٍ ليس مدخلًا. */
          can('products.create') ? (
            <Link className="btn btn-primary" href="/studio/catalog/new">
              منتج جديد
            </Link>
          ) : null
        }
      />

      <PageTabs tabs={TABS} can={can} label="أقسام الكتالوج" scope="tabs:catalog" />
    </section>
  );
}
