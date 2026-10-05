// UNIT: MEDIA-DATA
// FILE: media-data.ts
// STYLE: none
// PARENT: LIBRARY-PANEL, OPTIMIZE-PANEL, _INSPECT-DIALOG, _MEDIA-CARD, _SCAN-PANELS
// USES: LIB-ADMIN-API
// API: none
// MAP: docs/PROJECT_MAP.md

/**
 * اشتقاق ملفات الوسائط من أغلفة المنتجات — أحد مصدرَين، لا المصدر الوحيد.
 *
 * `GET /api/v1/products` العامة تكشف غلافًا واحدًا لكل منتج منشور
 * (ProductCardResource → `image` عند وجود صف cover فقط). قيمتها الباقية أنّها
 * **الوحيدة** التي تحمل رابط الصورة وأبعادها، فبها وحدها تُبنى الشبكة.
 *
 * والمكتبة نفسها لها مصدرها: `GET /api/v1/admin/media` تسرد كلّ أصل مسجَّل
 * بحجمه وبصمته المقتطعة وعدد مستعمليه، ويقرأها لوح «مكتبة الأصول». فما كان
 * يُعلَن هنا «ممتنعًا» — اليتيم والحجم والبصمة — صار مقروءًا من هناك، ولم يبقَ
 * ممتنعًا إلّا ما يمتنع فعلًا على **هذا** المصدر.
 *
 * عقيدة الصدق (MIP §1.3) في هذا الملف تحديدًا:
 *   · لا يُشتقّ هنا أي حجم بالبايت — صفّ الغلاف لا يحمله، والصفر كذب. يُقرأ
 *     الحجم من صفّ الأصل في اللوح الآخر، ولا مفتاح يربط الصفَّين هنا.
 *   · لا تُشتقّ حالة النصّ البديل — الواجهة تُبدّل عنوان المنتج عند غياب
 *     `alt_ar`، فالنصّ المُعاد لا يفرّق بين «مكتوب» و«مُستبدَل».
 *   · لا يُشتقّ «مكرّر» من هذه الصفوف — التكرار يحتاج الحجم وبصمة المحتوى،
 *     ولا يُشتقّ من الاسم أبدًا. المتاح هنا الأبعاد وحدها: مرشّحات لا نتيجة.
 * المشتقّ الوحيد الموثوق هو الصيغة، لأنها تُقرأ من امتداد المسار الحقيقي.
 */

import type { AssetRow } from '../../../lib/admin-api';

/* ───── شكل الاستجابة الحقيقي لـ GET /api/v1/products ───── */

export interface ProductCardImage {
  url: string;
  /** ما تخدمه الواجهة العامة: `alt_ar` أو عنوان المنتج بديلًا عنه. */
  alt: string;
  w: number | null;
  h: number | null;
}

export interface ProductCard {
  id: number;
  sku: string;
  title: string;
  slug: string;
  url: string;
  image?: ProductCardImage;
}

/** مجموعة موارد Laravel تُغلَّف في `data` — لا `meta` لهذه النقطة. */
export interface ProductCardPage {
  data: ProductCard[];
}

/* ───── النموذج المعروض ───── */

/** موضع استخدام مُثبَت: منتج منشور يحمل هذا الملف غلافًا له. */
export interface MediaReference {
  productId: number;
  title: string;
  sku: string;
}

/**
 * تصنيف الصيغة — المشتقّ الوحيد الموثوق، مصدره امتداد المسار الحقيقي:
 *   modern  — WebP/AVIF: مُحسَّنة أصلًا، إعادة الترميز خسارة بلا مكسب.
 *   vector  — SVG: لا يُحوَّل إلى نقطي.
 *   legacy  — JPEG/PNG/GIF…: مرشّحة للتحويل، والقرار يحتاج قياسًا فعليًا.
 *   unknown — لا امتداد يُقرأ: لا قرار قبل قراءة الملف نفسه.
 */
export type MediaFormat = 'modern' | 'vector' | 'legacy' | 'unknown';

export interface MediaFile {
  /** المسار كما تعيده الواجهة — هو المُعرّف الوحيد المتاح (لا يوجد id وسائط). */
  url: string;
  /** اسم الملف من آخر مقطع في المسار. */
  name: string;
  /** الامتداد بحروف صغيرة بلا نقطة، وسلسلة فارغة إن تعذّر. */
  ext: string;
  format: MediaFormat;
  width: number | null;
  height: number | null;
  /** النصّ الذي تخدمه الواجهة — قد يكون عنوان المنتج لا نصًّا بديلًا مكتوبًا. */
  servedAlt: string;
  /** مواضع الاستخدام المُثبَتة فقط. */
  references: MediaReference[];
}

const MODERN = new Set(['webp', 'avif']);
const VECTOR = new Set(['svg', 'svgz']);
const LEGACY = new Set(['jpg', 'jpeg', 'png', 'gif', 'bmp', 'tif', 'tiff', 'heic', 'ico']);

/** آخر مقطع في المسار بعد إسقاط الاستعلام والمرساة. */
function fileNameOf(url: string): string {
  const clean = url.split('#')[0]?.split('?')[0] ?? url;
  const last = clean.split('/').filter(Boolean).pop() ?? clean;
  try {
    return decodeURIComponent(last);
  } catch {
    return last;
  }
}

function extensionOf(name: string): string {
  const match = /\.([A-Za-z0-9]+)$/.exec(name);
  return match?.[1] ? match[1].toLowerCase() : '';
}

function formatOf(ext: string): MediaFormat {
  if (MODERN.has(ext)) return 'modern';
  if (VECTOR.has(ext)) return 'vector';
  if (LEGACY.has(ext)) return 'legacy';
  return 'unknown';
}

/**
 * ملفات فريدة بالمسار مع تجميع كل مواضع الاستخدام المُثبَتة عليها.
 * المنتج بلا `image` لا يُنتج ملفًا — الغياب غياب، لا عنصر فارغ.
 */
export function filesFromProducts(products: ProductCard[]): MediaFile[] {
  const byUrl = new Map<string, MediaFile>();

  for (const product of products) {
    const image = product.image;
    if (!image || typeof image.url !== 'string' || image.url === '') continue;

    const existing = byUrl.get(image.url);
    if (existing) {
      existing.references.push({ productId: product.id, title: product.title, sku: product.sku });
      continue;
    }

    const name = fileNameOf(image.url);
    const ext = extensionOf(name);
    byUrl.set(image.url, {
      url: image.url,
      name,
      ext,
      format: formatOf(ext),
      width: typeof image.w === 'number' ? image.w : null,
      height: typeof image.h === 'number' ? image.h : null,
      servedAlt: image.alt,
      references: [{ productId: product.id, title: product.title, sku: product.sku }],
    });
  }

  return [...byUrl.values()];
}

/** `1600×1200` أو null حين لا تُخزَّن الأبعاد. */
export function dimensionsOf(file: MediaFile): string | null {
  if (file.width === null || file.height === null) return null;
  return `${file.width}×${file.height}`;
}

/**
 * سبب التجاوز حين يكون مُشتقًّا من واقع الملف، وnull حين يكون التحويل
 * ممكنًا مبدئيًا. «ممكن مبدئيًا» ليس وعدًا بتوفير: القرار يحتاج قياسًا.
 */
export function skipReason(file: MediaFile): string | null {
  if (file.format === 'modern') {
    return `الملف بصيغة ${file.ext.toUpperCase()} أصلًا — إعادة الترميز تُفقد جودة بلا مكسب في الحجم. يُتجاوَز.`;
  }
  if (file.format === 'vector') {
    return 'SVG متجهي لا يُحوَّل إلى WebP. لا إجراء.';
  }
  if (file.format === 'unknown') {
    return 'لا امتداد يُقرأ من المسار — لا قرار تحويل قبل قراءة الملف نفسه.';
  }
  return null;
}

/* ───── التكرار: من صفوف الأصول، لا من الأغلفة ───── */

/**
 * تكرارٌ مُثبَت لا مرشَّح.
 *
 * المفتاح بصمة المحتوى وحجمه بالبايت معًا، وكلاهما يخرج من صفّ الأصل
 * (`MediaLibraryController::row`). والاسم لا يدخل الحساب لا إثباتًا ولا نفيًا:
 * الاسم المتطابق لا يُثبت تكرارًا والمختلف لا ينفيه.
 *
 * البصمة مقتطعة إلى اثني عشر محرفًا من sha256 عمدًا (سرٌّ لا يخرج كاملًا)،
 * فالتطابق عليها مع تطابق الحجم بالبايت دليلٌ عمليّ قاطع، وليس مساواةً
 * رياضيّة. هذا القيد يُقال في اللوح ولا يُخفى.
 */
export interface AssetDuplicateGroup {
  key: string;
  fingerprint: string;
  sizeBytes: number;
  rows: AssetRow[];
  /** ما يتحرّر لو بقيت نسخة واحدة — مقيس من الحجم، لا مقدَّر. */
  wastedBytes: number;
}

export function duplicateAssets(rows: readonly AssetRow[]): AssetDuplicateGroup[] {
  const groups = new Map<string, AssetDuplicateGroup>();

  for (const row of rows) {
    // بصمة فارغة لا تُجمَّع: غياب البصمة ليس تطابقًا مع غيابٍ آخر.
    if (row.fingerprint === '') continue;
    const key = `${row.fingerprint}:${row.sizeBytes}`;
    const group = groups.get(key);
    if (group) group.rows.push(row);
    else
      groups.set(key, {
        key,
        fingerprint: row.fingerprint,
        sizeBytes: row.sizeBytes,
        rows: [row],
        wastedBytes: 0,
      });
  }

  return [...groups.values()]
    .filter((group) => group.rows.length > 1)
    .map((group) => ({ ...group, wastedBytes: group.sizeBytes * (group.rows.length - 1) }))
    .sort((a, b) => b.wastedBytes - a.wastedBytes);
}

/* ───── نصوص الحالة الموحّدة ───── */

/** الحد الأقصى الذي تقبله النقطة العامة (`limit` ≤ 24) — سقف مصدر لا اختيار. */
const PRODUCTS_LIMIT = 24;

export const PRODUCTS_PATH = `/products?limit=${PRODUCTS_LIMIT}`;

/**
 * أسماء النقاط الغائبة كما تُنطق في الواجهة — مصدر واحد لكل الشاشتين.
 *
 * أربع مُدخلات حُذفت من هنا لأنّها كانت **غيابًا كاذبًا**، وهو أسوأ من
 * الغياب: `GET /api/v1/admin/media` قائمة ومُختبَرة
 * (`MediaLibraryController::index`)، تسرد المكتبة كاملة بترقيم صفحات،
 * وتُرشّح اليتيم بـ`?usage=orphan`، وتُخرج `sizeBytes` و`fingerprint` في كلّ
 * صفّ، و`GET /api/v1/admin/media/{id}` يعيد مواضع الاستعمال. فكان اللوح
 * يعلن عجزًا عن شيءٍ يفعله الخادم — وإعلان العجز إنّما يصدق حين يكون حقًّا.
 *
 * ما بقي هنا غيابٌ حقيقيّ: لا مسار في `routes/api-admin.php` يرفع أصلًا إلى
 * المكتبة ولا يحذفه منها ولا يحسّن صورة. (رفع الوسائط اليوم مقيَّد بمنتج
 * `‎/admin/products/{id}/media‎` أو بالاستوديو، وليس مكتبةً.)
 */
export const MISSING = {
  upload: 'POST /api/v1/admin/media',
  remove: 'DELETE /api/v1/admin/media/{id}',
  optimize: 'POST /api/v1/admin/media/optimize',
  optimizeStats: 'GET /api/v1/admin/media/optimize/stats',
  optimizeLog: 'GET /api/v1/admin/media/optimize/log',
  restore: 'POST /api/v1/admin/media/{id}/restore',
} as const;

/**
 * نطاق ما هو محمَّل فعلًا — يُعرض حرفيًا فوق كل عرض.
 *
 * لا يقول «لا نقطة نهاية تسرد المكتبة» بعد اليوم: تسردها لوحُ «مكتبة الأصول»
 * أعلى الشاشة من `GET /api/v1/admin/media`. ما تبقّى صحيحًا أنّ **هذا** العرض
 * مبنيّ على الأغلفة، لأنّه العرض الوحيد الذي يحتاج رابط الملفّ وأبعاده — وهما
 * وحدهما ما لا يُخرجه صفُّ الأصل.
 */
export const SOURCE_SCOPE =
  'هذا العرض مبنيّ على أغلفة المنتجات المنشورة وحدها، كما تعيدها GET /api/v1/products بحدّ ٢٤ منتجًا: ' +
  'هي المصدر الوحيد الذي يحمل رابط الصورة وأبعادها. أمّا جرد المكتبة كاملًا — بأحجامه وبصماته ' +
  'ويتاماه — فيقرأه لوح «مكتبة الأصول» أعلاه من GET /api/v1/admin/media.';
