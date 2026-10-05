'use client';
// UNIT: PAGE-MEDIA
// FILE: page.tsx
// ROUTE: /media
// STYLE: none
// PARENT: none
// USES: LIBRARY-PANEL, OPTIMIZE-PANEL, PAGE-TABS, RESOURCE
// API: none
// MAP: docs/PROJECT_MAP.md

/**
 * الوسائط — سطحٌ واحد بتبويبين.
 *
 * «تحسين الصور» كانت وجهةً في الشريط لسطحٍ **بلا نقطة نهايةٍ واحدة في
 * الخادم** — الصفحة نفسها تقول ذلك. وسطرٌ في الشريط لشيءٍ لا يعمل يُعلّم
 * المشغّل ألّا يثق بالشريط.
 */

import { Suspense } from 'react';
import { PageHeader } from '../../../components/resource';
import { PageTabs, type PageTab } from '../../../components/ui/page-tabs';
import { MediaLibraryPanel } from './library-panel';
import { ImageOpsPanel } from './optimize/optimize-panel';

const TABS: readonly PageTab[] = [
  { id: 'library', label: 'المكتبة', render: () => <MediaLibraryPanel /> },
  { id: 'optimize', label: 'تحسين الصور', render: () => <ImageOpsPanel /> },
];

export default function MediaWorkspacePage() {
  return (
    <Suspense fallback={null}>
      <MediaWorkspace />
    </Suspense>
  );
}

function MediaWorkspace() {
  return (
    <section>
      <PageHeader trail="الوسائط" title="مكتبة الوسائط" />
      <PageTabs tabs={TABS} label="أقسام الوسائط" scope="tabs:media" />
    </section>
  );
}
