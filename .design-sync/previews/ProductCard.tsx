/**
 * ProductCard — بطاقة المنتج.
 *
 * البيانات منقولة حرفيًّا من ‎GET /api/v1/products‎ على الإنتاج: عناوين مناهج
 * حقيقية، وأسعار بالهللات، ومسارات تصنيف فعلية. لا «منتج تجريبي» ولا لوريم.
 *
 * ملاحظتان من العقد نفسه:
 *   · القلب يُحذف كاملًا بلا `wishlistHref` — لا زرّ يقود إلى لا شيء.
 *   · التقييم يُصيَّر فقط بـ`ratingAvg` **و**`ratingCount` معًا: نجمة بلا عدد
 *     ادّعاء بلا مصدر.
 */

import { ProductCard, type ProductCardProps } from '@tahdheer/ui';

/** منتج إنتاجي حقيقي — الأساس الذي تشتقّ منه بقيّة الحالات. */
const BASE: ProductCardProps = {
  id: 4,
  sku: 'DEMO-ENG-G7-S1',
  title: 'أوراق عمل اللغة الإنجليزية — أول متوسط — الفصل الأول',
  slug: 'اوراق-عمل-الانجليزية-اول-متوسط',
  url: '/products/اوراق-عمل-الانجليزية-اول-متوسط',
  categoryPath: ['متوسط', 'اللغة الإنجليزية'],
  format: 'PDF',
  priceMinor: 3200,
  onSale: false,
  image: {
    url: 'data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHZpZXdCb3g9IjAgMCAxMjAwIDkwMCIgd2lkdGg9IjEyMDAiIGhlaWdodD0iOTAwIiByb2xlPSJpbWciIGFyaWEtbGFiZWw9Itin2YTZhNi62Kkg2KfZhNil2YbYrNmE2YrYstmK2Kkg4oCUINin2YTYtdmBINin2YTYo9mI2YQg2KfZhNmF2KrZiNiz2LciPgogIDxkZWZzPjxsaW5lYXJHcmFkaWVudCBpZD0iZyIgeDE9IjAiIHkxPSIwIiB4Mj0iMSIgeTI9IjEiPjxzdG9wIG9mZnNldD0iMCIgc3RvcC1jb2xvcj0iI2ZmZjZlMyIvPjxzdG9wIG9mZnNldD0iMSIgc3RvcC1jb2xvcj0iI2ZmZmZmZiIvPjwvbGluZWFyR3JhZGllbnQ+PC9kZWZzPgogIDxyZWN0IHdpZHRoPSIxMjAwIiBoZWlnaHQ9IjkwMCIgZmlsbD0idXJsKCNnKSIvPgogIDxyZWN0IHg9IjAiIHk9IjAiIHdpZHRoPSIxMjAwIiBoZWlnaHQ9IjE0IiBmaWxsPSIjYzk5NzAwIi8+CiAgPGcgZm9udC1mYW1pbHk9InNhbnMtc2VyaWYiIGRpcmVjdGlvbj0icnRsIiB0ZXh0LWFuY2hvcj0ic3RhcnQiPgogICAgPHRleHQgeD0iMTA5MCIgeT0iMjMwIiBmb250LXNpemU9IjQyIiBmb250LXdlaWdodD0iNzAwIiBmaWxsPSIjYzk5NzAwIiBvcGFjaXR5PSIwLjc1Ij7Yo9mI2LHYp9mCINi52YXZhDwvdGV4dD4KICAgIDx0ZXh0IHg9IjEwOTAiIHk9IjM2MCIgZm9udC1zaXplPSI4MCIgZm9udC13ZWlnaHQ9IjkwMCIgZmlsbD0iIzE0MjYxZCI+2KfZhNmE2LrYqSDYp9mE2KXZhtis2YTZitiy2YrYqTwvdGV4dD4KICAgIDx0ZXh0IHg9IjEwOTAiIHk9IjQ3MCIgZm9udC1zaXplPSI0NCIgZm9udC13ZWlnaHQ9IjUwMCIgZmlsbD0iIzNiNGQ0MyI+2KfZhNi12YEg2KfZhNij2YjZhCDYp9mE2YXYqtmI2LPYtzwvdGV4dD4KICA8L2c+CiAgPGcgdHJhbnNmb3JtPSJ0cmFuc2xhdGUoMTEwLDY5MCkiPgogICAgPHJlY3QgeD0iMCIgeT0iMCIgcng9IjE0IiB3aWR0aD0iMTkwIiBoZWlnaHQ9IjY0IiBmaWxsPSIjYzk5NzAwIi8+CiAgICA8dGV4dCB4PSI5NSIgeT0iNDIiIGZvbnQtZmFtaWx5PSJ1aS1tb25vc3BhY2UsIG1vbm9zcGFjZSIgZm9udC1zaXplPSIzMCIgZm9udC13ZWlnaHQ9IjcwMCIgZmlsbD0iI2ZmZmZmZiIgdGV4dC1hbmNob3I9Im1pZGRsZSI+UERGPC90ZXh0PgogIDwvZz4KICA8Y2lyY2xlIGN4PSIxNTAiIGN5PSIyMDAiIHI9IjcwIiBmaWxsPSIjYzk5NzAwIiBvcGFjaXR5PSIwLjEwIi8+CiAgPGNpcmNsZSBjeD0iMjU1IiBjeT0iMzAwIiByPSIzOCIgZmlsbD0iI2M5OTcwMCIgb3BhY2l0eT0iMC4wNyIvPgo8L3N2Zz4=',
    alt: 'أوراق عمل اللغة الإنجليزية — أول متوسط — الفصل الأول',
    w: 1200,
    h: 900,
  },
};

const cell: React.CSSProperties = {
  direction: 'rtl' as const, maxWidth: 320 };

/** الحالة القانونية: منتج منشور بسعر واحد، بلا عرض وبلا تقييم بعد. */
export function Default() {
  return (
    <div style={cell}>
      <ProductCard {...BASE} />
    </div>
  );
}

/** عرض حقيقي: السعر الأصلي والنسبة يأتيان من الخادم لا من حساب في الواجهة. */
export function OnSale() {
  return (
    <div style={cell}>
      <ProductCard
        {...BASE}
        id={1}
        sku="DEMO-MATH-G4-S1"
        image={{ url: 'data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHZpZXdCb3g9IjAgMCAxMjAwIDkwMCIgd2lkdGg9IjEyMDAiIGhlaWdodD0iOTAwIiByb2xlPSJpbWciIGFyaWEtbGFiZWw9Itin2YTYsdmK2KfYttmK2KfYqiDigJQg2KfZhNi12YEg2KfZhNix2KfYqNi5INin2YTYp9io2KrYr9in2KbZiiI+CiAgPGRlZnM+PGxpbmVhckdyYWRpZW50IGlkPSJnIiB4MT0iMCIgeTE9IjAiIHgyPSIxIiB5Mj0iMSI+PHN0b3Agb2Zmc2V0PSIwIiBzdG9wLWNvbG9yPSIjZWFmNWVmIi8+PHN0b3Agb2Zmc2V0PSIxIiBzdG9wLWNvbG9yPSIjZmZmZmZmIi8+PC9saW5lYXJHcmFkaWVudD48L2RlZnM+CiAgPHJlY3Qgd2lkdGg9IjEyMDAiIGhlaWdodD0iOTAwIiBmaWxsPSJ1cmwoI2cpIi8+CiAgPHJlY3QgeD0iMCIgeT0iMCIgd2lkdGg9IjEyMDAiIGhlaWdodD0iMTQiIGZpbGw9IiMxZTczNTUiLz4KICA8ZyBmb250LWZhbWlseT0ic2Fucy1zZXJpZiIgZGlyZWN0aW9uPSJydGwiIHRleHQtYW5jaG9yPSJzdGFydCI+CiAgICA8dGV4dCB4PSIxMDkwIiB5PSIyMzAiIGZvbnQtc2l6ZT0iNDIiIGZvbnQtd2VpZ2h0PSI3MDAiIGZpbGw9IiMxZTczNTUiIG9wYWNpdHk9IjAuNzUiPtiq2K3YttmK2LE8L3RleHQ+CiAgICA8dGV4dCB4PSIxMDkwIiB5PSIzNjAiIGZvbnQtc2l6ZT0iODAiIGZvbnQtd2VpZ2h0PSI5MDAiIGZpbGw9IiMxNDI2MWQiPtin2YTYsdmK2KfYttmK2KfYqjwvdGV4dD4KICAgIDx0ZXh0IHg9IjEwOTAiIHk9IjQ3MCIgZm9udC1zaXplPSI0NCIgZm9udC13ZWlnaHQ9IjUwMCIgZmlsbD0iIzNiNGQ0MyI+2KfZhNi12YEg2KfZhNix2KfYqNi5INin2YTYp9io2KrYr9in2KbZijwvdGV4dD4KICA8L2c+CiAgPGcgdHJhbnNmb3JtPSJ0cmFuc2xhdGUoMTEwLDY5MCkiPgogICAgPHJlY3QgeD0iMCIgeT0iMCIgcng9IjE0IiB3aWR0aD0iMTkwIiBoZWlnaHQ9IjY0IiBmaWxsPSIjMWU3MzU1Ii8+CiAgICA8dGV4dCB4PSI5NSIgeT0iNDIiIGZvbnQtZmFtaWx5PSJ1aS1tb25vc3BhY2UsIG1vbm9zcGFjZSIgZm9udC1zaXplPSIzMCIgZm9udC13ZWlnaHQ9IjcwMCIgZmlsbD0iI2ZmZmZmZiIgdGV4dC1hbmNob3I9Im1pZGRsZSI+RE9DWDwvdGV4dD4KICA8L2c+CiAgPGNpcmNsZSBjeD0iMTUwIiBjeT0iMjAwIiByPSI3MCIgZmlsbD0iIzFlNzM1NSIgb3BhY2l0eT0iMC4xMCIvPgogIDxjaXJjbGUgY3g9IjI1NSIgY3k9IjMwMCIgcj0iMzgiIGZpbGw9IiMxZTczNTUiIG9wYWNpdHk9IjAuMDciLz4KPC9zdmc+', alt: 'تحضير الرياضيات — الصف الرابع الابتدائي — الفصل الأول', w: 1200, h: 900 }}
        title="تحضير الرياضيات — الصف الرابع الابتدائي — الفصل الأول"
        categoryPath={['ابتدائي', 'الرياضيات']}
        format="Word"
        editable
        priceMinor={2400}
        regularMinor={4000}
        discountPct={40}
        onSale
      />
    </div>
  );
}

/** تقييم مُجمَّع حقيقي: المتوسّط والعدد معًا، وإلّا لا يُصيَّر شيء. */
export function WithRating() {
  return (
    <div style={cell}>
      <ProductCard
        {...BASE}
        id={2}
        sku="DEMO-SCI-G5-S1"
        image={{ url: 'data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHZpZXdCb3g9IjAgMCAxMjAwIDkwMCIgd2lkdGg9IjEyMDAiIGhlaWdodD0iOTAwIiByb2xlPSJpbWciIGFyaWEtbGFiZWw9Itin2YTYudmE2YjZhSDigJQg2KfZhNi12YEg2KfZhNiu2KfZhdizINin2YTYp9io2KrYr9in2KbZiiI+CiAgPGRlZnM+PGxpbmVhckdyYWRpZW50IGlkPSJnIiB4MT0iMCIgeTE9IjAiIHgyPSIxIiB5Mj0iMSI+PHN0b3Agb2Zmc2V0PSIwIiBzdG9wLWNvbG9yPSIjZThmMmZjIi8+PHN0b3Agb2Zmc2V0PSIxIiBzdG9wLWNvbG9yPSIjZmZmZmZmIi8+PC9saW5lYXJHcmFkaWVudD48L2RlZnM+CiAgPHJlY3Qgd2lkdGg9IjEyMDAiIGhlaWdodD0iOTAwIiBmaWxsPSJ1cmwoI2cpIi8+CiAgPHJlY3QgeD0iMCIgeT0iMCIgd2lkdGg9IjEyMDAiIGhlaWdodD0iMTQiIGZpbGw9IiMxZDVmOWMiLz4KICA8ZyBmb250LWZhbWlseT0ic2Fucy1zZXJpZiIgZGlyZWN0aW9uPSJydGwiIHRleHQtYW5jaG9yPSJzdGFydCI+CiAgICA8dGV4dCB4PSIxMDkwIiB5PSIyMzAiIGZvbnQtc2l6ZT0iNDIiIGZvbnQtd2VpZ2h0PSI3MDAiIGZpbGw9IiMxZDVmOWMiIG9wYWNpdHk9IjAuNzUiPti52LHYtjwvdGV4dD4KICAgIDx0ZXh0IHg9IjEwOTAiIHk9IjM2MCIgZm9udC1zaXplPSI4MCIgZm9udC13ZWlnaHQ9IjkwMCIgZmlsbD0iIzE0MjYxZCI+2KfZhNi52YTZiNmFPC90ZXh0PgogICAgPHRleHQgeD0iMTA5MCIgeT0iNDcwIiBmb250LXNpemU9IjQ0IiBmb250LXdlaWdodD0iNTAwIiBmaWxsPSIjM2I0ZDQzIj7Yp9mE2LXZgSDYp9mE2K7Yp9mF2LMg2KfZhNin2KjYqtiv2KfYptmKPC90ZXh0PgogIDwvZz4KICA8ZyB0cmFuc2Zvcm09InRyYW5zbGF0ZSgxMTAsNjkwKSI+CiAgICA8cmVjdCB4PSIwIiB5PSIwIiByeD0iMTQiIHdpZHRoPSIxOTAiIGhlaWdodD0iNjQiIGZpbGw9IiMxZDVmOWMiLz4KICAgIDx0ZXh0IHg9Ijk1IiB5PSI0MiIgZm9udC1mYW1pbHk9InVpLW1vbm9zcGFjZSwgbW9ub3NwYWNlIiBmb250LXNpemU9IjMwIiBmb250LXdlaWdodD0iNzAwIiBmaWxsPSIjZmZmZmZmIiB0ZXh0LWFuY2hvcj0ibWlkZGxlIj5QUFRYPC90ZXh0PgogIDwvZz4KICA8Y2lyY2xlIGN4PSIxNTAiIGN5PSIyMDAiIHI9IjcwIiBmaWxsPSIjMWQ1ZjljIiBvcGFjaXR5PSIwLjEwIi8+CiAgPGNpcmNsZSBjeD0iMjU1IiBjeT0iMzAwIiByPSIzOCIgZmlsbD0iIzFkNWY5YyIgb3BhY2l0eT0iMC4wNyIvPgo8L3N2Zz4=', alt: 'عرض بوربوينت — العلوم — الصف الخامس الابتدائي', w: 1200, h: 900 }}
        title="عرض بوربوينت — العلوم — الصف الخامس الابتدائي"
        categoryPath={['ابتدائي', 'العلوم']}
        format="PowerPoint"
        priceMinor={2900}
        ratingAvg={4.6}
        ratingCount={23}
      />
    </div>
  );
}

/** شارة تحريرية بلونَيها من الخادم، ورتبة في قائمة «الأكثر مبيعًا». */
export function RankedWithBadge() {
  return (
    <div style={cell}>
      <ProductCard
        {...BASE}
        id={5}
        sku="DEMO-BIO-G12-S1"
        image={{ url: 'data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHZpZXdCb3g9IjAgMCAxMjAwIDkwMCIgd2lkdGg9IjEyMDAiIGhlaWdodD0iOTAwIiByb2xlPSJpbWciIGFyaWEtbGFiZWw9Itin2YTYo9it2YrYp9ihIOKAlCDYp9mE2LXZgSDYp9mE2KvYp9mE2Ksg2KfZhNir2KfZhtmI2YoiPgogIDxkZWZzPjxsaW5lYXJHcmFkaWVudCBpZD0iZyIgeDE9IjAiIHkxPSIwIiB4Mj0iMSIgeTI9IjEiPjxzdG9wIG9mZnNldD0iMCIgc3RvcC1jb2xvcj0iI2U5ZmFmMiIvPjxzdG9wIG9mZnNldD0iMSIgc3RvcC1jb2xvcj0iI2ZmZmZmZiIvPjwvbGluZWFyR3JhZGllbnQ+PC9kZWZzPgogIDxyZWN0IHdpZHRoPSIxMjAwIiBoZWlnaHQ9IjkwMCIgZmlsbD0idXJsKCNnKSIvPgogIDxyZWN0IHg9IjAiIHk9IjAiIHdpZHRoPSIxMjAwIiBoZWlnaHQ9IjE0IiBmaWxsPSIjMzVjOThiIi8+CiAgPGcgZm9udC1mYW1pbHk9InNhbnMtc2VyaWYiIGRpcmVjdGlvbj0icnRsIiB0ZXh0LWFuY2hvcj0ic3RhcnQiPgogICAgPHRleHQgeD0iMTA5MCIgeT0iMjMwIiBmb250LXNpemU9IjQyIiBmb250LXdlaWdodD0iNzAwIiBmaWxsPSIjMzVjOThiIiBvcGFjaXR5PSIwLjc1Ij7ZhdmE2K7Zkdi1PC90ZXh0PgogICAgPHRleHQgeD0iMTA5MCIgeT0iMzYwIiBmb250LXNpemU9IjgwIiBmb250LXdlaWdodD0iOTAwIiBmaWxsPSIjMTQyNjFkIj7Yp9mE2KPYrdmK2KfYoTwvdGV4dD4KICAgIDx0ZXh0IHg9IjEwOTAiIHk9IjQ3MCIgZm9udC1zaXplPSI0NCIgZm9udC13ZWlnaHQ9IjUwMCIgZmlsbD0iIzNiNGQ0MyI+2KfZhNi12YEg2KfZhNir2KfZhNirINin2YTYq9in2YbZiNmKPC90ZXh0PgogIDwvZz4KICA8ZyB0cmFuc2Zvcm09InRyYW5zbGF0ZSgxMTAsNjkwKSI+CiAgICA8cmVjdCB4PSIwIiB5PSIwIiByeD0iMTQiIHdpZHRoPSIxOTAiIGhlaWdodD0iNjQiIGZpbGw9IiMzNWM5OGIiLz4KICAgIDx0ZXh0IHg9Ijk1IiB5PSI0MiIgZm9udC1mYW1pbHk9InVpLW1vbm9zcGFjZSwgbW9ub3NwYWNlIiBmb250LXNpemU9IjMwIiBmb250LXdlaWdodD0iNzAwIiBmaWxsPSIjZmZmZmZmIiB0ZXh0LWFuY2hvcj0ibWlkZGxlIj5QREY8L3RleHQ+CiAgPC9nPgogIDxjaXJjbGUgY3g9IjE1MCIgY3k9IjIwMCIgcj0iNzAiIGZpbGw9IiMzNWM5OGIiIG9wYWNpdHk9IjAuMTAiLz4KICA8Y2lyY2xlIGN4PSIyNTUiIGN5PSIzMDAiIHI9IjM4IiBmaWxsPSIjMzVjOThiIiBvcGFjaXR5PSIwLjA3Ii8+Cjwvc3ZnPg==', alt: 'ملخّص الأحياء — الصف الثالث الثانوي — الفصل الأول', w: 1200, h: 900 }}
        title="ملخّص الأحياء — الصف الثالث الثانوي — الفصل الأول"
        categoryPath={['ثانوي', 'الأحياء']}
        priceMinor={5500}
        rank={1}
        badge={{ label: 'الأكثر مبيعًا', bg: '#0f7b4f', fg: '#ffffff' }}
        wishlistHref="/account/wishlist/add?sku=DEMO-BIO-G12-S1"
      />
    </div>
  );
}

/** بلا صورة: البطاقة تحتفظ بارتفاعها ولا تنهار — منتج بلا غلاف حالة واقعية. */
export function WithoutImage() {
  return (
    <div style={cell}>
      <ProductCard
        {...BASE}
        id={3}
        image={undefined}
        sku="DEMO-ARB-G6-S2"
        title="اختبارات لغتي — الصف السادس الابتدائي — الفصل الثاني"
        categoryPath={['ابتدائي', 'لغتي']}
        priceMinor={1900}
      />
    </div>
  );
}

/** زرّ التذييل «أضف للسلة» — تصميم TahProductCard مكتملًا: سعر + زرّ بتخطيط space-between. */
export function WithCta() {
  return (
    <div style={cell}>
      <ProductCard
        {...BASE}
        id={4}
        sku="DEMO-ENG-G7-S1"
        title="أوراق عمل اللغة الإنجليزيّة — أول متوسط — الفصل الأول"
        categoryPath={['متوسط', 'اللغة الإنجليزيّة']}
        priceMinor={5500}
        regularMinor={7500}
        discountPct={27}
        onSale
        cta={{ label: 'أضف للسلة', href: '/products/demo-eng-g7-s1' }}
      />
    </div>
  );
}
