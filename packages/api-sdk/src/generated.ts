/**
 * مولَّد — لا يُحرَّر.
 *
 * المصدر: docs/api/openapi.json (المولَّدة بدورها من موجّه Laravel).
 * التوليد: node scripts/generate-sdk.mjs
 *
 * أنواع الاستجابات غائبة عمدًا: الوثيقة لا تصفها، والنوع المخترع أسوأ من
 * غيابه لأنّه يؤكّد حقلًا قد لا يُرسَل.
 */

/** قاعدة تحقّقٍ لم تُقرأ ساكنةً — قد يقبل الخادم حقولًا إضافيّة. */
export interface AdminAdminNoteStoreBody {
    "body": string;
    "entityId": number;
    "entityType": string;
  }

export interface AdminAdminNoteUpdateBody {
    "body": string;
  }

/** قاعدة تحقّقٍ لم تُقرأ ساكنةً — قد يقبل الخادم حقولًا إضافيّة. */
export interface AdminAdminUserStoreBody {
    "email": string;
    "name": string;
    "role": string;
  }

/** قاعدة تحقّقٍ لم تُقرأ ساكنةً — قد يقبل الخادم حقولًا إضافيّة. */
export interface AdminAdminUserUpdateBody {
    "email"?: string;
    "name"?: string;
    "role"?: string;
  }

/** قاعدة تحقّقٍ لم تُقرأ ساكنةً — قد يقبل الخادم حقولًا إضافيّة. */
export interface AdminAiStoreRequestBody {
    "entityId": number;
    "entityType": string;
  }

export interface AdminAiSuggestionRejectBody {
    "reason": string;
  }

export interface AdminAuthLoginBody {
    "email": string;
    "password": string;
  }

export interface AdminCaseAssignBody {
    "adminUserId"?: number | null;
  }

export interface AdminCaseNoteBody {
    "note": string;
  }

export interface AdminCaseRemediateBody {
    "action": string;
    "idempotencyKey": string;
    "reason"?: string | null;
  }

export interface AdminCaseTransitionBody {
    "note"?: string | null;
    "preventiveAction"?: string | null;
    "resolutionCode"?: string | null;
    "rootCause"?: string | null;
    "state": "ASSIGNED" | "INVESTIGATING" | "WAITING" | "RESOLVED" | "CLOSED";
  }

/** قاعدة تحقّقٍ لم تُقرأ ساكنةً — قد يقبل الخادم حقولًا إضافيّة. */
export interface AdminDemandUpdateBody {
    "decisionNote"?: string | null;
  }

/** قاعدة تحقّقٍ لم تُقرأ ساكنةً — قد يقبل الخادم حقولًا إضافيّة. */
export interface AdminEntitlementRevokeBody {
    "policy": string;
    "reason": string;
  }

export interface AdminHomeContentUpdateBody {
    "payload"?: unknown[];
  }

export interface AdminI18nUpdateLocaleBody {
    "isActive"?: boolean;
    "isDefault"?: string;
    "isRoutable"?: string;
    "position"?: number;
  }

/** قاعدة تحقّقٍ لم تُقرأ ساكنةً — قد يقبل الخادم حقولًا إضافيّة. */
export interface AdminIntegrationStoreBody {
    "confirmLive"?: boolean;
    "environment": string;
    "field": string;
    "provider": string;
    "reason"?: string | null;
    "value": string;
  }

/** قاعدة تحقّقٍ لم تُقرأ ساكنةً — قد يقبل الخادم حقولًا إضافيّة. */
export interface AdminNavigationLinkCheckBody {
    "urls": unknown[];
  }

export interface AdminNavigationPublishBody {
    "baseRevision"?: number | null;
    "idempotencyKey": string;
  }

export interface AdminNavigationRollbackBody {
    "operationId": string;
  }

/** قاعدة تحقّقٍ لم تُقرأ ساكنةً — قد يقبل الخادم حقولًا إضافيّة. */
export interface AdminNavigationUpdateBody {
    "name"?: string;
    "version": number;
  }

export interface AdminOperationsActionReviewRiskBody {
    "note": string;
    "outcome": "CLEARED" | "CONFIRMED_ABUSE";
  }

export interface AdminOperationsActionStockMovementBody {
    "idempotencyKey": string;
    "kind": "RECEIPT" | "ADJUSTMENT";
    "locationId": number;
    "quantity": number;
    "reason": string;
    "variantId": number;
  }

/** قاعدة تحقّقٍ لم تُقرأ ساكنةً — قد يقبل الخادم حقولًا إضافيّة. */
export interface AdminOperationsActionUpdateReturnBody {
    "note"?: string | null;
    "status": string;
  }

/** قاعدة تحقّقٍ لم تُقرأ ساكنةً — قد يقبل الخادم حقولًا إضافيّة. */
export interface AdminOperationsActionUpdateShipmentBody {
    "reason"?: string | null;
    "status": string;
    "trackingNumber"?: string | null;
  }

/** قاعدة تحقّقٍ لم تُقرأ ساكنةً — قد يقبل الخادم حقولًا إضافيّة. */
export interface AdminOrderRefundBody {
    "idempotencyKey": string;
    "policy": string;
    "reason"?: string | null;
  }

export interface AdminPaymentLedgerRefundCaptureBody {
    "amountMinor": number;
    "idempotencyKey": string;
    "reason": string;
  }

export interface AdminProductBundleStoreBody {
    "componentVariantId": number;
    "quantity"?: number;
    "required"?: boolean;
  }

export interface AdminProductDriveReplaceBody {
    "fileId": string;
  }

export interface AdminProductDriveStoreBody {
    "customerLabel"?: string | null;
    "fileId": string;
    "role"?: "main" | "companion" | "sample" | "teacher_guide" | "answer_key";
    "variantId"?: number | null;
  }

/** قاعدة تحقّقٍ لم تُقرأ ساكنةً — قد يقبل الخادم حقولًا إضافيّة. */
export interface AdminProductFileReorderBody {
    "order": unknown[];
  }

/** قاعدة تحقّقٍ لم تُقرأ ساكنةً — قد يقبل الخادم حقولًا إضافيّة. */
export interface AdminProductFileStoreBody {
    "label"?: string | null;
  }

/** قاعدة تحقّقٍ لم تُقرأ ساكنةً — قد يقبل الخادم حقولًا إضافيّة. */
export interface AdminProductFileUpdateBody {
    "label"?: string | null;
    "position"?: number;
  }

export interface AdminProductLayoutSuggestBody {
    "count"?: number;
    "field": "title" | "shortDesc" | "description" | "seoTitle" | "seoDesc" | "altText" | "faq";
  }

export interface AdminProductLayoutUpdateBody {
    "layout": unknown[];
    "version": number;
  }

/** قاعدة تحقّقٍ لم تُقرأ ساكنةً — قد يقبل الخادم حقولًا إضافيّة. */
export interface AdminProductMediaReorderBody {
    "order": unknown[];
  }

/** قاعدة تحقّقٍ لم تُقرأ ساكنةً — قد يقبل الخادم حقولًا إضافيّة. */
export interface AdminProductMediaStoreBody {
    "alt"?: string | null;
    "isCover"?: boolean;
  }

export interface AdminProductMediaUpdateBody {
    "alt"?: string | null;
    "isCover"?: boolean;
    "position"?: number;
  }

export interface AdminProductSeoUpdateBody {
    "canonicalOverride"?: string | null;
    "quickAnswer"?: string | null;
    "seoDesc"?: string | null;
    "seoTitle"?: string | null;
    "synonyms"?: string | null;
  }

/** قاعدة تحقّقٍ لم تُقرأ ساكنةً — قد يقبل الخادم حقولًا إضافيّة. */
export interface AdminProductStoreBody {
    "description"?: string | null;
    "editable"?: "yes" | "no" | "unset";
    "facts"?: unknown[];
    "format"?: string | null;
    "fulfillmentStrategies"?: unknown[];
    "gradeId"?: number | null;
    "productNature": "DIGITAL" | "PHYSICAL" | "SERVICE" | "HYBRID" | "ACCESS_BASED" | "COMPOSITE";
    "regularMinor"?: number;
    "resourceType"?: string | null;
    "saleFrom"?: string | null;
    "saleMinor"?: number | null;
    "saleTo"?: string | null;
    "semesterId"?: number | null;
    "shortDesc"?: string | null;
    "sku"?: string | null;
    "slug": string;
    "slugForce"?: boolean;
    "slugReason"?: string | null;
    "stageId"?: number | null;
    "subjectId"?: number | null;
    "subtitle"?: string | null;
    "title": string;
  }

/** قاعدة تحقّقٍ لم تُقرأ ساكنةً — قد يقبل الخادم حقولًا إضافيّة. */
export interface AdminProductTranslationUpdateBody {
    "description"?: string | null;
    "isIndexable"?: boolean;
    "shortDesc"?: string | null;
    "slug"?: string | null;
    "subtitle"?: string | null;
    "title"?: string | null;
  }

/** قاعدة تحقّقٍ لم تُقرأ ساكنةً — قد يقبل الخادم حقولًا إضافيّة. */
export interface AdminProductUpdateBody {
    "description"?: string | null;
    "editable"?: "yes" | "no" | "unset";
    "facts"?: unknown[];
    "format"?: string | null;
    "fulfillmentStrategies"?: unknown[];
    "gradeId"?: number | null;
    "productNature"?: "DIGITAL" | "PHYSICAL" | "SERVICE" | "HYBRID" | "ACCESS_BASED" | "COMPOSITE";
    "regularMinor"?: number;
    "resourceType"?: string | null;
    "saleFrom"?: string | null;
    "saleMinor"?: number | null;
    "saleTo"?: string | null;
    "semesterId"?: number | null;
    "shortDesc"?: string | null;
    "sku"?: string | null;
    "slug"?: string;
    "slugForce"?: boolean;
    "slugReason"?: string | null;
    "stageId"?: number | null;
    "subjectId"?: number | null;
    "subtitle"?: string | null;
    "title"?: string;
  }

/** قاعدة تحقّقٍ لم تُقرأ ساكنةً — قد يقبل الخادم حقولًا إضافيّة. */
export interface AdminProductVariantStoreBody {
    "barcode"?: string | null;
    "billingIntervalCount"?: number | null;
    "billingIntervalUnit"?: "DAY" | "WEEK" | "MONTH" | "YEAR";
    "format"?: string | null;
    "fulfillmentStrategies"?: unknown[];
    "gtin"?: string | null;
    "heightMm"?: number | null;
    "inventoryStrategy"?: "UNLIMITED" | "TRACKED" | "LICENSE_POOL" | "SEAT_CAPACITY" | "TIME_CAPACITY" | "MADE_TO_ORDER" | "PREORDER" | "BACKORDER" | "EXTERNAL";
    "isbn"?: string | null;
    "lengthMm"?: number | null;
    "mpn"?: string | null;
    "offerStatus": "draft" | "active" | "paused" | "retired";
    "position"?: number;
    "regularMinor": number;
    "saleMinor"?: number | null;
    "sellingModel": "ONE_TIME" | "SUBSCRIPTION" | "RECURRING" | "PREORDER" | "BACKORDER" | "RENTAL" | "RESERVATION" | "BOOKING" | "QUOTE_REQUIRED" | "PAY_WHAT_YOU_WANT" | "FREE" | "LICENSE_PURCHASE" | "MEMBERSHIP";
    "sku": string;
    "slug": string;
    "status": "draft" | "published" | "retired";
    "title"?: string | null;
    "trialDays"?: number | null;
    "weightGrams"?: number | null;
    "widthMm"?: number | null;
  }

/** قاعدة تحقّقٍ لم تُقرأ ساكنةً — قد يقبل الخادم حقولًا إضافيّة. */
export interface AdminProductVariantUpdateBody {
    "barcode"?: string | null;
    "billingIntervalCount"?: number | null;
    "billingIntervalUnit"?: "DAY" | "WEEK" | "MONTH" | "YEAR";
    "format"?: string | null;
    "fulfillmentStrategies"?: unknown[];
    "gtin"?: string | null;
    "heightMm"?: number | null;
    "inventoryStrategy"?: "UNLIMITED" | "TRACKED" | "LICENSE_POOL" | "SEAT_CAPACITY" | "TIME_CAPACITY" | "MADE_TO_ORDER" | "PREORDER" | "BACKORDER" | "EXTERNAL";
    "isbn"?: string | null;
    "lengthMm"?: number | null;
    "mpn"?: string | null;
    "offerStatus"?: "draft" | "active" | "paused" | "retired";
    "position"?: number;
    "regularMinor"?: number;
    "saleMinor"?: number | null;
    "sellingModel"?: "ONE_TIME" | "SUBSCRIPTION" | "RECURRING" | "PREORDER" | "BACKORDER" | "RENTAL" | "RESERVATION" | "BOOKING" | "QUOTE_REQUIRED" | "PAY_WHAT_YOU_WANT" | "FREE" | "LICENSE_PURCHASE" | "MEMBERSHIP";
    "sku"?: string;
    "slug"?: string;
    "status"?: "draft" | "published" | "retired";
    "title"?: string | null;
    "trialDays"?: number | null;
    "weightGrams"?: number | null;
    "widthMm"?: number | null;
  }

export interface AdminProductWithdrawBody {
    "to": "retired" | "draft";
  }

export interface AdminReviewRejectBody {
    "note"?: string | null;
  }

export interface AdminReviewReplyBody {
    "body": string;
  }

/** قاعدة تحقّقٍ لم تُقرأ ساكنةً — قد يقبل الخادم حقولًا إضافيّة. */
export interface AdminSearchStoreSynonymBody {
    "direction"?: "one_way" | "two_way";
    "scope"?: string | null;
    "state"?: string;
    "synonym": string;
    "term": string;
  }

/** قاعدة تحقّقٍ لم تُقرأ ساكنةً — قد يقبل الخادم حقولًا إضافيّة. */
export interface AdminSearchUpdateSynonymBody {
    "action"?: "rollback";
    "direction"?: "one_way" | "two_way";
    "scope"?: string | null;
    "state"?: string;
  }

/** قاعدة تحقّقٍ لم تُقرأ ساكنةً — قد يقبل الخادم حقولًا إضافيّة. */
export interface AdminShippingPreviewBody {
    "countryCode": string;
    "orderMinor": number;
    "region"?: string | null;
    "shippingClassIds"?: unknown[];
    "weightGrams": number;
  }

/** قاعدة تحقّقٍ لم تُقرأ ساكنةً — قد يقبل الخادم حقولًا إضافيّة. */
export interface AdminShippingStoreZoneBody {
    "active"?: boolean;
    "code": string;
    "countryCodes": unknown[];
    "name": string;
    "priority"?: number;
    "regions"?: unknown[];
  }

/** قاعدة تحقّقٍ لم تُقرأ ساكنةً — قد يقبل الخادم حقولًا إضافيّة. */
export interface AdminShippingUpdateZoneBody {
    "active"?: boolean;
    "countryCodes"?: unknown[];
    "name"?: string;
    "priority"?: number;
    "regions"?: unknown[];
  }

export interface AdminThemePreviewBody {
    "document": unknown[];
  }

export interface AdminThemeRestoreBody {
    "id": number;
  }

export interface AdminThemeUpdateBody {
    "baseEtag"?: string | null;
    "document": unknown[];
  }

export interface AdminUrlPlatformAdminChangeSlugBody {
    "expectedVersion": number;
    "force"?: boolean;
    "reason": string;
    "slug": string;
  }

export interface AdminUrlPlatformAdminRollbackBody {
    "historyId": number;
    "reason"?: string | null;
  }

export interface AdminUrlPlatformAdminSuggestBody {
    "candidate"?: string | null;
    "locale"?: string;
    "title": string;
  }

export interface AssistantAskBody {
    "question": string;
  }

export interface CartAddItemBody {
    "productId": number;
    "qty"?: number;
    "variantId"?: number | null;
  }

export interface CartApplyCouponBody {
    "code": string;
  }

/** قاعدة تحقّقٍ لم تُقرأ ساكنةً — قد يقبل الخادم حقولًا إضافيّة. */
export interface CheckoutStoreBody {
    "email": string;
    "marketingOptIn"?: boolean;
    "name": string;
    "phone": string;
    "policyConsent"?: string;
    "shippingAddress"?: unknown[];
    "shippingAddress.buildingNumber"?: string | null;
    "shippingAddress.city"?: string;
    "shippingAddress.countryCode"?: string;
    "shippingAddress.district"?: string | null;
    "shippingAddress.notes"?: string | null;
    "shippingAddress.phone"?: string | null;
    "shippingAddress.postalCode"?: string | null;
    "shippingAddress.recipientName"?: string | null;
    "shippingAddress.region"?: string | null;
    "shippingAddress.secondaryNumber"?: string | null;
    "shippingAddress.street"?: string | null;
    "taxProfile"?: unknown[] | null;
    "taxProfile.orgName"?: string;
    "taxProfile.vatNumber"?: string;
  }

export interface ConsentStoreBody {
    "granted": boolean;
  }

export interface RedirectReportNotFoundBody {
    "path": string;
    "referer"?: string | null;
  }

/** قاعدة تحقّقٍ لم تُقرأ ساكنةً — قد يقبل الخادم حقولًا إضافيّة. */
export interface ReviewStoreBody {
    "authorCity"?: string | null;
    "authorRole"?: string | null;
    "body": string;
    "categoryScores"?: unknown[] | null;
    "rating": number;
    "title"?: string | null;
  }

export const operations = {
  adminAdminNoteDestroy: {
    method: "DELETE",
    path: "/api/v1/admin/notes/{id}",
    params: ["id"],
    permissions: ["notes.write"],
    body: null as never,
  },
  adminAdminNoteIndex: {
    method: "GET",
    path: "/api/v1/admin/notes",
    params: [],
    permissions: [],
    body: null as never,
  },
  adminAdminNoteStore: {
    method: "POST",
    path: "/api/v1/admin/notes",
    params: [],
    permissions: ["notes.write"],
    body: null as unknown as AdminAdminNoteStoreBody,
  },
  adminAdminNoteUpdate: {
    method: "PATCH",
    path: "/api/v1/admin/notes/{id}",
    params: ["id"],
    permissions: ["notes.write"],
    body: null as unknown as AdminAdminNoteUpdateBody,
  },
  adminAdminPreferencesShow: {
    method: "GET",
    path: "/api/v1/admin/me/preferences",
    params: [],
    permissions: [],
    body: null as never,
  },
  adminAdminPreferencesUpdate: {
    method: "PUT",
    path: "/api/v1/admin/me/preferences",
    params: [],
    permissions: [],
    body: null as never,
  },
  adminAdminUserDestroy: {
    method: "DELETE",
    path: "/api/v1/admin/users/{id}",
    params: ["id"],
    permissions: ["users.manage"],
    body: null as never,
  },
  adminAdminUserIndex: {
    method: "GET",
    path: "/api/v1/admin/users",
    params: [],
    permissions: ["users.manage"],
    body: null as never,
  },
  adminAdminUserResetPassword: {
    method: "POST",
    path: "/api/v1/admin/users/{id}/password",
    params: ["id"],
    permissions: ["users.manage"],
    body: null as never,
  },
  adminAdminUserStore: {
    method: "POST",
    path: "/api/v1/admin/users",
    params: [],
    permissions: ["users.manage"],
    body: null as unknown as AdminAdminUserStoreBody,
  },
  adminAdminUserUpdate: {
    method: "PATCH",
    path: "/api/v1/admin/users/{id}",
    params: ["id"],
    permissions: ["users.manage"],
    body: null as unknown as AdminAdminUserUpdateBody,
  },
  adminAiCancelRequest: {
    method: "POST",
    path: "/api/v1/admin/ai/requests/{id}/cancel",
    params: ["id"],
    permissions: ["products.update"],
    body: null as never,
  },
  adminAiClearCache: {
    method: "POST",
    path: "/api/v1/admin/ai/cache/clear",
    params: [],
    permissions: ["system.manage"],
    body: null as never,
  },
  adminAiPause: {
    method: "POST",
    path: "/api/v1/admin/ai/pause",
    params: [],
    permissions: ["system.manage"],
    body: null as never,
  },
  adminAiProbe: {
    method: "POST",
    path: "/api/v1/admin/ai/probe",
    params: [],
    permissions: ["audit.read"],
    body: null as never,
  },
  adminAiRequests: {
    method: "GET",
    path: "/api/v1/admin/ai/requests",
    params: [],
    permissions: ["audit.read"],
    body: null as never,
  },
  adminAiResume: {
    method: "POST",
    path: "/api/v1/admin/ai/resume",
    params: [],
    permissions: ["system.manage"],
    body: null as never,
  },
  adminAiRetryRequest: {
    method: "POST",
    path: "/api/v1/admin/ai/requests/{id}/retry",
    params: ["id"],
    permissions: ["products.update"],
    body: null as never,
  },
  adminAiShowRequest: {
    method: "GET",
    path: "/api/v1/admin/ai/requests/{id}",
    params: ["id"],
    permissions: ["audit.read"],
    body: null as never,
  },
  adminAiStatus: {
    method: "GET",
    path: "/api/v1/admin/ai",
    params: [],
    permissions: ["audit.read"],
    body: null as never,
  },
  adminAiStoreRequest: {
    method: "POST",
    path: "/api/v1/admin/ai/requests",
    params: [],
    permissions: ["products.update"],
    body: null as unknown as AdminAiStoreRequestBody,
  },
  adminAiSuggestionApprove: {
    method: "POST",
    path: "/api/v1/admin/ai/suggestions/{id}/approve",
    params: ["id"],
    permissions: ["products.update"],
    body: null as never,
  },
  adminAiSuggestionEnrichProduct: {
    method: "POST",
    path: "/api/v1/admin/products/{id}/ai/enrich",
    params: ["id"],
    permissions: ["products.update"],
    body: null as never,
  },
  adminAiSuggestionIndex: {
    method: "GET",
    path: "/api/v1/admin/ai/suggestions",
    params: [],
    permissions: ["products.read"],
    body: null as never,
  },
  adminAiSuggestionReject: {
    method: "POST",
    path: "/api/v1/admin/ai/suggestions/{id}/reject",
    params: ["id"],
    permissions: ["products.update"],
    body: null as unknown as AdminAiSuggestionRejectBody,
  },
  adminAuditExport: {
    method: "GET",
    path: "/api/v1/admin/audit/export",
    params: [],
    permissions: ["audit.read"],
    body: null as never,
  },
  adminAuditIndex: {
    method: "GET",
    path: "/api/v1/admin/audit",
    params: [],
    permissions: ["audit.read"],
    body: null as never,
  },
  adminAuthLogin: {
    method: "POST",
    path: "/api/v1/admin/auth/login",
    params: [],
    permissions: [],
    body: null as unknown as AdminAuthLoginBody,
  },
  adminAuthLogout: {
    method: "POST",
    path: "/api/v1/admin/auth/logout",
    params: [],
    permissions: [],
    body: null as never,
  },
  adminAuthMe: {
    method: "GET",
    path: "/api/v1/admin/me",
    params: [],
    permissions: [],
    body: null as never,
  },
  adminCaseAssign: {
    method: "PATCH",
    path: "/api/v1/admin/cases/{id}/assignee",
    params: ["id"],
    permissions: ["operations.manage"],
    body: null as unknown as AdminCaseAssignBody,
  },
  adminCaseIndex: {
    method: "GET",
    path: "/api/v1/admin/cases",
    params: [],
    permissions: ["orders.read"],
    body: null as never,
  },
  adminCaseNote: {
    method: "POST",
    path: "/api/v1/admin/cases/{id}/notes",
    params: ["id"],
    permissions: ["operations.manage"],
    body: null as unknown as AdminCaseNoteBody,
  },
  adminCaseRemediate: {
    method: "POST",
    path: "/api/v1/admin/cases/{id}/remediations",
    params: ["id"],
    permissions: ["operations.manage"],
    body: null as unknown as AdminCaseRemediateBody,
  },
  adminCaseShow: {
    method: "GET",
    path: "/api/v1/admin/cases/{id}",
    params: ["id"],
    permissions: ["orders.read"],
    body: null as never,
  },
  adminCaseTransition: {
    method: "PATCH",
    path: "/api/v1/admin/cases/{id}",
    params: ["id"],
    permissions: ["operations.manage"],
    body: null as unknown as AdminCaseTransitionBody,
  },
  adminCatalogQualityIndex: {
    method: "GET",
    path: "/api/v1/admin/catalog/quality",
    params: [],
    permissions: ["products.read"],
    body: null as never,
  },
  adminCouponDestroy: {
    method: "DELETE",
    path: "/api/v1/admin/coupons/{coupon}",
    params: ["coupon"],
    permissions: ["promotions.manage"],
    body: null as never,
  },
  adminCouponExpire: {
    method: "POST",
    path: "/api/v1/admin/coupons/{coupon}/expire",
    params: ["coupon"],
    permissions: ["promotions.manage"],
    body: null as never,
  },
  adminCouponIndex: {
    method: "GET",
    path: "/api/v1/admin/coupons",
    params: [],
    permissions: ["promotions.manage"],
    body: null as never,
  },
  adminCouponStore: {
    method: "POST",
    path: "/api/v1/admin/coupons",
    params: [],
    permissions: ["promotions.manage"],
    body: null as never,
  },
  adminCouponUpdate: {
    method: "PATCH",
    path: "/api/v1/admin/coupons/{coupon}",
    params: ["coupon"],
    permissions: ["promotions.manage"],
    body: null as never,
  },
  adminCrawlPage: {
    method: "GET",
    path: "/api/v1/admin/crawl/page",
    params: [],
    permissions: ["analytics.read"],
    body: null as never,
  },
  adminCrawlRun: {
    method: "POST",
    path: "/api/v1/admin/crawl/run",
    params: [],
    permissions: ["search.index.manage"],
    body: null as never,
  },
  adminCrawlShow: {
    method: "GET",
    path: "/api/v1/admin/crawl",
    params: [],
    permissions: ["analytics.read"],
    body: null as never,
  },
  adminCustomerIndex: {
    method: "GET",
    path: "/api/v1/admin/customers",
    params: [],
    permissions: ["customers.read"],
    body: null as never,
  },
  adminCustomerShow: {
    method: "GET",
    path: "/api/v1/admin/customers/{id}",
    params: ["id"],
    permissions: ["customers.read"],
    body: null as never,
  },
  adminDemandIndex: {
    method: "GET",
    path: "/api/v1/admin/demand",
    params: [],
    permissions: ["analytics.read"],
    body: null as never,
  },
  adminDemandUpdate: {
    method: "PATCH",
    path: "/api/v1/admin/demand/{materialRequest}",
    params: ["materialRequest"],
    permissions: ["products.create"],
    body: null as unknown as AdminDemandUpdateBody,
  },
  adminEntitlementRevoke: {
    method: "POST",
    path: "/api/v1/admin/entitlements/{id}/revoke",
    params: ["id"],
    permissions: ["entitlements.revoke"],
    body: null as unknown as AdminEntitlementRevokeBody,
  },
  adminHomeContentIndex: {
    method: "GET",
    path: "/api/v1/admin/home-content",
    params: [],
    permissions: ["content.manage"],
    body: null as never,
  },
  adminHomeContentUpdate: {
    method: "PUT",
    path: "/api/v1/admin/home-content/{block}",
    params: ["block"],
    permissions: ["content.manage"],
    body: null as unknown as AdminHomeContentUpdateBody,
  },
  adminI18nHreflangAudit: {
    method: "GET",
    path: "/api/v1/admin/i18n/hreflang-audit",
    params: [],
    permissions: ["analytics.read"],
    body: null as never,
  },
  adminI18nOverview: {
    method: "GET",
    path: "/api/v1/admin/i18n/overview",
    params: [],
    permissions: ["analytics.read"],
    body: null as never,
  },
  adminI18nUpdateLocale: {
    method: "PATCH",
    path: "/api/v1/admin/i18n/locales/{code}",
    params: ["code"],
    permissions: ["appearance.manage"],
    body: null as unknown as AdminI18nUpdateLocaleBody,
  },
  adminInsightsIndex: {
    method: "GET",
    path: "/api/v1/admin/insights",
    params: [],
    permissions: ["analytics.read"],
    body: null as never,
  },
  adminInsightsShow: {
    method: "GET",
    path: "/api/v1/admin/insights/{domain}",
    params: ["domain"],
    permissions: ["analytics.read"],
    body: null as never,
  },
  adminIntegrationDestroy: {
    method: "DELETE",
    path: "/api/v1/admin/integrations/credentials",
    params: [],
    permissions: ["integrations.manage"],
    body: null as never,
  },
  adminIntegrationIndex: {
    method: "GET",
    path: "/api/v1/admin/integrations",
    params: [],
    permissions: ["integrations.manage"],
    body: null as never,
  },
  adminIntegrationStore: {
    method: "POST",
    path: "/api/v1/admin/integrations/credentials",
    params: [],
    permissions: ["integrations.manage"],
    body: null as unknown as AdminIntegrationStoreBody,
  },
  adminNavigationIndex: {
    method: "GET",
    path: "/api/v1/admin/navigation",
    params: [],
    permissions: ["appearance.manage"],
    body: null as never,
  },
  adminNavigationLinkCheck: {
    method: "POST",
    path: "/api/v1/admin/navigation/link-check",
    params: [],
    permissions: ["appearance.manage"],
    body: null as unknown as AdminNavigationLinkCheckBody,
  },
  adminNavigationOperation: {
    method: "GET",
    path: "/api/v1/admin/navigation/{location}/operations/{operationId}",
    params: ["location", "operationId"],
    permissions: ["appearance.manage"],
    body: null as never,
  },
  adminNavigationPages: {
    method: "GET",
    path: "/api/v1/admin/navigation/pages",
    params: [],
    permissions: ["appearance.manage"],
    body: null as never,
  },
  adminNavigationPublish: {
    method: "POST",
    path: "/api/v1/admin/navigation/{location}/publish",
    params: ["location"],
    permissions: ["appearance.manage"],
    body: null as unknown as AdminNavigationPublishBody,
  },
  adminNavigationRollback: {
    method: "POST",
    path: "/api/v1/admin/navigation/{location}/rollback",
    params: ["location"],
    permissions: ["appearance.manage"],
    body: null as unknown as AdminNavigationRollbackBody,
  },
  adminNavigationShow: {
    method: "GET",
    path: "/api/v1/admin/navigation/{location}",
    params: ["location"],
    permissions: ["appearance.manage"],
    body: null as never,
  },
  adminNavigationUpdate: {
    method: "PATCH",
    path: "/api/v1/admin/navigation/{location}",
    params: ["location"],
    permissions: ["appearance.manage"],
    body: null as unknown as AdminNavigationUpdateBody,
  },
  adminOperationsActionRetryUnit: {
    method: "POST",
    path: "/api/v1/admin/operations/fulfillment/{id}/retry",
    params: ["id"],
    permissions: ["operations.manage"],
    body: null as never,
  },
  adminOperationsActionReviewRisk: {
    method: "PATCH",
    path: "/api/v1/admin/operations/risk/{id}",
    params: ["id"],
    permissions: ["operations.manage"],
    body: null as unknown as AdminOperationsActionReviewRiskBody,
  },
  adminOperationsActionStockMovement: {
    method: "POST",
    path: "/api/v1/admin/operations/inventory/movements",
    params: [],
    permissions: ["inventory.manage"],
    body: null as unknown as AdminOperationsActionStockMovementBody,
  },
  adminOperationsActionUpdateReturn: {
    method: "PATCH",
    path: "/api/v1/admin/operations/returns/{id}",
    params: ["id"],
    permissions: ["operations.manage"],
    body: null as unknown as AdminOperationsActionUpdateReturnBody,
  },
  adminOperationsActionUpdateShipment: {
    method: "PATCH",
    path: "/api/v1/admin/operations/shipments/{id}",
    params: ["id"],
    permissions: ["operations.manage"],
    body: null as unknown as AdminOperationsActionUpdateShipmentBody,
  },
  adminOperationsActionVerifySource: {
    method: "POST",
    path: "/api/v1/admin/operations/asset-sources/{id}/verify",
    params: ["id"],
    permissions: ["operations.manage"],
    body: null as never,
  },
  adminOperationsAssetSources: {
    method: "GET",
    path: "/api/v1/admin/operations/asset-sources",
    params: [],
    permissions: ["products.read"],
    body: null as never,
  },
  adminOperationsFulfillmentUnits: {
    method: "GET",
    path: "/api/v1/admin/operations/fulfillment",
    params: [],
    permissions: ["orders.read"],
    body: null as never,
  },
  adminOperationsInventory: {
    method: "GET",
    path: "/api/v1/admin/operations/inventory",
    params: [],
    permissions: ["products.read"],
    body: null as never,
  },
  adminOperationsReturns: {
    method: "GET",
    path: "/api/v1/admin/operations/returns",
    params: [],
    permissions: ["orders.read"],
    body: null as never,
  },
  adminOperationsRiskClusters: {
    method: "GET",
    path: "/api/v1/admin/operations/risk/clusters",
    params: [],
    permissions: ["orders.read"],
    body: null as never,
  },
  adminOperationsRiskReviews: {
    method: "GET",
    path: "/api/v1/admin/operations/risk",
    params: [],
    permissions: ["orders.read"],
    body: null as never,
  },
  adminOperationsShipments: {
    method: "GET",
    path: "/api/v1/admin/operations/shipments",
    params: [],
    permissions: ["orders.read"],
    body: null as never,
  },
  adminOrderIndex: {
    method: "GET",
    path: "/api/v1/admin/orders",
    params: [],
    permissions: ["orders.read"],
    body: null as never,
  },
  adminOrderRefund: {
    method: "POST",
    path: "/api/v1/admin/orders/{id}/refund",
    params: ["id"],
    permissions: ["orders.refund"],
    body: null as unknown as AdminOrderRefundBody,
  },
  adminOrderShow: {
    method: "GET",
    path: "/api/v1/admin/orders/{id}",
    params: ["id"],
    permissions: ["orders.read"],
    body: null as never,
  },
  adminPaymentLedgerProvider: {
    method: "GET",
    path: "/api/v1/admin/payments/provider",
    params: [],
    permissions: ["payments.manage"],
    body: null as never,
  },
  adminPaymentLedgerReconciliation: {
    method: "GET",
    path: "/api/v1/admin/payments/reconciliation",
    params: [],
    permissions: ["orders.read"],
    body: null as never,
  },
  adminPaymentLedgerRefundCapture: {
    method: "POST",
    path: "/api/v1/admin/payments/transactions/{id}/refund",
    params: ["id"],
    permissions: ["orders.refund"],
    body: null as unknown as AdminPaymentLedgerRefundCaptureBody,
  },
  adminPaymentLedgerRefunds: {
    method: "GET",
    path: "/api/v1/admin/payments/refunds",
    params: [],
    permissions: ["orders.read"],
    body: null as never,
  },
  adminPaymentLedgerTransaction: {
    method: "GET",
    path: "/api/v1/admin/payments/transactions/{id}",
    params: ["id"],
    permissions: ["orders.read"],
    body: null as never,
  },
  adminPaymentLedgerTransactions: {
    method: "GET",
    path: "/api/v1/admin/payments/transactions",
    params: [],
    permissions: ["orders.read"],
    body: null as never,
  },
  adminPaymentOperationsReplayWebhook: {
    method: "POST",
    path: "/api/v1/admin/payments/webhooks/{id}/replay",
    params: ["id"],
    permissions: ["payments.manage"],
    body: null as never,
  },
  adminPaymentOperationsWebhooks: {
    method: "GET",
    path: "/api/v1/admin/payments/webhooks",
    params: [],
    permissions: ["orders.read"],
    body: null as never,
  },
  adminProductBundleDestroy: {
    method: "DELETE",
    path: "/api/v1/admin/products/{id}/bundle/{componentId}",
    params: ["id", "componentId"],
    permissions: ["products.update"],
    body: null as never,
  },
  adminProductBundleIndex: {
    method: "GET",
    path: "/api/v1/admin/products/{id}/bundle",
    params: ["id"],
    permissions: ["products.read"],
    body: null as never,
  },
  adminProductBundleStore: {
    method: "POST",
    path: "/api/v1/admin/products/{id}/bundle",
    params: ["id"],
    permissions: ["products.update"],
    body: null as unknown as AdminProductBundleStoreBody,
  },
  adminProductDriveHealth: {
    method: "GET",
    path: "/api/v1/admin/drive/health",
    params: [],
    permissions: ["products.read"],
    body: null as never,
  },
  adminProductDriveIndex: {
    method: "GET",
    path: "/api/v1/admin/products/{id}/drive",
    params: ["id"],
    permissions: ["products.read"],
    body: null as never,
  },
  adminProductDriveReplace: {
    method: "PATCH",
    path: "/api/v1/admin/products/{id}/drive/{sourceId}",
    params: ["id", "sourceId"],
    permissions: ["products.update"],
    body: null as unknown as AdminProductDriveReplaceBody,
  },
  adminProductDriveStore: {
    method: "POST",
    path: "/api/v1/admin/products/{id}/drive",
    params: ["id"],
    permissions: ["products.update"],
    body: null as unknown as AdminProductDriveStoreBody,
  },
  adminProductFileDestroy: {
    method: "DELETE",
    path: "/api/v1/admin/products/{id}/files/{fileId}",
    params: ["id", "fileId"],
    permissions: ["products.update"],
    body: null as never,
  },
  adminProductFileIndex: {
    method: "GET",
    path: "/api/v1/admin/products/{id}/files",
    params: ["id"],
    permissions: ["products.read"],
    body: null as never,
  },
  adminProductFileReorder: {
    method: "POST",
    path: "/api/v1/admin/products/{id}/files/reorder",
    params: ["id"],
    permissions: ["products.update"],
    body: null as unknown as AdminProductFileReorderBody,
  },
  adminProductFileStore: {
    method: "POST",
    path: "/api/v1/admin/products/{id}/files",
    params: ["id"],
    permissions: ["products.update"],
    body: null as unknown as AdminProductFileStoreBody,
  },
  adminProductFileUpdate: {
    method: "PATCH",
    path: "/api/v1/admin/products/{id}/files/{fileId}",
    params: ["id", "fileId"],
    permissions: ["products.update"],
    body: null as unknown as AdminProductFileUpdateBody,
  },
  adminProductIndex: {
    method: "GET",
    path: "/api/v1/admin/products",
    params: [],
    permissions: ["products.read"],
    body: null as never,
  },
  adminProductIntelligenceIndex: {
    method: "GET",
    path: "/api/v1/admin/catalog/intelligence",
    params: [],
    permissions: ["products.read"],
    body: null as never,
  },
  adminProductIntelligenceShow: {
    method: "GET",
    path: "/api/v1/admin/catalog/intelligence/{product}",
    params: ["product"],
    permissions: ["products.read"],
    body: null as never,
  },
  adminProductLayoutCoverage: {
    method: "GET",
    path: "/api/v1/admin/products/{id}/coverage",
    params: ["id"],
    permissions: ["products.read"],
    body: null as never,
  },
  adminProductLayoutPublish: {
    method: "POST",
    path: "/api/v1/admin/products/{id}/layout/publish",
    params: ["id"],
    permissions: ["products.publish"],
    body: null as never,
  },
  adminProductLayoutShow: {
    method: "GET",
    path: "/api/v1/admin/products/{id}/layout",
    params: ["id"],
    permissions: ["products.read"],
    body: null as never,
  },
  adminProductLayoutSuggest: {
    method: "POST",
    path: "/api/v1/admin/products/{id}/writing/suggest",
    params: ["id"],
    permissions: ["products.update"],
    body: null as unknown as AdminProductLayoutSuggestBody,
  },
  adminProductLayoutUpdate: {
    method: "PATCH",
    path: "/api/v1/admin/products/{id}/layout",
    params: ["id"],
    permissions: ["products.update"],
    body: null as unknown as AdminProductLayoutUpdateBody,
  },
  adminProductMediaDestroy: {
    method: "DELETE",
    path: "/api/v1/admin/products/{id}/media/{mediaId}",
    params: ["id", "mediaId"],
    permissions: ["products.update"],
    body: null as never,
  },
  adminProductMediaIndex: {
    method: "GET",
    path: "/api/v1/admin/products/{id}/media",
    params: ["id"],
    permissions: ["products.read"],
    body: null as never,
  },
  adminProductMediaReorder: {
    method: "POST",
    path: "/api/v1/admin/products/{id}/media/reorder",
    params: ["id"],
    permissions: ["products.update"],
    body: null as unknown as AdminProductMediaReorderBody,
  },
  adminProductMediaStore: {
    method: "POST",
    path: "/api/v1/admin/products/{id}/media",
    params: ["id"],
    permissions: ["products.update"],
    body: null as unknown as AdminProductMediaStoreBody,
  },
  adminProductMediaUpdate: {
    method: "PATCH",
    path: "/api/v1/admin/products/{id}/media/{mediaId}",
    params: ["id", "mediaId"],
    permissions: ["products.update"],
    body: null as unknown as AdminProductMediaUpdateBody,
  },
  adminProductPublish: {
    method: "PATCH",
    path: "/api/v1/admin/products/{id}/publish",
    params: ["id"],
    permissions: ["products.publish"],
    body: null as never,
  },
  adminProductSeoShow: {
    method: "GET",
    path: "/api/v1/admin/products/{id}/seo",
    params: ["id"],
    permissions: ["products.read"],
    body: null as never,
  },
  adminProductSeoUpdate: {
    method: "PATCH",
    path: "/api/v1/admin/products/{id}/seo",
    params: ["id"],
    permissions: ["products.update"],
    body: null as unknown as AdminProductSeoUpdateBody,
  },
  adminProductShow: {
    method: "GET",
    path: "/api/v1/admin/products/{id}",
    params: ["id"],
    permissions: ["products.read"],
    body: null as never,
  },
  adminProductStore: {
    method: "POST",
    path: "/api/v1/admin/products",
    params: [],
    permissions: ["products.create"],
    body: null as unknown as AdminProductStoreBody,
  },
  adminProductTranslationEligibility: {
    method: "GET",
    path: "/api/v1/admin/products/{id}/language-eligibility",
    params: ["id"],
    permissions: ["products.read"],
    body: null as never,
  },
  adminProductTranslationIndex: {
    method: "GET",
    path: "/api/v1/admin/products/{id}/translations",
    params: ["id"],
    permissions: ["products.read"],
    body: null as never,
  },
  adminProductTranslationPublish: {
    method: "POST",
    path: "/api/v1/admin/products/{id}/translations/{locale}/publish",
    params: ["id", "locale"],
    permissions: ["products.publish"],
    body: null as never,
  },
  adminProductTranslationUpdate: {
    method: "PATCH",
    path: "/api/v1/admin/products/{id}/translations/{locale}",
    params: ["id", "locale"],
    permissions: ["products.update"],
    body: null as unknown as AdminProductTranslationUpdateBody,
  },
  adminProductTypeIndex: {
    method: "GET",
    path: "/api/v1/admin/product-types",
    params: [],
    permissions: ["products.read"],
    body: null as never,
  },
  adminProductUpdate: {
    method: "PATCH",
    path: "/api/v1/admin/products/{id}",
    params: ["id"],
    permissions: ["products.update"],
    body: null as unknown as AdminProductUpdateBody,
  },
  adminProductVariantDestroy: {
    method: "DELETE",
    path: "/api/v1/admin/products/{id}/variants/{variantId}",
    params: ["id", "variantId"],
    permissions: ["products.update"],
    body: null as never,
  },
  adminProductVariantIndex: {
    method: "GET",
    path: "/api/v1/admin/products/{id}/variants",
    params: ["id"],
    permissions: ["products.read"],
    body: null as never,
  },
  adminProductVariantStore: {
    method: "POST",
    path: "/api/v1/admin/products/{id}/variants",
    params: ["id"],
    permissions: ["products.update"],
    body: null as unknown as AdminProductVariantStoreBody,
  },
  adminProductVariantUpdate: {
    method: "PATCH",
    path: "/api/v1/admin/products/{id}/variants/{variantId}",
    params: ["id", "variantId"],
    permissions: ["products.update"],
    body: null as unknown as AdminProductVariantUpdateBody,
  },
  adminProductWithdraw: {
    method: "PATCH",
    path: "/api/v1/admin/products/{id}/withdraw",
    params: ["id"],
    permissions: ["products.publish"],
    body: null as unknown as AdminProductWithdrawBody,
  },
  adminRedirectAdminDestroy: {
    method: "DELETE",
    path: "/api/v1/admin/redirects/{id}",
    params: ["id"],
    permissions: ["redirects.manage"],
    body: null as never,
  },
  adminRedirectAdminIndex: {
    method: "GET",
    path: "/api/v1/admin/redirects",
    params: [],
    permissions: ["redirects.manage"],
    body: null as never,
  },
  adminRedirectAdminStore: {
    method: "POST",
    path: "/api/v1/admin/redirects",
    params: [],
    permissions: ["redirects.manage"],
    body: null as never,
  },
  adminRedirectAdminUpdate: {
    method: "PATCH",
    path: "/api/v1/admin/redirects/{id}",
    params: ["id"],
    permissions: ["redirects.manage"],
    body: null as never,
  },
  adminReviewApprove: {
    method: "POST",
    path: "/api/v1/admin/reviews/{reviewId}/approve",
    params: ["reviewId"],
    permissions: ["reviews.moderate"],
    body: null as never,
  },
  adminReviewIndex: {
    method: "GET",
    path: "/api/v1/admin/reviews",
    params: [],
    permissions: ["reviews.moderate"],
    body: null as never,
  },
  adminReviewReject: {
    method: "POST",
    path: "/api/v1/admin/reviews/{reviewId}/reject",
    params: ["reviewId"],
    permissions: ["reviews.moderate"],
    body: null as unknown as AdminReviewRejectBody,
  },
  adminReviewReply: {
    method: "POST",
    path: "/api/v1/admin/reviews/{reviewId}/reply",
    params: ["reviewId"],
    permissions: ["reviews.moderate"],
    body: null as unknown as AdminReviewReplyBody,
  },
  adminSearchExplainShow: {
    method: "GET",
    path: "/api/v1/admin/search/explain",
    params: [],
    permissions: ["analytics.read"],
    body: null as never,
  },
  adminSearchIndexReindex: {
    method: "POST",
    path: "/api/v1/admin/search/index/reindex",
    params: [],
    permissions: ["search.index.manage"],
    body: null as never,
  },
  adminSearchIndexRollback: {
    method: "POST",
    path: "/api/v1/admin/search/index/rollback",
    params: [],
    permissions: ["search.index.manage"],
    body: null as never,
  },
  adminSearchIndexShow: {
    method: "GET",
    path: "/api/v1/admin/search/index",
    params: [],
    permissions: ["analytics.read"],
    body: null as never,
  },
  adminSearchQueries: {
    method: "GET",
    path: "/api/v1/admin/search/queries",
    params: [],
    permissions: ["analytics.read"],
    body: null as never,
  },
  adminSearchStoreSynonym: {
    method: "POST",
    path: "/api/v1/admin/search/synonyms",
    params: [],
    permissions: ["search.synonyms.manage"],
    body: null as unknown as AdminSearchStoreSynonymBody,
  },
  adminSearchSynonyms: {
    method: "GET",
    path: "/api/v1/admin/search/synonyms",
    params: [],
    permissions: ["search.synonyms.manage"],
    body: null as never,
  },
  adminSearchUpdateSynonym: {
    method: "PATCH",
    path: "/api/v1/admin/search/synonyms/{id}",
    params: ["id"],
    permissions: ["search.synonyms.manage"],
    body: null as unknown as AdminSearchUpdateSynonymBody,
  },
  adminShippingIndex: {
    method: "GET",
    path: "/api/v1/admin/shipping",
    params: [],
    permissions: ["orders.read"],
    body: null as never,
  },
  adminShippingPreview: {
    method: "POST",
    path: "/api/v1/admin/shipping/preview",
    params: [],
    permissions: ["orders.read"],
    body: null as unknown as AdminShippingPreviewBody,
  },
  adminShippingStoreRate: {
    method: "POST",
    path: "/api/v1/admin/shipping/rates",
    params: [],
    permissions: ["shipping.manage"],
    body: null as never,
  },
  adminShippingStoreZone: {
    method: "POST",
    path: "/api/v1/admin/shipping/zones",
    params: [],
    permissions: ["shipping.manage"],
    body: null as unknown as AdminShippingStoreZoneBody,
  },
  adminShippingUpdateRate: {
    method: "PATCH",
    path: "/api/v1/admin/shipping/rates/{id}",
    params: ["id"],
    permissions: ["shipping.manage"],
    body: null as never,
  },
  adminShippingUpdateZone: {
    method: "PATCH",
    path: "/api/v1/admin/shipping/zones/{id}",
    params: ["id"],
    permissions: ["shipping.manage"],
    body: null as unknown as AdminShippingUpdateZoneBody,
  },
  adminSitemapAuditShow: {
    method: "GET",
    path: "/api/v1/admin/seo/sitemap/audit",
    params: [],
    permissions: ["analytics.read"],
    body: null as never,
  },
  adminSystemDeadEvents: {
    method: "GET",
    path: "/api/v1/admin/system/outbox/dead",
    params: [],
    permissions: ["audit.read"],
    body: null as never,
  },
  adminSystemHealth: {
    method: "GET",
    path: "/api/v1/admin/system",
    params: [],
    permissions: ["audit.read"],
    body: null as never,
  },
  adminSystemReplayOutboxEvent: {
    method: "POST",
    path: "/api/v1/admin/system/outbox/{id}/replay",
    params: ["id"],
    permissions: ["system.manage"],
    body: null as never,
  },
  adminThemeDestroy: {
    method: "DELETE",
    path: "/api/v1/admin/theme",
    params: [],
    permissions: ["appearance.manage"],
    body: null as never,
  },
  adminThemePreview: {
    method: "POST",
    path: "/api/v1/admin/theme/preview",
    params: [],
    permissions: ["appearance.manage"],
    body: null as unknown as AdminThemePreviewBody,
  },
  adminThemeRestore: {
    method: "POST",
    path: "/api/v1/admin/theme/restore",
    params: [],
    permissions: ["appearance.manage"],
    body: null as unknown as AdminThemeRestoreBody,
  },
  adminThemeShow: {
    method: "GET",
    path: "/api/v1/admin/theme",
    params: [],
    permissions: ["appearance.manage"],
    body: null as never,
  },
  adminThemeUpdate: {
    method: "PUT",
    path: "/api/v1/admin/theme",
    params: [],
    permissions: ["appearance.manage"],
    body: null as unknown as AdminThemeUpdateBody,
  },
  adminThemeVersions: {
    method: "GET",
    path: "/api/v1/admin/theme/versions",
    params: [],
    permissions: ["appearance.manage"],
    body: null as never,
  },
  adminUrlPlatformAdminChangeSlug: {
    method: "POST",
    path: "/api/v1/admin/url/registry/{id}/slug",
    params: ["id"],
    permissions: ["url.edit"],
    body: null as unknown as AdminUrlPlatformAdminChangeSlugBody,
  },
  adminUrlPlatformAdminHealth: {
    method: "GET",
    path: "/api/v1/admin/url/health",
    params: [],
    permissions: ["url.read"],
    body: null as never,
  },
  adminUrlPlatformAdminHistory: {
    method: "GET",
    path: "/api/v1/admin/url/registry/{id}/history",
    params: ["id"],
    permissions: ["url.read"],
    body: null as never,
  },
  adminUrlPlatformAdminIndex: {
    method: "GET",
    path: "/api/v1/admin/url/registry",
    params: [],
    permissions: ["url.read"],
    body: null as never,
  },
  adminUrlPlatformAdminInspect: {
    method: "GET",
    path: "/api/v1/admin/url/inspect",
    params: [],
    permissions: ["url.read"],
    body: null as never,
  },
  adminUrlPlatformAdminLock: {
    method: "POST",
    path: "/api/v1/admin/url/registry/{id}/lock",
    params: ["id"],
    permissions: ["url.edit"],
    body: null as never,
  },
  adminUrlPlatformAdminNotFound: {
    method: "GET",
    path: "/api/v1/admin/seo/not-found",
    params: [],
    permissions: ["analytics.read"],
    body: null as never,
  },
  adminUrlPlatformAdminRollback: {
    method: "POST",
    path: "/api/v1/admin/url/registry/{id}/rollback",
    params: ["id"],
    permissions: ["url.edit"],
    body: null as unknown as AdminUrlPlatformAdminRollbackBody,
  },
  adminUrlPlatformAdminSuggest: {
    method: "POST",
    path: "/api/v1/admin/url/slug/suggest",
    params: [],
    permissions: ["url.read"],
    body: null as unknown as AdminUrlPlatformAdminSuggestBody,
  },
  adminUrlPlatformAdminUnlock: {
    method: "POST",
    path: "/api/v1/admin/url/registry/{id}/unlock",
    params: ["id"],
    permissions: ["url.edit"],
    body: null as never,
  },
  assistantAsk: {
    method: "POST",
    path: "/api/v1/assistant/ask",
    params: [],
    permissions: [],
    body: null as unknown as AssistantAskBody,
  },
  bundleIndex: {
    method: "GET",
    path: "/api/v1/bundles",
    params: [],
    permissions: [],
    body: null as never,
  },
  bundleShow: {
    method: "GET",
    path: "/api/v1/bundles/{slug}",
    params: ["slug"],
    permissions: [],
    body: null as never,
  },
  cartAddItem: {
    method: "POST",
    path: "/api/v1/cart/items",
    params: [],
    permissions: [],
    body: null as unknown as CartAddItemBody,
  },
  cartApplyCoupon: {
    method: "POST",
    path: "/api/v1/cart/coupon",
    params: [],
    permissions: [],
    body: null as unknown as CartApplyCouponBody,
  },
  cartRemoveCoupon: {
    method: "DELETE",
    path: "/api/v1/cart/coupon",
    params: [],
    permissions: [],
    body: null as never,
  },
  cartRemoveItem: {
    method: "DELETE",
    path: "/api/v1/cart/items/{itemId}",
    params: ["itemId"],
    permissions: [],
    body: null as never,
  },
  cartShow: {
    method: "GET",
    path: "/api/v1/cart",
    params: [],
    permissions: [],
    body: null as never,
  },
  checkoutMethods: {
    method: "GET",
    path: "/api/v1/checkout/methods",
    params: [],
    permissions: [],
    body: null as never,
  },
  checkoutPayment: {
    method: "POST",
    path: "/api/v1/checkout/orders/{token}/payment",
    params: ["token"],
    permissions: [],
    body: null as never,
  },
  checkoutStore: {
    method: "POST",
    path: "/api/v1/checkout/orders",
    params: [],
    permissions: [],
    body: null as unknown as CheckoutStoreBody,
  },
  consentShow: {
    method: "GET",
    path: "/api/v1/consent",
    params: [],
    permissions: [],
    body: null as never,
  },
  consentStore: {
    method: "POST",
    path: "/api/v1/consent",
    params: [],
    permissions: [],
    body: null as unknown as ConsentStoreBody,
  },
  curriculumIndex: {
    method: "GET",
    path: "/api/v1/curriculum",
    params: [],
    permissions: [],
    body: null as never,
  },
  curriculumShow: {
    method: "GET",
    path: "/api/v1/curriculum/{type}/{slug}",
    params: ["type", "slug"],
    permissions: [],
    body: null as never,
  },
  downloadGrant: {
    method: "POST",
    path: "/api/v1/downloads/{entitlementId}/grant",
    params: ["entitlementId"],
    permissions: [],
    body: null as never,
  },
  downloadRedeem: {
    method: "GET",
    path: "/api/v1/downloads/redeem/{rawToken}",
    params: ["rawToken"],
    permissions: [],
    body: null as never,
  },
  eventStore: {
    method: "POST",
    path: "/api/v1/events",
    params: [],
    permissions: [],
    body: null as never,
  },
  getApiV1AdminDashboard: {
    method: "GET",
    path: "/api/v1/admin/dashboard",
    params: [],
    permissions: ["analytics.read"],
    body: null as never,
  },
  getApiV1AdminDashboardSummary: {
    method: "GET",
    path: "/api/v1/admin/dashboard/summary",
    params: [],
    permissions: ["analytics.read"],
    body: null as never,
  },
  getApiV1AdminOpsSummary: {
    method: "GET",
    path: "/api/v1/admin/ops/summary",
    params: [],
    permissions: ["orders.read"],
    body: null as never,
  },
  getApiV1AdminSearchIntelligence: {
    method: "GET",
    path: "/api/v1/admin/search/intelligence",
    params: [],
    permissions: ["analytics.read"],
    body: null as never,
  },
  getApiV1CatalogDimension: {
    method: "GET",
    path: "/api/v1/catalog/{dimension}",
    params: ["dimension"],
    permissions: [],
    body: null as never,
  },
  getApiV1Health: {
    method: "GET",
    path: "/api/v1/health",
    params: [],
    permissions: [],
    body: null as never,
  },
  getApiV1LlmsData: {
    method: "GET",
    path: "/api/v1/llms-data",
    params: [],
    permissions: [],
    body: null as never,
  },
  getApiV1MediaPath: {
    method: "GET",
    path: "/api/v1/media/{path}",
    params: ["path"],
    permissions: [],
    body: null as never,
  },
  getApiV1Samples: {
    method: "GET",
    path: "/api/v1/samples",
    params: [],
    permissions: [],
    body: null as never,
  },
  getApiV1SamplesFilesAsset: {
    method: "GET",
    path: "/api/v1/samples/files/{asset}",
    params: ["asset"],
    permissions: [],
    body: null as never,
  },
  getApiV1Search: {
    method: "GET",
    path: "/api/v1/search",
    params: [],
    permissions: [],
    body: null as never,
  },
  getApiV1SearchSuggest: {
    method: "GET",
    path: "/api/v1/search/suggest",
    params: [],
    permissions: [],
    body: null as never,
  },
  getApiV1Theme: {
    method: "GET",
    path: "/api/v1/theme",
    params: [],
    permissions: [],
    body: null as never,
  },
  homeAnnouncements: {
    method: "GET",
    path: "/api/v1/home/announcements",
    params: [],
    permissions: [],
    body: null as never,
  },
  homeBenefits: {
    method: "GET",
    path: "/api/v1/home/benefits",
    params: [],
    permissions: [],
    body: null as never,
  },
  homeCta: {
    method: "GET",
    path: "/api/v1/home/cta",
    params: [],
    permissions: [],
    body: null as never,
  },
  homeFaq: {
    method: "GET",
    path: "/api/v1/home/faq",
    params: [],
    permissions: [],
    body: null as never,
  },
  homeHero: {
    method: "GET",
    path: "/api/v1/home/hero",
    params: [],
    permissions: [],
    body: null as never,
  },
  homeIdentity: {
    method: "GET",
    path: "/api/v1/home/identity",
    params: [],
    permissions: [],
    body: null as never,
  },
  homeLayout: {
    method: "GET",
    path: "/api/v1/home/layout",
    params: [],
    permissions: [],
    body: null as never,
  },
  homePromo: {
    method: "GET",
    path: "/api/v1/home/promo",
    params: [],
    permissions: [],
    body: null as never,
  },
  homeReviews: {
    method: "GET",
    path: "/api/v1/home/reviews",
    params: [],
    permissions: [],
    body: null as never,
  },
  homeWhy: {
    method: "GET",
    path: "/api/v1/home/why",
    params: [],
    permissions: [],
    body: null as never,
  },
  navigationShow: {
    method: "GET",
    path: "/api/v1/navigation/{location}",
    params: ["location"],
    permissions: [],
    body: null as never,
  },
  orderShow: {
    method: "GET",
    path: "/api/v1/orders/{token}",
    params: ["token"],
    permissions: [],
    body: null as never,
  },
  paymentFakeConfirm: {
    method: "POST",
    path: "/api/v1/payments/fake/{orderToken}/confirm",
    params: ["orderToken"],
    permissions: [],
    body: null as never,
  },
  paymentWebhook: {
    method: "POST",
    path: "/api/v1/payments/webhook/{provider}",
    params: ["provider"],
    permissions: [],
    body: null as never,
  },
  postApiV1MaterialRequests: {
    method: "POST",
    path: "/api/v1/material-requests",
    params: [],
    permissions: [],
    body: null as never,
  },
  postApiV1SearchClick: {
    method: "POST",
    path: "/api/v1/search/click",
    params: [],
    permissions: [],
    body: null as never,
  },
  postApiV1SearchImpressions: {
    method: "POST",
    path: "/api/v1/search/impressions",
    params: [],
    permissions: [],
    body: null as never,
  },
  postApiV1SearchSuggestSelect: {
    method: "POST",
    path: "/api/v1/search/suggest/select",
    params: [],
    permissions: [],
    body: null as never,
  },
  productIndex: {
    method: "GET",
    path: "/api/v1/products",
    params: [],
    permissions: [],
    body: null as never,
  },
  productShow: {
    method: "GET",
    path: "/api/v1/products/{slug}",
    params: ["slug"],
    permissions: [],
    body: null as never,
  },
  redirectReportNotFound: {
    method: "POST",
    path: "/api/v1/not-found",
    params: [],
    permissions: [],
    body: null as unknown as RedirectReportNotFoundBody,
  },
  redirectResolve: {
    method: "GET",
    path: "/api/v1/redirects/resolve",
    params: [],
    permissions: [],
    body: null as never,
  },
  redirectRules: {
    method: "GET",
    path: "/api/v1/redirects/rules",
    params: [],
    permissions: [],
    body: null as never,
  },
  reviewIndex: {
    method: "GET",
    path: "/api/v1/products/{productId}/reviews",
    params: ["productId"],
    permissions: [],
    body: null as never,
  },
  reviewStore: {
    method: "POST",
    path: "/api/v1/products/{productId}/reviews",
    params: ["productId"],
    permissions: [],
    body: null as unknown as ReviewStoreBody,
  },
  reviewVoteStore: {
    method: "POST",
    path: "/api/v1/reviews/{reviewId}/votes",
    params: ["reviewId"],
    permissions: [],
    body: null as never,
  },
  robotsInspect: {
    method: "GET",
    path: "/api/v1/seo/indexability",
    params: [],
    permissions: [],
    body: null as never,
  },
  robotsPolicy: {
    method: "GET",
    path: "/api/v1/robots",
    params: [],
    permissions: [],
    body: null as never,
  },
  seoProduct: {
    method: "GET",
    path: "/api/v1/seo/products/{slug}",
    params: ["slug"],
    permissions: [],
    body: null as never,
  },
  sitemapIndex: {
    method: "GET",
    path: "/api/v1/sitemap/index",
    params: [],
    permissions: [],
    body: null as never,
  },
  sitemapShow: {
    method: "GET",
    path: "/api/v1/sitemap/{provider}",
    params: ["provider"],
    permissions: [],
    body: null as never,
  },
  urlResolveResolve: {
    method: "GET",
    path: "/api/v1/url/resolve",
    params: [],
    permissions: [],
    body: null as never,
  },
} as const;

export type OperationId = keyof typeof operations;

export type OperationBody<Id extends OperationId> = (typeof operations)[Id]['body'];

export type OperationParams<Id extends OperationId> =
  (typeof operations)[Id]['params'][number] extends never
    ? Record<string, never>
    : Record<(typeof operations)[Id]['params'][number] & string, string | number>;
