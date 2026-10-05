/**
 * /payment-methods — «طرق الدفع»
 *
 * Design: «Tahdheer - Store Pages» → the `is.payment` branch (method rows with
 * a step disclosure, security card, refund-time card, quick FAQ, shop CTA).
 * That design's BRAND and its shipping / cash-on-delivery model are
 * NON-CANONICAL and are not reproduced: «التحاضير الحديثة» sells digital files
 * only, so there is no delivery and no cash on delivery.
 *
 * HONESTY DOCTRINE — every claim below was verified against the backend, and
 * nothing here is a guess:
 *  · Payments run through ONE adapter: backend/laravel/app/Domain/Payments/
 *    TapProvider.php (Tap hosted page, `source.id = src_all`). ProviderRegistry
 *    also has a FakeProvider that only resolves in local/testing.
 *  · The order is not paid until the SIGNED gateway webhook arrives
 *    (InvalidWebhookSignature rejects it) — CheckoutController::store.
 *  · No card data reaches this store: checkout returns a `payUrl` and the
 *    customer pays on the gateway's own page.
 *  · No fee of any kind is added to a total: PricingService EXTRACTS VAT from
 *    an inclusive price and there is no surcharge, shipping or fee field.
 *  · A tax profile (اسم المنشأة + الرقم الضريبي) is accepted at checkout.
 *  · Apple Pay, bank transfer and STC Pay have NO implementation, so they are
 *    listed in their honest «غير مفعّل» state instead of as available options.
 *    Promising a method that does not exist is the same lie as a fake button.
 *  · The design's per-method refund-day table is SAMPLE data: real refund
 *    timing depends on the issuing bank, and the refund window itself is still
 *    an unresolved owner decision on /policies/refund. It is stated, not faked.
 *
 * There is no payment-methods API endpoint; this is editorial copy about the
 * store's own verified capability, not data rendered as if it came from one.
 */
import type { Metadata } from "next";
import { routes } from "../../lib/routes";
import { PaymentMark } from "../../components/chrome/PaymentMark";
import styles from "./page.module.css";

export const metadata: Metadata = {
  title: "طرق الدفع",
  description:
    "وسائل الدفع المفعّلة في متجر التحاضير الحديثة، وخطوات الدفع، وما لم يُفعَّل بعد — بلا رسوم إضافية على أي وسيلة.",
  alternates: { canonical: routes.paymentMethods },
};

/** Generic glyphs only for the tiles — brand marks come from `PaymentMark`
 *  (the official mada logo; other marks as labelled text), never hand-drawn. */
function CardGlyph() {
  return (
    <svg
      aria-hidden="true"
      viewBox="0 0 24 24"
      fill="none"
      stroke="currentColor"
      strokeWidth="1.8"
    >
      <rect x="2.5" y="5" width="19" height="14" rx="2.5" />
      <path d="M2.5 9.5h19" />
      <path d="M6 15h4" strokeLinecap="round" />
    </svg>
  );
}

function WalletGlyph() {
  return (
    <svg
      aria-hidden="true"
      viewBox="0 0 24 24"
      fill="none"
      stroke="currentColor"
      strokeWidth="1.8"
    >
      <path d="M3 7.5A2.5 2.5 0 0 1 5.5 5H18a2 2 0 0 1 2 2v1.5" />
      <rect x="3" y="7.5" width="18" height="11.5" rx="2.5" />
      <circle cx="16.5" cy="13.5" r="1.3" fill="currentColor" stroke="none" />
    </svg>
  );
}

function BankGlyph() {
  return (
    <svg
      aria-hidden="true"
      viewBox="0 0 24 24"
      fill="none"
      stroke="currentColor"
      strokeWidth="1.8"
      strokeLinecap="round"
    >
      <path d="M3 9.5 12 4.5l9 5" />
      <path d="M5 10v8M9.7 10v8M14.3 10v8M19 10v8" />
      <path d="M3 19.5h18" />
    </svg>
  );
}

function CheckGlyph() {
  return (
    <svg
      aria-hidden="true"
      viewBox="0 0 24 24"
      fill="none"
      stroke="currentColor"
      strokeWidth="2.6"
      strokeLinecap="round"
      strokeLinejoin="round"
    >
      <path d="m5 12.5 4.5 4.5L19 7" />
    </svg>
  );
}

function ShieldGlyph() {
  return (
    <svg
      aria-hidden="true"
      viewBox="0 0 24 24"
      fill="none"
      stroke="currentColor"
      strokeWidth="2"
      strokeLinejoin="round"
    >
      <path d="M12 3 20 6v6c0 4.2-3.2 7.6-8 9-4.8-1.4-8-4.8-8-9V6l8-3Z" />
    </svg>
  );
}

type Glyph = "card" | "wallet" | "bank";

const GLYPHS: Record<Glyph, () => React.JSX.Element> = {
  card: CardGlyph,
  wallet: WalletGlyph,
  bank: BankGlyph,
};

interface EnabledMethod {
  name: string;
  /** علامات الدفع كما يرسمها `PaymentMark` — «mada» شعارها الرسميّ، والباقي نصٌّ لاتينيّ. */
  marks: readonly string[];
  note: string;
  glyph: Glyph;
  /** Which brand token tints the icon tile. */
  tone: "green" | "info";
  steps: readonly string[];
}

/** Verified live path: checkout → Tap hosted page → signed webhook → delivery. */
const ENABLED: readonly EnabledMethod[] = [
  {
    name: "مدى",
    marks: ["mada"],
    note: "الخصم المباشر من حسابك البنكي عبر بطاقة مدى.",
    glyph: "card",
    tone: "green",
    steps: [
      "أكمل بيانات الطلب في صفحة الدفع: الاسم والبريد ورقم الجوال.",
      "ننقلك إلى صفحة بوابة الدفع المرخّصة، وتختار «مدى» هناك.",
      "تُدخل بيانات البطاقة داخل صفحة البوابة، وتُكمل التحقّق الذي يطلبه بنكك.",
      "لا يُعدّ الطلب مدفوعًا حتى يصلنا إشعار موقّع من البوابة؛ عندها يظهر رابط التنزيل في صفحة تأكيد الطلب.",
    ],
  },
  {
    name: "البطاقات الائتمانية",
    marks: ["VISA", "MC"],
    note: "بطاقات محلية ودولية، عبر البوابة نفسها.",
    glyph: "wallet",
    tone: "info",
    steps: [
      "أكمل بيانات الطلب في صفحة الدفع: الاسم والبريد ورقم الجوال.",
      "ننقلك إلى صفحة بوابة الدفع المرخّصة، وتختار «بطاقة ائتمانية» هناك.",
      "تُدخل بيانات البطاقة داخل صفحة البوابة، وتُكمل التحقّق الذي يطلبه بنكك.",
      "لا يُعدّ الطلب مدفوعًا حتى يصلنا إشعار موقّع من البوابة؛ عندها يظهر رابط التنزيل في صفحة تأكيد الطلب.",
    ],
  },
];

interface UnavailableMethod {
  name: string;
  latin?: string;
  /** Why it is not offered — an honest reason, never a coming-soon promise. */
  reason: string;
  glyph: Glyph;
}

const UNAVAILABLE: readonly UnavailableMethod[] = [
  {
    name: "أبل باي",
    latin: "Apple Pay",
    reason:
      "غير مؤكَّد تفعيلها في حساب البوابة، ولا نَعِد بوسيلة لم نتحقّق من عملها.",
    glyph: "wallet",
  },
  {
    name: "التحويل البنكي",
    reason: "مصمَّم ولم يُشغَّل بعد، فلا يظهر خيارًا عند الدفع.",
    glyph: "bank",
  },
  {
    name: "إس تي سي باي",
    latin: "STC Pay",
    reason: "لم تُربط بالمتجر بعد.",
    glyph: "wallet",
  },
];

/** Each item is a fact about this codebase — see the file header for its source. */
const SECURITY: readonly { title: string; body: string }[] = [
  {
    title: "بيانات بطاقتك لا تمرّ بنا",
    body: "الدفع يتم داخل صفحة بوابة الدفع المرخّصة، ولا يصل رقم بطاقتك إلى خوادم المتجر إطلاقًا.",
  },
  {
    title: "التأكيد من البوابة لا من المتصفّح",
    body: "لا يُعدّ الطلب مدفوعًا إلا بإشعار موقّع من البوابة نتحقّق من توقيعه؛ رسالة نجاح في المتصفّح وحدها لا تكفي.",
  },
  {
    title: "لا رسوم على أي وسيلة",
    body: "السعر المعروض شامل ضريبة القيمة المضافة، ولا يُضاف إليه أي رسم عند اختيار وسيلة الدفع.",
  },
  {
    title: "فاتورة ضريبية عند الطلب",
    body: "يمكنك إدخال اسم المنشأة والرقم الضريبي في صفحة الدفع لتصدر الفاتورة باسم منشأتك.",
  },
];

const FAQ: readonly { q: string; a: string }[] = [
  {
    q: "هل يوجد دفع عند الاستلام؟",
    a: "لا. كل المنتجات ملفات رقمية تُسلَّم فور تأكيد الدفع، فلا يوجد شحن ولا دفع عند الاستلام.",
  },
  {
    q: "هل تظهر رسوم إضافية عند الدفع؟",
    a: "لا. المبلغ الذي تراه في السلة هو ما يُخصم، شاملًا ضريبة القيمة المضافة.",
  },
  {
    q: "متى يصلني الملف؟",
    // «يبقى في حسابك» وعدٌ بلا حساب: التنزيل مربوطٌ برمز الطلب وحده.
    a: "بعد وصول تأكيد البوابة مباشرة: رابط التنزيل يظهر في صفحة تأكيد الطلب، ويُعاد فتحه لاحقًا برابط الطلب نفسه — فاحفظه.",
  },
];

function MethodIcon({
  glyph,
  tone,
}: {
  glyph: Glyph;
  tone: "green" | "info" | "off";
}) {
  const Glyph = GLYPHS[glyph];
  const toneClass =
    tone === "green"
      ? styles.tileGreen
      : tone === "info"
        ? styles.tileInfo
        : styles.tileOff;
  return (
    <span className={`${styles.tile} ${toneClass}`} aria-hidden="true">
      <Glyph />
    </span>
  );
}

export default function PaymentMethodsPage() {
  return (
    <div className={styles.page}>
      <div className={styles.wrap}>
        <nav aria-label="مسار التنقّل">
          <ol className={styles.crumbs}>
            <li>
              <a href={routes.home}>الرئيسية</a>
            </li>
            <li aria-current="page">طرق الدفع</li>
          </ol>
        </nav>

        <header className={styles.head}>
          <p className={styles.eyebrow}>الدفع</p>
          <h1 className={styles.h1}>طرق الدفع</h1>
          <p className={styles.lede}>
            كل المنتجات ملفات رقمية، والدفع يتم داخل صفحة بوابة الدفع المرخّصة.
            هذه هي الوسائل المفعّلة اليوم، وما لم يُفعَّل بعد مذكور صراحةً — لا
            نَعِد بوسيلة قبل تشغيلها.
          </p>
        </header>
        <div className={styles.grid}>
          <div className={styles.methods}>
            <section aria-labelledby="pm-enabled">
              <h2 className={styles.sectionTitle} id="pm-enabled">
                الوسائل المفعّلة
              </h2>
              <ul className={styles.rows} role="list">
                {ENABLED.map((method) => (
                  <li key={method.name} className={styles.row}>
                    <details className={styles.disclosure}>
                      <summary className={styles.summary}>
                        <MethodIcon glyph={method.glyph} tone={method.tone} />
                        <span className={styles.rowText}>
                          <span className={styles.rowName}>
                            {method.name}
                            <span className={styles.rowMarks}>
                              {method.marks.map((mark) => (
                                <span key={mark} className={styles.rowMark}>
                                  {/* «مدى» مكتوبةٌ اسمًا قبلها — الشعار لا يكرّرها سمعًا. */}
                                  <PaymentMark mark={mark} decorative={mark === "mada"} />
                                </span>
                              ))}
                            </span>
                          </span>
                          <span className={styles.rowNote}>{method.note}</span>
                        </span>
                        <span className={styles.feePill}>بلا رسوم</span>
                        <span className={styles.chev} aria-hidden="true">
                          <svg
                            viewBox="0 0 24 24"
                            fill="none"
                            stroke="currentColor"
                            strokeWidth="2.2"
                            strokeLinecap="round"
                            strokeLinejoin="round"
                          >
                            <path d="m6 9.5 6 6 6-6" />
                          </svg>
                        </span>
                      </summary>
                      <ol className={styles.steps}>
                        {method.steps.map((step, i) => (
                          <li key={step} className={styles.step}>
                            <span className={styles.stepNum} aria-hidden="true">
                              {i + 1}
                            </span>
                            <span className={styles.stepText}>{step}</span>
                          </li>
                        ))}
                      </ol>
                    </details>
                  </li>
                ))}
              </ul>
            </section>

            <section aria-labelledby="pm-off">
              <h2 className={styles.sectionTitle} id="pm-off">
                لم تُفعَّل بعد
              </h2>
              <ul className={styles.rows} role="list">
                {UNAVAILABLE.map((method) => (
                  <li
                    key={method.name}
                    className={`${styles.row} ${styles.rowOff}`}
                  >
                    <div className={styles.summaryStatic}>
                      <MethodIcon glyph={method.glyph} tone="off" />
                      <span className={styles.rowText}>
                        <span className={styles.rowName}>
                          {method.name}
                          {method.latin ? (
                            <>
                              {" "}
                              <bdi dir="ltr" className={styles.rowLatin}>
                                {method.latin}
                              </bdi>
                            </>
                          ) : null}
                        </span>
                        <span className={styles.rowNote}>{method.reason}</span>
                      </span>
                      <span className={styles.offPill}>غير مفعّل</span>
                    </div>
                  </li>
                ))}
              </ul>
            </section>
          </div>

          <aside className={styles.aside}>
            <section className={styles.secureCard} aria-labelledby="pm-secure">
              <h2 className={styles.secureTitle} id="pm-secure">
                <span className={styles.secureIcon} aria-hidden="true">
                  <ShieldGlyph />
                </span>
                أمان الدفع
              </h2>
              <ul className={styles.secureList} role="list">
                {SECURITY.map((item) => (
                  <li key={item.title} className={styles.secureItem}>
                    <span className={styles.secureCheck} aria-hidden="true">
                      <CheckGlyph />
                    </span>
                    <span>
                      <b className={styles.secureItemTitle}>{item.title}</b>
                      <span className={styles.secureItemBody}>{item.body}</span>
                    </span>
                  </li>
                ))}
              </ul>
            </section>

            <section className={styles.card} aria-labelledby="pm-refund">
              <h2 className={styles.cardTitle} id="pm-refund">
                مدّة الاسترداد
              </h2>
              <div className={styles.cardBody}>
                <p className={styles.cardPara}>
                  عند قبول طلب الاسترجاع يُعاد المبلغ بنفس وسيلة الدفع. مدّة
                  وصوله تتبع سياسة بنكك أو بوابة الدفع، وهي خارج سيطرتنا — فلا
                  نعلن مدّة لا نملكها.
                </p>
                <p className={styles.cardPara}>
                  شروط الاسترجاع نفسها لم تُعتمد بعد؛ الحالات المحسومة والمعلّقة
                  منشورة في{" "}
                  <a className={styles.cardLink} href={routes.policy("refund")}>
                    سياسة الاسترجاع
                  </a>
                  .
                </p>
              </div>
            </section>

            <section className={styles.card} aria-labelledby="pm-faq">
              <h2 className={styles.cardTitle} id="pm-faq">
                أسئلة سريعة
              </h2>
              <dl className={styles.faq}>
                {FAQ.map((item) => (
                  <div key={item.q} className={styles.faqItem}>
                    <dt className={styles.faqQ}>{item.q}</dt>
                    <dd className={styles.faqA}>{item.a}</dd>
                  </div>
                ))}
              </dl>
            </section>

            <a className={styles.cta} href={routes.shop}>
              تصفّح المتجر
            </a>
          </aside>
        </div>
      </div>
    </div>
  );
}
