// UNIT: PAGE-STUDIO-CATALOG-NEW
// FILE: page.tsx
// ROUTE: /studio/catalog/new
// STYLE: none
// PARENT: none
// USES: NEW-STUDIO
// API: none
// API-VIA: NEW-STUDIO
// MAP: docs/PROJECT_MAP.md

/**
 * /studio/catalog/new — استوديو المنتج في وضع الإنشاء.
 *
 * المسار يبقى كما هو فلا يُكسر رابطٌ محفوظ ولا زرٌّ في القائمة؛ وما تغيّر
 * أنّ خلفه صار **الاستوديو نفسه** لا نموذجًا ثانيًا يقول الشيء نفسه بكلماتٍ
 * أخرى. راجع `new-studio.tsx` لشرح لماذا كان السطحان عيبًا.
 */

import NewProductStudio from './new-studio';

export default function NewProductPage() {
  return <NewProductStudio />;
}
