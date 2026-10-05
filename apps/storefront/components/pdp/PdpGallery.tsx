'use client';
import Image from 'next/image';
import { useCallback, useEffect, useId, useRef, useState, type KeyboardEvent } from 'react';
import { formatNumber, qty } from '@tahdheer/design-system/format';
import { BackIcon, CloseIcon, EyeIcon, NextIcon, ZoomIcon } from './icons';
import { usePageSelection } from './PageSelection';
import styles from './pdp.module.css';

/**
 * Gallery — Storefront Product.dc.html «gallery» (stage + 88px rail) and its
 * mobile form (stage + 64×52 horizontal rail).
 *
 * Every page is a real product medium. The design's paper mock is where the
 * page image sits: the stored image takes the paper's box (82% of the stage
 * height) with the paper's radius and shadow.
 *
 * بلا وسائط: المسرح نفسه بأبعاده نفسها وورقةٌ مصبوغة بخطوطٍ زخرفيّة (لغة
 * بطاقة المنتج المشتركة لبطاقةٍ بلا صورة) — `div` مخفيّ عن التقنيات المساعدة
 * لا `figure`، فلا يدّعي معاينةً، ولا شريط مصغّرات. الشارة وكبسولة المعاينة
 * تبقيان فقط حين تقف خلفهما بيانات حقيقيّة (`bestSeller` · عيّنات الخادم).
 *
 * The stage tints rotate through the design's six page tints by position.
 * They are decoration (no data claim), so they follow order, not content.
 *
 * شارة «الأكثر طلبًا» تُصيَّر من `bestSeller` وحده، والشارة البيضاء من اسم
 * الصفحة المخزَّن. وكبسولة المعاينة تعدّ **العيّنات** التي يصدرها الخادم
 * (ملفّات لا صفحات)، فتُسمّى باسمها: «معاينة مجانية (ملفان)» لا «12 صفحة».
 *
 * التكبير: زرّ «تكبير» (‎44px‎) في زاوية المسرح — أو النقر على الصفحة نفسها —
 * يفتح `<dialog>` أصليًّا (`showModal`: حبسُ التركيز وEsc من المتصفّح) بالصفحة
 * كاملةً، وأسهمٍ في اتّجاه القراءة (السابقة يمينًا، التالية يسارًا، ومفاتيح
 * الأسهم كذلك في RTL)، وعدّادٍ حيّ. الإغلاق يعيد التركيز إلى زرّ التكبير،
 * والصفحة التي وقف عليها الزائر تصير صفحةَ المسرح.
 */
export interface GalleryPage {
  url: string;
  alt: string;
  /** Short rail label («الغلاف»…) — only when the medium has a real one. */
  label?: string;
  width?: number;
  height?: number;
}

export interface PdpGalleryProps {
  pages: GalleryPage[];
  /** «الأكثر طلبًا» — ProductResource.bestSeller. */
  bestSeller?: boolean;
  preview?: { href: string; count: number };
}

export const PAGE_TINTS = ['navy', 'teal', 'sand', 'butter', 'lilac', 'steel'] as const;

export function tintOf(index: number): (typeof PAGE_TINTS)[number] {
  return PAGE_TINTS[index % PAGE_TINTS.length] ?? 'navy';
}

const SAMPLE_FORMS = { zero: '', one: 'ملف واحد', two: 'ملفان', few: 'ملفات', many: 'ملفًا' } as const;

export function PdpGallery({ pages, bestSeller = false, preview }: PdpGalleryProps) {
  const { page, pick } = usePageSelection();
  const current = pages[Math.min(page, pages.length - 1)];
  if (!current) {
    return (
      <div className={styles.gallery}>
        <div className={styles.stage} data-tint={tintOf(0)} data-empty="">
          <span className={styles.stagePaper} aria-hidden="true">
            <span />
            <span />
            <span />
            <span />
            <span />
          </span>
          {bestSeller ? (
            <span className={styles.stageBadges}>
              <span className={styles.badgeNavy}>الأكثر طلبًا</span>
            </span>
          ) : null}
          {preview && preview.count > 0 ? (
            <a className={styles.previewPill} href={preview.href} target="_blank" rel="noopener">
              <EyeIcon />
              <span className={styles.previewPillLong}>معاينة مجانية ({qty(preview.count, SAMPLE_FORMS)})</span>
              <span className={styles.previewPillShort}>معاينة {qty(preview.count, SAMPLE_FORMS)}</span>
            </a>
          ) : null}
        </div>
      </div>
    );
  }

  const index = pages.indexOf(current);

  return (
    <div className={styles.gallery}>
      <figure className={styles.stage} data-tint={tintOf(index)}>
        <Zoom pages={pages} index={index} pick={pick} />
        {/* `unoptimized`: الوسائط عبر وسيط المتجر بلا محسِّن صور؛ الأبعاد الاحتياطيّة نسبة A4 — CSS يحكم الحجم الظاهر. */}
        <Image
          className={styles.stageImage}
          src={current.url}
          alt={current.alt}
          width={current.width ?? 1240}
          height={current.height ?? 1754}
          priority={index === 0}
          unoptimized
          data-zoomable=""
        />
        {bestSeller || current.label ? (
          <span className={styles.stageBadges}>
            {bestSeller ? <span className={styles.badgeNavy}>الأكثر طلبًا</span> : null}
            {current.label ? <span className={styles.badgeLabel}>{current.label}</span> : null}
          </span>
        ) : null}
        {preview ? (
          <a className={styles.previewPill} href={preview.href} target="_blank" rel="noopener">
            <EyeIcon />
            <span className={styles.previewPillLong}>معاينة مجانية ({qty(preview.count, SAMPLE_FORMS)})</span>
            <span className={styles.previewPillShort}>معاينة {qty(preview.count, SAMPLE_FORMS)}</span>
          </a>
        ) : null}
      </figure>

      {pages.length > 1 ? (
        <div className={styles.rail} role="group" aria-label="صفحات المعاينة">
          {pages.map((item, i) => (
            <button
              key={item.url}
              type="button"
              className={styles.thumb}
              data-tint={tintOf(i)}
              aria-label={item.label ?? `الصورة ${i + 1}`}
              aria-pressed={i === index}
              onClick={() => pick(i)}
            >
              <Image src={item.url} alt="" width={item.width ?? 1240} height={item.height ?? 1754} unoptimized />
            </button>
          ))}
        </div>
      ) : null}
    </div>
  );
}

/**
 * زرّ التكبير ونافذته. النافذة تُركَّب عند الفتح وحده؛ والنقر على صورة المسرح
 * (`data-zoomable`) يضغط الزرّ نفسه — فعلٌ واحد بمدخلين، والزرّ مدخلُ لوحة المفاتيح.
 */
function Zoom({ pages, index, pick }: { pages: GalleryPage[]; index: number; pick: (i: number) => void }) {
  const [open, setOpen] = useState(false);
  const buttonRef = useRef<HTMLButtonElement>(null);

  useEffect(() => {
    const figure = buttonRef.current?.parentElement;
    const image = figure?.querySelector<HTMLElement>('[data-zoomable]');
    if (!image) return;
    const onClick = () => buttonRef.current?.click();
    image.addEventListener('click', onClick);
    return () => image.removeEventListener('click', onClick);
  }, [index]);

  const closed = useCallback(() => {
    setOpen(false);
    buttonRef.current?.focus({ preventScroll: true });
  }, []);

  const label = pages[index]?.label;
  return (
    <>
      <button
        ref={buttonRef}
        type="button"
        className={styles.zoom}
        aria-haspopup="dialog"
        aria-expanded={open}
        onClick={() => setOpen(true)}
      >
        <ZoomIcon />
        <span className={styles.zoomLabel}>تكبير</span>
        <span className={styles.srOnly}>{label ? ` «${label}»` : ' الصفحة'}</span>
      </button>
      {open ? <Lightbox pages={pages} start={index} pick={pick} onClosed={closed} /> : null}
    </>
  );
}

function Lightbox({
  pages,
  start,
  pick,
  onClosed,
}: {
  pages: GalleryPage[];
  start: number;
  pick: (i: number) => void;
  onClosed: () => void;
}) {
  const dialogRef = useRef<HTMLDialogElement>(null);
  const titleId = useId();
  const [at, setAt] = useState(start);
  const count = pages.length;
  const page = pages[at] ?? pages[0];

  useEffect(() => {
    const dialog = dialogRef.current;
    if (!dialog) return;
    if (!dialog.open) dialog.showModal();
    /* قفل تمرير الصفحة خلف النافذة؛ الموضع لا يُمسّ فيعود كما كان. */
    const root = document.documentElement;
    const previous = root.style.overflow;
    root.style.overflow = 'hidden';
    return () => {
      root.style.overflow = previous;
    };
  }, []);

  const go = useCallback(
    (next: number) => {
      const clamped = Math.max(0, Math.min(count - 1, next));
      setAt(clamped);
      pick(clamped);
    },
    [count, pick],
  );

  const close = useCallback(() => dialogRef.current?.close(), []);

  /* الأسهم في اتّجاه القراءة: في RTL السهم الأيسر «التالية». */
  const onKeyDown = (event: KeyboardEvent<HTMLDialogElement>) => {
    const rtl = getComputedStyle(event.currentTarget).direction === 'rtl';
    const forward = rtl ? 'ArrowLeft' : 'ArrowRight';
    const backward = rtl ? 'ArrowRight' : 'ArrowLeft';
    if (event.key === forward) go(at + 1);
    else if (event.key === backward) go(at - 1);
    else if (event.key === 'Home') go(0);
    else if (event.key === 'End') go(count - 1);
    else return;
    event.preventDefault();
  };

  if (!page) return null;

  return (
    <dialog ref={dialogRef} className={styles.lightbox} aria-labelledby={titleId} onClose={onClosed} onKeyDown={onKeyDown}>
      {/* الستارة (وصفة `scrim`) — النقر عليها يغلق؛ `::backdrop` شفّاف فلا ستارتان. */}
      <div className={styles.lightboxScrim} aria-hidden="true" onClick={close} />
      <div className={styles.lightboxPanel}>
        <div className={styles.lightboxHead}>
          <h2 id={titleId} className={styles.lightboxTitle}>
            {page.label ?? 'معاينة الصفحات'}
          </h2>
          {count > 1 ? (
            <p className={styles.lightboxCount} aria-live="polite">
              <bdi dir="ltr">{formatNumber(at + 1)}</bdi> من <bdi dir="ltr">{formatNumber(count)}</bdi>
            </p>
          ) : null}
          <button type="button" className={styles.lightboxClose} onClick={close} aria-label="أغلق المعاينة" autoFocus>
            <CloseIcon />
          </button>
        </div>

        <figure className={styles.lightboxFigure}>
          <Image
            key={page.url}
            className={styles.lightboxImage}
            src={page.url}
            alt={page.alt}
            width={page.width ?? 1240}
            height={page.height ?? 1754}
            unoptimized
          />
        </figure>

        {count > 1 ? (
          <div className={styles.lightboxNav}>
            <button
              type="button"
              className={styles.lightboxStep}
              onClick={() => go(at - 1)}
              disabled={at === 0}
              aria-label="الصفحة السابقة"
            >
              <BackIcon />
            </button>
            <button
              type="button"
              className={styles.lightboxStep}
              onClick={() => go(at + 1)}
              disabled={at === count - 1}
              aria-label="الصفحة التالية"
            >
              <NextIcon />
            </button>
          </div>
        ) : null}
      </div>
    </dialog>
  );
}
