'use client';
// UNIT: ERROR-HOME
// FILE: error.tsx
// STYLE: none
// PARENT: none
// USES: RESOURCE
// API: none
// MAP: docs/PROJECT_MAP.md

/**
 * حدّ الخطأ للوحة — يلتقط رميات التصيير في صفحات اللوحة كلّها.
 *
 * في جذر `app/` لا داخل `(workspaces)`: `AdminShell` تُصيَّر في
 * `app/layout.tsx:81`، فحدٌّ في الجذر يقع **داخلها** — الشريط الجانبي
 * والترويسة و`dir` باقية — ويشمل `login` و`app/page.tsx` معًا بملفٍّ واحد.
 *
 * حدُّ **رميات التصيير** لا أخطاء الجلب: تلك تلتقطها React Query داخل
 * `useResource` وتُعرض حالةً صريحة بزرّ إعادة في `ResourceView`
 * (`components/resource.tsx`) ولا تصل إلى هنا. ما يصل هنا رميةٌ في دالّة
 * العرض أو في صفحة خادم.
 *
 * لا يُعرض للمستعمِل نصّ الخطأ ولا `digest` ولا الكومة ولا اسم الصنف: نصّ
 * الرمية خادميّ وقد يحمل مسارًا أو مفتاحًا، وشاشة اللوحة تُصوَّر وتُشارَك.
 * المعرّف إلى `console.error` للمشغّل وحده.
 *
 * الأنماط كلّها من `app/globals.css`: `.empty-state` ٣٠٤ و`.empty-title` ٣١٤
 * و`.btn` ٣٤٣ و`.btn-primary` ٣٦٤ و`.btn-ghost` ٣٧٣. صفر ملفّ أنماط جديد.
 */

import Link from 'next/link';
import { useEffect } from 'react';
import { PageHeader } from '../components/resource';

export default function AdminError({
  error,
  reset,
}: {
  error: Error & { digest?: string };
  reset: () => void;
}) {
  useEffect(() => {
    // للمشغّل لا للمستعمِل: المعرّف وحده يربط هذه الشاشة بسطر سجلّ الخادم.
    console.error('admin render error', error.digest ?? '(no digest)');
  }, [error]);

  return (
    <section>
      {/* الرأس يُنشر هنا وإلّا ورثت شاشةُ الخطأ عنوان الصفحة التي انكسرت. */}
      <PageHeader trail="خطأ" title="تعذّر عرض الشاشة" />
      <div className="empty-state" role="alert">
        <p className="empty-title">تعذّر عرض هذه الشاشة</p>
        <p>
          انكسر تصيير الصفحة قبل أن يكتمل. تفصيل السبب في سجلّ الخادم وفي وحدة
          تحكّم المتصفّح — ولا يُعرض هنا.
        </p>
        <p>
          إن كنت في منتصف حفظ فتحقّق من وصول التغيير قبل إعادة إدخاله: الانكسار
          قد يقع بعد أن يصل الطلب.
        </p>
        <p>
          <button type="button" className="btn btn-primary" onClick={reset}>
            أعِد المحاولة
          </button>{' '}
          <Link className="btn btn-ghost" href="/">
            العودة إلى النظرة العامة
          </Link>
        </p>
      </div>
    </section>
  );
}
