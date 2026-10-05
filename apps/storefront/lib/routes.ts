/**
 * The single route map (MIP §3.1 — zero `href="#"`; all links come from one
 * route-map module). Paths follow the §3.2 route matrix; routes not yet built
 * in this skeleton return a REAL 404 until their phase lands — never a fake page.
 */
export const routes = {
  home: '/',
  stages: '/stages',
  grades: '/grades',
  subjects: '/subjects',
  semesters: '/semesters',
  kinds: '/kinds',
  shop: '/shop',
  offers: '/offers',
  bestSellers: '/best-sellers',
  /* العيّنات المجّانيّة — «لن تشتري ملفًّا لم تره». */
  samples: '/samples',
  newArrivals: '/new',
  blog: '/blog',
  contact: '/contact',
  about: '/about',
  howItWorks: '/how-it-works',
  help: '/help',
  search: '/search',
  cart: '/cart',
  /* المفضّلة — مخزنٌ على المتصفّح (lib/wishlist.ts)؛ الصفحة تُصيَّر من localStorage. */
  wishlist: '/wishlist',
  checkout: '/checkout',
  login: '/login',
  request: '/request',
  paymentMethods: '/payment-methods',
  /* مصادر الرسوم والشعارات — إسنادٌ يشترطه ترخيص Freepik (lib/art.ts ‎CREDITS‎). */
  credits: '/credits',
  bundles: '/bundles',
  product: (slug: string) => `/products/${slug}`,
  /*
   * Taxonomy archives are built by `termPath()` in the catalog dimension
   * table, which also carries the kind dimension's path/filter distinction —
   * so they are NOT duplicated here as string templates.
   */
  /** Detail page exists only once the bundle API does — until then a REAL 404. */
  bundle: (slug: string) => `/bundles/${slug}`,
  policy: (slug: 'refund' | 'privacy' | 'cookies' | 'terms' | 'license') => `/policies/${slug}`,
} as const;
