/**
 * Homepage sections (MIP §3.3 — the 13-id canonical enum).
 *
 * VISUAL SOURCE: «Tahdheer - Homepage Redesign» option 1a/1d (Hero A committed)
 * → TahHomeBody (desktop 1440) · TahHomeMobile (390) · TahTrust (trust band) ·
 * TahHomeReviews (reviews band). Layout, spacing and type come from those
 * artboards; colour comes from the design-system tokens they were drawn from.
 *
 * HONESTY DOCTRINE: every section fetches its content from the real API.
 * Two different absences, two different answers:
 *   200 + nothing published -> EmptyState audience="visitor" renders NOTHING
 *     (an admin preview surface would pass "admin" for the dashed box).
 *   status != 200 (a fault) -> <Failed /> renders a visible notice. A server
 *     outage is an event the visitor is entitled to, not a silent blank page.
 * The sample values in
 * the artboards (٣٥ ر.س، «٤٨ ملفًّا»، 4.9، «١٢٤ زائرًا») are NOT copied: only
 * the layout and styling are. Counts, ratings, ranks, coupon codes and dates
 * all come from props/API or are omitted entirely.
 *
 * The ONLY static content is the §3.3 VERIFIED committed copy (trust band
 * items), the section titles, and navigation labels
 * pointing at real routes — design-evidence strings, not data.
 */
import type { ReactNode } from 'react';
import type { ProductCardDto } from '@tahdheer/api-sdk/types';
import { EmptyState } from '@tahdheer/ui';
import { qty, type PluralForms } from '@tahdheer/design-system/format';
import { apiGet, apiGetCollection, toCardProps } from '../../lib/api';
import type { HomeCtx, HomepageSectionId } from '../../lib/homepage';
import { termPath } from '../../app/(catalog)/_components/dimensions';
/* صيغةُ جمع المنتجات — مصدرها الوحيد، تستورده أربع صفحاتٍ أخرى. */
import { PRODUCT_FORMS } from '../../app/(catalog)/_components/query';
import { routes } from '../../lib/routes';
import { CouponCard } from './CouponCard';
import { FeaturedTabs } from './FeaturedTabs';
import { Icon } from './icons';
import { SmartFinder } from './SmartFinder';
import { StagesExplorer, type GradeTerm, type StageTerm } from './StagesExplorer';
import finderStyles from './SmartFinderHero.module.css';
import { SectionHead } from './SectionHead';
import { ArtTile } from './ArtTile';
import { subjectArt, TEACHER_ART } from '../../lib/art';
import { ThemedArt } from './ThemedArt';
import teacherStyles from './Teachers.module.css';
import subjectStyles from './SubjectRail.module.css';
import { DESIGN_SECTIONS } from './design';
import { WIDGET_SECTIONS } from './widgets';
import { Failed, failed } from './Failed';
import styles from './sections.module.css';

/** This app renders the public storefront — visitors get nothing when empty. */
const AUDIENCE = 'visitor' as const;

function Empty({ title }: { title: string }) {
  return <EmptyState audience={AUDIENCE} title={title} />;
}

/**
 * Full-bleed section shell. Backgrounds and block padding are keyed off
 * `data-section` in the stylesheet; the inline container (`.inner`) belongs to
 * each section body so that an empty section collapses to nothing at all
 * (`.section:empty`) instead of leaving a band of dead space.
 *
 * ─── لماذا سمتان لا واحدة ───
 *
 * `data-section` تخصّ ورقة أنماط هذا الملفّ (خلفيّات وحشوات)، و`data-theme-section`
 * هي **نطاق التجاوز على مستوى المتجر**: `ThemeCss::overrides` يبثّ لكلّ قسمٍ
 * حُرِّر سطرًا `[data-theme-section="X"]{--tp-*}` داخل `/theme.css` الذي يربطه
 * التخطيط في كلّ صفحة.
 *
 * وقبل هذا السطر كانت السمة غائبة من المتجر كلّه: التجاوز يُحفَظ بـ
 * `PUT /admin/theme`، ويُطبَّع على الخادم، ويُصرَّف إلى `/theme.css` فعلًا — ثمّ
 * لا يطابق محدّدُه شيئًا. فكان المالك يضبط طباعة قسمٍ في الاستوديو، ويراها في
 * معاينته، ولا تتغيّر في متجره. الخطّ كان كاملًا إلّا من هذه الحلقة.
 *
 * وفصلُهما مقصود: خلط النطاقين يجعل تغيير ورقة أنماط داخليّة يكسر تجاوزات
 * حفظها المالك، وهما شيئان لا يملكهما أحد.
 */
function Section({ id, children }: { id: HomepageSectionId; children: ReactNode }) {
  return (
    <section id={id} data-section={id} data-theme-section={id} className={styles.section}>
      {children}
    </section>
  );
}

/* ---------------------------------------------------------------- interim DTOs
 * Typecheck-only shapes for endpoints the Laravel API will expose; the
 * generated @tahdheer/api-sdk supersedes them (OpenAPI owns the contracts).
 * Fields added for this pass are all OPTIONAL — the existing contract is
 * unchanged, and every optional field simply does not render when absent. */

interface TermDto {
  slug: string;
  /** Arabic label — the catalog resource emits it as `name`. */
  name: string;
  /** Real product count from the catalog — absent means "do not show a count". */
  productsCount?: number;
  /** Short descriptor under the term name (e.g. the grades it covers). */
  subtitle?: string;
}

interface PromoDto {
  campaign?: { title: string; description?: string; ends_at: string; url: string };
  coupon?: { code: string; description?: string };
}

interface WhyCardDto {
  title: string;
  description?: string;
}

interface FaqItemDto {
  id: number;
  question: string;
  answer: string;
}

/* --------------------------------------------------------------- copy helpers */

const DAY_FORMS: PluralForms = {
  one: 'يوم واحد',
  two: 'يومين',
  few: 'أيام',
  many: 'يومًا',
  zero: 'اليوم',
};

/** Whole days left until an ISO timestamp; null when the date is unusable. */
function daysUntil(iso: string): number | null {
  const end = new Date(iso).getTime();
  if (Number.isNaN(end)) return null;
  return Math.ceil((end - Date.now()) / 86_400_000);
}


/* ------------------------------------------------------------------ sections */

async function FeaturedSection() {
  const { data, status } = await apiGetCollection<ProductCardDto>(
    '/api/v1/products?source=featured&limit=8',
  );
  if (failed(status)) return <Failed />;
  if (data.length === 0) return <Empty title="لا توجد منتجات مميّزة بعد" />;

  return (
    <div className={styles.inner}>
      <FeaturedTabs eyebrow="مختارات" title="منتجات مختارة" items={data.map(toCardProps)} />
    </div>
  );
}

/**
 * «تسوّق حسب المادة» — بلاطةٌ مربّعة واحدة لكلّ مادّة: رسمها من `subjectArt()`،
 * وبلا رسمٍ صادق حرفُها الأوّل طباعةً (لا أيقونة مستعارة). المكتب شبكةٌ تملأ
 * الصفّ، والجوّال شريطٌ أفقيّ بالتقاط — البيانات نفسها بترميزٍ واحد. العدد
 * يُكتب فقط حين يبثّه الكتالوج.
 */
async function SubjectsSection() {
  const { data, status } = await apiGetCollection<TermDto>('/api/v1/catalog/subjects');
  if (failed(status)) return <Failed />;
  if (data.length === 0) return <Empty title="لا توجد مواد منشورة بعد" />;

  return (
    <div className={styles.inner}>
      <SectionHead
        eyebrow="تصفّح"
        title="تسوّق حسب المادة"
        action={{ label: 'عرض جميع المواد', shortLabel: 'الكل', href: routes.subjects }}
      />
      <ul className={subjectStyles.rail} role="list">
        {data.map((subject) => (
          <li key={subject.slug}>
            <a className={subjectStyles.tile} href={termPath('subjects', subject.slug)}>
              <ArtTile
                art={subjectArt(subject.name) ?? subjectArt(subject.slug)}
                name={subject.name}
                sizes="(max-width: 767px) 40px, 52px"
              />
              <span className={subjectStyles.text}>
                <b className={subjectStyles.name}>{subject.name}</b>
                {typeof subject.productsCount === 'number' ? (
                  <bdi className={subjectStyles.count}>{qty(subject.productsCount, PRODUCT_FORMS)}</bdi>
                ) : null}
              </span>
            </a>
          </li>
        ))}
      </ul>
    </div>
  );
}

/** Campaign panel + coupon card. Both halves are data-gated independently. */
async function PromoSection() {
  const { data, status } = await apiGet<PromoDto>('/api/v1/home/promo');
  if (failed(status)) return <Failed />;
  if (!data || (!data.campaign && !data.coupon)) {
    return <Empty title="لا توجد حملة أو قسيمة فعّالة" />;
  }

  const campaign = data.campaign;
  const days = campaign ? daysUntil(campaign.ends_at) : null;

  return (
    <div className={`${styles.inner} ${styles.promoPair}`}>
      {campaign ? (
        <a className={styles.promoPanel} href={campaign.url}>
          <span className={styles.promoBlob} aria-hidden="true" />
          {days !== null && days >= 0 ? (
            <time className={styles.promoEnds} dateTime={campaign.ends_at}>
              {days === 0 ? 'ينتهي اليوم' : `ينتهي بعد ${qty(days, DAY_FORMS)}`}
            </time>
          ) : null}
          <span className={styles.promoTitle}>{campaign.title}</span>
          {campaign.description ? <span className={styles.promoDesc}>{campaign.description}</span> : null}
          <span className={styles.promoCta}>تصفّح العرض</span>
        </a>
      ) : null}

      {data.coupon ? (
        <CouponCard code={data.coupon.code} description={data.coupon.description} />
      ) : null}
    </div>
  );
}

function WhyCards({ cards }: { cards: WhyCardDto[] }) {
  return (
    <ol className={styles.whyGrid} role="list">
      {cards.map((card) => (
        <li key={card.title} className={styles.whyCard}>
          <span className={styles.whyNum} aria-hidden="true" />
          <b className={styles.whyTitle}>{card.title}</b>
          {card.description ? <p className={styles.whyDesc}>{card.description}</p> : null}
        </li>
      ))}
    </ol>
  );
}

/**
 * سجلُّ «لماذا يختار المعلّمون» — الشكل الذي يفصله عن «المزايا».
 *
 * كان القسمان يتشاركان `WhyCards` نفسها، فيقرأ الزائر شبكتَي بطاقاتٍ
 * متطابقتين تحت عنوانين مختلفين ولا يرى فرقًا بين «لماذا نحن» و«ماذا
 * نقدّم». والسجلّ يعطي الأهمَّ وزنَه: رقمٌ مفرّغ، وعنوان، ونصٌّ بمقياس
 * قراءة، وخيطٌ يفصل — لا رفَّ بطاقاتٍ ثانيًا.
 */
function WhyLedger({ cards }: { cards: WhyCardDto[] }) {
  return (
    <ol className={styles.whyLedger}>
      {cards.map((card) => (
        <li key={card.title} className={styles.ledgerItem}>
          {/* الرقم ترقيمٌ بصريّ لا محتوى: العدّاد في الورقة، وهو خارج شجرة الوصول. */}
          <span className={styles.ledgerNum} aria-hidden="true" />
          <div>
            <h3 className={styles.ledgerTitle}>{card.title}</h3>
            {card.description ? <p className={styles.ledgerDesc}>{card.description}</p> : null}
          </div>
        </li>
      ))}
    </ol>
  );
}

/**
 * «للمعلّمين» — قسم «لماذا نحن» بوجهين: شخصيّتا المعلّم والمعلّمة (`TEACHER_ART`)
 * على لوحٍ هادئ بجانب سجلّ القيم. القيم نفسها بطاقات `/home/why` التي يحرّرها
 * المالك — لا رقمَ ولا ادّعاء من الشيفرة؛ والدعوة تنقّلٌ إلى المراحل (سطحٌ حقيقيّ).
 * الجوّال: الشخصيّتان صغيرتان فوق السجلّ، النصّ بعدهما مباشرة.
 */
async function WhySection() {
  const { data, status } = await apiGet<WhyCardDto[]>('/api/v1/home/why');
  if (failed(status)) return <Failed />;
  if (!data || data.length === 0) return <Empty title="بطاقات «لماذا نحن» غير مُعدّة بعد" />;

  return (
    <div className={`${styles.inner} ${teacherStyles.layout}`}>
      <div className={teacherStyles.people} aria-hidden="true">
        <ThemedArt art={TEACHER_ART.woman} alt="" sizes="(max-width: 767px) 104px, (max-width: 1024px) 150px, 180px" className={teacherStyles.person} />
        <ThemedArt art={TEACHER_ART.man} alt="" sizes="(max-width: 767px) 104px, (max-width: 1024px) 150px, 180px" className={teacherStyles.person} />
      </div>
      <div className={teacherStyles.copy}>
        {/* The artboard heading carries a «18 ألف معلّم» figure — a sample, so it
            is dropped: the question stands without an unverifiable count. */}
        <SectionHead eyebrow="للمعلّمين" title="لماذا يختار المعلّمون «التحاضير الحديثة»؟" />
        <WhyLedger cards={data} />
        <a className={teacherStyles.cta} href={routes.stages}>
          ابدأ من مرحلتك
        </a>
      </div>
    </div>
  );
}

async function BenefitsSection() {
  const { data, status } = await apiGet<WhyCardDto[]>('/api/v1/home/benefits');
  if (failed(status)) return <Failed />;
  if (!data || data.length === 0) return <Empty title="قسم المزايا غير مُعدّ بعد" />;

  return (
    <div className={styles.inner}>
      <SectionHead align="center" eyebrow="مزايا" title="مزايا المتجر" />
      <WhyCards cards={data} />
    </div>
  );
}

async function FaqSection() {
  const { data, status } = await apiGet<FaqItemDto[]>('/api/v1/home/faq');
  if (failed(status)) return <Failed />;
  if (!data || data.length === 0) return <Empty title="لا توجد أسئلة شائعة منشورة بعد" />;

  return (
    <div className={styles.inner}>
      <SectionHead align="center" eyebrow="مساعدة" title="الأسئلة الشائعة" />
      <div className={styles.faqList}>
        {/* Native single-open accordion via the shared `name`: the answer stays
            in the DOM at all times (SEO / print / deep-linking rule) and the
            control needs no JavaScript at all. Both artboards initialise
            `faq: 0`, so the first published question ships expanded. */}
        {data.map((item, index) => (
          <details
            key={item.id}
            name="home-faq"
            open={index === 0}
            className={styles.faqItem}
          >
            <summary className={styles.faqQuestion}>
              <span>{item.question}</span>
              <span className={styles.faqMark}>
                <Icon name="plus" size={14} strokeWidth={2.4} />
              </span>
            </summary>
            <p className={styles.faqAnswer}>{item.answer}</p>
          </details>
        ))}
      </div>
    </div>
  );
}

/* ------------------------------------------------------------------- registry */

/**
 * التصنيف الذي يشتركان فيه — نداءان لا أربعة.
 *
 * `smart-finder` و`stages-explorer` يقرآن المراحل والصفوف نفسها. وكلّ صفٍّ
 * يحمل مرحلته في حمولته (`stage`)، فالانتماء حقيقةٌ مبثوثة يُجمَّع بها بلا
 * نداءٍ لكلّ مرحلة.
 */
async function loadCurriculum(): Promise<{
  stages: StageTerm[];
  grades: GradeTerm[];
  status: number;
}> {
  const [stagesRes, gradesRes] = await Promise.all([
    apiGetCollection<StageTerm>('/api/v1/catalog/stages'),
    apiGetCollection<GradeTerm>('/api/v1/catalog/grades'),
  ]);
  /* أسوأ الحالتين يحكم: قسمٌ نصفُ بياناته عطلٌ يُعرض عطلًا لا نصفَ شجرة. */
  const status = stagesRes.status !== 200 ? stagesRes.status : gradesRes.status;
  return { stages: stagesRes.data, grades: gradesRes.data, status };
}

/**
 * هيرو «كلّ ما تحتاجه لدرسك» — بحثٌ واختصارات، ولوحةُ وصولٍ سريع.
 *
 * منقولٌ عن `HeroSmartFinder`. العنوان والنصّ والبحث تُصيَّر على الخادم فتصل
 * بلا جافاسكربت؛ واللوحة وحدها جزيرةُ عميل لأنّ خطوة المادّة تُجلب بعد اختيار
 * الصفّ. والاختصارات مراحلُ حقيقيّة لا أسماءً مكتوبة.
 */
async function SmartFinderSection() {
  const { stages, grades, status } = await loadCurriculum();
  if (failed(status)) return <Failed />;
  if (stages.length === 0) return <Empty title="لا توجد مراحل تعليمية منشورة بعد" />;

  return (
    <div className={finderStyles.inner}>
      <div className={finderStyles.card}>
        <span className={finderStyles.ringStart} aria-hidden="true" />
        <span className={finderStyles.ringEnd} aria-hidden="true" />

        <div className={finderStyles.grid}>
          <div className={finderStyles.lead}>
            <h1 className={finderStyles.title}>كل ما تحتاجه لدرسك… في مكان واحد</h1>
            <p className={finderStyles.lede}>
              اختر مرحلتك وصفّك ومادّتك، وانتقل مباشرة إلى التحاضير والعروض والملفّات التعليمية المناسبة.
            </p>

            {/* نموذج GET حقيقيّ إلى صفحة البحث — يعمل بلا جافاسكربت. */}
            <form className={finderStyles.search} action={routes.search} role="search">
              <span className={finderStyles.searchIcon} aria-hidden="true">
                <Icon name="grid" size={18} strokeWidth={2} />
              </span>
              <input
                className={finderStyles.searchInput}
                type="search"
                name="q"
                aria-label="ابحث باسم المادّة أو نوع الملفّ"
                placeholder="ابحث باسم المادّة أو نوع الملفّ…"
              />
              <button className={finderStyles.searchSubmit} type="submit">
                بحث
              </button>
            </form>

            <div className={finderStyles.shortcuts}>
              <span>اختصارات:</span>
              <ul className={finderStyles.shortcutList} role="list">
                {stages.map((stage) => (
                  <li key={stage.slug}>
                    <a className={finderStyles.shortcut} href={termPath('stages', stage.slug)}>
                      {stage.name}
                    </a>
                  </li>
                ))}
              </ul>
            </div>
          </div>

          <SmartFinder stages={stages} grades={grades} />
        </div>
      </div>
    </div>
  );
}

/** «ابدأ من مرحلتك» — منقولٌ عن `HeroEducationStages`. */
async function StagesExplorerSection() {
  const { stages, grades, status } = await loadCurriculum();
  if (failed(status)) return <Failed />;
  if (stages.length === 0) return <Empty title="لا توجد مراحل تعليمية منشورة بعد" />;

  return <StagesExplorer stages={stages} grades={grades} />;
}

export const HOMEPAGE_SECTIONS: Record<HomepageSectionId, (ctx: HomeCtx) => ReactNode> = {
  hero: DESIGN_SECTIONS.hero,
  'smart-finder': () => <SmartFinderSection />,
  'stages-explorer': () => <StagesExplorerSection />,
  'lesson-bundle': WIDGET_SECTIONS['lesson-bundle'],
  'curriculum-navigator': WIDGET_SECTIONS['curriculum-navigator'],
  'product-types': WIDGET_SECTIONS['product-types'],
  'popular-by-grade': WIDGET_SECTIONS['popular-by-grade'],
  trust: DESIGN_SECTIONS.trust,
  stages: DESIGN_SECTIONS.stages,
  featured: () => <FeaturedSection />,
  subjects: () => <SubjectsSection />,
  best: DESIGN_SECTIONS.best,
  'all-products': DESIGN_SECTIONS['all-products'],
  samples: DESIGN_SECTIONS.samples,
  promo: () => <PromoSection />,
  why: () => <WhySection />,
  benefits: () => <BenefitsSection />,
  reviews: DESIGN_SECTIONS.reviews,
  faq: () => <FaqSection />,
  cta: DESIGN_SECTIONS.cta,
};

export { Section };
