/**
 * /stages — the stage hub («المراحل التعليمية» in the §3.2 route matrix).
 *
 * The hub the header and the homepage rail both point at. Its body — and the
 * bodies of the other four dimension hubs — come from the shared dimension
 * table in `app/(catalog)/_components/dimensions.ts`.
 */
import type { Metadata } from 'next';
import { TermHub, termHubMetadata } from '../../(catalog)/_components/TermHub';

export function generateMetadata(): Promise<Metadata> {
  return termHubMetadata('stages');
}

export default function Page() {
  return <TermHub dimension="stages" />;
}
