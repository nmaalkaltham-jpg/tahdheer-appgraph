/**
 * /stages/[slug] — one stage's archive.
 *
 * The stage was the last dimension still rendering its own copy of the
 * «Tahdheer - Category» archive; it now renders from the shared `TermArchive`
 * like grades, subjects, semesters and kinds, so one archive body serves all
 * five. What the route gains by that: `?sort=` and `?page=` are validated
 * before they reach the API (an unknown sort no longer degrades the page to
 * the retry card), the grade rail joins the other three dimensions, the facet
 * rail is dropped instead of drawn empty when the taxonomy ships nothing, and
 * the grid gets the real add-to-cart island.
 */
import type { Metadata } from 'next';
import { Suspense } from 'react';
import { CatalogSkeleton } from '../../(catalog)/_components/CatalogSkeleton';
import {
  assertTermRoute,
  TermArchive,
  termArchiveMetadata,
  type ArchiveSearch,
} from '../../(catalog)/_components/TermArchive';

type Params = Promise<{ slug: string }>;
type Search = Promise<ArchiveSearch>;

export async function generateMetadata({
  params,
  searchParams,
}: {
  params: Params;
  searchParams: Search;
}): Promise<Metadata> {
  const [{ slug }, sp] = await Promise.all([params, searchParams]);

  return termArchiveMetadata('stages', slug, sp);
}

export default async function Page({
  params,
  searchParams,
}: {
  params: Params;
  searchParams: Search;
}) {
  const [{ slug }, sp] = await Promise.all([params, searchParams]);
  /*
   * 404 حقيقيّ: يُقرَّر هنا قبل أوّل بايت. لا `loading.tsx` لهذا المقطع —
   * حدُّ الانتظار هو `Suspense` أدناه، أي بعد قرار الوجود لا قبله، فلا تُبثّ
   * صفحة «لم نجد» بحالة 200.
   */
  await assertTermRoute('stages', slug);

  return (
    <Suspense fallback={<CatalogSkeleton />}>
      <TermArchive dimension="stages" segment={slug} sp={sp} />
    </Suspense>
  );
}
