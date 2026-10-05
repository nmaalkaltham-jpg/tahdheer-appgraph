'use client';
/**
 * «الأكثر طلبًا لصفّك» — الزائر يختار صفّه مرّة، ونحتفظ به في المتصفّح.
 *
 * منقولٌ عن `PopularByGradeWidget` في «Home Components.dc.html»، بما يملكه
 * المتجر فعلًا:
 *  · تبويبان لا خمسة: «الأكثر طلبًا» (`sort=bestselling` — وحداتٌ مدفوعة
 *    فعلًا) و«الأحدث» (`sort=newest`). و«الأعلى تقييمًا» و«الحزم» و«المجاني»
 *    لا ترتيبَ ولا مرشّحَ لها في `/search` فلا تُرسم تبويباتٍ لا تُغيّر شيئًا.
 *  · «N عملية شراء» في القالب لا حقلَ لها في بطاقة المنتج فتُحذف؛ والتقييم
 *    بمتوسّطٍ وعددٍ حقيقيّين فقط.
 *  · «معاينة سريعة» و«المفضّلة» لا طرفَ واجهةٍ لهما — يبقى «أضف» وحده.
 *  · الصفّ المحفوظ في `localStorage` كما يَعِد القالب («نحتفظ باختيارك»).
 */
import { useEffect, useRef, useState, useSyncExternalStore } from 'react';
import type { ProductCardDto } from '@tahdheer/api-sdk/types';
import { qty, type PluralForms } from '@tahdheer/design-system/format';
import { ProductCard } from '@tahdheer/ui';
import { CardAddToCart } from '../product/CardAddToCart';
import { cardFallbackArt } from '../product/CardArt';
import { cardPreview, cardWishlist } from '../../app/(catalog)/_components/ProductGrid';
import { toCardProps } from '../../lib/api';
import { routes } from '../../lib/routes';
import { Icon } from './icons';
import { searchCatalog } from './searchClient';
import type { GradeTerm } from './StagesExplorer';
import styles from './widgets.module.css';

const STORAGE_KEY = 'tahdheer.home.grade';
const LIMIT = 6;

const TABS = [
  { id: 'bestselling', label: 'الأكثر طلبًا' },
  { id: 'newest', label: 'الأحدث' },
] as const;
type TabId = (typeof TABS)[number]['id'];

/* النتيجة تحمل مفتاح طلبها (صفّ + تبويب): ما لا يطابق المعروض يُقرأ «تحميل». */
type ProductsState =
  | { key: string; kind: 'ready'; items: ProductCardDto[] }
  | { key: string; kind: 'failed' };

/*
 * الصفّ المحفوظ مخزنٌ خارجيّ لا حالةُ مكوّن: يُقرأ بعد الترطيب بلا تعارض
 * (لقطة الخادم `null`)، ويتغيّر بحدثٍ محلّيّ أو من نافذةٍ أخرى.
 */
const CHANGE_EVENT = 'tahdheer:home-grade';

function subscribeGrade(onChange: () => void) {
  window.addEventListener('storage', onChange);
  window.addEventListener(CHANGE_EVENT, onChange);
  return () => {
    window.removeEventListener('storage', onChange);
    window.removeEventListener(CHANGE_EVENT, onChange);
  };
}

function readGrade(): string | null {
  try {
    return window.localStorage.getItem(STORAGE_KEY);
  } catch {
    return null;
  }
}

function writeGrade(slug: string | null) {
  try {
    if (slug === null) window.localStorage.removeItem(STORAGE_KEY);
    else window.localStorage.setItem(STORAGE_KEY, slug);
  } catch {
    /* التخزين معطّل — لا يبقى الاختيار بعد هذه الصفحة. */
  }
  window.dispatchEvent(new Event(CHANGE_EVENT));
}


/** `purchaseCount` يبثّه `ProductCardResource` — «N عملية شراء» تُكتب به وحده. */
type CardDto = ProductCardDto & { purchaseCount?: number };

const PURCHASE_FORMS: PluralForms = {
  zero: 'لا مشتريات',
  one: 'عملية شراء واحدة',
  two: 'عمليتا شراء',
  few: 'عمليات شراء',
  many: 'عملية شراء',
};

function Card({ dto }: { dto: CardDto }) {
  const bought = typeof dto.purchaseCount === 'number' && dto.purchaseCount > 0 ? dto.purchaseCount : null;
  /* البطاقة الواحدة بأفعالها الواحدة (القلب · «معاينة» · «أضف للسلة») كبقيّة المتجر. */
  const props = toCardProps(dto);
  return (
    <ProductCard
      {...props}
      variant="featured"
      metaSlot={bought ? qty(bought, PURCHASE_FORMS) : undefined}
      actions={cardWishlist(props)}
      previewSlot={props.unavailable ? undefined : cardPreview(props)}
      mediaSlot={cardFallbackArt(props.categoryPath, Boolean(props.image))}
      ctaSlot={<CardAddToCart productId={dto.id} title={dto.title} disabled={props.unavailable} />}
    />
  );
}

export function PopularByGrade({ grades }: { grades: GradeTerm[] }) {
  const saved = useSyncExternalStore(subscribeGrade, readGrade, () => null);
  /* صفٌّ لم يعد منشورًا يُهمل. */
  const grade = saved !== null && grades.some((g) => g.slug === saved) ? saved : null;
  const [tab, setTab] = useState<TabId>('bestselling');
  const [products, setProducts] = useState<ProductsState | null>(null);
  const seq = useRef(0);
  const key = `${grade ?? ''}|${tab}`;

  useEffect(() => {
    if (grade === null) return;
    const mine = ++seq.current;
    void searchCatalog({ grade, sort: tab }).then((res) => {
      if (mine !== seq.current) return;
      setProducts(
        res.ok
          ? { key, kind: 'ready', items: (res.payload.results?.items ?? []).slice(0, LIMIT) }
          : { key, kind: 'failed' },
      );
    });
  }, [grade, tab, key]);

  const view: ProductsState | { kind: 'loading' } = products && products.key === key ? products : { kind: 'loading' };
  const pick = writeGrade;

  const gradeName = grades.find((g) => g.slug === grade)?.name;

  return (
    <>
      <div className={`${styles.head} ${styles.headPopular}`}>
        <div>
          <p className={styles.eyebrow}>مخصّص لك</p>
          <h2 className={styles.h2}>الأكثر طلبًا لصفك</h2>
        </div>
        <div className={styles.picker}>
          <span className={styles.scope}>{gradeName ? `صفّك: ${gradeName}` : 'اختر صفك'}</span>
          {grade !== null ? (
            <button type="button" className={styles.chip} onClick={() => pick(null)}>
              تغيير الصف
            </button>
          ) : null}
        </div>
      </div>

      <div role="tablist" aria-label="ترتيب المنتجات" className={styles.tabs}>
        {TABS.map((t) => (
          <button
            key={t.id}
            type="button"
            role="tab"
            aria-selected={tab === t.id}
            className={styles.tab}
            onClick={() => setTab(t.id)}
          >
            {t.label}
          </button>
        ))}
      </div>

      {grade === null ? (
        <div className={styles.needGrade}>
          <span className={styles.needIcon}>
            <Icon name="cap" size={24} strokeWidth={1.9} />
          </span>
          <b className={styles.needTitle}>اختر صفك لنعرض لك الأكثر طلبًا فيه</b>
          <p className={styles.needLede}>نحتفظ باختيارك أثناء تنقّلك في المتجر، ولن نسألك عنه مرة أخرى.</p>
          <div className={styles.needChips}>
            {grades.map((g) => (
              <button
                key={g.slug}
                type="button"
                className={`${styles.chip} ${styles.chipSquare}`}
                onClick={() => pick(g.slug)}
              >
                {g.name}
              </button>
            ))}
          </div>
        </div>
      ) : view.kind === 'loading' ? (
        <p className={styles.note} aria-live="polite">
          جارٍ جلب منتجات هذا الصفّ…
        </p>
      ) : view.kind === 'failed' ? (
        <p className={styles.note} aria-live="polite">
          تعذّر جلب المنتجات — أعد المحاولة لاحقًا.
        </p>
      ) : view.items.length === 0 ? (
        <div className={styles.empty}>
          <b className={styles.emptyTitle}>لا توجد منتجات في هذا التبويب لصفك بعد</b>
          <p className={styles.emptyLede}>جرّب تبويبًا آخر، أو اطلب الملف ونجهّزه لك.</p>
          <a className={styles.emptyCta} href={routes.request}>
            اطلب ملفًا
          </a>
        </div>
      ) : (
        <div className={styles.products}>
          {view.items.map((dto) => (
            <Card key={dto.id} dto={dto} />
          ))}
        </div>
      )}
    </>
  );
}
