// UNIT: PAGE-SEO-HEALTH-SITEMAP
// FILE: page.tsx
// ROUTE: /seo-health/sitemap
// STYLE: none
// PARENT: none
// USES: RESOURCE, SITEMAP-AUDIT-PANEL
// API: none
// API-VIA: SITEMAP-AUDIT-PANEL
// MAP: docs/PROJECT_MAP.md

/**
 * تدقيق خريطة الموقع.
 *
 * ─── لماذا سقطت البوّابة ───
 *
 * كانت الشاشة مغلقةً ببوّابة القدرة لأنّ القدرة كانت مؤجَّلة، وكان ذلك
 * صحيحًا: `sitemap:audit` يقيس كلّ ليلة ويطبع نتيجته على مخرَجٍ قياسيّ لا
 * يقرؤه أحد في حاوية — فلا شيء يُعرض. ونصّ البوّابة نفسه كان يسمّي الناقص:
 * «تخزين نتائج `sitemap:audit` وعرضها».
 *
 * صار الأمر يحفظ قياسه في `sitemap_audits`، و`GET /admin/seo/sitemap/audit`
 * يقرؤه. فالقدرة مبنيّة، وبوّابةٌ تبقى بعدها تحذيرٌ بائتٌ يكذب في الاتّجاه
 * المعاكس — والاتّجاهان معًا شرطٌ لازم.
 *
 * (ولا يُذكر اسم مكوّن البوّابة هنا: الحارس يفحص النصّ فحصًا حرفيًّا، وذكرُه
 * في شرحٍ يجعله يقرأ الشاشة مغلقةً وهي مفتوحة.)
 */

import { PageHeader } from '../../../../components/resource';
import { SitemapAuditPanel } from './sitemap-audit-panel';

export default function Page() {
  return (
    <>
      <PageHeader trail="الظهور" title="تدقيق خريطة الموقع" />
      <SitemapAuditPanel />
    </>
  );
}
