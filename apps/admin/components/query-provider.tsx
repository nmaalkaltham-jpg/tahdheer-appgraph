'use client';
// UNIT: QUERY-PROVIDER
// FILE: query-provider.tsx
// STYLE: none
// PARENT: LAYOUT-HOME
// USES: none
// API: none
// MAP: docs/PROJECT_MAP.md

/**
 * مزوّد ذاكرة الخادم — طبقة حالة الخادم الوحيدة في اللوحة.
 *
 * لماذا وُجد: ‎useResource‎ كان ‎useState + useEffect + fetch‎ بلا ذاكرة ولا
 * إزالة تكرار. النتيجة مقيسة لا مفترضة: مسار ‎/admin/dashboard‎ وحده يُطلب
 * من سبعة ملفّات مستقلّة، وشاشة «نظام النمو» تفتح عشرة موارد متوازية، فتُعاد
 * الطلبات نفسها في كلّ تنقّل. مفتاح الذاكرة يطوي ذلك كلّه في طلب واحد.
 *
 * ‎staleTime: 30s‎ لا صفر: بيانات اللوحة تقاريرُ لا مؤشّرات لحظيّة، والتنقّل
 * بين تبويبين يقرأ المسار نفسه مرّتين في ثوانٍ. ثلاثون ثانية تمنع ذلك ولا
 * تُبقي رقمًا قديمًا على الشاشة بعد حفظ.
 *
 * ‎retry: false‎ عمدًا: السلوك السابق لم يكن يعيد المحاولة، وإدخال إعادة
 * تلقائيّة هنا كان سيغيّر توقيت ظهور الخطأ في مئة موضع دفعةً واحدة. الإعادة
 * قرارٌ لكلّ نداء، لا افتراضٌ عامّ.
 *
 * ‎refetchOnWindowFocus: false‎ لأنّ اللوحة تُترك مفتوحة في تبويب طوال اليوم:
 * كلّ عودة إليها كانت ستُطلق موجة طلبات لا يطلبها أحد.
 *
 * العميل يُنشأ داخل ‎useState‎ لا في وحدة النمط: وحدةٌ واحدة مشتركة بين
 * الطلبات على الخادم تسرّب ذاكرة مستخدم إلى آخر.
 */

import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { useState, type ReactNode } from 'react';

export function AdminQueryProvider({ children }: { children: ReactNode }) {
  const [client] = useState(
    () =>
      new QueryClient({
        defaultOptions: {
          queries: {
            staleTime: 30_000,
            gcTime: 5 * 60_000,
            retry: false,
            refetchOnWindowFocus: false,
          },
        },
      }),
  );

  return <QueryClientProvider client={client}>{children}</QueryClientProvider>;
}
