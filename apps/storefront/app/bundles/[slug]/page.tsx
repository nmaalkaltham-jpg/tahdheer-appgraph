/**
 * /bundles/[slug] — a single bundle.
 *
 * TODAY EVERY SLUG IS A REAL 404. There is no bundle endpoint, so
 * `fetchBundle` returns null and this page calls `notFound()` — a genuine 404
 * status, not a shell page pretending a bundle exists.
 *
 * WIRING IS A DATA SWAP: the moment `fetchBundle` (see _lib/bundles-api.ts)
 * returns a `BundleDto`, `BundleDetail` renders the full «Tahdheer - Bundle»
 * page — contents list, owned-item exclusion, price card, why-bundle — with no
 * change to this file, the component or its stylesheet.
 *
 * `generateMetadata` reads the SAME fetcher, so the title can never describe a
 * bundle the body does not render.
 */
import type { Metadata } from 'next';
import { notFound } from 'next/navigation';
import { routes } from '../../../lib/routes';
import { BundleDetail } from '../_components/BundleDetail';
import { fetchBundle } from '../_lib/bundles-api';

interface PageProps {
  params: Promise<{ slug: string }>;
}

export async function generateMetadata({ params }: PageProps): Promise<Metadata> {
  const { slug } = await params;
  const bundle = await fetchBundle(slug);
  if (!bundle) return {};
  return {
    title: bundle.title,
    ...(bundle.intro ? { description: bundle.intro } : {}),
    alternates: { canonical: routes.bundle(bundle.slug) },
  };
}

export default async function BundlePage({ params }: PageProps) {
  const { slug } = await params;
  const bundle = await fetchBundle(slug);
  if (!bundle) notFound();

  return <BundleDetail bundle={bundle} />;
}
