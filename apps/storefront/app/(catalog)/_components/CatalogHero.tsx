/**
 * Breadcrumb + gradient hero shared by every catalog surface.
 * Design: «Tahdheer - Category» header (the archive basis); the `ornate`
 * variant adds the gold eyebrow pill and the two soft discs of the
 * «Tahdheer - Store Pages» offers header — structure and styling only.
 *
 * Facts are passed in by the page and are always derived from real API data
 * (a count the API returned, a date the API returned) or are a statement about
 * how the store works — never a sample number from the artboard.
 */
import type { ReactNode } from 'react';
import type { Art, StageKey } from '../../../lib/art';
import { ArtTile, stageTone } from '../../../components/home/ArtTile';
import { CatalogIcon, type CatalogIconName } from './icons';
import styles from './catalog.module.css';
import heroStyles from './CatalogHero.module.css';

/**
 * رسم المفردة في الترويسة — مرحلةٌ برسمها ولهجتها، صفٌّ برسم مرحلته، مادّةٌ
 * برسمها أو حرفها. `art: null` ⇒ الحرف البديل (لا أيقونة مستعارة).
 */
export interface CatalogHeroArt {
  art: Art | null;
  name: string;
  stage?: StageKey | null;
}

export interface Crumb {
  label: string;
  /** Absent on the last crumb — the current page is not a link. */
  href?: string;
}

export interface HeroFact {
  icon: CatalogIconName;
  text: ReactNode;
}

export interface CatalogHeroProps {
  crumbs: Crumb[];
  eyebrow?: { icon: CatalogIconName; label: string };
  title: string;
  intro?: ReactNode;
  facts?: HeroFact[];
  /** Offers header treatment (discs + narrow measure). */
  ornate?: boolean;
  /** بلاطة رسم المفردة بدل رزمة الأوراق الزخرفيّة. */
  art?: CatalogHeroArt;
}

export function CatalogHero({ crumbs, eyebrow, title, intro, facts, ornate, art }: CatalogHeroProps) {
  return (
    <>
      <nav className={styles.breadcrumbNav} aria-label="مسار التنقّل">
        <ol className={styles.breadcrumb}>
          {crumbs.map((crumb, index) => (
            <li key={index} className={styles.crumbItem}>
              {crumb.href ? (
                <a className={styles.crumbLink} href={crumb.href}>
                  {crumb.label}
                </a>
              ) : (
                <span aria-current="page" className={styles.crumbCurrent}>
                  {crumb.label}
                </span>
              )}
            </li>
          ))}
        </ol>
      </nav>

      <header
        className={`${ornate ? `${styles.hero} ${styles.heroOrnate}` : styles.hero} ${art ? `${heroStyles.withArt} ${stageTone(art.stage ?? null)}` : ''}`}
      >
        {ornate ? (
          <>
            <span className={`${styles.orb} ${styles.orbGold}`} aria-hidden="true" />
            <span className={`${styles.orb} ${styles.orbPale}`} aria-hidden="true" />
          </>
        ) : null}

        <div className={ornate ? `${styles.heroBody} ${styles.heroNarrow}` : styles.heroBody}>
          {eyebrow ? (
            <span className={styles.eyebrow}>
              <CatalogIcon name={eyebrow.icon} strokeWidth={2} className={styles.eyebrowIcon} />
              {eyebrow.label}
            </span>
          ) : null}

          <h1 className={styles.h1}>{title}</h1>
          {intro ? <p className={styles.intro}>{intro}</p> : null}

          {facts && facts.length > 0 ? (
            <div className={styles.facts}>
              {facts.map((fact, index) => (
                <span key={index} className={styles.fact}>
                  <CatalogIcon name={fact.icon} className={styles.factIcon} />
                  {fact.text}
                </span>
              ))}
            </div>
          ) : null}
        </div>

        {art ? (
          /* بلاطةٌ ثابتة المقاس على لهجة المرحلة — تظهر في الجوّال أيضًا (صغيرةً في الزاوية). */
          <ArtTile art={art.art} name={art.name} sizes="(max-width: 767px) 40px, 88px" className={heroStyles.tile} />
        ) : ornate ? null : (
          /*
           * زخرفة الهيرو الهادئة: رزمةُ أوراقٍ بزاويةٍ ذهبيّة مطويّة — ورقةُ الغلاف
           * نفسها التي ترسمها بطاقة المنتج (لغة العلامة)، لا فقاعةٌ متدرّجة.
           * تملأ الطرف الختاميّ الفارغ من العرض المتوسّط فصاعدًا، وتُخفى دونه.
           */
          <span className={styles.heroArt} aria-hidden="true">
            <span className={styles.sheetBack} />
            <span className={styles.sheetFront}>
              <span />
              <span />
              <span />
              <span />
            </span>
          </span>
        )}
      </header>
    </>
  );
}
