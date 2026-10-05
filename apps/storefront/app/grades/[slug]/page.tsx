/**
 * /grades/[slug] — one grade's archive.
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

  return termArchiveMetadata('grades', slug, sp);
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
  await assertTermRoute('grades', slug);

  return (
    <Suspense fallback={<CatalogSkeleton />}>
      <TermArchive dimension="grades" segment={slug} sp={sp} />
    </Suspense>
  );
}
