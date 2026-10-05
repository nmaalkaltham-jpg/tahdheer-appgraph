// UNIT: UI-INDEX
// FILE: index.ts
// STYLE: none
// PARENT: none
// USES: ACCORDION, ADMINSHELL, BEAMBACKDROP, EMPTYSTATE, FAQLIST, HIGHLIGHTGRID +13
// API: none
// MAP: docs/PROJECT_MAP.md

/**
 * @tahdheer/ui — core shared components (MIP §2.5).
 * Law: layout is a prop, not a file. Consumed via workspace +
 * transpilePackages; token vars require '@tahdheer/design-system/tokens.css'
 * loaded at the app root.
 */
/**
 * ─── نطاق تجاوزات الثيم: `data-theme-component` ───
 *
 * كلّ مكوّن مشترك يجذّر نفسه بـ`data-theme-component="{id}"`، والمعرّف هو نفسه
 * مفتاح المكوّن في `PROP_SCHEMAS` — مفردةٌ واحدة لا مفردتان.
 *
 * ولماذا: `ThemeCss::overrides` يبثّ لكلّ مكوّنٍ حُرِّر سطرًا
 * `[data-theme-component="X"]{--tp-*}` داخل `/theme.css` الذي يربطه المتجر في
 * كلّ صفحة. فضبطُ «اسم المنتج» مرّةً واحدة يسري على كلّ بطاقة في المتجر:
 * الرئيسية والأرشيف وصفحة المنتج والبحث — لا مرّة لكلّ سطح.
 *
 * وقبل هذه السمات كان الخادم يُطبّع التجاوزات ويحفظها ويُصرّفها إلى CSS فعلًا،
 * ثمّ لا يطابق محدّدُها شيئًا في المتجر كلّه. الخطّ كان كاملًا إلّا من حلقته
 * الأخيرة.
 *
 * والسمة على **جذر المكوّن** لا على غلافٍ في الصفحة: الغلاف يخصّ سطحًا واحدًا،
 * والجذر يسري أينما رُكّب المكوّن.
 */

export { ProductCard, productCardCtaClass } from './ProductCard';
export type { ProductCardProps, ProductCardImage, ProductCardBadge, ProductCardCta } from './ProductCard';

export { ProductCollection, COLLECTION_DEFAULTS } from './ProductCollection';
export type {
  ProductCollectionProps,
  CollectionSettings,
  CollectionParts,
  CollectionHide,
  CollectionState,
  CollectionLayout,
  CollectionDensity,
  CollectionBorder,
  CollectionShadow,
  CollectionRatio,
  CollectionFit,
  CollectionSurface,
  CollectionHover,
  CollectionEntrance,
  CollectionHeading,
} from './ProductCollection';

export { FaqList } from './FaqList';
export type { FaqListProps, FaqItem, FaqOpenMode } from './FaqList';

// المرحلة ٣٫١٠: عائلة الكشف — النسق من مدخل السجل (C107).
export { Tabs } from './Tabs';
export type { TabsProps, TabItem, TabsLayout } from './Tabs';
export { Accordion } from './Accordion';
export type { AccordionProps, AccordionItem, AccordionLayout } from './Accordion';

export { LayoutContainer, CONTAINER_DEFAULTS } from './LayoutContainer';
export type {
  LayoutContainerProps,
  LayoutContainerSettings,
  ContainerEngine,
  ContainerResponsive,
  ContainerResponsiveKey,
  ContainerWidth,
  ContainerBg,
  ContainerRadius,
  ContainerBorder,
  ContainerDirection,
  ContainerJustify,
  ContainerAlign,
  ContainerColMode,
} from './LayoutContainer';

export { PromoBanner, PROMO_DEFAULTS } from './PromoBanner';
export type { PromoBannerProps, PromoBannerSettings, PromoHeight, PromoOverlay, PromoAlign } from './PromoBanner';

export { TrustStrip } from './TrustStrip';
export type { TrustStripProps, TrustStripVariant, TrustItem, TrustItemTone } from './TrustStrip';

export { EmptyState } from './EmptyState';
export type { EmptyStateProps } from './EmptyState';

export { Toast, ToastViewport, TOAST_LIMIT, TOAST_DURATION_MS } from './Toast';
export type { ToastProps, ToastViewportProps, ToastData, ToastTone } from './Toast';

export { SectionHeading } from './SectionHeading';
export type { SectionHeadingProps } from './SectionHeading';

export { PopupShell, POPUP_DEFAULTS, TAH_POPUP_EVENT } from './PopupShell';
export type {
  PopupShellProps,
  PopupShellSettings,
  PopupTrigger,
  PopupFrequency,
  PopupWidth,
  TahPopupDetail,
} from './PopupShell';

/*
 * قسمان انتقلا من منطقة المورّدين إلى هنا لأنّهما قسما صفحة حقيقيّان:
 * يُدرَجان من الاستوديو، ويُحفظان في المستند، ويصيّرهما المتجر. بقاؤهما هناك
 * كان يحبسهما في الأطلس بلا مستهلك إنتاج.
 */
export { HighlightGrid } from './HighlightGrid';
export type { HighlightGridProps, HighlightGridItem } from './HighlightGrid';

export { StickyReveal } from './StickyReveal';
export type { StickyRevealProps, StickyRevealSection } from './StickyReveal';

/*
 * ثلاث حاويات مؤثِّرة ومكوّن وسائط، انتقلت من منطقة المورّدين إلى هنا لأنّها
 * صارت مُدخَلات استوديو تُدرَج وتُحفظ ويصيّرها المتجر. الثلاث تلفّ أبناءها
 * (‎CONTAINER_COMPONENTS‎)، و‎Lens‎ يلفّ صورة لا أبناء.
 */
export { SpotlightLayer } from './SpotlightLayer';
export type { SpotlightLayerProps } from './SpotlightLayer';

export { BeamBackdrop } from './BeamBackdrop';
export type { BeamBackdropProps } from './BeamBackdrop';

export { TracingBeam } from './TracingBeam';
export type { TracingBeamProps } from './TracingBeam';

export { Lens } from './Lens';
export type { LensProps } from './Lens';

/*
 * قشرة لوحة الإدارة — خمسة تخطيطات في مكوّنٍ واحد.
 *
 * «التخطيط خاصّية لا ملفّ» (MIP §2.5): خمس قشراتٍ في خمسة ملفّات تتباعد بعد
 * أوّل تعديل، فيصير «التصميم الثاني» نسخةً قديمة من الأوّل لا خيارًا عنه.
 */
export { AdminShell } from './AdminShell';
export type {
  AdminShellProps,
  AdminNavLayout,
  AdminNavGroup,
  AdminNavItem,
  AdminTab,
} from './AdminShell';
