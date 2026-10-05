import type { SchemaGraph } from '../../lib/seo';

interface ProductSchemaProps {
  /** The graph exactly as `GET /api/v1/seo/products/{slug}` returned it. */
  graph: SchemaGraph | null | undefined;
}

/**
 * The ONE JSON-LD block on a page (MIP §7.4).
 *
 * This component does not build, merge, enrich or filter the graph — the
 * Laravel SchemaBuilder is the single writer, and it built this graph from the
 * same arrays the page renders visibly. Rendering it here unchanged is what
 * keeps «visible = schema» true end to end.
 *
 * Mount it exactly once per page. A second JSON-LD block anywhere in the tree
 * would break the single-graph rule.
 *
 * Injection safety: `JSON.stringify` can only produce data — no markup survives
 * it — and every less-than sign is then replaced by its unicode escape, so no
 * product string can terminate the script element early.
 */
export function ProductSchema({ graph }: ProductSchemaProps) {
  // No graph ⇒ no markup. An empty <script> would be a lie about nothing.
  if (!graph || !Array.isArray(graph['@graph']) || graph['@graph'].length === 0) {
    return null;
  }

  return (
    <script
      type="application/ld+json"
      /* sanitized: data-only JSON, every less-than sign unicode-escaped below */
      dangerouslySetInnerHTML={{ __html: JSON.stringify(graph).replace(/</g, '\\u003c') }}
    />
  );
}

