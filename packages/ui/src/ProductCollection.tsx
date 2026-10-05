// UNIT: PRODUCTCOLLECTION
// FILE: ProductCollection.tsx
// STYLE: ProductCollection.module.css
// PARENT: UI-INDEX
// USES: PRODUCTCARD, REGISTRY-INDEX
// API: none
// MAP: docs/PROJECT_MAP.md

/**
 * ProductCollection — مجموعة منتجات قابلة للتحرير بالكامل.
 *
 * لماذا وُجد هذا الملف: محرّر المكوّنات في اللوحة يحرّر ثلاثة وثلاثين ضابطًا
 * لعنصر «مجموعة مُستعلَمة» (tah/collection.0101) — ولم يكن لهذا العنصر تنفيذ
 * في أي مكان. المحرّر كان يرسم هيكلًا سلكيًّا مقلّدًا، والمتجر يركّب البطاقات
 * في أربعة ملفّات متفرّقة (ProductGrid، FeaturedTabs، Archive، offers). فما
 * تراه في اللوحة لم يكن ما يُنشر، ولم يكن هناك «ما يُنشر» أصلًا.
 *
 * هذا الملف هو المصدر الواحد: تستعمله معاينة اللوحة والمتجر معًا، فيستحيل
 * انحرافهما — وهو شرط «ما تعاينه هو ما تنشره».
 *
 * الحزمة تعلن قانونها في index.ts: «التخطيط خاصية لا ملف». الخمسة تخطيطات
 * هنا فروعُ عرضٍ داخل مكوّن واحد، لا خمسة مكوّنات.
 *
 * الخصائص تطابق معرّفات الضوابط حرفيًّا (layout, cols, gap, density …) فلا
 * طبقة ترجمة بين المحرّر والمكوّن تنحرف بمرور الوقت.
 */
import type { CSSProperties, ReactNode } from 'react';
import type { CollectionSchemaKey } from '@tahdheer/registry';
import { ProductCard, type ProductCardProps } from './ProductCard';
import styles from './ProductCollection.module.css';

export type CollectionLayout = 'grid' | 'carousel' | 'slider' | 'list' | 'bento';
export type CollectionDensity = 'comfy' | 'mid' | 'tight';
export type CollectionBorder = 'none' | 'thin' | 'solid';
export type CollectionShadow = 'none' | 'soft' | 'lift';
export type CollectionRatio = 'square' | 'wide' | 'tall';
export type CollectionFit = 'cover' | 'contain';
export type CollectionSurface = 'plain' | 'soft' | 'warm' | 'dark';
export type CollectionHover = 'lift' | 'glow' | 'tilt' | 'none';
export type CollectionEntrance = 'up' | 'fade' | 'zoom' | 'none';
export type CollectionHeading = 'h2' | 'h3' | 'none';
export type CollectionState = 'ready' | 'loading' | 'empty' | 'error';

/** أجزاء بطاقة المنتج التي تُعرض. */
export interface CollectionParts {
  img: boolean;
  title: boolean;
  price: boolean;
  badge: boolean;
  cta: boolean;
}

/** الأجهزة التي يُخفى فيها القسم. */
export interface CollectionHide {
  desk: boolean;
  tab: boolean;
  mob: boolean;
}

/**
 * إعدادات المجموعة — الثلاثة والثلاثون ضابطًا.
 *
 * المجموعة الأولى تؤثّر في المظهر مباشرةً. والثانية (source/sort/skipOOS)
 * تُنفَّذ كاستعلام على الخادم: لا تغيّر شكل المكوّن بل *أيّ* منتجات تصله،
 * ولذلك تصل هنا كنتيجة (`items`) لا كمعامل. تُوثَّق ضمن العقد حتى يبقى
 * التسلسل مرئيًّا من طرف إلى طرف.
 */
export interface CollectionSettings {
  heading: string;
  headingLevel: CollectionHeading;
  hSize: number;
  hWeight: 700 | 800 | 900;

  layout: CollectionLayout;
  cols: number;
  gap: number;
  density: CollectionDensity;
  mobCols: number;

  radius: number;
  border: CollectionBorder;
  shadow: CollectionShadow;
  surface: CollectionSurface;

  ratio: CollectionRatio;
  fit: CollectionFit;
  parts: CollectionParts;

  hover: CollectionHover;
  spotlight: boolean;
  motion: number;
  entrance: CollectionEntrance;

  hide: CollectionHide;
  anchor: string;
  cls: string;

  emptyMsg: string;
  errorMsg: string;
}

/*
 * عقد الاستوديو §2.3: مفاتيح مخطط «المجموعة» في @tahdheer/registry يجب أن
 * تكون مجموعة جزئية من مفاتيح CollectionSettings — فحص ترجمة فقط (استيراد
 * نوعي، صفر أثر وقت التشغيل): مفتاح في المخطط بلا خاصية هنا يكسر البناء.
 */
void (null as unknown as CollectionSchemaKey satisfies keyof CollectionSettings);

export interface ProductCollectionProps {
  settings: CollectionSettings;
  items: ProductCardProps[];
  /** الحالات الأربع الإلزامية — تُمرَّر صراحةً لا تُستنتج من طول القائمة. */
  state?: CollectionState;
  /** يوقف حركة الدخول: تستعمله المعاينة لإعادة التشغيل عند الطلب. */
  replayKey?: number | string;
}

const DENSITY_SCALE: Record<CollectionDensity, number> = {
  comfy: 1,
  mid: 0.88,
  tight: 0.74,
};

const BORDER_WIDTH: Record<CollectionBorder, string> = {
  none: '0',
  thin: '1px',
  solid: '1.5px',
};

const RATIO: Record<CollectionRatio, string> = {
  square: '1 / 1',
  wide: '4 / 3',
  tall: '3 / 4',
};

export const COLLECTION_DEFAULTS: CollectionSettings = {
  heading: '',
  headingLevel: 'h2',
  hSize: 11,
  hWeight: 800,
  layout: 'grid',
  cols: 3,
  gap: 10,
  density: 'comfy',
  mobCols: 2,
  radius: 6,
  border: 'none',
  shadow: 'none',
  surface: 'plain',
  ratio: 'square',
  fit: 'cover',
  parts: { img: true, title: true, price: true, badge: false, cta: false },
  hover: 'lift',
  spotlight: false,
  motion: 5,
  entrance: 'up',
  hide: { desk: false, tab: false, mob: false },
  anchor: '',
  cls: '',
  emptyMsg: '',
  errorMsg: '',
};

/**
 * الاستدارة المضبوطة من اللوحة رقمٌ حرّ؛ تُلتقط إلى أقرب درجةٍ في سلّم النظام
 * (DESIGN-SYSTEM §1: ‎8/10/12/16/20/24‎) فلا تخرج الكتلة عن لغة البطاقات.
 * الصفر يبقى صفرًا — اختيارٌ صريح لسطحٍ حادّ.
 */
const RADIUS_SCALE: readonly [number, string][] = [
  [8, 'var(--r-xs)'],
  [10, 'var(--r-sm)'],
  [12, 'var(--r-md)'],
  [16, 'var(--r-lg)'],
  [20, 'var(--r-xl)'],
  [24, 'var(--r-2xl)'],
];

function snapRadius(px: number): string {
  if (!(px > 0)) return '0px';
  let best = RADIUS_SCALE[0]!;
  for (const step of RADIUS_SCALE) if (Math.abs(step[0] - px) < Math.abs(best[0] - px)) best = step;
  return best[1];
}

export function ProductCollection({
  settings,
  items,
  state = 'ready',
  replayKey,
}: ProductCollectionProps) {
  const s = { ...COLLECTION_DEFAULTS, ...settings };
  const density = DENSITY_SCALE[s.density];

  /*
   * كل ضابط يخرج كمتغيّر CSS مخصّص لا كصنف مبني بالتسلسل: القيم المستمرّة
   * (الأعمدة، الفراغ، الاستدارة) لا تُمثَّل بأصناف ثابتة، ومحاولة ذلك تنتج
   * عشرات الأصناف التي تنحرف عن مدى الضابط أوّل مرّة يتغيّر.
   */
  const vars: CSSProperties = {
    ['--pc-cols' as string]: String(Math.max(1, s.cols)),
    ['--pc-cols-mob' as string]: String(Math.max(1, s.mobCols)),
    ['--pc-gap' as string]: `${Math.round(s.gap * density)}px`,
    ['--pc-pad' as string]: `${Math.round(12 * density)}px`,
    ['--r-pc' as string]: snapRadius(s.radius),
    ['--pc-border' as string]: BORDER_WIDTH[s.border],
    ['--pc-ratio' as string]: RATIO[s.ratio],
    ['--pc-fit' as string]: s.fit,
    ['--pc-h-size' as string]: `${s.hSize}px`,
    ['--pc-h-weight' as string]: String(s.hWeight),
  };

  const classes = [
    styles.collection,
    styles[`layout_${s.layout}`],
    styles[`surface_${s.surface}`],
    styles[`shadow_${s.shadow}`],
    s.hide.desk ? styles.hideDesk : '',
    s.hide.tab ? styles.hideTab : '',
    s.hide.mob ? styles.hideMob : '',
    // العنوان والسعر إلزاميّان في بيانات البطاقة — يُخفيان بنيويًّا بالصنف
    // (انظر تعليق visibleParts). كان المفتاحان بلا أثر: عيب مقيس (WP-R).
    s.parts.title ? '' : styles.noTitle,
    s.parts.price ? '' : styles.noPrice,
    s.spotlight ? styles.spotlight : '',
    // صنف المشرف أخيرًا فيستطيع الفوز — وهو الغرض منه.
    s.cls.trim(),
  ]
    .filter(Boolean)
    .join(' ');

  const body = renderBody(s, items, state, density);
  /*
   * العنوان يُبنى هنا لا في JSX: 'none' ليست مستوى عنوان، وتضييق النوع لا
   * ينتقل عبر متغيّر منطقي وسيط — فيبقى TypeScript محقًّا في الاعتراض.
   */
  const heading =
    s.headingLevel !== 'none' && s.heading.trim() !== '' ? (
      <Heading level={s.headingLevel} className={styles.heading}>
        {s.heading}
      </Heading>
    ) : null;

  return (
    <section
      // المرساة معرّف حقيقي في DOM: يعمل الرابط #anchor فورًا في المعاينة.
      id={s.anchor.trim() || undefined}
      className={classes}
      style={vars}
      data-motion={String(Math.max(0, Math.min(10, s.motion)))}
      data-enter={s.entrance}
      data-hover={s.hover}
      data-state={state}
      data-theme-component="collection"
      key={replayKey}
    >
      {heading}
      {body}
    </section>
  );
}

function renderBody(
  s: CollectionSettings,
  items: ProductCardProps[],
  state: CollectionState,
  density: number,
): ReactNode {
  if (state === 'loading') {
    // هياكل بعدد الأعمدة لا عدد ثابت: التحميل يجب أن يشبه ما سيحلّ محلّه.
    return (
      <div className={styles.track} aria-busy="true" aria-live="polite">
        {Array.from({ length: Math.max(1, s.cols) }, (_, i) => (
          <div key={i} className={styles.skeleton} aria-hidden="true" />
        ))}
        <span className="sr-only">جارٍ تحميل المنتجات…</span>
      </div>
    );
  }

  /*
   * حالتا الفراغ والخطأ كانتا تُصيَّران EmptyState بجمهور «زائر» — وهي تعيد
   * null لهذا الجمهور، فلا تظهر رسالة المشرف إطلاقًا (عيب مقيس، WP-R).
   * الصندوق هنا مرئي دائمًا: ضابطا emptyMsg/errorMsg يعدّلان ما يُعرض فعلًا.
   */
  if (state === 'error') {
    return (
      <div className={styles.stateBox} role="alert">
        <p className={styles.stateTitle}>تعذّر تحميل المنتجات</p>
        <p className={styles.stateHint}>{s.errorMsg.trim() || 'أعد المحاولة بعد قليل.'}</p>
      </div>
    );
  }

  if (state === 'empty' || items.length === 0) {
    return (
      <div className={styles.stateBox} role="status">
        <p className={styles.stateTitle}>لا منتجات هنا بعد</p>
        {s.emptyMsg.trim() !== '' ? <p className={styles.stateHint}>{s.emptyMsg}</p> : null}
      </div>
    );
  }

  const cards = items.map((item, index) => (
    <li
      key={item.id}
      className={styles.cell}
      // فهرس التتابع: التأخير مشتقّ منه في طبقة الحركة المشتركة.
      style={{ ['--mo-index' as string]: String(index) }}
      data-reveal="in"
    >
      <ProductCard {...visibleParts(item, s.parts)} />
    </li>
  ));

  return (
    <ul className={styles.track} role="list" style={{ ['--pc-density' as string]: String(density) }}>
      {cards}
    </ul>
  );
}

/**
 * الأجزاء الاختيارية (الصورة، الشارة، زر السلة) تُخفى بحذف بياناتها من
 * الخصائص — فلا تصل العنصرَ أصلًا. أمّا العنوان والسعر فخاصّيتان إلزاميّتان
 * في ProductCardProps لا سبيل لحذفهما كبيانات دون كسر عقد البطاقة، فيُخفيان
 * بصنفَي noTitle/noPrice في ورقة الأنماط (display:none يخرج العنصر من العرض
 * ومن شجرة الوصول معًا؛ يبقى في مصدر الصفحة — حدّ معلوم لهذا المسار).
 * كان مفتاحا title/price بلا أثر إطلاقًا — عيب مقيس أُصلح في مكانه (WP-R).
 */
function visibleParts(item: ProductCardProps, parts: CollectionParts): ProductCardProps {
  const out: ProductCardProps = { ...item };
  if (!parts.img) delete (out as { image?: unknown }).image;
  if (!parts.badge) delete (out as { badge?: unknown }).badge;
  if (!parts.cta) {
    delete (out as { cta?: unknown }).cta;
    delete (out as { ctaSlot?: unknown }).ctaSlot;
  }
  return out;
}

function Heading({
  level,
  className,
  children,
}: {
  level: 'h2' | 'h3';
  // أنواع وحدات CSS في هذه الحزمة تُعيد string|undefined (css-modules.d.ts)،
  // فاشتراط string هنا يكسر البناء على أوّل استعمال.
  className?: string;
  children: ReactNode;
}) {
  // مستوى العنوان بنية لا مظهر: قسمٌ داخل صفحة لا يجوز أن يحمل h1 ثانيًا.
  return level === 'h2' ? (
    <h2 className={className}>{children}</h2>
  ) : (
    <h3 className={className}>{children}</h3>
  );
}
