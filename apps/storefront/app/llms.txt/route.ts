import { apiGet } from '../../lib/api';
import { BRAND, siteOrigin } from '../../lib/seo';

/** GET /api/v1/llms-data — the live catalog index (MIP §7.3.16). */
interface LlmsDataDto {
  brand: string;
  items: {
    title: string;
    url: string;
    stage: string | null;
    subject: string | null;
    semester: string | null;
    oneLiner: string | null;
  }[];
  coverage: { published: number; included: number; pct: number | null; withOneLiner: number };
}

/**
 * `/llms.txt` — a Markdown catalog index for language models.
 *
 * Rules this route keeps (MIP §7.3.16 / §7.10 LLMO):
 *   · Built from the LIVE catalog through the same IndexabilityPolicy that
 *     governs the sitemap, so cart/checkout/account/paid files can never
 *     appear here either.
 *   · `X-Robots-Tag: noindex` — this file is for machines to READ, not for
 *     search engines to index.
 *   · NOT A CLOAKING SURFACE: every line restates facts already visible on the
 *     public pages. There is no machine-only content, and a product with no
 *     one-liner is simply listed without one.
 *   · API unreachable ⇒ an explicit NOT_CONFIGURED body, never a fabricated
 *     catalog.
 */
export const dynamic = 'force-dynamic';

export async function GET(): Promise<Response> {
  const { data } = await apiGet<LlmsDataDto>('/api/v1/llms-data');

  return new Response(data ? renderCatalog(data) : renderUnavailable(), {
    headers: {
      'Content-Type': 'text/plain; charset=utf-8',
      'X-Robots-Tag': 'noindex',
      'Cache-Control': 'public, max-age=0, s-maxage=3600',
    },
  });
}

function renderCatalog(data: LlmsDataDto): string {
  const origin = siteOrigin();
  const lines = [
    `# ${data.brand || BRAND}`,
    '',
    'فهرس الكتالوج المنشور — موارد تعليمية رقمية للمنهج السعودي.',
    'كل سطر هنا يعيد ذكر معلومة ظاهرة على الصفحة العامة نفسها؛ لا محتوى مخصص للآلات.',
    '',
  ];

  if (origin) lines.push(`الموقع: ${origin}`, '');

  lines.push(
    '## التغطية',
    '',
    `- منتجات منشورة: ${data.coverage.published}`,
    `- مُدرَجة في هذا الفهرس: ${data.coverage.included}`,
    // «غير مقيس» rather than a flattering default when there is nothing to measure.
    `- نسبة التغطية: ${data.coverage.pct === null ? 'غير مقيس' : `${data.coverage.pct}%`}`,
    `- تحمل وصفًا مختصرًا: ${data.coverage.withOneLiner}`,
    '',
    '## المنتجات',
    '',
  );

  if (data.items.length === 0) {
    // Explicit empty state — an empty catalog says so.
    lines.push('لا توجد منتجات منشورة حاليًا.');
    return `${lines.join('\n')}\n`;
  }

  for (const item of data.items) {
    // Curriculum facts are omitted when absent rather than padded with «غير محدد».
    const facts = [item.stage, item.subject, item.semester].filter(Boolean).join(' · ');
    lines.push(`- [${item.title}](${item.url})`);
    if (facts) lines.push(`  - المنهج: ${facts}`);
    if (item.oneLiner) lines.push(`  - ${item.oneLiner}`);
  }

  return `${lines.join('\n')}\n`;
}

function renderUnavailable(): string {
  return [
    `# ${BRAND}`,
    '',
    'NOT_CONFIGURED — تعذّر الوصول إلى واجهة الكتالوج، فلا فهرس متاح الآن.',
    'لا تُعرض هنا أي بيانات مُختلَقة بديلة.',
    '',
  ].join('\n');
}
