// UNIT: LAYOUT-HOME
// FILE: layout.tsx
// ROUTE: /
// STYLE: globals.css, tokens.css
// PARENT: none
// USES: ADMIN-SESSION, ADMIN-SHELL, LIB-I18N, LOCALE, PAGE-HEAD, QUERY-PROVIDER +1
// API: none
// API-VIA: ADMIN-SESSION, PAGE-HEAD
// MAP: docs/PROJECT_MAP.md

import type { Metadata } from 'next';
import { cookies } from 'next/headers';
import { Tajawal } from 'next/font/google';
import type { ReactNode } from 'react';
import '@tahdheer/design-system/tokens.css';
import AdminShell from '../components/admin-shell';
import { AdminSessionProvider } from '../components/admin-session';
import { LocaleProvider } from '../components/locale';
import { AdminQueryProvider } from '../components/query-provider';
import { PageHeadProvider } from '../components/page-head';
import { StoreUrlProvider } from '../components/store-url';
import { NuqsAdapter } from 'nuqs/adapters/next/app';
import { DEFAULT_LOCALE, dirOf, isLocale, LOCALE_COOKIE } from '../lib/i18n';
import { PREPAINT_SCRIPT } from '../lib/appearance';
import './globals.css';

/**
 * اللوحة كانت تُعلن font-family:'Tajawal' في globals.css ولا تحمّل الخطّ
 * إطلاقًا — لا next/font ولا @font-face ولا رابط. تحقّقنا من الإنتاج الحيّ:
 * صفر مرجع خطّ في HTML المُقدَّم. فكانت تُصيَّر بخطّ النظام، و1251 إعلان وزن
 * فيها بلا أثر حقيقي.
 *
 * الأوزان هنا هي المستعملة فعلًا في أنماط اللوحة (400/500/700/800/900).
 * الوزن 600 مستعمل 39 مرّة و«Tajawal» لا يملكه أصلًا — تحقّقنا وزنًا وزنًا من
 * المزوّد — فيُقنَص إلى 700 كما يفعل FontCatalog على الخادم.
 */
const tajawal = Tajawal({
  weight: ['400', '500', '700', '800', '900'],
  /*
   * `latin` مع `arabic`: اللوحة صارت ثنائية اللغة، وبالمجموعة العربية وحدها
   * كان النصّ الإنجليزي يسقط إلى خطّ النظام — فتختلف الواجهة عن نفسها بحسب
   * اللغة، وهو انكسار هوية لا تفصيل.
   */
  subsets: ['arabic', 'latin'],
  display: 'swap',
  variable: '--font-tajawal',
  fallback: ['SF Arabic', 'Segoe UI', 'Tahoma', 'Arial', 'sans-serif'],
});

export const metadata: Metadata = {
  title: 'التحاضير الحديثة — لوحة التشغيل',
  robots: { index: false, follow: false },
};

/**
 * يُقرأ وقت الطلب لا وقت البناء، فتصلح الصورة نفسها لكل بيئة. القيمة الفارغة
 * تُخفي الرابط بدل أن تولّد عنوانًا مُخمَّنًا يقود إلى 404.
 */
function storeUrl(): string | null {
  const raw = process.env.STOREFRONT_URL?.trim();
  if (!raw) return null;
  try {
    const url = new URL(raw);
    return url.protocol === 'https:' || url.protocol === 'http:' ? url.origin : null;
  } catch {
    return null;
  }
}

/**
 * اللغة تُقرأ هنا — في الخادم — لا في العميل.
 *
 * `lang` و`dir` يجب أن يكونا صحيحين في **أوّل بايت**: قراءةٌ بعد الترطيب
 * تعني صفحةً تُرسم RTL ثمّ تقفز إلى LTR أمام العين، وتعني مستندًا يعلن
 * `lang="ar"` بينما يعرض إنجليزية — فيخطئ قارئ الشاشة وتشكيل النصّ معًا.
 */
export default async function RootLayout({ children }: { children: ReactNode }) {
  const jar = await cookies();
  const raw = jar.get(LOCALE_COOKIE)?.value;
  const locale = isLocale(raw) ? raw : DEFAULT_LOCALE;

  return (
    <html lang={locale} dir={dirOf(locale)} className={tajawal.variable}>
      <body suppressHydrationWarning>
        {/*
          ═══ سمة اللوحة قبل أوّل بكسل ═══

          كانت السمة تُكتب في `useEffect` داخل `AdminShell` — أي **بعد** أوّل
          رسم. فمن اختار الفاتح كان يرى ومضةً داكنة في كلّ تنقّل صفحةٍ كامل،
          والخادم لا يعرف تخزين المتصفّح فيرسل الداكن دائمًا.

          وهذا السكربت هو الموضع الوحيد الذي يسبق الرسم: أوّل ابنٍ لـ`<body>`
          (فالعنصر موجودٌ حين يعمل) وغيرُ مؤجَّل، فيضبط السمة قبل أن يلوّن
          المتصفّح شيئًا. و`useEffect` يبقى: يكتب القيمة نفسها بعد الإماهة
          ويتابع تبديل المستعمل.

          `suppressHydrationWarning` على `<body>` لأنّ السكربت يضيف سمةً لم
          يُصيّرها الخادم — وهو الفرق المقصود بعينه.

          ولا فشلَ صامت: التخزين قد يكون محجوبًا، فكلّ شيءٍ داخل `try`
          والافتراضيّ الداكن هو ما ترسمه الورقة أصلًا. ويضبط السكربت نفسه
          الكثافة والمظهر (`data-accent` · `data-radius` · `data-fx-*`) —
          فلا يومض زجاجٌ أطفأه المستعمل ولا لونٌ غيّره.
        */}
        <script
          /* sanitized: constant literal from lib/appearance.ts, no interpolated or user-controlled input */
          dangerouslySetInnerHTML={{ __html: PREPAINT_SCRIPT }}
        />
        {/* حالة المرشّحات في العنوان لا في الذاكرة: رابطٌ يُشارَك يعيد المشهد نفسه. */}
        <NuqsAdapter>
        <AdminQueryProvider>
        <LocaleProvider locale={locale}>
          <StoreUrlProvider value={storeUrl()}>
            <AdminSessionProvider>
              <PageHeadProvider>
              <AdminShell>{children}</AdminShell>
            </PageHeadProvider>
            </AdminSessionProvider>
          </StoreUrlProvider>
        </LocaleProvider>
        </AdminQueryProvider>
        </NuqsAdapter>
        {/*
          * فاحص الواجهة: أداة تطوير لا ميزة منتَج. الشرط يُقيَّم في الخادم —
          * لا `NEXT_PUBLIC_` لأنّه يتسرّب إلى حزمة المتصفّح — فلا يُصيَّر
          * المِعلاق في الإنتاج أصلًا، ولا تُشحن لوحته ولا خريطتها إلى مستخدم.
          */}
              </body>
    </html>
  );
}
