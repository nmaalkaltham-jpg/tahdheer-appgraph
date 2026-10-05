// UNIT: LIB-ADMIN-API-TYPES
// FILE: admin-api-types.ts
// STYLE: none
// PARENT: LIB-ADMIN-API
// USES: none
// API: none
// MAP: docs/PROJECT_MAP.md

/* ───── أنواع الاستجابات المستعملة في اللوحة ───── */

export interface Identity {
  user: {
    id: number;
    name: string;
    email: string;
    role: string;
    roleLabel: string;
    lastLoginAt: string | null;
  };
  permissions: string[];
  csrfToken: string;
}

export interface Paginated<T> {
  data: T[];
  meta: { page: number; perPage: number; lastPage: number; total: number };
  filters?: { statuses?: string[]; states?: string[]; sources?: string[] };
}

interface ReadinessItem {
  key: string;
  label: string;
  ok: boolean;
}

export interface Readiness {
  ready: boolean;
  completedCount: number;
  totalCount: number;
  items: ReadinessItem[];
  missing: string[];
  missingLabels: string[];
}

export interface ProductRow {
  id: number;
  sku: string;
  title: string;
  slug: string;
  status: string;
  priceMinor?: number;
  regularMinor?: number;
  onSale?: boolean;
  categoryPath: string[];
  readiness: Readiness;
}

export interface ProductDetail extends ProductRow {
  subtitle: string | null;
  shortDesc: string | null;
  description: string | null;
  editableState: string;
  stageId: number | null;
  gradeId: number | null;
  subjectId: number | null;
  semesterId: number | null;
  saleMinor: number | null;
  /*
   * نافذة التخفيض — `product_prices.sale_from` / `sale_to`، يعيدهما الخادم في
   * تفصيل المنتج (ProductController::detail). `PricingService::activeSaleMinor()`
   * وحدها تقرّر سريان التخفيض، و`onSale` أعلاه هو حكمها المحسوب.
   */
  saleFrom?: string | null;
  saleTo?: string | null;
  facts: { key: string; intValue: number | null; textValue: string | null }[];
  fileCount: number;
  imageCount: number;
}

export interface OrderRow {
  id: number;
  number: string;
  email: string;
  status: string;
  totalMinor: number;
  vatMinor: number;
  createdAt: string | null;
  paidAt: string | null;
}

export interface OrderDetail extends OrderRow {
  phone: string | null;
  items: { id: number; productId: number; title: string; priceMinor: number }[];
  payments: { id: number; provider: string; method: string | null; maskedInstrument: string | null; state: string }[];
  refunds: {
    id: number;
    amountMinor: number;
    /** PENDING | SUCCEEDED | FAILED | UNKNOWN | MANUAL — مصير الاسترداد عند المزوّد. */
    status: RefundStatus;
    provider: string | null;
    providerRefundId: string | null;
    providerError: string | null;
    reason: string | null;
    policy: string | null;
    createdAt: string | null;
    settledAt: string | null;
  }[];
  /** ما خرج فعلًا (المحسوم). */
  refundedMinor: number;
  /** ما ينتظر جواب المزوّد — محجوزٌ فلا يُردّ فوقه. */
  refundReservedMinor: number;
  refundableMinor: number;
  entitlements: { id: number; productId: number; status: string; downloadCount: number }[];
}

export interface CustomerRow {
  id: number;
  name: string;
  email: string;
  phone: string | null;
  ordersCount: number;
  entitlementsCount: number;
  activeEntitlementsCount: number;
  createdAt: string | null;
}

export interface SearchQueriesReport {
  window: { days: number; from: string; to: string };
  totals: { queriesLogged: number; zeroResultQueries: number; distinctQueries: number };
  /** null = لا بيانات كافية للحساب — لا صفر يُقرأ أداءً سيّئًا. */
  metrics: {
    zeroResultRate: number | null;
    ctr: number | null;
    meanClickPosition: number | null;
    clickedQueries: number;
  };
  topQueries: { query: string; count: number; zeroResultCount: number; lastSeenAt: string | null }[];
  zeroResultQueries: { query: string; count: number; lastSeenAt: string | null }[];
}

export interface SearchIndexStatus {
  engineHealthy: boolean;
  everBuilt: boolean;
  indexName: string | null;
  generation: number;
  status: string;
  lastBuiltAt: string | null;
  publishedProducts: number;
  staleSeconds: number | null;
  configuredProvider: string;
  /** sku ⇒ قيمة resource_type المنحرفة عن مصطلحات التصنيف. */
  kindMismatches: Record<string, string>;
  /** تنبّؤ: ما سيخدم الطلب القادم بحسب الإعداد وصحّة المحرّك وجيل الفهرس. */
  servingPath: 'meilisearch' | 'postgres_fallback';
  /** قياس: ما خدم فعلًا في النافذة، مقروءًا من `search_queries`. */
  measured: MeasuredServing;
  /** التنبّؤ يخالف القياس ⇒ تعثّرٌ لا يراه فحص الصحّة. null = لا سطر يُقاس. */
  predictionDiverges: boolean | null;
}

/**
 * ما خدم البحث فعلًا. كلّ نسبة هنا `null` حين لا سطر في النافذة — «لم يُقس»
 * ليست «صفر»، وصفرٌ في خانة التدهور يُقرأ ادّعاءَ سلامةٍ لم يُفحص.
 */
interface MeasuredServing {
  windowHours: number;
  /** المقيس وحده: أسطر `unknown` مستبعَدة من كلّ رقم هنا. */
  totalQueries: number;
  /** أسطرٌ في النافذة كُتبت قبل وجود القياس — تُعدّ ولا تدخل أيّ نسبة. */
  unmeasuredRows: number;
  /** اسم المزوّد ⇒ عدد ما خدمه. `unknown` أسطرٌ سابقة للقياس. */
  byProvider: Record<string, number>;
  degradedQueries: number | null;
  degradedRate: number | null;
  /** سبب التدهور ⇒ عدده. `unhealthy` فحص صحّة ساقط، وما عداه نوع استثناء. */
  reasons: Record<string, number>;
  latencyP50Ms: number | null;
  latencyP95Ms: number | null;
  /** `mixed` حين لا يبلغ مزوّدٌ الحصّة الغالبة. `null` حين لا قياس. */
  servedPath: string | null;
}

export interface SynonymRow {
  id: number;
  term: string;
  synonym: string;
  direction: string;
  scope: string | null;
  state: string;
  source: string;
  evidenceCount: number;
  updatedAt: string | null;
}

interface NotConfiguredMetric {
  key: string;
  label: string;
  state: string;
  reason: string;
}

export interface Dashboard {
  generatedAt: string;
  measured: {
    products: { total: number; byStatus: Record<string, number> };
    orders: { total: number; byStatus: Record<string, number> };
    revenue: { paidStatuses: string[]; ordersCount: number; totalMinor: number; vatMinor: number; currency: string };
    entitlements: { total: number; byStatus: Record<string, number> };
    /*
     * ─── النوع صُحّح في اتّجاهين ───
     *
     * كان يُعلن `scope` و`retentionDays` ولا يبثّهما الخادم قطّ — حقلان
     * موهومان يقرؤهما أيّ مستهلكٍ `undefined`. وكان يُغفل نقرات البحث وقد
     * صارت مقيسة.
     *
     * والنسبتان `number | null`: مقامٌ صفريّ لا نسبة له، وصفرٌ هنا يُقرأ
     * «بحثٌ لا يُنقر عليه أبدًا» — تهمةٌ للفهرس بينما الحقيقة أنّ لا بحث جرى.
     */
    search: {
      queriesLogged: number;
      zeroResultQueries: number;
      clickedQueries: number;
      clickThroughRate: number | null;
      meanClickPosition: number | null;
    };
  };
  notConfigured: NotConfiguredMetric[];
}

export interface CatalogTerm {
  id: number;
  slug: string;
  name: string;
}

/** صحّة التشغيل — كل حقل عدٌّ من جدوله؛ `null` يعني «لا قياس» لا صفرًا. */
interface SystemOutbox {
  pending: number;
  processing: number;
  processed: number;
  dead: number;
  oldestPendingSeconds: number | null;
  stalled: boolean;
}

interface SystemQueue {
  driver: string;
  status: 'MEASURED' | 'NOT_MEASURABLE';
  pending: number | null;
  failed: number | null;
  oldestPendingSeconds?: number | null;
}

interface SystemConsumerRow {
  eventType: string;
  written: number;
  lastWrittenAt: string | null;
  consumers: number;
}

export interface SystemHealth {
  outbox: SystemOutbox;
  queue: SystemQueue;
  operations: SystemOperations;
  consumers: SystemConsumerRow[];
}

/** سجلّ العمليّات ذات الأثر الخارجيّ — ما يحتاج إنسانًا. */
interface SystemOperations {
  reserved: number;
  processing: number;
  /** نتيجةٌ لم يُعرف جوابها. تحجز موضوعها فلا يُعاد ولا يُصحَّح. */
  uncertain: number;
  succeeded: number;
  failed: number;
  /** عاملٌ مات ولم يلتقطه التعافي بعد. ازديادُه يعني أنّ التعافي معطّل. */
  expiredLeases: number;
  uncertainSample: SystemUncertainRow[];
}

interface SystemUncertainRow {
  id: number;
  kind: string;
  subject: string | null;
  /** UNKNOWN تعني أنّ المزوّد عجز عن الجواب — لا أنّ الأثر لم يقع. */
  providerState: string | null;
  reconcileAttempts: number;
  lastError: string | null;
  createdAt: string | null;
}

export interface DeadEventRow {
  id: string;
  eventType: string;
  aggregateType: string;
  aggregateId: string;
  attempts: number;
  hasConsumer: boolean;
  createdAt: string | null;
  lastAttemptAt: string | null;
}

/** دفتر الذكاء — التكلفة تُقسم: ما له سعر مُعلَن وما لا سعر له. */
export interface AiUsage {
  provider: {
    name: string;
    configured: boolean;
    promptVersion: string;
    state: 'CONFIGURED' | 'NOT_CONFIGURED';
  };
  data: {
    range: { from: string; to: string };
    requests: number;
    failed: number;
    retried: number;
    failureRate: number | null;
    inputTokens: number;
    outputTokens: number;
    costedRequests: number;
    costedMinor: number;
    unpricedRequests: number;
    medianLatencyMs: number | null;
  };
  byModel: {
    model: string | null;
    status: string;
    requests: number;
    inputTokens: number;
    outputTokens: number;
    costMinor: number | null;
    priced: boolean;
  }[];
}

/** رسم معرفة المشروع — مُولَّد من المستودع، لا سجلّ يُكتب باليد. */
interface IntelGap {
  kind: string;
  severity: 'P0' | 'P1' | 'P2' | 'P3';
  subject: string;
  detail: string;
  evidence: string;
}

interface IntelDomain {
  id: string;
  name: string;
  files: number;
}

export interface IntelGraph {
  version: number;
  commit: string | null;
  apiLayer: { state: 'MEASURED' | 'NOT_AVAILABLE'; reason: string | null };
  counts: Record<string, number>;
  gapsBySeverity: Partial<Record<'P0' | 'P1' | 'P2' | 'P3', number>>;
  gaps: IntelGap[];
  /**
   * أصنافُ فجوةٍ تعرفها الشاشة ولا مصدر يحسبها — الاسم إلى السبب.
   *
   * غيابُها من `gaps` ليس صفرًا مقيسًا. والمولّد يكتبها منذ إصدارين والشاشة
   * لا تقرؤها، فيقرأ الناظر غيابَ الصنف «لا فجوة من هذا النوع» — وهو الخلط
   * الذي وُضع الحقل ليمنعه.
   */
  notComputed: Record<string, string>;
  /** عدد المدخلات `UNKNOWN` داخل الأصناف المحسوبة: صفرٌ فوقها ناقصٌ بمقدارها. */
  unknownInputs: Record<string, number>;
  /** ما طُرح من صنفٍ محسوب، بقاعدة الطرح وأدلّته — طرحٌ معلَن لا حذفٌ صامت. */
  excludedFromGaps?: Record<
    string,
    { rule: string; redirectOnly: { url: string; to: string; evidence: string }[] }
  >;
  nodes: { domains: IntelDomain[] };
}

/** النشر الذي لا يحمل الرسم يقول ذلك بدل أن يعرض أصفارًا. */
export interface IntelUnavailable {
  state: 'NOT_GENERATED';
  reason: string;
}

export type IntelResponse = IntelGraph | IntelUnavailable;

/** سطر في دفتر نداءات الذكاء — التكلفة `null` لنموذجٍ بلا سعرٍ مُعلَن. */
export interface AiRequestRow {
  id: number;
  provider: string;
  model: string | null;
  task: string;
  field: string | null;
  productId: number | null;
  actorEmail: string | null;
  promptVersion: string | null;
  /**
   * حالة العرض. صارت أربعًا حين اكتسب النداء دورة حياة: يُحجز في الطابور
   * فيُرى ويُلغى قبل أن يبدأ. و`lifecycle` هي حالة الخادم الخام لمن يحتاج
   * التمييز بين «فشل» و«رُفض خرجه».
   */
  status: 'ok' | 'failed' | 'queued' | 'cancelled';
  lifecycle: 'QUEUED' | 'RUNNING' | 'SUCCEEDED' | 'FAILED' | 'REJECTED' | 'CANCELLED';
  cached: boolean;
  errorCode: string | null;
  attempts: number;
  inputTokens: number | null;
  outputTokens: number | null;
  costMinor: number | null;
  latencyMs: number | null;
  suggestions: number | null;
  createdAt: string | null;
}

/** دفتر المعاملات — الأداة مقنَّعة كما خُزّنت، ولا سرّ في الحمولة. */
interface PaymentTransactionRow {
  id: number;
  provider: string;
  intentId: string | null;
  method: string | null;
  instrument: string | null;
  state: string;
  /** ما قبضه المزوّد فعلًا — لا ما يقوله الطلب. `null` = لم يُبلَّغ. */
  amountMinor?: number | null;
  currency?: string | null;
  /** قرار التمويل: FUNDED · UNFUNDABLE (مع سببه) · EXTRA (دُفع مرّتين). */
  funding?: 'FUNDED' | 'UNFUNDABLE' | 'EXTRA' | null;
  fundingReason?: string | null;
  refundedMinor?: number;
  refundReservedMinor?: number;
  /** ما يمكن ردّه من هذا التحصيل وحده — لغير الموِّل فقط. */
  refundableMinor?: number | null;
  orderId: number | null;
  orderNumber: string | null;
  orderStatus: string | null;
  orderTotalMinor: number | null;
  at: string | null;
}

export const FUNDING_LABELS: Record<'FUNDED' | 'UNFUNDABLE' | 'EXTRA', string> = {
  FUNDED: 'موَّل الطلب',
  UNFUNDABLE: 'وصل ولم يُموِّل',
  EXTRA: 'زائد — دُفع الطلب مرّتين',
};

interface PaymentSummary {
  transactions: number;
  byState: Record<string, number>;
  /** `null` حين لا عمليّات — لا مقام فلا نسبة. */
  failureRate: number | null;
  gatewayEvents: number;
  /** مالٌ حُصّل وطلبه غير مدفوع — أخطر رقم في الصفحة. */
  capturedButUnpaidOrders: number;
}

export interface PaymentTransactions {
  data: PaymentTransactionRow[];
  meta: { total: number; page: number; perPage: number };
  filters: { states: string[]; providers: string[] };
  summary: PaymentSummary;
}

interface PaymentEventRow {
  id: number;
  eventType: string;
  at: string | null;
  payload: Record<string, unknown>;
}

export interface PaymentTransactionDetail extends PaymentTransactionRow {
  events: PaymentEventRow[];
}

export type RefundStatus = 'PENDING' | 'SUCCEEDED' | 'FAILED' | 'UNKNOWN' | 'MANUAL';

export const REFUND_STATUS_LABELS: Record<RefundStatus, string> = {
  PENDING: 'بانتظار المزوّد',
  SUCCEEDED: 'رُدّ',
  FAILED: 'رفضه المزوّد',
  UNKNOWN: 'غير محسوم',
  MANUAL: 'يدويّ',
};

interface RefundRow {
  id: number;
  orderId: number;
  orderNumber: string | null;
  amountMinor: number;
  status: RefundStatus;
  provider: string | null;
  providerRefundId: string | null;
  providerError: string | null;
  settledAt: string | null;
  reason: string | null;
  policy: string | null;
  /** `null` = لم يُبلَّغ العميل بعد. */
  notifiedAt: string | null;
  at: string | null;
}

export interface RefundLedger {
  data: RefundRow[];
  meta: { total: number; page: number; perPage: number };
  summary: { refunds: number; totalMinor: number; unnotified: number; unresolved: number; unresolvedMinor: number };
}

/** مكتبة الأصول — النظرة العابرة للمنتجات. لا مفتاح مخزن ولا بصمة كاملة. */
export interface AssetRow {
  id: number;
  name: string;
  originalName: string;
  mime: string;
  kind: 'image' | 'document' | 'archive' | 'other';
  sizeBytes: number;
  visibility: 'public_sample' | 'paid_private';
  provider: string;
  /** بصمة مقتطعة — تكفي للتمييز ولا تُعيد بناء شيء. */
  fingerprint: string;
  usedBy: number;
  /** `usage_count` المخزَّن خالف عدّ الصفوف — العدّاد انحرف. */
  counterDrift: boolean;
  orphan: boolean;
  at: string | null;
}

export interface AssetUsageRow {
  productId: number;
  productSku: string | null;
  productTitle: string | null;
  productStatus: string | null;
  role: string;
  position: number;
}

export interface AssetLibrary {
  data: AssetRow[];
  meta: { total: number; page: number; perPage: number };
  filters: { kinds: string[]; visibilities: string[] };
  summary: {
    assets: number;
    bytes: number;
    orphans: number;
    orphanBytes: number;
    paidPrivate: number;
    /** أصلٌ يخدم منتجَين فأكثر — حيث يخطئ الحذف. */
    shared: number;
    byKind: Record<string, number>;
  };
}
