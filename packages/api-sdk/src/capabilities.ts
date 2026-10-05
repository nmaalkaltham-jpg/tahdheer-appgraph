/**
 * مولَّد — لا يُحرَّر.
 *
 * المصدر: docs/capabilities.json
 * التوليد: node scripts/generate-sdk.mjs
 *
 * القدرة غير المتاحة تُغلق بوّابتُها قبل أيّ نداء: لا طلب يُرسَل، ولا
 * يُستعمل ٤٠٤ كاشفًا للتوفّر.
 */

export type CapabilityStatus = 'COMPLETE' | 'ADMIN_UI' | 'API_ONLY' | 'SYSTEM_ONLY' | 'DEFERRED';

export const capabilities = {
  "ai.gateway": { status: "COMPLETE" as CapabilityStatus, title: "بوّابة الذكاء المحلّيّة" },
  "ai.usage": { status: "ADMIN_UI" as CapabilityStatus, title: "محاسبة استهلاك الذكاء الاصطناعيّ" },
  "analytics.carts": { status: "ADMIN_UI" as CapabilityStatus, title: "تحليلات السلال المتروكة" },
  "analytics.dashboard": { status: "COMPLETE" as CapabilityStatus, title: "لوحة المؤشّرات" },
  "analytics.funnel": { status: "ADMIN_UI" as CapabilityStatus, title: "قمع الشراء" },
  "analytics.intelligence": { status: "DEFERRED" as CapabilityStatus, title: "ذكاء أصول المنتجات" },
  "analytics.os": { status: "ADMIN_UI" as CapabilityStatus, title: "مركز العمليّات التحليليّة" },
  "audit": { status: "COMPLETE" as CapabilityStatus, title: "سجلّ التدقيق" },
  "auth": { status: "SYSTEM_ONLY" as CapabilityStatus, title: "جلسة اللوحة" },
  "catalog.products": { status: "COMPLETE" as CapabilityStatus, title: "المنتجات" },
  "catalog.quality": { status: "COMPLETE" as CapabilityStatus, title: "جودة الكتالوج" },
  "catalog.intelligence": { status: "COMPLETE" as CapabilityStatus, title: "ذكاء المنتج" },
  "content.home": { status: "COMPLETE" as CapabilityStatus, title: "محتوى الصفحة الرئيسة" },
  "crawl": { status: "COMPLETE" as CapabilityStatus, title: "زحف الموقع" },
  "customers": { status: "COMPLETE" as CapabilityStatus, title: "العملاء والاستحقاقات" },
  "dashboard": { status: "COMPLETE" as CapabilityStatus, title: "لوحة التشغيل" },
  "demand": { status: "COMPLETE" as CapabilityStatus, title: "الطلب غير الملبّى" },
  "design.theme": { status: "API_ONLY" as CapabilityStatus, title: "وثيقة السمة" },
  "diagnostics": { status: "ADMIN_UI" as CapabilityStatus, title: "تشخيص اللوحة" },
  "growth": { status: "ADMIN_UI" as CapabilityStatus, title: "لوحة النموّ" },
  "integrations": { status: "COMPLETE" as CapabilityStatus, title: "مفاتيح الربط" },
  "media.library": { status: "ADMIN_UI" as CapabilityStatus, title: "مكتبة الوسائط" },
  "navigation": { status: "COMPLETE" as CapabilityStatus, title: "قوائم المتجر" },
  "operations.asset_sources": { status: "COMPLETE" as CapabilityStatus, title: "مصادر الأصول" },
  "operations.cases": { status: "COMPLETE" as CapabilityStatus, title: "القضايا: احتيال وحوادث ودعم" },
  "operations.fulfillment": { status: "COMPLETE" as CapabilityStatus, title: "التنفيذ" },
  "operations.inventory": { status: "COMPLETE" as CapabilityStatus, title: "المخزون وحركاته" },
  "operations.returns": { status: "COMPLETE" as CapabilityStatus, title: "الإرجاعات" },
  "operations.risk": { status: "COMPLETE" as CapabilityStatus, title: "مراجعة المخاطر" },
  "operations.shipments": { status: "COMPLETE" as CapabilityStatus, title: "الشحنات" },
  "orders": { status: "COMPLETE" as CapabilityStatus, title: "الطلبات والملاحظات" },
  "payments.center": { status: "COMPLETE" as CapabilityStatus, title: "مركز المدفوعات" },
  "payments.webhooks": { status: "COMPLETE" as CapabilityStatus, title: "أحداث الدفع الواردة" },
  "performance": { status: "DEFERRED" as CapabilityStatus, title: "قياسات الأداء" },
  "promotions": { status: "COMPLETE" as CapabilityStatus, title: "رموز الخصم" },
  "redirects": { status: "COMPLETE" as CapabilityStatus, title: "إدارة التحويلات" },
  "reviews": { status: "COMPLETE" as CapabilityStatus, title: "المراجعات" },
  "search": { status: "COMPLETE" as CapabilityStatus, title: "البحث والمرادفات" },
  "seo.health": { status: "ADMIN_UI" as CapabilityStatus, title: "صحّة SEO" },
  "seo.international": { status: "COMPLETE" as CapabilityStatus, title: "التدويل وhreflang" },
  "seo.overview": { status: "ADMIN_UI" as CapabilityStatus, title: "نظرة SEO" },
  "seo.sitemap_audit": { status: "COMPLETE" as CapabilityStatus, title: "تدقيق خريطة الموقع" },
  "settings": { status: "ADMIN_UI" as CapabilityStatus, title: "الإعدادات" },
  "settings.email": { status: "DEFERRED" as CapabilityStatus, title: "إعدادات البريد" },
  "settings.storage": { status: "DEFERRED" as CapabilityStatus, title: "إعدادات التخزين والمزوّدين" },
  "shipping.rules": { status: "COMPLETE" as CapabilityStatus, title: "مناطق الشحن وقواعده" },
  "system.health": { status: "COMPLETE" as CapabilityStatus, title: "صحّة النظام" },
  "url.platform": { status: "COMPLETE" as CapabilityStatus, title: "منصّة العناوين" },
  "users": { status: "COMPLETE" as CapabilityStatus, title: "مستخدمو اللوحة" },
} as const;

export type CapabilityKey = keyof typeof capabilities;

/** متاحةٌ = مبنيّة فعلًا. `DEFERRED` وحدها غير متاحة. */
export function isCapabilityAvailable(key: CapabilityKey): boolean {
  return capabilities[key].status !== 'DEFERRED';
}

export function capabilityTitle(key: CapabilityKey): string {
  return capabilities[key].title;
}
