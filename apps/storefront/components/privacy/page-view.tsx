'use client';
/**
 * باعث `page_view` — الحدث الذي بلا سطرٍ واحدٍ منه لا يوجد قياس.
 *
 * ─── لماذا مكوّنٌ لا استدعاءٌ في كلّ صفحة ───
 *
 * صفحات المتجر عشرات، ومعظمها مكوّنات خادم. ووضعُ نداءٍ في كلٍّ منها يعني
 * أنّ صفحةً جديدة تُضاف بلا قياسٍ ولا يلاحظ أحد — وهو بالضبط ما يجعل تقرير
 * «أكثر الصفحات زيارة» يكذب بصمت. مكوّنٌ واحد في التخطيط يقيس كلّ ما يُصيَّر
 * تحته، اليوم وبعد سنة.
 *
 * ─── وكيف يُعرف انتقال المسار ───
 *
 * التنقّل في App Router لا يُعيد تحميل الصفحة، فلا `load` ثانٍ. `usePathname`
 * هو ما يتغيّر — والمُرجِع يُقاس على المسار وحده بلا وسائط استعلام، فبحثٌ
 * فيه اسمُ المستخدم لا يصل الجدول (والخادم يقصّها أيضًا: طبقتان لأنّ الأولى
 * قد تُنسى في نداءٍ آخر).
 */
import { usePathname } from 'next/navigation';
import { useEffect, useRef } from 'react';
import { externalReferrer, redactPath } from '../../lib/private-paths';
import { track } from '../../lib/telemetry';

export function PageViewEmitter() {
  const pathname = usePathname();
  const last = useRef<string | null>(null);

  useEffect(() => {
    if (pathname === null || last.current === pathname) return;

    /*
     * المُرجِع من داخل الموقع لا يُرسل: الخادم يختزله إلى مضيفٍ على أيّ حال،
     * ومضيفُنا نفسه ليس مصدر زيارة — إرسالُه يجعل «مصادر الزيارات» صفحةَ
     * المتجر نفسها في أعلى القائمة. وقد يكون صفحةَ طلبٍ بمفتاحها. ومن موقعٍ
     * آخر يُرسل أصلُه وحده، فهو كلّ ما يحفظه الخادم.
     */
    const referrer =
      last.current === null && typeof document !== 'undefined'
        ? externalReferrer(document.referrer, window.location.origin)
        : undefined;

    last.current = pathname;
    // `/orders/{token}` يُقاس صفحةً لا مفتاحًا: `/orders/[token]`.
    track({ type: 'page_view', path: redactPath(pathname), referrer });
  }, [pathname]);

  return null;
}
