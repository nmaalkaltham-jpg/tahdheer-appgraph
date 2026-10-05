// UNIT: PAGE-REDIRECTS
// FILE: page.tsx
// ROUTE: /redirects
// STYLE: none
// PARENT: none
// USES: none
// API: none
// MAP: docs/PROJECT_MAP.md

/**
 * المسار القديم يبقى يعمل — ويحوّل إلى تبويبه في «الظهور والبحث».
 *
 * الحذف كان سيكسر كلّ رابطٍ محفوظ ومفضّلةٍ وإشارةٍ في وثيقة. و`redirect`
 * (٣٠٧) لا `permanentRedirect` (٣٠٨): الدائم يُخزَّن في المتصفّح بلا
 * انتهاء، فلو عاد سطحٌ إلى هذا المسار يومًا بقيت الأجهزة القديمة محوَّلةً
 * عنه بلا سبيلٍ لإبطال ذلك من الخادم.
 */

import { redirect } from 'next/navigation';

export default function LegacyRoute(): never {
  redirect('/seo?t=redirects');
}
