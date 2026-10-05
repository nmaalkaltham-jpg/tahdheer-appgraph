'use client';
// UNIT: HERO-SLIDES-EDITOR
// FILE: hero-slides-editor.tsx
// STYLE: page.module.css
// PARENT: HOME-CONTENT-PANEL
// USES: LIB-ADMIN-API, LIB-CONTRACT, LIB-HERO-SLIDES, REORDERABLE, STORE-URL
// API: none
// MAP: docs/PROJECT_MAP.md

/**
 * محرّر الهيرو المتحرّك — «شرائح الهيرو» و«إعدادات حركة الهيرو».
 * (docs/architecture/HERO-SLIDER-CONTRACT-2026-09-25.md §3)
 *
 * ─── الشكل من الخادم ───
 *
 * `schema['home.hero_slides'].slide` يحمل الأنواع وحقول كلٍّ منها والتسميات
 * والحدود والمفردات المغلقة؛ فلا نسخة هنا من قائمةٍ يملكها الكاتب. وما يبقى
 * في هذا الملفّ هو كيف يُرسم كلّ **نوع مدخل** (نصّ، زرّ، صورة، عرض، بحث).
 *
 * ─── والقيم المغلقة لا تُكتب نصًّا ───
 *
 * الموضوع عيّناتٌ لونيّة، والمحاذاة والحجاب والبؤرة أزرارُ اختيارٍ متجاورة —
 * مجموعات راديو أصليّة، فالأسهم تتنقّل بينها بلوحة المفاتيح بلا شيفرة.
 *
 * ─── والترتيب بالسحب وبالأزرار معًا ───
 *
 * السحب بـ`useReorder` (أحداث المؤشّر، كما في شريط التبويبات)، و«أعلى/أسفل»
 * بديلُه للوحة المفاتيح وقارئ الشاشة — لا يُحتكر الترتيب لمن يملك فأرة.
 */

import { useCallback, useMemo, useState, type ReactNode } from 'react';
import { api } from '../../../lib/admin-api';
import { proxyPath } from '../../../lib/contract';
import {
  THEME_SWATCHES,
  duplicateSlide,
  emptySlide,
  moveItem,
  reorderByKeys,
  slideIssues,
  slidesFromStored,
  slidesToPayload,
  type SlideDraft,
  type SlideLinkDraft,
} from '../../../lib/hero-slides';
import { useReorder } from '../../../components/ui/reorderable';
import { useStoreUrl } from '../../../components/store-url';
import styles from './page.module.css';

/* ─────────────────────────── شكل المخطّط ─────────────────────────── */

interface Option {
  value: string;
  label: string;
}

interface SlideFieldSpec {
  label: string;
  type: 'text' | 'textarea' | 'link' | 'bool' | 'choice' | 'media' | 'offer' | 'search';
  max?: number;
  required?: boolean;
  options?: Option[];
  focal?: Option[];
}

interface SlideKindSpec extends Option {
  description: string;
  fields: string[];
}

export interface SlidesSpec {
  kind: 'slides';
  max: number;
  chipsMax: number;
  limits: Record<string, number>;
  slide: { kinds: SlideKindSpec[]; fields: Record<string, SlideFieldSpec> };
}

interface SettingSpec {
  label: string;
  type: 'bool' | 'int' | 'choice';
  min?: number;
  max?: number;
  step?: number;
  options?: Option[];
}

export type SliderSettingsSpec = Record<string, SettingSpec>;

type Outcome = { tone: 'ok' | 'error'; text: string } | null;

/** حفظ كتلة واحدة — الرسالة من الخادم حرفيًّا عند الفشل. */
function useBlockSave(blockKey: string) {
  const [busy, setBusy] = useState(false);
  const [outcome, setOutcome] = useState<Outcome>(null);

  const save = useCallback(
    async (payload: unknown): Promise<{ stored: unknown } | null> => {
      setBusy(true);
      setOutcome(null);
      const result = await api.put<{ data: { value: unknown } }>(
        proxyPath('adminHomeContentUpdate', { block: blockKey.replace(/^home\./, '') }),
        { payload },
      );
      setBusy(false);
      if (!result.ok) {
        setOutcome({ tone: 'error', text: `تعذّر الحفظ: ${result.message}` });
        return null;
      }
      return { stored: result.data.data.value };
    },
    [blockKey],
  );

  return { busy, outcome, setOutcome, save };
}

function PreviewLink() {
  const storeUrl = useStoreUrl();
  /* بلا عنوان متجر لا رابط: رابطٌ لا يُفتح أسوأ من غيابه. */
  if (storeUrl === null) return null;
  return (
    <a className={styles.preview} href={`${storeUrl}/`} target="_blank" rel="noreferrer">
      عاين في المتجر <span aria-hidden="true">↗</span>
      <span className={styles.srOnly}> (يفتح في نافذة جديدة)</span>
    </a>
  );
}

/* ───────────────────────────── الشرائح ───────────────────────────── */

export function HeroSlidesEditor({
  blockKey,
  spec,
  value,
  title,
}: {
  blockKey: string;
  spec: SlidesSpec;
  value: unknown;
  title: string;
}) {
  const [slides, setSlides] = useState<SlideDraft[]>(() => slidesFromStored(value));
  const [open, setOpen] = useState<string | null>(null);
  const [removed, setRemoved] = useState<{ slide: SlideDraft; index: number } | null>(null);
  const { busy, outcome, setOutcome, save } = useBlockSave(blockKey);

  const kinds = spec.slide.kinds;
  const kindOf = useCallback((kind: string) => kinds.find((k) => k.value === kind) ?? kinds[0], [kinds]);

  const issues = useMemo(
    () =>
      Object.fromEntries(
        slides.map((slide) => [
          slide.key,
          slideIssues(slide, kindOf(slide.kind)?.fields ?? [], spec.limits, spec.chipsMax),
        ]),
      ),
    [slides, kindOf, spec.limits, spec.chipsMax],
  );
  const invalid = slides.filter((slide) => Object.keys(issues[slide.key] ?? {}).length > 0).length;

  const edit = (next: SlideDraft[]) => {
    setSlides(next);
    setOutcome(null);
  };
  const update = (key: string, patch: Partial<SlideDraft>) =>
    edit(slides.map((slide) => (slide.key === key ? { ...slide, ...patch } : slide)));

  const reorder = useReorder({
    keys: slides.map((slide) => slide.key),
    onReorder: (keys) => edit(reorderByKeys(slides, keys)),
    attribute: 'data-slide-key',
  });

  const add = (kind: string) => {
    const slide = emptySlide(kind);
    edit([...slides, slide]);
    setOpen(slide.key);
  };

  const remove = (index: number) => {
    const slide = slides[index];
    if (slide === undefined) return;
    setRemoved({ slide, index });
    edit(slides.filter((_, at) => at !== index));
  };

  const undo = () => {
    if (removed === null) return;
    const next = [...slides];
    next.splice(Math.min(removed.index, next.length), 0, removed.slide);
    setRemoved(null);
    edit(next);
  };

  const submit = async () => {
    if (invalid > 0) return;
    const result = await save(slidesToPayload(slides));
    if (result === null) return;
    setSlides(slidesFromStored(result.stored));
    setRemoved(null);
    setOpen(null);
    setOutcome({
      tone: 'ok',
      text:
        result.stored === null
          ? 'حُفظ بلا شرائح — يعرض المتجر شرائحه الافتراضيّة الثلاث.'
          : 'حُفظت الشرائح. والمعروض أعلاه هو ما سيقرؤه المتجر.',
    });
  };

  const full = slides.length >= spec.max;

  return (
    <section className={styles.block} aria-labelledby={`b-${blockKey}`}>
      <div className={styles.blockHead}>
        <h2 className={styles.blockTitle} id={`b-${blockKey}`}>
          {title}
        </h2>
        <code className={styles.blockKey}>{blockKey}</code>
        <PreviewLink />
      </div>
      <p className={styles.hint}>
        حتّى {spec.max} شرائح تدور بالترتيب الذي تضعه هنا. المعطَّلة تُحفظ ولا تظهر. وبلا شرائح
        يعرض المتجر شرائحه الافتراضيّة الثلاث من حقول «الهيرو».
      </p>

      {slides.length === 0 ? <p className={styles.empty}>لا شرائح بعد — أضف أوّلها أدناه.</p> : null}

      <ol className={styles.slides}>
        {slides.map((slide, index) => {
          const kind = kindOf(slide.kind);
          const slideIssuesFor = issues[slide.key] ?? {};
          const expanded = open === slide.key;
          const name = slide.title.trim() || 'شريحة بلا عنوان';
          const panelId = `slide-${slide.key}`;
          return (
            <li
              key={slide.key}
              data-slide-key={slide.key}
              className={[
                styles.slide,
                reorder.dragging === slide.key ? styles.slideDragging : '',
                reorder.over === slide.key && reorder.dragging !== slide.key ? styles.slideOver : '',
                slide.enabled ? '' : styles.slideOff,
              ].join(' ')}
            >
              <div className={styles.slideHead}>
                <button
                  type="button"
                  className={styles.handle}
                  aria-label={`حرّك «${name}» — اسحب، أو استعمل السهمين أعلى وأسفل`}
                  {...reorder.handleProps(slide.key)}
                  onKeyDown={(event) => {
                    /* بديلُ السحب للوحة المفاتيح: السهمان ينقلان الشريحة خطوةً. */
                    const step = event.key === 'ArrowUp' ? -1 : event.key === 'ArrowDown' ? 1 : 0;
                    if (step === 0) return;
                    event.preventDefault();
                    edit(moveItem(slides, index, index + step));
                  }}
                >
                  <span aria-hidden="true">⋮⋮</span>
                </button>
                <span
                  className={styles.swatch}
                  aria-hidden="true"
                  style={swatchStyle(slide.theme)}
                />
                <span className={styles.slideName}>
                  <span className={styles.slideIndex}>{index + 1}.</span> {name}
                  <span className={styles.kindTag}>{kind?.label ?? slide.kind}</span>
                  {Object.keys(slideIssuesFor).length > 0 ? (
                    <span className={styles.issueTag}>تحتاج إصلاحًا</span>
                  ) : null}
                </span>
                <span className={styles.orderControls}>
                  <label className={styles.switch}>
                    <input
                      type="checkbox"
                      role="switch"
                      checked={slide.enabled}
                      onChange={(event) => update(slide.key, { enabled: event.target.checked })}
                    />
                    <span>{slide.enabled ? 'مُفعَّلة' : 'معطَّلة'}</span>
                  </label>
                  <button
                    type="button"
                    className={styles.move}
                    disabled={index === 0}
                    aria-label={`انقل «${name}» لأعلى`}
                    onClick={() => edit(moveItem(slides, index, index - 1))}
                  >
                    ↑
                  </button>
                  <button
                    type="button"
                    className={styles.move}
                    disabled={index === slides.length - 1}
                    aria-label={`انقل «${name}» لأسفل`}
                    onClick={() => edit(moveItem(slides, index, index + 1))}
                  >
                    ↓
                  </button>
                  <button
                    type="button"
                    className={styles.add}
                    aria-expanded={expanded}
                    aria-controls={panelId}
                    onClick={() => setOpen(expanded ? null : slide.key)}
                  >
                    {expanded ? 'أغلق' : 'حرّر'}
                  </button>
                </span>
              </div>

              {expanded && kind !== undefined ? (
                <div id={panelId} className={styles.slideBody}>
                  <SlideFields
                    slide={slide}
                    kind={kind}
                    spec={spec}
                    issues={slideIssuesFor}
                    onChange={(patch) => update(slide.key, patch)}
                  />
                  <div className={styles.slideActions}>
                    <button
                      type="button"
                      className={styles.add}
                      disabled={full}
                      onClick={() => {
                        const copy = duplicateSlide(slide);
                        edit([...slides.slice(0, index + 1), copy, ...slides.slice(index + 1)]);
                        setOpen(copy.key);
                      }}
                    >
                      كرّر الشريحة
                    </button>
                    <button type="button" className={styles.remove} onClick={() => remove(index)}>
                      احذف الشريحة
                    </button>
                  </div>
                </div>
              ) : null}
            </li>
          );
        })}
      </ol>

      {removed !== null ? (
        <div role="status" className={styles.toast}>
          <span>حُذفت «{removed.slide.title.trim() || 'شريحة بلا عنوان'}» — لن تُحذف فعلًا قبل الحفظ.</span>
          <button type="button" className={styles.add} onClick={undo}>
            تراجع
          </button>
        </div>
      ) : null}

      <fieldset className={styles.row} disabled={full}>
        <legend className={styles.rowLegend}>
          {full ? `بلغت الحدّ (${spec.max} شرائح)` : 'أضف شريحة — اختر نوعها'}
        </legend>
        <div className={styles.kindPicker}>
          {kinds.map((kind) => (
            <button key={kind.value} type="button" className={styles.kindOption} onClick={() => add(kind.value)}>
              <span className={styles.kindLabel}>{kind.label}</span>
              <span className={styles.kindDescription}>{kind.description}</span>
            </button>
          ))}
        </div>
      </fieldset>

      <div className={styles.actions}>
        <button type="button" className={styles.save} onClick={() => void submit()} disabled={busy || invalid > 0}>
          {busy ? 'جارٍ الحفظ…' : 'احفظ الشرائح'}
        </button>
        {invalid > 0 ? (
          <p className={styles.error} role="status">
            {invalid === 1 ? 'شريحة واحدة تحتاج إصلاحًا' : `${invalid} شرائح تحتاج إصلاحًا`} قبل الحفظ.
          </p>
        ) : null}
        {outcome ? (
          <p role="status" className={outcome.tone === 'ok' ? styles.ok : styles.error}>
            {outcome.text}
          </p>
        ) : null}
      </div>
    </section>
  );
}

function swatchStyle(theme: string) {
  return { background: THEME_SWATCHES[theme] ?? THEME_SWATCHES['navy-teal'] };
}

function SlideFields({
  slide,
  kind,
  spec,
  issues,
  onChange,
}: {
  slide: SlideDraft;
  kind: SlideKindSpec;
  spec: SlidesSpec;
  issues: Record<string, string>;
  onChange: (patch: Partial<SlideDraft>) => void;
}) {
  const id = (name: string) => `s-${slide.key}-${name}`;

  const render = (name: string): ReactNode => {
    const field = spec.slide.fields[name];
    if (field === undefined) return null;
    const error = issues[name];

    switch (field.type) {
      case 'text':
      case 'textarea': {
        const key = name as 'eyebrow' | 'title' | 'titleAccent' | 'subtitle';
        return (
          <CountedField
            key={name}
            id={id(name)}
            label={field.label}
            value={slide[key]}
            max={field.max}
            required={field.required === true}
            multiline={field.type === 'textarea'}
            error={error}
            onChange={(next) => onChange({ [key]: next })}
          />
        );
      }
      case 'link': {
        const key = name as 'primary' | 'secondary';
        return (
          <LinkField
            key={name}
            id={id(name)}
            legend={field.label}
            value={slide[key]}
            error={error}
            onChange={(next) => onChange({ [key]: next })}
          />
        );
      }
      case 'bool':
        return (
          <label key={name} className={styles.switch}>
            <input
              type="checkbox"
              role="switch"
              checked={slide.showProductCard}
              onChange={(event) => onChange({ showProductCard: event.target.checked })}
            />
            <span>{field.label}</span>
          </label>
        );
      case 'choice': {
        const key = name as 'theme' | 'align' | 'overlay';
        return name === 'theme' ? (
          <ThemePicker
            key={name}
            name={id(name)}
            legend={field.label}
            value={slide.theme}
            options={field.options ?? []}
            onChange={(next) => onChange({ theme: next })}
          />
        ) : (
          <Segmented
            key={name}
            name={id(name)}
            legend={field.label}
            value={slide[key]}
            options={field.options ?? []}
            onChange={(next) => onChange({ [key]: next })}
          />
        );
      }
      case 'media':
        return (
          <fieldset key={name} className={styles.row}>
            <legend className={styles.rowLegend}>{field.label}</legend>
            <div className={styles.grid}>
              <PlainField
                id={id('media-url')}
                label="رابط الصورة"
                value={slide.mediaUrl}
                ltr
                hint="من مكتبة المتجر (/storage/…) أو رابط يبدأ بـ https://"
                error={error}
                onChange={(next) => onChange({ mediaUrl: next })}
              />
              <PlainField
                id={id('media-alt')}
                label="وصف الصورة (لقارئ الشاشة)"
                value={slide.mediaAlt}
                maxLength={spec.limits.alt}
                hint="اتركه فارغًا إن كانت الصورة زخرفيّة."
                onChange={(next) => onChange({ mediaAlt: next })}
              />
            </div>
            <Segmented
              name={id('focal')}
              legend="بؤرة الصورة"
              value={slide.mediaFocal}
              options={field.focal ?? []}
              onChange={(next) => onChange({ mediaFocal: next })}
            />
          </fieldset>
        );
      case 'offer':
        return (
          <fieldset key={name} className={styles.row}>
            <legend className={styles.rowLegend}>{field.label}</legend>
            <div className={styles.grid}>
              <PlainField
                id={id('offer-badge')}
                label="الشارة"
                value={slide.offerBadge}
                maxLength={spec.limits.badge}
                onChange={(next) => onChange({ offerBadge: next })}
              />
              <div className={styles.field}>
                <label className={styles.label} htmlFor={id('offer-ends')}>
                  ينتهي العرض في
                </label>
                <input
                  id={id('offer-ends')}
                  className={styles.input}
                  type="datetime-local"
                  value={slide.offerEndsAt}
                  aria-invalid={error !== undefined}
                  aria-describedby={id('offer-ends-hint')}
                  onChange={(event) => onChange({ offerEndsAt: event.target.value })}
                />
                <p className={error ? styles.fieldError : styles.fieldHint} id={id('offer-ends-hint')}>
                  {error ?? 'بعد الموعد يختفي العدّاد وتبقى الشريحة.'}
                </p>
              </div>
              <PlainField
                id={id('offer-coupon')}
                label="رمز القسيمة"
                value={slide.offerCoupon}
                ltr
                maxLength={spec.limits.couponCode}
                onChange={(next) => onChange({ offerCoupon: next })}
              />
            </div>
          </fieldset>
        );
      case 'search':
        return (
          <fieldset key={name} className={styles.row}>
            <legend className={styles.rowLegend}>{field.label}</legend>
            <PlainField
              id={id('search-placeholder')}
              label="النصّ داخل مربّع البحث"
              value={slide.searchPlaceholder}
              maxLength={spec.limits.placeholder}
              onChange={(next) => onChange({ searchPlaceholder: next })}
            />
            {slide.chips.map((chip, at) => (
              <div className={styles.listRow} key={`${slide.key}-chip-${at}`}>
                <LinkField
                  id={id(`chip-${at}`)}
                  legend={`الاختصار ${at + 1}`}
                  value={chip}
                  onChange={(next) =>
                    onChange({ chips: slide.chips.map((old, i) => (i === at ? next : old)) })
                  }
                />
                <button
                  type="button"
                  className={styles.remove}
                  onClick={() => onChange({ chips: slide.chips.filter((_, i) => i !== at) })}
                >
                  احذف الاختصار {at + 1}
                </button>
              </div>
            ))}
            {error ? <p className={styles.fieldError}>{error}</p> : null}
            <button
              type="button"
              className={styles.add}
              disabled={slide.chips.length >= (field.max ?? spec.chipsMax)}
              onClick={() => onChange({ chips: [...slide.chips, { label: '', href: '' }] })}
            >
              أضف اختصارًا ({slide.chips.length}/{field.max ?? spec.chipsMax})
            </button>
          </fieldset>
        );
      default:
        return null;
    }
  };

  return <div className={styles.rows}>{kind.fields.map(render)}</div>;
}

/* ─────────────────────────── إعدادات الحركة ─────────────────────────── */

export function SliderSettingsEditor({
  blockKey,
  settings,
  value,
  title,
}: {
  blockKey: string;
  settings: SliderSettingsSpec;
  value: unknown;
  title: string;
}) {
  const initial = useCallback(
    (stored: unknown): Record<string, boolean | number | string> => {
      const object = (stored ?? {}) as Record<string, unknown>;
      /* الافتراضات مرآةُ العقد؛ والمخزَّن يغلبها حين يكون من النوع الصحيح. */
      const defaults: Record<string, boolean | number | string> = {
        autoplay: true,
        intervalMs: 6000,
        transition: 'slide',
        continuous: true,
        pauseOnHover: false,
        showArrows: true,
        showDots: true,
        showProgress: true,
        height: 'standard',
        ambient: 'drift',
      };
      return Object.fromEntries(
        Object.entries(settings).map(([name, spec]) => {
          const raw = object[name];
          const fallback = defaults[name] ?? (spec.type === 'bool' ? false : '');
          const ok =
            (spec.type === 'bool' && typeof raw === 'boolean') ||
            (spec.type === 'int' && typeof raw === 'number') ||
            (spec.type === 'choice' && typeof raw === 'string');
          return [name, ok ? (raw as boolean | number | string) : fallback];
        }),
      );
    },
    [settings],
  );

  const [draft, setDraft] = useState(() => initial(value));
  const { busy, outcome, setOutcome, save } = useBlockSave(blockKey);

  const set = (name: string, next: boolean | number | string) => {
    setDraft({ ...draft, [name]: next });
    setOutcome(null);
  };

  const submit = async () => {
    const result = await save(draft);
    if (result === null) return;
    setDraft(initial(result.stored));
    setOutcome({ tone: 'ok', text: 'حُفظت إعدادات الحركة.' });
  };

  const entries = Object.entries(settings);
  const bools = entries.filter(([, spec]) => spec.type === 'bool');
  const others = entries.filter(([, spec]) => spec.type !== 'bool');

  return (
    <section className={styles.block} aria-labelledby={`b-${blockKey}`}>
      <div className={styles.blockHead}>
        <h2 className={styles.blockTitle} id={`b-${blockKey}`}>
          {title}
        </h2>
        <code className={styles.blockKey}>{blockKey}</code>
        <PreviewLink />
      </div>
      <p className={styles.hint}>
        الدوران يتوقّف دائمًا لمن فعّل «تقليل الحركة» في جهازه، ويظهر للزائر زرّ إيقافٍ ما دام التشغيل
        التلقائيّ مفعّلًا.
      </p>

      <div className={styles.switches}>
        {bools.map(([name, spec]) => (
          <label key={name} className={styles.switch}>
            <input
              type="checkbox"
              role="switch"
              checked={draft[name] === true}
              onChange={(event) => set(name, event.target.checked)}
            />
            <span>{spec.label}</span>
          </label>
        ))}
      </div>

      {others.map(([name, spec]) =>
        spec.type === 'int' ? (
          <IntervalField
            key={name}
            id={`f-${blockKey}-${name}`}
            label={spec.label}
            value={typeof draft[name] === 'number' ? (draft[name] as number) : 6000}
            min={spec.min ?? 3000}
            max={spec.max ?? 15000}
            step={spec.step ?? 500}
            disabled={draft.autoplay === false}
            onChange={(next) => set(name, next)}
          />
        ) : (
          <Segmented
            key={name}
            name={`f-${blockKey}-${name}`}
            legend={spec.label}
            value={String(draft[name] ?? '')}
            options={spec.options ?? []}
            onChange={(next) => set(name, next)}
          />
        ),
      )}

      <div className={styles.actions}>
        <button type="button" className={styles.save} onClick={() => void submit()} disabled={busy}>
          {busy ? 'جارٍ الحفظ…' : 'احفظ الإعدادات'}
        </button>
        {outcome ? (
          <p role="status" className={outcome.tone === 'ok' ? styles.ok : styles.error}>
            {outcome.text}
          </p>
        ) : null}
      </div>
    </section>
  );
}

/* ─────────────────────────── مدخلات صغيرة ─────────────────────────── */

function IntervalField({
  id,
  label,
  value,
  min,
  max,
  step,
  disabled,
  onChange,
}: {
  id: string;
  label: string;
  value: number;
  min: number;
  max: number;
  step: number;
  disabled: boolean;
  onChange: (next: number) => void;
}) {
  const seconds = (value / 1000).toLocaleString('ar', { maximumFractionDigits: 1 });
  const clamp = (n: number) => Math.min(max, Math.max(min, Math.round(n)));
  return (
    <div className={styles.field}>
      <label className={styles.label} htmlFor={id}>
        {label}: <output htmlFor={id}>{seconds} ثانية</output>
      </label>
      <div className={styles.interval}>
        <input
          id={id}
          type="range"
          className={styles.range}
          min={min}
          max={max}
          step={step}
          value={value}
          disabled={disabled}
          aria-valuetext={`${seconds} ثانية`}
          onChange={(event) => onChange(clamp(Number(event.target.value)))}
        />
        <input
          type="number"
          className={`${styles.input} ${styles.number}`}
          aria-label={`${label} بالثواني`}
          min={min / 1000}
          max={max / 1000}
          step={step / 1000}
          value={value / 1000}
          disabled={disabled}
          dir="ltr"
          onChange={(event) => {
            const next = Number(event.target.value);
            if (Number.isFinite(next) && event.target.value !== '') onChange(clamp(next * 1000));
          }}
        />
      </div>
      <p className={styles.fieldHint}>
        من {min / 1000} إلى {max / 1000} ثانية.{disabled ? ' يعمل حين يُفعَّل التشغيل التلقائيّ.' : ''}
      </p>
    </div>
  );
}

function Segmented({
  name,
  legend,
  value,
  options,
  onChange,
}: {
  name: string;
  legend: string;
  value: string;
  options: readonly Option[];
  onChange: (next: string) => void;
}) {
  return (
    <fieldset className={styles.segmentedGroup}>
      <legend className={styles.label}>{legend}</legend>
      <div className={styles.segmented}>
        {options.map((option) => (
          <label key={option.value} className={styles.segment}>
            <input
              type="radio"
              name={name}
              value={option.value}
              checked={value === option.value}
              onChange={() => onChange(option.value)}
            />
            <span>{option.label}</span>
          </label>
        ))}
      </div>
    </fieldset>
  );
}

function ThemePicker({
  name,
  legend,
  value,
  options,
  onChange,
}: {
  name: string;
  legend: string;
  value: string;
  options: readonly Option[];
  onChange: (next: string) => void;
}) {
  return (
    <fieldset className={styles.segmentedGroup}>
      <legend className={styles.label}>{legend}</legend>
      <div className={styles.themes}>
        {options.map((option) => (
          <label key={option.value} className={styles.theme}>
            <input
              type="radio"
              name={name}
              value={option.value}
              checked={value === option.value}
              onChange={() => onChange(option.value)}
            />
            <span className={styles.themeSwatch} aria-hidden="true" style={swatchStyle(option.value)} />
            <span className={styles.themeLabel}>{option.label}</span>
          </label>
        ))}
      </div>
    </fieldset>
  );
}

function LinkField({
  id,
  legend,
  value,
  error,
  onChange,
}: {
  id: string;
  legend: string;
  value: SlideLinkDraft;
  error?: string | undefined;
  onChange: (next: SlideLinkDraft) => void;
}) {
  return (
    <fieldset className={styles.linkGroup}>
      <legend className={styles.label}>{legend}</legend>
      <div className={styles.grid}>
        <PlainField
          id={`${id}-label`}
          label="نصّ الزرّ"
          value={value.label}
          maxLength={40}
          onChange={(label) => onChange({ ...value, label })}
        />
        <PlainField
          id={`${id}-href`}
          label="الوجهة"
          value={value.href}
          ltr
          hint="تبدأ بـ / (صفحة في المتجر) أو https://"
          error={error}
          onChange={(href) => onChange({ ...value, href })}
        />
      </div>
    </fieldset>
  );
}

function PlainField({
  id,
  label,
  value,
  onChange,
  hint,
  error,
  ltr = false,
  maxLength,
}: {
  id: string;
  label: string;
  value: string;
  onChange: (next: string) => void;
  hint?: string;
  error?: string | undefined;
  ltr?: boolean;
  maxLength?: number | undefined;
}) {
  const note = error ?? hint;
  return (
    <div className={styles.field}>
      <label className={styles.label} htmlFor={id}>
        {label}
      </label>
      <input
        id={id}
        className={styles.input}
        type="text"
        value={value}
        {...(maxLength !== undefined ? { maxLength } : {})}
        {...(ltr ? { dir: 'ltr' as const } : {})}
        aria-invalid={error !== undefined}
        {...(note !== undefined ? { 'aria-describedby': `${id}-note` } : {})}
        onChange={(event) => onChange(event.target.value)}
      />
      {note !== undefined ? (
        <p id={`${id}-note`} className={error !== undefined ? styles.fieldError : styles.fieldHint}>
          {note}
        </p>
      ) : null}
    </div>
  );
}

function CountedField({
  id,
  label,
  value,
  max,
  required,
  multiline,
  error,
  onChange,
}: {
  id: string;
  label: string;
  value: string;
  max: number | undefined;
  required: boolean;
  multiline: boolean;
  error: string | undefined;
  onChange: (next: string) => void;
}) {
  const common = {
    id,
    className: styles.input,
    value,
    ...(max !== undefined ? { maxLength: max } : {}),
    'aria-invalid': error !== undefined,
    'aria-describedby': `${id}-count${error !== undefined ? ` ${id}-error` : ''}`,
    ...(required ? { 'aria-required': true } : {}),
  };
  return (
    <div className={styles.field}>
      <label className={styles.label} htmlFor={id}>
        {label}
        {required ? <span className={styles.required}> (إلزاميّ)</span> : null}
      </label>
      {multiline ? (
        <textarea {...common} rows={3} onChange={(event) => onChange(event.target.value)} />
      ) : (
        <input {...common} type="text" onChange={(event) => onChange(event.target.value)} />
      )}
      <div className={styles.fieldFoot}>
        {error !== undefined ? (
          <p id={`${id}-error`} className={styles.fieldError}>
            {error}
          </p>
        ) : (
          <span />
        )}
        {max !== undefined ? (
          <span id={`${id}-count`} className={styles.counter}>
            {value.length}/{max}
          </span>
        ) : (
          <span id={`${id}-count`} />
        )}
      </div>
    </div>
  );
}
