// UNIT: PAGE-STUDIO-CATALOG-ID
// FILE: page.tsx
// ROUTE: /studio/catalog/[id]
// STYLE: none
// PARENT: none
// USES: STUDIO-CLIENT, STUDIO-DATA
// API: none
// API-VIA: STUDIO-CLIENT
// MAP: docs/PROJECT_MAP.md

/**
 * /studio/catalog/{id} — الخطوة مُعنوَنة في الرابط (?step=…) فتُشارَك وتُحفظ
 * في المفضّلة. القراءة تتم هنا على الخادم، ثم يحتفظ العميل بالخطوة في حالته
 * ويحدّث الرابط بلا تنقّل — حتى لا يفقد المشغّل تحريرًا غير محفوظ.
 */

import { toStepKey } from '../studio-data';
import StudioClient from './studio-client';

export default async function CatalogStudioPage({
  params,
  searchParams,
}: {
  params: Promise<{ id: string }>;
  searchParams: Promise<Record<string, string | string[] | undefined>>;
}) {
  const { id } = await params;
  const query = await searchParams;
  const raw = query.step;

  return <StudioClient productId={id} initialStep={toStepKey(Array.isArray(raw) ? raw[0] : raw)} />;
}
