/**
 * ProductCollection — مجموعة المنتجات القابلة للضبط.
 *
 * قانون الحزمة: «التخطيط خاصّية لا ملفّ». المكوّن الواحد يغطّي خمسة تخطيطات
 * وأربع حالات، فلا يُنسَخ إلى خمسة مكوّنات تتباعد مع الوقت.
 *
 * `COLLECTION_DEFAULTS` هو الأساس في كل خليّة — لا كائن إعدادات مكتوب يدويًّا
 * ينحرف عن الافتراضات الحقيقية.
 *
 * الحالات الأربع تُمرَّر صراحةً (`state`) ولا تُستنتج من طول القائمة: قائمة
 * فارغة أثناء التحميل ليست «لا نتائج»، والخلط بينهما يُظهر «لا نتائج» لثانية
 * في كل تصفّح.
 *
 * القدرات الثلاث التي كانت معطّلة **أُصلحت في `7b86d86` (WP-R)** فصارت
 * معروضة هنا: `layout="list"` (صفّ أفقيّ حقيقي)، و`state="empty"`
 * و`state="error"` برسالتيهما. وضوابط `parts` الخمسة تعمل كلّها — الصورة
 * والشارة والزرّ بحذف البيانات، والعنوان والسعر بـ`display:none`.
 *
 * الحدّ الوحيد الباقي: `parts.img: false` لا يُنتج قائمة نصّية بل بطاقات
 * بصناديق وسائط فارغة — وهو سلوك `ProductCard` الصحيح، فلا يُعرض كقصّة.
 */

import { ProductCollection, COLLECTION_DEFAULTS, type ProductCardProps } from '@tahdheer/ui';

/** منتجات إنتاجية حقيقية من ‎GET /api/v1/products‎. */
const ITEMS: ProductCardProps[] = [
  {
    id: 1, sku: 'DEMO-MATH-G4-S1',
    title: 'تحضير الرياضيات — الصف الرابع الابتدائي — الفصل الأول',
    slug: 'math-g4-s1', url: '/products/math-g4-s1',
    categoryPath: ['ابتدائي', 'الرياضيات'], format: 'Word',
    priceMinor: 2400, regularMinor: 4000, discountPct: 40, onSale: true, editable: true,
    image: { url: `data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHZpZXdCb3g9IjAgMCAxMjAwIDkwMCIgd2lkdGg9IjEyMDAiIGhlaWdodD0iOTAwIiByb2xlPSJpbWciIGFyaWEtbGFiZWw9Itin2YTYsdmK2KfYttmK2KfYqiDigJQg2KfZhNi12YEg2KfZhNix2KfYqNi5INin2YTYp9io2KrYr9in2KbZiiI+CiAgPGRlZnM+PGxpbmVhckdyYWRpZW50IGlkPSJnIiB4MT0iMCIgeTE9IjAiIHgyPSIxIiB5Mj0iMSI+PHN0b3Agb2Zmc2V0PSIwIiBzdG9wLWNvbG9yPSIjZWFmNWVmIi8+PHN0b3Agb2Zmc2V0PSIxIiBzdG9wLWNvbG9yPSIjZmZmZmZmIi8+PC9saW5lYXJHcmFkaWVudD48L2RlZnM+CiAgPHJlY3Qgd2lkdGg9IjEyMDAiIGhlaWdodD0iOTAwIiBmaWxsPSJ1cmwoI2cpIi8+CiAgPHJlY3QgeD0iMCIgeT0iMCIgd2lkdGg9IjEyMDAiIGhlaWdodD0iMTQiIGZpbGw9IiMxZTczNTUiLz4KICA8ZyBmb250LWZhbWlseT0ic2Fucy1zZXJpZiIgZGlyZWN0aW9uPSJydGwiIHRleHQtYW5jaG9yPSJzdGFydCI+CiAgICA8dGV4dCB4PSIxMDkwIiB5PSIyMzAiIGZvbnQtc2l6ZT0iNDIiIGZvbnQtd2VpZ2h0PSI3MDAiIGZpbGw9IiMxZTczNTUiIG9wYWNpdHk9IjAuNzUiPtiq2K3YttmK2LE8L3RleHQ+CiAgICA8dGV4dCB4PSIxMDkwIiB5PSIzNjAiIGZvbnQtc2l6ZT0iODAiIGZvbnQtd2VpZ2h0PSI5MDAiIGZpbGw9IiMxNDI2MWQiPtin2YTYsdmK2KfYttmK2KfYqjwvdGV4dD4KICAgIDx0ZXh0IHg9IjEwOTAiIHk9IjQ3MCIgZm9udC1zaXplPSI0NCIgZm9udC13ZWlnaHQ9IjUwMCIgZmlsbD0iIzNiNGQ0MyI+2KfZhNi12YEg2KfZhNix2KfYqNi5INin2YTYp9io2KrYr9in2KbZijwvdGV4dD4KICA8L2c+CiAgPGcgdHJhbnNmb3JtPSJ0cmFuc2xhdGUoMTEwLDY5MCkiPgogICAgPHJlY3QgeD0iMCIgeT0iMCIgcng9IjE0IiB3aWR0aD0iMTkwIiBoZWlnaHQ9IjY0IiBmaWxsPSIjMWU3MzU1Ii8+CiAgICA8dGV4dCB4PSI5NSIgeT0iNDIiIGZvbnQtZmFtaWx5PSJ1aS1tb25vc3BhY2UsIG1vbm9zcGFjZSIgZm9udC1zaXplPSIzMCIgZm9udC13ZWlnaHQ9IjcwMCIgZmlsbD0iI2ZmZmZmZiIgdGV4dC1hbmNob3I9Im1pZGRsZSI+RE9DWDwvdGV4dD4KICA8L2c+CiAgPGNpcmNsZSBjeD0iMTUwIiBjeT0iMjAwIiByPSI3MCIgZmlsbD0iIzFlNzM1NSIgb3BhY2l0eT0iMC4xMCIvPgogIDxjaXJjbGUgY3g9IjI1NSIgY3k9IjMwMCIgcj0iMzgiIGZpbGw9IiMxZTczNTUiIG9wYWNpdHk9IjAuMDciLz4KPC9zdmc+`, alt: 'تحضير الرياضيات', w: 1200, h: 900 },
  },
  {
    id: 2, sku: 'DEMO-SCI-G5-S1',
    title: 'عرض بوربوينت — العلوم — الصف الخامس الابتدائي',
    slug: 'sci-g5-s1', url: '/products/sci-g5-s1',
    categoryPath: ['ابتدائي', 'العلوم'], format: 'PowerPoint',
    priceMinor: 2900, onSale: false, ratingAvg: 4.6, ratingCount: 23,
    image: { url: `data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHZpZXdCb3g9IjAgMCAxMjAwIDkwMCIgd2lkdGg9IjEyMDAiIGhlaWdodD0iOTAwIiByb2xlPSJpbWciIGFyaWEtbGFiZWw9Itin2YTYudmE2YjZhSDigJQg2KfZhNi12YEg2KfZhNiu2KfZhdizINin2YTYp9io2KrYr9in2KbZiiI+CiAgPGRlZnM+PGxpbmVhckdyYWRpZW50IGlkPSJnIiB4MT0iMCIgeTE9IjAiIHgyPSIxIiB5Mj0iMSI+PHN0b3Agb2Zmc2V0PSIwIiBzdG9wLWNvbG9yPSIjZThmMmZjIi8+PHN0b3Agb2Zmc2V0PSIxIiBzdG9wLWNvbG9yPSIjZmZmZmZmIi8+PC9saW5lYXJHcmFkaWVudD48L2RlZnM+CiAgPHJlY3Qgd2lkdGg9IjEyMDAiIGhlaWdodD0iOTAwIiBmaWxsPSJ1cmwoI2cpIi8+CiAgPHJlY3QgeD0iMCIgeT0iMCIgd2lkdGg9IjEyMDAiIGhlaWdodD0iMTQiIGZpbGw9IiMxZDVmOWMiLz4KICA8ZyBmb250LWZhbWlseT0ic2Fucy1zZXJpZiIgZGlyZWN0aW9uPSJydGwiIHRleHQtYW5jaG9yPSJzdGFydCI+CiAgICA8dGV4dCB4PSIxMDkwIiB5PSIyMzAiIGZvbnQtc2l6ZT0iNDIiIGZvbnQtd2VpZ2h0PSI3MDAiIGZpbGw9IiMxZDVmOWMiIG9wYWNpdHk9IjAuNzUiPti52LHYtjwvdGV4dD4KICAgIDx0ZXh0IHg9IjEwOTAiIHk9IjM2MCIgZm9udC1zaXplPSI4MCIgZm9udC13ZWlnaHQ9IjkwMCIgZmlsbD0iIzE0MjYxZCI+2KfZhNi52YTZiNmFPC90ZXh0PgogICAgPHRleHQgeD0iMTA5MCIgeT0iNDcwIiBmb250LXNpemU9IjQ0IiBmb250LXdlaWdodD0iNTAwIiBmaWxsPSIjM2I0ZDQzIj7Yp9mE2LXZgSDYp9mE2K7Yp9mF2LMg2KfZhNin2KjYqtiv2KfYptmKPC90ZXh0PgogIDwvZz4KICA8ZyB0cmFuc2Zvcm09InRyYW5zbGF0ZSgxMTAsNjkwKSI+CiAgICA8cmVjdCB4PSIwIiB5PSIwIiByeD0iMTQiIHdpZHRoPSIxOTAiIGhlaWdodD0iNjQiIGZpbGw9IiMxZDVmOWMiLz4KICAgIDx0ZXh0IHg9Ijk1IiB5PSI0MiIgZm9udC1mYW1pbHk9InVpLW1vbm9zcGFjZSwgbW9ub3NwYWNlIiBmb250LXNpemU9IjMwIiBmb250LXdlaWdodD0iNzAwIiBmaWxsPSIjZmZmZmZmIiB0ZXh0LWFuY2hvcj0ibWlkZGxlIj5QUFRYPC90ZXh0PgogIDwvZz4KICA8Y2lyY2xlIGN4PSIxNTAiIGN5PSIyMDAiIHI9IjcwIiBmaWxsPSIjMWQ1ZjljIiBvcGFjaXR5PSIwLjEwIi8+CiAgPGNpcmNsZSBjeD0iMjU1IiBjeT0iMzAwIiByPSIzOCIgZmlsbD0iIzFkNWY5YyIgb3BhY2l0eT0iMC4wNyIvPgo8L3N2Zz4=`, alt: 'عرض العلوم', w: 1200, h: 900 },
  },
  {
    id: 3, sku: 'DEMO-ARB-G6-S2',
    title: 'اختبارات لغتي — الصف السادس الابتدائي — الفصل الثاني',
    slug: 'arb-g6-s2', url: '/products/arb-g6-s2',
    categoryPath: ['ابتدائي', 'لغتي'], format: 'PDF',
    priceMinor: 1900, onSale: false,
    image: { url: `data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHZpZXdCb3g9IjAgMCAxMjAwIDkwMCIgd2lkdGg9IjEyMDAiIGhlaWdodD0iOTAwIiByb2xlPSJpbWciIGFyaWEtbGFiZWw9ItmE2LrYqtmKIOKAlCDYp9mE2LXZgSDYp9mE2LPYp9iv2LMg2KfZhNin2KjYqtiv2KfYptmKIj4KICA8ZGVmcz48bGluZWFyR3JhZGllbnQgaWQ9ImciIHgxPSIwIiB5MT0iMCIgeDI9IjEiIHkyPSIxIj48c3RvcCBvZmZzZXQ9IjAiIHN0b3AtY29sb3I9IiNmM2VlZmIiLz48c3RvcCBvZmZzZXQ9IjEiIHN0b3AtY29sb3I9IiNmZmZmZmYiLz48L2xpbmVhckdyYWRpZW50PjwvZGVmcz4KICA8cmVjdCB3aWR0aD0iMTIwMCIgaGVpZ2h0PSI5MDAiIGZpbGw9InVybCgjZykiLz4KICA8cmVjdCB4PSIwIiB5PSIwIiB3aWR0aD0iMTIwMCIgaGVpZ2h0PSIxNCIgZmlsbD0iIzZiM2ZhMCIvPgogIDxnIGZvbnQtZmFtaWx5PSJzYW5zLXNlcmlmIiBkaXJlY3Rpb249InJ0bCIgdGV4dC1hbmNob3I9InN0YXJ0Ij4KICAgIDx0ZXh0IHg9IjEwOTAiIHk9IjIzMCIgZm9udC1zaXplPSI0MiIgZm9udC13ZWlnaHQ9IjcwMCIgZmlsbD0iIzZiM2ZhMCIgb3BhY2l0eT0iMC43NSI+2KfYrtiq2KjYp9ixPC90ZXh0PgogICAgPHRleHQgeD0iMTA5MCIgeT0iMzYwIiBmb250LXNpemU9IjgwIiBmb250LXdlaWdodD0iOTAwIiBmaWxsPSIjMTQyNjFkIj7ZhNi62KrZijwvdGV4dD4KICAgIDx0ZXh0IHg9IjEwOTAiIHk9IjQ3MCIgZm9udC1zaXplPSI0NCIgZm9udC13ZWlnaHQ9IjUwMCIgZmlsbD0iIzNiNGQ0MyI+2KfZhNi12YEg2KfZhNiz2KfYr9izINin2YTYp9io2KrYr9in2KbZijwvdGV4dD4KICA8L2c+CiAgPGcgdHJhbnNmb3JtPSJ0cmFuc2xhdGUoMTEwLDY5MCkiPgogICAgPHJlY3QgeD0iMCIgeT0iMCIgcng9IjE0IiB3aWR0aD0iMTkwIiBoZWlnaHQ9IjY0IiBmaWxsPSIjNmIzZmEwIi8+CiAgICA8dGV4dCB4PSI5NSIgeT0iNDIiIGZvbnQtZmFtaWx5PSJ1aS1tb25vc3BhY2UsIG1vbm9zcGFjZSIgZm9udC1zaXplPSIzMCIgZm9udC13ZWlnaHQ9IjcwMCIgZmlsbD0iI2ZmZmZmZiIgdGV4dC1hbmNob3I9Im1pZGRsZSI+UERGPC90ZXh0PgogIDwvZz4KICA8Y2lyY2xlIGN4PSIxNTAiIGN5PSIyMDAiIHI9IjcwIiBmaWxsPSIjNmIzZmEwIiBvcGFjaXR5PSIwLjEwIi8+CiAgPGNpcmNsZSBjeD0iMjU1IiBjeT0iMzAwIiByPSIzOCIgZmlsbD0iIzZiM2ZhMCIgb3BhY2l0eT0iMC4wNyIvPgo8L3N2Zz4=`, alt: 'اختبارات لغتي', w: 1200, h: 900 },
  },
  {
    id: 4, sku: 'DEMO-ENG-G7-S1',
    title: 'أوراق عمل اللغة الإنجليزية — أول متوسط — الفصل الأول',
    slug: 'eng-g7-s1', url: '/products/eng-g7-s1',
    categoryPath: ['متوسط', 'اللغة الإنجليزية'], format: 'PDF',
    priceMinor: 3200, onSale: false,
    image: { url: `data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHZpZXdCb3g9IjAgMCAxMjAwIDkwMCIgd2lkdGg9IjEyMDAiIGhlaWdodD0iOTAwIiByb2xlPSJpbWciIGFyaWEtbGFiZWw9Itin2YTZhNi62Kkg2KfZhNil2YbYrNmE2YrYstmK2Kkg4oCUINin2YTYtdmBINin2YTYo9mI2YQg2KfZhNmF2KrZiNiz2LciPgogIDxkZWZzPjxsaW5lYXJHcmFkaWVudCBpZD0iZyIgeDE9IjAiIHkxPSIwIiB4Mj0iMSIgeTI9IjEiPjxzdG9wIG9mZnNldD0iMCIgc3RvcC1jb2xvcj0iI2ZmZjZlMyIvPjxzdG9wIG9mZnNldD0iMSIgc3RvcC1jb2xvcj0iI2ZmZmZmZiIvPjwvbGluZWFyR3JhZGllbnQ+PC9kZWZzPgogIDxyZWN0IHdpZHRoPSIxMjAwIiBoZWlnaHQ9IjkwMCIgZmlsbD0idXJsKCNnKSIvPgogIDxyZWN0IHg9IjAiIHk9IjAiIHdpZHRoPSIxMjAwIiBoZWlnaHQ9IjE0IiBmaWxsPSIjYzk5NzAwIi8+CiAgPGcgZm9udC1mYW1pbHk9InNhbnMtc2VyaWYiIGRpcmVjdGlvbj0icnRsIiB0ZXh0LWFuY2hvcj0ic3RhcnQiPgogICAgPHRleHQgeD0iMTA5MCIgeT0iMjMwIiBmb250LXNpemU9IjQyIiBmb250LXdlaWdodD0iNzAwIiBmaWxsPSIjYzk5NzAwIiBvcGFjaXR5PSIwLjc1Ij7Yo9mI2LHYp9mCINi52YXZhDwvdGV4dD4KICAgIDx0ZXh0IHg9IjEwOTAiIHk9IjM2MCIgZm9udC1zaXplPSI4MCIgZm9udC13ZWlnaHQ9IjkwMCIgZmlsbD0iIzE0MjYxZCI+2KfZhNmE2LrYqSDYp9mE2KXZhtis2YTZitiy2YrYqTwvdGV4dD4KICAgIDx0ZXh0IHg9IjEwOTAiIHk9IjQ3MCIgZm9udC1zaXplPSI0NCIgZm9udC13ZWlnaHQ9IjUwMCIgZmlsbD0iIzNiNGQ0MyI+2KfZhNi12YEg2KfZhNij2YjZhCDYp9mE2YXYqtmI2LPYtzwvdGV4dD4KICA8L2c+CiAgPGcgdHJhbnNmb3JtPSJ0cmFuc2xhdGUoMTEwLDY5MCkiPgogICAgPHJlY3QgeD0iMCIgeT0iMCIgcng9IjE0IiB3aWR0aD0iMTkwIiBoZWlnaHQ9IjY0IiBmaWxsPSIjYzk5NzAwIi8+CiAgICA8dGV4dCB4PSI5NSIgeT0iNDIiIGZvbnQtZmFtaWx5PSJ1aS1tb25vc3BhY2UsIG1vbm9zcGFjZSIgZm9udC1zaXplPSIzMCIgZm9udC13ZWlnaHQ9IjcwMCIgZmlsbD0iI2ZmZmZmZiIgdGV4dC1hbmNob3I9Im1pZGRsZSI+UERGPC90ZXh0PgogIDwvZz4KICA8Y2lyY2xlIGN4PSIxNTAiIGN5PSIyMDAiIHI9IjcwIiBmaWxsPSIjYzk5NzAwIiBvcGFjaXR5PSIwLjEwIi8+CiAgPGNpcmNsZSBjeD0iMjU1IiBjeT0iMzAwIiByPSIzOCIgZmlsbD0iI2M5OTcwMCIgb3BhY2l0eT0iMC4wNyIvPgo8L3N2Zz4=`, alt: 'أوراق عمل الإنجليزية', w: 1200, h: 900 },
  },
];

/** شبكة بأربعة أعمدة — الشكل القانوني في صفحات التصنيف. */
export function Grid() {
  return (
    <ProductCollection
      settings={{ ...COLLECTION_DEFAULTS, heading: 'الأكثر مبيعًا', cols: 4, entrance: 'none' }}
      items={ITEMS}
      state="ready"
    />
  );
}


/**
 * بينتو — أوّل عنصر يأخذ خليّتين.
 *
 * عنصران لا ثلاثة: الثالث يبدأ صفًّا ثانيًا يتجاوز ارتفاع بطاقة المعاينة
 * فيُقصّ، وعدم التماثل المقصود يظهر كاملًا بعنصرين.
 */
export function Bento() {
  return (
    <ProductCollection
      settings={{ ...COLLECTION_DEFAULTS, heading: 'مختارات', layout: 'bento', cols: 3, entrance: 'none' }}
      items={ITEMS.slice(0, 2)}
      state="ready"
    />
  );
}

/** سطح داكن وحدود سميكة وزوايا حادّة — محور الضبط البصري. */
export function DarkSurface() {
  return (
    <ProductCollection
      settings={{
        ...COLLECTION_DEFAULTS,
        heading: 'عروض الأسبوع',
        surface: 'dark',
        border: 'solid',
        radius: 4,
        shadow: 'lift',
        density: 'tight',
        cols: 3,
        entrance: 'none',
      }}
      items={ITEMS.slice(0, 3)}
      state="ready"
    />
  );
}

/**
 * كاروسيل — شريط أفقي بتمرير.
 *
 * نفس البطاقة ونفس البيانات؛ التخطيط خاصّية لا ملفّ.
 */
export function Carousel() {
  return (
    <ProductCollection
      settings={{ ...COLLECTION_DEFAULTS, heading: 'تصفّح سريع', layout: 'carousel', entrance: 'none' }}
      items={ITEMS}
      state="ready"
    />
  );
}

/** التحميل: هيكل عظمي — ليس «لا نتائج»، والفرق يُمرَّر صراحةً. */
export function Loading() {
  return (
    <ProductCollection
      settings={{ ...COLLECTION_DEFAULTS, heading: 'جارٍ التحميل', cols: 4, entrance: 'none' }}
      items={[]}
      state="loading"
    />
  );
}



/**
 * قائمة — صفٌّ أفقيّ («كائن وسائط»): عمود صورة `clamp(96px, 28cqi, 200px)`
 * والمحتوى بجانبه. كان هذا التخطيط بطاقةً رأسية بارتفاع ~950px (عيب مقيس
 * WP-R) فأُصلح؛ عنصران يكفيان لإظهار بنية الصفّ بلا قصّ.
 */
export function ListLayout() {
  return (
    <ProductCollection
      settings={{ ...COLLECTION_DEFAULTS, heading: 'نتائج البحث', layout: 'list', entrance: 'none' }}
      items={ITEMS.slice(0, 2)}
      state="ready"
    />
  );
}

/**
 * الفراغ — صندوق مرئيّ برسالة، لا «لا شيء».
 *
 * `emptyMsg` تُصيَّر فعلًا (كانت تُمرَّر إلى EmptyState بجمهور «زائر»
 * فتُعيد null — أُصلح في WP-R). الفراغ حالة مُعلنة لا تُستنتج من طول القائمة.
 */
export function Empty() {
  return (
    <ProductCollection
      settings={{
        ...COLLECTION_DEFAULTS,
        heading: 'نتائج البحث',
        cols: 4,
        entrance: 'none',
        emptyMsg: 'لا مناهج تطابق هذا التصفية. جرّب توسيع المرحلة أو المادّة.',
      }}
      items={[]}
      state="empty"
    />
  );
}

/**
 * الخطأ — متميّز عن الفراغ.
 *
 * الفرق جوهريّ: عطلُ تحميلٍ يبدو «لا نتائج» يدفع الزائر إلى تغيير بحثه بدل
 * إعادة المحاولة. `errorMsg` تعدّل النصّ؛ وبتركها فارغة يظهر
 * «أعد المحاولة بعد قليل.» افتراضًا.
 */
export function ErrorState() {
  return (
    <ProductCollection
      settings={{
        ...COLLECTION_DEFAULTS,
        heading: 'نتائج البحث',
        cols: 4,
        entrance: 'none',
        errorMsg: 'تعذّر جلب المناهج. الخلل عندنا لا في بحثك — أعد المحاولة.',
      }}
      items={[]}
      state="error"
    />
  );
}
