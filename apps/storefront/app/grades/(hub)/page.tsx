/**
 * /grades — the grade hub («الصفوف الدراسية» in the §3.2 route matrix).
 *
 * Hub and archive both render from the shared dimension table — see
 * `app/(catalog)/_components/dimensions.ts`.
 */
import type { Metadata } from 'next';
import { TermHub, termHubMetadata } from '../../(catalog)/_components/TermHub';

export function generateMetadata(): Promise<Metadata> {
  return termHubMetadata('grades');
}

export default function Page() {
  return <TermHub dimension="grades" />;
}
