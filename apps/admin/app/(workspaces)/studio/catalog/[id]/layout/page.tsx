// UNIT: PAGE-STUDIO-CATALOG-ID-LAYOUT
// FILE: page.tsx
// ROUTE: /studio/catalog/[id]/layout
// STYLE: none
// PARENT: none
// USES: COMPOSER-CLIENT
// API: none
// API-VIA: COMPOSER-CLIENT
// MAP: docs/PROJECT_MAP.md

/**
 * /studio/catalog/{id}/layout — منشئ صفحة المنتج.
 * القراءة للمنتج فقط؛ لا نقطة نهاية تحفظ تخطيطًا، والشاشة تقول ذلك صراحةً.
 */

import ComposerClient from './composer-client';

export default async function ProductLayoutPage({ params }: { params: Promise<{ id: string }> }) {
  const { id } = await params;

  return <ComposerClient productId={id} />;
}
