/**
 * 404 («Tahdheer - 404»): REAL 404 status (automatic via not-found.tsx), a
 * cause-aware hero, the failed URL echoed back, four start-here paths and the
 * best sellers as a quick alternative.
 *
 * وهي أيضًا نقطة الإبلاغ عن الروابط المكسورة. `not_found_hits` موجود منذ
 * إنشاء الجدول ولم يكتب فيه شيء قطّ: نقطة ‎POST /api/v1/not-found‎ مسجَّلة
 * ولا مستهلك لها، فكان كشفُ الروابط المكسورة سلبيًّا معطَّلًا بالكامل.
 *
 * المسار يصل من الوسيط عبر ترويسة: صفحة 404 في App Router لا تتلقّى المسار.
 * تُقرأ الترويسة مرّة واحدة هنا ثم يمرّ المسار إلى البلاغ وإلى العرض معًا.
 *
 * السببان المعروضان مشتقّان من المسار نفسه لا مُخمَّنان: «رابط مقطوع» حين
 * يقف العنوان عند عائلة مسار تحتاج جزءًا إضافيًّا (‎/products‎ بلا مُعرّف
 * مثلًا)، و«غير موجودة» فيما عدا ذلك. الحالة الثالثة في التصميم — «منتج
 * مسحوب» ومعها لافتة النسخة البديلة — تحتاج حقلًا لا تنشره الواجهة البرمجية
 * اليوم، فلا تُعرض: لافتةُ بديلٍ لا مصدر له اختراعٌ لا تصميم.
 */
import { headers } from "next/headers";
import type { Metadata } from "next";
import type { ProductCardDto } from "@tahdheer/api-sdk/types";
import { qty } from "@tahdheer/design-system/format";
import { ProductCard } from "@tahdheer/ui";
import { apiGetCollection, toCardProps } from "../lib/api";
import { routes } from "../lib/routes";
import { fetchTerms } from "./(catalog)/_components/catalog-api";
import { termPath } from "./(catalog)/_components/dimensions";
import {
  CatalogIcon,
  type CatalogIconName,
} from "./(catalog)/_components/icons";
import { FILE_FORMS } from "./(catalog)/_components/query";
import { AccountIcon } from "./account/icons";
import styles from "./not-found.module.css";
import { ThemedArt } from "../components/home/ThemedArt";
import { EMPTY_ART, type Art } from "../lib/art";
import { budget } from "../lib/fetch-budget";
import { isCredentialPath, redactPath, redactUrl } from "../lib/private-paths";
import {
  MAX_SUGGESTIONS,
  resolvePath,
  type UrlResolution,
} from "../lib/url-resolve";

/** المراحل المعروضة كنقاط بداية — ثلاث بطاقات كما يرسم التصميم. */
/** عنوان اللسان: كان يسقط إلى اسم المتجر وحده فلا يميّز قارئُ الشاشة صفحة 404 من غيرها. */
export const metadata: Metadata = { title: "الصفحة غير موجودة", robots: { index: false } };

const STAGE_CARDS = 3;

/** بدائل سريعة: ثلاث بطاقات، وهي نفس قائمة صفحة «الأكثر مبيعًا». */
const ALTERNATIVES = 3;

/** تدرّج البطاقات: ذهبي ثم أخضر ثم أخضر غامق، بترتيب التصميم. */
const STAGE_TINTS = [
  styles.tintGold,
  styles.tintGreen,
  styles.tintDeep,
] as const;
const STAGE_ICONS: CatalogIconName[] = ["blocks", "book", "files"];

/**
 * عائلات المسارات التي لا يكتمل عنوانها إلا بجزء إضافي.
 *
 * `segments` عدد الأجزاء التي يحتاجها العنوان الصحيح؛ ما دونها عنوانٌ توقّف
 * في منتصفه — وهو ما يحدث عند نسخ رابط من رسالة أو محادثة تقطعه.
 */
const SLUG_ROUTES: { base: string; segments: number }[] = [
  { base: "/products", segments: 2 },
  { base: "/bundles", segments: 2 },
  { base: "/policies", segments: 2 },
  { base: "/orders", segments: 2 },
  { base: "/stages", segments: 2 },
  { base: "/grades", segments: 2 },
  { base: "/subjects", segments: 2 },
  { base: "/semesters", segments: 2 },
  { base: "/kinds", segments: 2 },
  { base: "/curriculum", segments: 3 },
];

type Cause = "missing" | "truncated" | "gone";

const HERO: Record<
  Cause,
  { art: Art; tag: string; title: string; body: string }
> = {
  missing: {
    art: EMPTY_ART.search,
    tag: "الصفحة غير موجودة",
    title: "لم نجد هذه الصفحة",
    body: "الرابط لا يشير إلى شيء عندنا. قد يكون منسوخًا ناقصًا أو قديمًا — والملف الذي تريده غالبًا موجود باسم آخر.",
  },
  truncated: {
    art: EMPTY_ART.generic,
    tag: "رابط غير مكتمل",
    title: "الرابط يبدو مقطوعًا",
    body: "ينقص الرابط جزؤه الأخير — يحدث كثيرًا عند النسخ من رسالة أو محادثة. ابحث بالاسم بدلًا منه، أو ابدأ من المرحلة.",
  },
  /*
   * «ذهب» ليست «لم يوجد».
   *
   * السجلّ يردّ ٤١٠ لكيانٍ تقاعد بسياسة `gone`، وهو معنًى آخر: الزائر لم
   * يُخطئ في النسخ، والصفحة كانت هنا وأُزيلت. وعرضُ «لم نجد هذه الصفحة»
   * مكانها يدفعه يراجع رابطًا سليمًا (قاعدة ١١ — دلالة المصدر تُحفظ).
   */
  gone: {
    art: EMPTY_ART.generic,
    tag: "أُزيلت الصفحة",
    title: "هذه الصفحة أُزيلت",
    body: "الرابط كان صحيحًا، والمحتوى لم يعد منشورًا عندنا. لم تُخطئ في النسخ — ابحث عن بديلٍ بالاسم أو ابدأ من المرحلة.",
  },
};

/** السبب يُشتقّ من المسار وحده — ولا سبب يُعرض بلا مسار. */
function resolveCause(
  path: string | null,
  resolution: UrlResolution | null,
): Cause {
  /* حكم السجلّ يغلب الاشتقاق من شكل المسار: هو وحده يعرف ما أُزيل. */
  if (resolution?.outcome === "gone") return "gone";
  if (!path) return "missing";
  const depth = path.split("/").filter(Boolean).length;
  const truncated = SLUG_ROUTES.some(
    (route) =>
      (path === route.base || path.startsWith(`${route.base}/`)) &&
      depth < route.segments,
  );

  return truncated ? "truncated" : "missing";
}

/**
 * يُبلغ عن الرابط المكسور مرّة واحدة عند التصيير.
 *
 * الفشل يُبتلع: قياسٌ لا يُفشل صفحة. والزائر يرى 404 صحيحة سواء وصل البلاغ
 * أم لا — الإبلاغ لصالح المشغّل لا لصالح الطلب.
 */
async function reportBrokenLink(
  path: string | null,
  referer: string | null,
): Promise<void> {
  const base = process.env.NEXT_PUBLIC_API_URL;
  if (!base || !path) return;

  /*
   * رمز الطلب اعتمادُ تحميلٍ لا مسار. وكان يُرسَل حرفيًّا إلى
   * `/api/v1/not-found` فيُخزَّن في `not_found_hits.path` ويُقرأ من لوحة
   * الإدارة — أي أنّ عطلًا عابرًا في الخادم (كان يُترجم ٤٠٤ قبل هذا التغيير)
   * كان يكفي لتسريب مفتاح ملفّات مشترٍ. تُبلَّغ العائلة، ولا تُبلَّغ القيمة —
   * والمُرجِع كذلك: صفحةُ طلبٍ أو `/account/orders?token=` مُرجِعٌ يحمل المفتاح.
   */
  try {
    await fetch(`${base}/api/v1/not-found`, {
      signal: budget("edge"),
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ path: redactPath(path), referer: redactUrl(referer) }),
      cache: "no-store",
    });
  } catch {
    /* قياس فقط. */
  }
}

export default async function NotFound() {
  const header = await headers();
  const path = header.get("x-tahdheer-path");

  const [, stages, best, resolution] = await Promise.all([
    reportBrokenLink(path, header.get("referer")),
    fetchTerms("stages"),
    apiGetCollection<ProductCardDto>(
      `/api/v1/products?source=best&limit=${ALTERNATIVES}`,
    ),
    /*
     * السجلّ يُسأل عن هذا المسار بعينه: هل أُزيل؟ وهل يشبهه عنوانٌ منشور؟
     * وكان يحسب الاثنين ولا يسأله أحد — فتُعرض «الأكثر مبيعًا» لمن أخطأ
     * حرفًا واحدًا في اسم منتجٍ قائم.
     */
    // مسارٌ بمفتاح لا بديل له في السجلّ، وسؤالُه يضع المفتاح في رابط طلبٍ إلى الخادم.
    path === null || isCredentialPath(path) ? Promise.resolve(null) : resolvePath(path),
  ]);

  const hero = HERO[resolveCause(path, resolution)];
  /* المقترَح مرتَّبٌ بدرجته من الخادم — يُقصّ ولا يُعاد ترتيبه. */
  const suggestions = (resolution?.suggestions ?? []).slice(0, MAX_SUGGESTIONS);

  return (
    <div className={styles.page}>
      <div className={styles.wrap}>
        <header className={styles.hero}>
          <div className={styles.heroRow}>
            <ThemedArt
              art={hero.art}
              alt=""
              sizes="(max-width: 767px) 88px, 120px"
              className={styles.heroArt}
            />

            <div className={styles.heroMain}>
              <span className={styles.tag}>{hero.tag}</span>
              <h1 className={styles.title}>{hero.title}</h1>
              <p className={styles.body}>{hero.body}</p>

              {path ? (
                <div className={styles.urlBox}>
                  <span className={styles.urlLabel}>الرابط المطلوب</span>
                  <bdi dir="ltr" className={styles.urlValue}>
                    {path}
                  </bdi>
                </div>
              ) : null}
            </div>
          </div>
        </header>

        {/*
         * المسارات القريبة قبل البحث وقبل «ابدأ من هنا»: هي الجواب نفسه حين
         * يوجد، والبحثُ ما يُلجأ إليه حين لا يوجد. وسببُ الاقتراح بنصّ الخادم
         * لا بتفسيرٍ يُكتب هنا.
         */}
        {suggestions.length > 0 ? (
          <section className={styles.start}>
            <h2 className={styles.startTitle}>هل تقصد؟</h2>
            <ul className={styles.guessList} role="list">
              {suggestions.map((suggestion) => (
                <li key={suggestion.path}>
                  <a className={styles.guess} href={suggestion.path}>
                    <bdi dir="ltr" className={styles.guessPath}>
                      {suggestion.path}
                    </bdi>
                    <span className={styles.guessWhy}>{suggestion.why}</span>
                  </a>
                </li>
              ))}
            </ul>
          </section>
        ) : null}

        <form
          className={styles.search}
          action={routes.search}
          method="get"
          role="search"
        >
          <label className={styles.searchLabel} htmlFor="nf-search">
            ابحث عمّا كنت تريده
          </label>
          <div className={styles.searchField}>
            <CatalogIcon
              name="search"
              strokeWidth={2}
              className={styles.searchIcon}
            />
            <input
              id="nf-search"
              className={styles.searchInput}
              type="search"
              name="q"
              dir="auto"
              placeholder="اسم المادة أو الصف أو نوع الملف"
            />
            <button className={styles.searchBtn} type="submit">
              بحث
            </button>
          </div>
        </form>

        <section className={styles.start}>
          <h2 className={styles.startTitle}>أو ابدأ من هنا</h2>
          <ul className={styles.startGrid} role="list">
            {stages.data.slice(0, STAGE_CARDS).map((stage, index) => (
              <li key={stage.slug}>
                <a
                  className={styles.startCard}
                  href={termPath("stages", stage.slug)}
                >
                  <span
                    className={`${styles.startIcon} ${STAGE_TINTS[index] ?? ""}`}
                  >
                    <CatalogIcon
                      name={STAGE_ICONS[index] ?? "book"}
                      strokeWidth={2}
                      className={styles.startGlyph}
                    />
                  </span>
                  <b className={styles.startName}>{stage.name}</b>
                  {typeof stage.productsCount === "number" ? (
                    <span className={styles.startBody}>
                      {qty(stage.productsCount, FILE_FORMS)}
                    </span>
                  ) : null}
                  <span className={styles.startCta}>
                    تصفّح
                    <CatalogIcon
                      name="chev"
                      strokeWidth={2.4}
                      className={styles.startGo}
                    />
                  </span>
                </a>
              </li>
            ))}

            <li>
              <a className={styles.startCard} href={routes.contact}>
                <span className={`${styles.startIcon} ${styles.tintGreen}`}>
                  <AccountIcon
                    name="headset"
                    strokeWidth={2}
                    className={styles.startGlyph}
                  />
                </span>
                <b className={styles.startName}>اسأل الدعم</b>
                <span className={styles.startBody}>
                  نجد لك الملف أو نُعدّه بالطلب
                </span>
                <span className={styles.startCta}>
                  تواصل معنا
                  <CatalogIcon
                    name="chev"
                    strokeWidth={2.4}
                    className={styles.startGo}
                  />
                </span>
              </a>
            </li>
          </ul>
        </section>

        {best.data.length > 0 ? (
          <section className={styles.alt}>
            <div className={styles.altHead}>
              <h2 className={styles.altTitle}>الأكثر مبيعًا — بديل سريع</h2>
              <a className={styles.altLink} href={routes.shop}>
                كل المنتجات
              </a>
            </div>
            <ul className={styles.altGrid} role="list">
              {best.data.map((dto) => (
                <li key={dto.id}>
                  <ProductCard {...toCardProps(dto)} />
                </li>
              ))}
            </ul>
          </section>
        ) : null}
      </div>
    </div>
  );
}
