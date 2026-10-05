/**
 * PromoBanner — كتلة العرض بصورة يملكها المستند.
 *
 * الغلاف data-URI مضمَّن (متصفّح التحقّق بلا شبكة)، والبؤرة
 * تُبثّ object-position فعليًّا. خليّة placeholder تُظهر سلوك الكانفس وحده:
 * المسار المنشور بلا صورة يُصيِّر لا شيء — وذلك هو الصدق، لا عيب معاينة.
 */

import { PromoBanner } from '@tahdheer/ui';

const COVER = {
  url: 'data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHZpZXdCb3g9IjAgMCAxNjAwIDYwMCIgd2lkdGg9IjE2MDAiIGhlaWdodD0iNjAwIiByb2xlPSJpbWciIGFyaWEtbGFiZWw9Iti52LHYtiDZhtmH2KfZitipINin2YTZgdi12YQg4oCUINiu2YTZgdmK2Kkg2KfZhNit2YXZhNipIj48ZGVmcz48bGluZWFyR3JhZGllbnQgaWQ9ImciIHgxPSIwIiB5MT0iMCIgeDI9IjEiIHkyPSIxIj48c3RvcCBvZmZzZXQ9IjAiIHN0b3AtY29sb3I9IiMxMjNkMmYiLz48c3RvcCBvZmZzZXQ9IjEiIHN0b3AtY29sb3I9IiMxZTczNTUiLz48L2xpbmVhckdyYWRpZW50PjwvZGVmcz48cmVjdCB3aWR0aD0iMTYwMCIgaGVpZ2h0PSI2MDAiIGZpbGw9InVybCgjZykiLz48Y2lyY2xlIGN4PSIxMzgwIiBjeT0iMTIwIiByPSIxODAiIGZpbGw9IiNkOGI1NmEiIG9wYWNpdHk9IjAuMTgiLz48Y2lyY2xlIGN4PSIyMjAiIGN5PSI1MDAiIHI9IjE0MCIgZmlsbD0iI2ZmZmZmZiIgb3BhY2l0eT0iMC4wNiIvPjxnIGZvbnQtZmFtaWx5PSJzYW5zLXNlcmlmIiBkaXJlY3Rpb249InJ0bCIgdGV4dC1hbmNob3I9InN0YXJ0Ij48dGV4dCB4PSIxNDgwIiB5PSIzMDAiIGZvbnQtc2l6ZT0iODgiIGZvbnQtd2VpZ2h0PSI5MDAiIGZpbGw9IiNmZmZmZmYiIG9wYWNpdHk9IjAuMTQiPti52LHYtiDZhtmH2KfZitipINin2YTZgdi12YQ8L3RleHQ+PC9nPjwvc3ZnPg==',
  alt: 'خلفية حملة عرض نهاية الفصل',
  w: 1600,
  h: 600,
};

/** الشكل الكامل: صورة + تظليل soft + عنوان ونص وزرّ — محاذاة البداية. */
export function Default() {
  return (
    <div dir="rtl">
    <PromoBanner
      settings={{
        image: COVER,
        heading: 'عرض نهاية الفصل',
        text: 'خصومات على حِزم التحاضير حتى نهاية الأسبوع.',
        ctaLabel: 'تسوّق العرض',
        ctaHref: '/offers',
        height: 'md',
        overlay: 'soft',
        align: 'start',
      }}
    />
    </div>
  );
}

/** تظليل strong ومحاذاة المنتصف وارتفاع lg — لافتة بطولية. */
export function CenteredStrong() {
  return (
    <div dir="rtl">
    <PromoBanner
      settings={{
        image: { ...COVER, focalX: 80, focalY: 30 },
        heading: 'حقيبة المعلّم الشاملة',
        text: 'كل ما تحتاجه للفصل الدراسي في حزمة واحدة.',
        ctaLabel: 'اكتشف الحقيبة',
        ctaHref: '/bundles',
        height: 'lg',
        overlay: 'strong',
        align: 'center',
      }}
    />
    </div>
  );
}

/** ارتفاع sm بلا زرّ — شريط إعلان رفيع (ctaLabel فارغ = لا زرّ). */
export function SlimNoCta() {
  return (
    <div dir="rtl">
    <PromoBanner
      settings={{
        image: { ...COVER, focalY: 70 },
        heading: 'شحن رقمي فوري بعد الدفع',
        text: '',
        ctaLabel: '',
        ctaHref: '',
        height: 'sm',
        overlay: 'soft',
        align: 'start',
      }}
    />
    </div>
  );
}

/**
 * بلا صورة + placeholder — ما يراه المحرّر في الكانفس فقط: عنصر نائب
 * موسوم. الزائر في هذه الحالة لا يرى شيئًا إطلاقًا (المكوّن يعيد null).
 */
export function CanvasPlaceholder() {
  return (
    <div dir="rtl">
    <PromoBanner
      settings={{ image: null, heading: 'كتلة عرض بلا صورة بعد', text: '', ctaLabel: '', ctaHref: '', height: 'md', overlay: 'none', align: 'start' }}
      placeholder
    />
    </div>
  );
}
