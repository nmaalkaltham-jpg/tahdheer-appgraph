/**
 * A taxonomy hub: one card per term the catalog really publishes.
 *
 * The shape is «تسوّق حسب المرحلة» from the homepage rail, so a reader arriving
 * from the homepage sees the same object twice rather than two dialects of it.
 * Five dimensions render from this one component (see `dimensions.ts`).
 *
 * The per-term product count is REAL: it comes from the search response's own
 * taxonomy facets, computed over the published catalog. When the engine ships
 * no count for a term, the card carries the chevron alone — never a number
 * that looks computed but is not.
 */
import type { Metadata } from 'next';
import { qty } from '@tahdheer/design-system/format';
import { fetchSearch, type SearchResponse } from '../../../lib/search-api';
import { routes } from '../../../lib/routes';
import { CatalogEmpty } from './CatalogEmpty';
import { CatalogFailure } from './CatalogFailure';
import { CatalogHero } from './CatalogHero';
import { fetchTerms, type TermDto } from './catalog-api';
import { DIMENSIONS, termPath, type HubDimension } from './dimensions';
import { CatalogIcon, type CatalogIconName } from './icons';
import { collectionRobots, PRODUCT_FORMS } from './query';
import styles from './catalog.module.css';
import hub from './TermHub.module.css';
import { STAGE_ART, subjectArt } from '../../../lib/art';
import { gradeGlyph, gradeInStage, gradeShortName, stageKeyOf } from '../../../lib/homepage';
import { ArtTile, stageTone } from '../../../components/home/ArtTile';

/* Decorative tile glyphs/tints, cycled by position exactly as the homepage
   rail cycles them — presentation only, never a claim about the term. */
const TILE_ICONS: readonly CatalogIconName[] = ['blocks', 'book', 'cap', 'trophy'];
const TILE_TINTS: readonly (string | undefined)[] = [
  styles.tintTeal,
  styles.tintNavy,
  styles.tintSand,
  styles.tintLilac,
];

function cycle<T>(list: readonly T[], index: number): T | undefined {
  return list[index % list.length];
}

/**
 * Published-product counts per term slug, taken from the search facets.
 *
 * Four dimensions arrive in `facets.taxonomy` keyed by the filter name; the
 * kind dimension arrives as its own `facets.kinds` list because the engine
 * indexes it as a plain attribute. An engine that ships neither yields an
 * empty map, and every card simply renders without a count.
 */
function countsFor(dimension: HubDimension, search: SearchResponse | null): Map<string, number> {
  const counts = new Map<string, number>();
  if (!search) return counts;

  const { filterKey } = DIMENSIONS[dimension];

  for (const facet of search.facets.taxonomy ?? []) {
    if (facet.key !== filterKey) continue;
    for (const value of facet.values) counts.set(value.value, value.count);
  }

  if (dimension === 'kinds') {
    for (const kind of search.facets.kinds ?? []) {
      if (typeof kind.count === 'number') counts.set(kind.slug, kind.count);
    }
  }

  return counts;
}

/** The hub's own request — `page: 1` with no filters, memoised per request. */
function hubSearch() {
  return fetchSearch({ page: 1 });
}

export async function termHubMetadata(dimension: HubDimension): Promise<Metadata> {
  const copy = DIMENSIONS[dimension];
  const [terms, search] = await Promise.all([fetchTerms(copy.taxonomy), hubSearch()]);

  return {
    title: copy.hubTitle,
    description: copy.hubDescription,
    alternates: { canonical: copy.hubPath },
    /*
     * A hub with nothing to index is held back — the same §7.2 inventory
     * threshold the server applies before it will emit this URL in the
     * sitemap. Links stay followed either way, so the crawler still reaches
     * whatever the catalog does publish.
     */
    robots: collectionRobots(
      terms.data.length > 0 ? (search.data?.results.total ?? 0) : 0,
      false,
    ),
  };
}

interface GradeTerm extends TermDto {
  stage?: { slug: string; name: string } | null;
}

const GRADE_FORMS = DIMENSIONS.grades.forms;

function stageArtOf(stage: { slug: string; name: string }) {
  const key = stageKeyOf(stage.name) ?? stageKeyOf(stage.slug);
  return key ? STAGE_ART[key] : null;
}

/** عددٌ حقيقيّ أو لا شيء — من فرز البحث أوّلًا، ثمّ من حقل المفردة. */
function countOf(counts: Map<string, number>, term: TermDto): number | undefined {
  return counts.get(term.slug) ?? term.productsCount;
}

/**
 * بطاقة المرحلة في المحور: الرسم على لهجتها، والاسم رابطُ أرشيفها، والأرقام
 * (منتجات · صفوف) حين تُبثّ، ثمّ صفوفها روابطَ — الخطوة التالية في المسار
 * مرحلة ← صف ← مادّة ← فصل ← نوع ← منتجات (الثلاثة الأخيرة رفُّ الأرشيف).
 */
function StageHubCard({ term, count, grades }: { term: TermDto; count?: number; grades: GradeTerm[] }) {
  const key = stageKeyOf(term.name) ?? stageKeyOf(term.slug);
  const href = termPath('stages', term.slug);
  const facts = [
    typeof count === 'number' ? qty(count, PRODUCT_FORMS) : null,
    grades.length > 0 ? qty(grades.length, GRADE_FORMS) : null,
  ].filter(Boolean);
  return (
    <div className={`${hub.card} ${stageTone(key)}`}>
      <a className={hub.cardHead} href={href}>
        <ArtTile art={key ? STAGE_ART[key] : null} name={term.name} sizes="(max-width: 767px) 40px, 58px" className={hub.tileLg} />
        <span className={hub.cardText}>
          <b className={hub.cardName}>{term.name}</b>
          {facts.length > 0 ? (
            <span className={hub.facts}>
              {facts.map((fact, i) => (
                <bdi key={i}>{fact}</bdi>
              ))}
            </span>
          ) : null}
        </span>
      </a>
      {grades.length > 0 ? (
        <ul className={hub.grades} role="list" aria-label={`صفوف ${term.name}`}>
          {grades.map((grade) => (
            <li key={grade.slug}>
              <a className={hub.gradeChip} href={termPath('grades', grade.slug)} aria-label={grade.name}>
                {gradeShortName(grade.name, term.name)}
              </a>
            </li>
          ))}
        </ul>
      ) : null}
      <a className={hub.cta} href={href}>
        كل ملفات {term.name}
        <CatalogIcon name="chev" strokeWidth={2.4} className={hub.ctaIcon} />
      </a>
    </div>
  );
}

/** بطاقة مفردةٍ برسمها (صفٌّ برسم مرحلته، مادّةٌ برسمها أو حرفها) — البطاقة كلّها رابط. */
function ArtTermCard({
  dimension,
  term,
  count,
}: {
  dimension: 'grades' | 'subjects';
  term: GradeTerm;
  count?: number;
}) {
  /* الصفّ: رقمه على لهجة مرحلته (رسم المرحلة في رأس مجموعته مرّةً واحدة)؛ المادّة: رسمها أو حرفها. */
  const grade = dimension === 'grades';
  const stage = grade ? (stageKeyOf(term.stage?.name ?? '') ?? stageKeyOf(term.name)) : null;
  const art = grade ? null : (subjectArt(term.name) ?? subjectArt(term.slug));
  return (
    <a className={`${hub.tileCard} ${stageTone(stage)}`} href={termPath(dimension, term.slug)}>
      <ArtTile
        art={art}
        name={term.name}
        glyph={grade ? gradeGlyph(term.name) : undefined}
        sizes="(max-width: 767px) 32px, 40px"
        className={hub.tileSm}
      />
      <span className={hub.cardText}>
        <b className={hub.tileName}>{term.name}</b>
        {typeof count === 'number' ? <bdi className={hub.tileCount}>{qty(count, PRODUCT_FORMS)}</bdi> : null}
      </span>
      <CatalogIcon name="chev" strokeWidth={2.4} className={hub.tileGo} />
    </a>
  );
}

export async function TermHub({ dimension }: { dimension: HubDimension }) {
  const copy = DIMENSIONS[dimension];
  /* المراحل تحتاج صفوفها، والصفوف تحتاج مراحلها للتجميع — نداءٌ واحد إضافيّ لكلٍّ منهما. */
  const [termsRes, searchRes, gradesRes, stagesRes] = await Promise.all([
    fetchTerms(copy.taxonomy),
    hubSearch(),
    dimension === 'stages' ? fetchTerms('grades') : null,
    dimension === 'grades' ? fetchTerms('stages') : null,
  ]);

  const crumbs = [{ label: 'الرئيسية', href: routes.home }, { label: copy.hubTitle }];

  // «The taxonomy did not answer» must never render as «this store has no
  // subjects» — an outage gets the retry card, not an empty state.
  if (termsRes.status !== 200) {
    return <CatalogFailure crumbs={crumbs} title={copy.hubTitle} retryHref={copy.hubPath} />;
  }

  const terms: GradeTerm[] = termsRes.data;
  const counts = countsFor(dimension, searchRes.data);
  const allGrades: GradeTerm[] = gradesRes?.status === 200 ? gradesRes.data : [];

  /* الصفوف مجمّعةً تحت مراحلها بترتيب المراحل؛ صفٌّ بلا مرحلةٍ معروفة في مجموعةٍ أخيرة بلا عنوان. */
  const gradeGroups =
    dimension === 'grades'
      ? (() => {
          const stages = stagesRes?.status === 200 ? stagesRes.data : [];
          const groups = stages
            .map((stage) => ({ stage, items: terms.filter((g) => gradeInStage(g, stage)) }))
            .filter((group) => group.items.length > 0);
          const rest = terms.filter((g) => !groups.some((group) => group.items.includes(g)));
          return rest.length > 0 ? [...groups, { stage: null, items: rest }] : groups;
        })()
      : null;

  const body = (() => {
    if (terms.length === 0) return null;
    if (dimension === 'stages') {
      return (
        <ul className={hub.stageGrid} role="list">
          {terms.map((term) => (
            <li key={term.slug}>
              <StageHubCard term={term} count={countOf(counts, term)} grades={allGrades.filter((g) => gradeInStage(g, term))} />
            </li>
          ))}
        </ul>
      );
    }
    if (gradeGroups) {
      return (
        <div className={hub.groups}>
          {gradeGroups.map((group) => (
            <section
              key={group.stage?.slug ?? '_'}
              className={`${hub.group} ${stageTone(group.stage ? stageKeyOf(group.stage.name) : null)}`}
              aria-label={group.stage?.name ?? copy.hubTitle}
            >
              {group.stage ? (
                <h2 className={hub.groupTitle}>
                  <ArtTile
                    art={stageArtOf(group.stage)}
                    name={group.stage.name}
                    sizes="40px"
                    className={hub.tileXs}
                  />
                  <a href={termPath('stages', group.stage.slug)}>{group.stage.name}</a>
                </h2>
              ) : null}
              <ul className={hub.tileGrid} role="list">
                {group.items.map((term) => (
                  <li key={term.slug}>
                    <ArtTermCard dimension="grades" term={term} count={countOf(counts, term)} />
                  </li>
                ))}
              </ul>
            </section>
          ))}
        </div>
      );
    }
    if (dimension === 'subjects') {
      return (
        <ul className={hub.tileGrid} role="list">
          {terms.map((term) => (
            <li key={term.slug}>
              <ArtTermCard dimension="subjects" term={term} count={countOf(counts, term)} />
            </li>
          ))}
        </ul>
      );
    }
    return (
      <ul className={styles.stageGrid} role="list">
        {terms.map((term, index) => {
          const count = countOf(counts, term);

          return (
            <li key={term.slug}>
              <a className={styles.stageCard} href={termPath(dimension, term.slug)}>
                <span className={`${styles.stageIcon} ${cycle(TILE_TINTS, index) ?? ''}`}>
                  <CatalogIcon name={cycle(TILE_ICONS, index) ?? 'book'} strokeWidth={1.9} />
                </span>
                <b className={styles.stageName}>{term.name}</b>
                <span className={styles.stageFoot}>
                  {/* عزلٌ ثنائيّ الاتّجاه: الرقم اللاتينيّ داخل العربيّة لا يُعاد ترتيبه. */}
                  {typeof count === 'number' ? <bdi>{qty(count, PRODUCT_FORMS)}</bdi> : null}
                  <CatalogIcon name="chev" strokeWidth={2.4} className={styles.stageGo} />
                </span>
              </a>
            </li>
          );
        })}
      </ul>
    );
  })();

  return (
    <div className={styles.page}>
      <CatalogHero
        crumbs={crumbs}
        title={copy.hubTitle}
        intro={copy.hubIntro}
        facts={
          terms.length > 0
            ? [
                { icon: copy.icon, text: `${qty(terms.length, copy.forms)} منشورة` },
                { icon: 'download', text: 'تحميل فوري بعد الدفع' },
              ]
            : undefined
        }
      />

      {body ?? (
        <CatalogEmpty
          icon={copy.icon}
          title={copy.emptyTitle}
          text={copy.emptyText}
          actions={[{ label: 'تصفّح كل المنتجات', href: routes.shop, primary: true }]}
        />
      )}
    </div>
  );
}
