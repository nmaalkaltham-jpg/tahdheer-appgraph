/**
 * كتل الهيرو الأربع من «Storefront Redesign.dc.html»، يختار المالك بينها
 * (وبين الشرائح الافتراضيّة) من اللوحة:
 *   `institutional` ← 1a «مؤسسي — كتالوج محكم»
 *   `bold`          ← 1b «جريء — كتل لونيّة وتيبوغرافيا ضخمة»
 *   `luxe`          ← 1c «داكن وراقي»
 *   `brand`         ← 2a «هويّة الشعار»
 *
 * الجزء «hero» وحده من كلّ لوحة: ما حولها في اللوحات معالجةٌ بديلة للصفحة
 * كلّها تجاوزتها «Storefront Home.dc.html».
 *
 * ─── الصدق ───
 * النصّ كلّه من `home.hero` (العنوان وكلمته الذهبيّة والوصف والسطر العلويّ
 * والدعوتان وشرائح الثقة)؛ بطاقة المعاينة أوّل الأكثر مبيعًا بصيغته وسعره؛
 * والإحصاءات من `home.reviews` وحدها. أرقام اللوحة («٤٨٠٠+ ملفًّا»، «١٨ ألف
 * معلّم»، «٤٨ صفحة») لا مصدر لها فتُحذف عناصرها لا تُنسخ.
 *
 * الأربعة مكوّنات خادم: لا حالة فيها ولا تفاعل.
 */

import type { ReactNode } from 'react';
import type { ProductCardDto } from '@tahdheer/api-sdk/types';
import { formatMoney, formatNumber, qty, type PluralForms } from '@tahdheer/design-system/format';

import { Icon, type IconName } from './icons';
import styles from './heroes.module.css';

export interface HeroCopy {
  title: string;
  titleAccent?: string;
  subtitle?: string;
  eyebrow?: string;
  trust?: readonly string[];
  primary: { label: string; href: string };
  secondary: { label: string; href: string };
}

/** ما تُبنى منه الإحصاءات — كلٌّ اختياريّ، وغيابه يُسقط عنصره. */
export interface HeroStats {
  ratingAvg?: number;
  ratingCount?: number;
}

const REVIEW_FORMS: PluralForms = {
  zero: 'لا تقييمات',
  one: 'تقييم واحد',
  two: 'تقييمين',
  few: 'تقييمات',
  many: 'تقييمًا',
};

function Lines({ widths, cls, line, rule, ruleAt }: { widths: number[]; cls?: string; line?: string; rule?: string; ruleAt?: number }) {
  return (
    <span className={`${styles.lines} ${cls}`} aria-hidden="true">
      {widths.map((w, i) => (
        <span key={i} className={styles.linesPair}>
          {rule && ruleAt === i ? <span className={`${styles.line} ${rule}`} /> : null}
          <span className={`${styles.line} ${line}`} style={w < 100 ? { inlineSize: `${w}%` } : undefined} />
        </span>
      ))}
    </span>
  );
}

function Title({ title, accent, className, accentClass }: { title: string; accent?: string; className?: string; accentClass?: string }) {
  return (
    <h1 className={className}>
      {title}
      {accent ? (
        <>
          {' '}
          <span className={accentClass}>{accent}</span>
        </>
      ) : null}
    </h1>
  );
}

const rating = (stats: HeroStats) =>
  typeof stats.ratingAvg === 'number' && typeof stats.ratingCount === 'number' && stats.ratingCount > 0
    ? { avg: formatNumber(Math.round(stats.ratingAvg * 10) / 10), count: stats.ratingCount }
    : null;

/* ═══════════════════════ 1a — مؤسسي · كتالوج محكم ═══════════════════════ */

export function HeroInstitutional({
  copy,
  product,
  stats,
}: {
  copy: HeroCopy;
  product?: ProductCardDto;
  stats: HeroStats;
}) {
  const rated = rating(stats);
  return (
    <div className={`${styles.stage} ${styles.inst}`}>
      <div className={styles.instCopy}>
        {copy.eyebrow ? <p className={styles.instEyebrow}>{copy.eyebrow}</p> : null}
        <Title title={copy.title} accent={copy.titleAccent} className={styles.instTitle} />
        {copy.subtitle ? <p className={styles.instLede}>{copy.subtitle}</p> : null}
        <div className={styles.instCtas}>
          <a className={styles.instPrimary} href={copy.primary.href}>
            {copy.primary.label}
          </a>
          <a className={styles.instGhost} href={copy.secondary.href}>
            {copy.secondary.label}
            <Icon name="chev" size={16} strokeWidth={2} />
          </a>
        </div>
        {rated ? (
          <div className={styles.instStats}>
            <div className={styles.instStat}>
              <b className={styles.instStatValue} dir="ltr">
                {rated.avg} / 5
              </b>
              <span className={styles.instStatLabel}>متوسّط التقييم من {qty(rated.count, REVIEW_FORMS)}</span>
            </div>
          </div>
        ) : null}
      </div>
      <div className={styles.instPanel}>
        <p className={styles.instPanelLabel}>معاينة قبل الشراء</p>
        {product ? (
          <a className={styles.instCard} href={product.url}>
            <span className={styles.instCardHead}>
              <b className={styles.instCardTitle}>{product.title}</b>
              {product.format ? <span className={styles.instBadge}>{product.format}</span> : null}
            </span>
            <Lines widths={[100, 88, 64, 76, 52]} cls={styles.instLines} line={styles.instLine} rule={styles.instRule} ruleAt={3} />
          </a>
        ) : null}
        {product ? (
          <div className={styles.instFacts}>
            {product.format ? (
              <div className={styles.instFact}>
                <span className={styles.instFactLabel}>الصيغة</span>
                <b>{product.format}</b>
              </div>
            ) : null}
            {product.digital ? (
              <div className={styles.instFact}>
                <span className={styles.instFactLabel}>التسليم</span>
                <b>فوري بعد الدفع</b>
              </div>
            ) : null}
            {product.editable ? (
              <div className={styles.instFact}>
                <span className={styles.instFactLabel}>التعديل</span>
                <b>مسموح ومفتوح</b>
              </div>
            ) : null}
          </div>
        ) : null}
      </div>
    </div>
  );
}

/* ═══════════════════════════ 1b — جريء ═══════════════════════════════════ */

export function HeroBold({ copy }: { copy: HeroCopy }) {
  return (
    <div className={`${styles.stage} ${styles.bold}`}>
      {copy.eyebrow ? <p className={styles.boldEyebrow}>{copy.eyebrow}</p> : null}
      <Title title={copy.title} accent={copy.titleAccent} className={styles.boldTitle} accentClass={styles.boldAccent} />
      {copy.subtitle ? <p className={styles.boldLede}>{copy.subtitle}</p> : null}
      <div className={styles.boldCtas}>
        <a className={styles.boldPrimary} href={copy.primary.href}>
          {copy.primary.label}
        </a>
        <a className={styles.boldGhost} href={copy.secondary.href}>
          {copy.secondary.label}
        </a>
        {copy.trust && copy.trust.length > 0 ? <span className={styles.boldNote}>{copy.trust.join(' · ')}</span> : null}
      </div>
    </div>
  );
}

/* ═══════════════════════ 1c — داكن راقي ══════════════════════════════════ */

export function HeroLuxe({
  copy,
  product,
  stats,
}: {
  copy: HeroCopy;
  product?: ProductCardDto;
  stats: HeroStats;
}) {
  const rated = rating(stats);
  const price = product ? formatMoney(product.priceMinor) : null;
  return (
    <div className={`${styles.stage} ${styles.luxe}`}>
      <div>
        {copy.eyebrow ? <p className={styles.luxeEyebrow}>{copy.eyebrow}</p> : null}
        <Title title={copy.title} accent={copy.titleAccent} className={styles.luxeTitle} />
        {copy.subtitle ? <p className={styles.luxeLede}>{copy.subtitle}</p> : null}
        <div className={styles.luxeCtas}>
          <a className={styles.luxePrimary} href={copy.primary.href}>
            {copy.primary.label}
          </a>
          <a className={styles.luxeGhost} href={copy.secondary.href}>
            {copy.secondary.label}
            <Icon name="chev" size={15} strokeWidth={2} />
          </a>
        </div>
        {rated ? (
          <div className={styles.luxeStats}>
            <span>
              <bdi dir="ltr">{rated.avg}</bdi> من 5 في {qty(rated.count, REVIEW_FORMS)}
            </span>
          </div>
        ) : null}
      </div>
      {product ? (
        <a className={styles.luxeCard} href={product.url}>
          <span className={styles.luxeCardHead}>
            <span className={styles.luxeCardLabel}>معاينة الملف</span>
            {product.format ? <span className={styles.luxeBadge}>{product.format}</span> : null}
          </span>
          <b className={styles.luxeCardTitle}>{product.title}</b>
          <span className={`${styles.lines} ${styles.luxeLines}`} aria-hidden="true">
            <span className={`${styles.line} ${styles.luxeLine}`} />
            <span className={`${styles.line} ${styles.luxeLine}`} style={{ inlineSize: '86%' }} />
            <span className={`${styles.line} ${styles.luxeLine} ${styles.luxeLineDim}`} style={{ inlineSize: '62%' }} />
            <span className={`${styles.line} ${styles.luxeLine} ${styles.luxeLineDim}`} style={{ inlineSize: '74%' }} />
          </span>
          <span className={styles.luxeFacts}>
            {product.format ? (
              <span className={styles.luxeFact}>
                <span className={styles.luxeFactLabel}>الصيغة</span>
                <b className={styles.luxeFactValue}>{product.format}</b>
              </span>
            ) : null}
            {product.digital ? (
              <span className={styles.luxeFact}>
                <span className={styles.luxeFactLabel}>التسليم</span>
                <b className={styles.luxeFactValue}>فوري بعد الدفع</b>
              </span>
            ) : null}
            {price ? (
              <span className={styles.luxeFact}>
                <span className={styles.luxeFactLabel}>السعر</span>
                <b className={styles.luxePrice} dir="ltr">
                  {price.amount} {price.currency}
                </b>
              </span>
            ) : null}
          </span>
        </a>
      ) : null}
    </div>
  );
}

/* ═══════════════════════ 2a — هويّة الشعار ═══════════════════════════════ */

/* أيقونة كلّ شريحة ثقة بكلماتها، كما في اللوحة (قلم للتعديل، درعٌ للدفع). */
const CHIP_ICONS: { match: RegExp; icon: IconName }[] = [
  { match: /تعديل|صيغ/, icon: 'pen' },
  { match: /دفع|آمن/, icon: 'shield' },
  { match: /تحميل|فوري/, icon: 'download' },
  { match: /دعم|واتساب/, icon: 'headset' },
];

export function HeroBrand({ copy, product }: { copy: HeroCopy; product?: ProductCardDto }) {
  const price = product ? formatMoney(product.priceMinor) : null;
  const chips = (copy.trust ?? []).slice(0, 2);
  return (
    <div className={`${styles.stage} ${styles.brand}`}>
      <span className={styles.brandBlob} aria-hidden="true" />
      <span className={styles.brandBlobGold} aria-hidden="true" />
      <div className={styles.brandCopy}>
        {copy.eyebrow ? (
          <p className={styles.brandEyebrow}>
            <span className={styles.brandMark} aria-hidden="true">
              <Icon name="medal" size={12} strokeWidth={2.2} />
            </span>
            {copy.eyebrow}
          </p>
        ) : null}
        <Title title={copy.title} accent={copy.titleAccent} className={styles.brandTitle} accentClass={styles.brandAccent} />
        {copy.subtitle ? <p className={styles.brandLede}>{copy.subtitle}</p> : null}
        <div className={styles.brandCtas}>
          <a className={styles.brandPrimary} href={copy.primary.href}>
            <Icon name="grid" size={18} strokeWidth={1.9} />
            {copy.primary.label}
          </a>
          <a className={styles.brandGhost} href={copy.secondary.href}>
            {copy.secondary.label}
            <Icon name="chev" size={15} strokeWidth={2} />
          </a>
        </div>
      </div>
      <div className={styles.brandSide}>
        {product && price ? (
          <a className={styles.brandCard} href={product.url}>
            <span className={styles.brandCardHead}>
              <b className={styles.brandCardTitle}>{product.title}</b>
              {product.format ? <span className={styles.brandBadge}>{product.format}</span> : null}
            </span>
            <Lines widths={[100, 86, 60, 74]} cls={styles.brandLines} line={styles.brandLine} rule={styles.brandRule} ruleAt={3} />
            <span className={styles.brandCardFoot}>
              <b className={styles.brandPrice} dir="ltr">
                {price.amount} <span className={styles.brandCur}>{price.currency}</span>
              </b>
              {product.digital ? (
                <span className={styles.brandNote}>
                  <Icon name="check" size={15} strokeWidth={2.2} />
                  تحميل فوري
                </span>
              ) : null}
            </span>
          </a>
        ) : null}
        {chips.length > 0 ? (
          <div className={styles.brandChips}>
            {chips.map((chip) => (
              <span key={chip} className={styles.brandChip}>
                <span className={styles.brandChipIcon}>
                  <Icon name={CHIP_ICONS.find((c) => c.match.test(chip))?.icon ?? 'check'} size={16} strokeWidth={2} />
                </span>
                {chip}
              </span>
            ))}
          </div>
        ) : null}
      </div>
    </div>
  );
}

export type HeroBlock = ReactNode;
