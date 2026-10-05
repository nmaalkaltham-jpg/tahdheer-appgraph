'use client';

/**
 * صندوق بحث الترويسة مع إكمال تلقائي حيّ.
 *
 * كانت نقطة ‎/search/suggest‎ قائمةً على الخادم ولا أحد يناديها من الواجهة —
 * قدرة مبنية وغير موصولة. هذا هو المستهلك.
 *
 * ثلاثة سلوكيات ليست تحسينًا بل صحّة:
 *  · **debounce ٢٥٠مل.** طلبٌ لكل حرف يضرب حدّ المعدّل ويغرق المحرّك بلا فائدة.
 *  · **AbortController.** بدونه استجابة «علو» البطيئة تصل بعد استجابة «علوم»
 *    السريعة فتستبدل الاقتراحات الصحيحة بأقدم منها — سباقٌ يظهر عشوائيًّا.
 *  · **النموذج يبقى نموذجًا.** Enter يُرسل GET ‎/search?q=‎ الحقيقي، وبلا
 *    JavaScript يعمل البحث كاملًا كما كان.
 *
 * ARIA: combobox/listbox بحسب نمط WAI-ARIA — الأسهم تنقل التفعيل عبر
 * aria-activedescendant والتركيز يبقى في الحقل، وEscape يغلق، والحالة تُعلن
 * لقارئ الشاشة عبر عدّاد نتائج حيّ.
 *
 * ─── وطَورُ الطلب صار مرئيًّا ───
 *
 * كان الصندوق يعرض حالةً واحدة: قائمةً فيها اقتراحات. وما سواها فراغٌ صامت —
 * الانتظار فراغ، و«لا اقتراح لهذه الكلمة» فراغ، وانقطاع الشبكة فراغ. فالكاتب
 * لا يعرف أوقعت كتابته أم لا، فيعيد الكتابة أو ينتظر ما لن يأتي.
 *
 * الآن `phase` يسمّي الطور، ولكلّ طورٍ سطره. وهذه السطور **خارج** الـ
 * `listbox`: عنصرٌ ليس `option` داخل قائمةٍ يكسر النمط عند قارئ الشاشة.
 *
 * والعقد لم يُمسّ: `fetchSuggest` نفسها، والحدّان نفسهما، وEnter يُرسل
 * النموذج كما كان.
 */

import { useCallback, useEffect, useId, useRef, useState, useSyncExternalStore } from 'react';
import { formatMoney } from '@tahdheer/design-system/format';
import { fetchSuggest, sendSuggestSelect, type SuggestItem } from '../../lib/search-api';
import styles from './SearchBox.module.css';

const DEBOUNCE_MS = 250;

/** حدّ الخادم: أقلّ من حرفين لا اقتراح — لا نطلب ما سيُرفض. */
const MIN_CHARS = 2;

/**
 * طَور الاقتراحات.
 *
 * `idle` يشمل «لم يُطلب بعد» و«الخادم غير مهيّأ»: كلاهما لا يُعرض فيه شيء،
 * لأنّ الاقتراح كماليّ ولا يُعلَن عن غيابه بضجّة.
 */
type Phase = 'idle' | 'loading' | 'ready' | 'error';

/** The mobile app bar's shorter prompt (Storefront Home Mobile.dc.html) — same breakpoint as the header. */
const COMPACT_QUERY = '(max-width: 767px)';

function subscribeCompact(onChange: () => void) {
  const media = window.matchMedia(COMPACT_QUERY);
  media.addEventListener('change', onChange);
  return () => media.removeEventListener('change', onChange);
}

export interface SearchBoxProps {
  /** Desktop prompt (Storefront Home/Product.dc.html header). */
  placeholder?: string;
  /** Prompt under the mobile breakpoint (767px). */
  compactPlaceholder?: string;
}

export function SearchBox({
  placeholder = 'ابحث عن مادة، مرحلة، أو نوع ملف…',
  compactPlaceholder = 'ابحث عن مادة أو مرحلة…',
}: SearchBoxProps = {}) {
  const compact = useSyncExternalStore(
    subscribeCompact,
    () => window.matchMedia(COMPACT_QUERY).matches,
    () => false,
  );
  const [value, setValue] = useState('');
  const [items, setItems] = useState<SuggestItem[]>([]);
  const [open, setOpen] = useState(false);
  const [active, setActive] = useState(-1);
  const [phase, setPhase] = useState<Phase>('idle');

  const listId = useId();
  const abortRef = useRef<AbortController | null>(null);
  const timerRef = useRef<ReturnType<typeof setTimeout> | null>(null);
  const rootRef = useRef<HTMLDivElement>(null);
  const inputRef = useRef<HTMLInputElement>(null);

  const close = useCallback(() => {
    /*
     * الإغلاق يُلغي ما في الطريق أيضًا: مؤقّت مسلَّح أو طلب جارٍ كانا
     * يكملان بعد Escape أو النقر خارجًا ثم يعيدان فتح القائمة من تلقاء
     * نفسها — رفضُ المستخدم الصريح يُحترم حتى نهايته.
     */
    if (timerRef.current) clearTimeout(timerRef.current);
    abortRef.current?.abort();
    setOpen(false);
    setActive(-1);
    /*
     * الطور المعلَّق يُصفَّر مع الإغلاق: لولا ذلك لبقي «يبحث…» أو «تعذّر»
     * محفوظًا، فيعود ظاهرًا عند أوّل تركيزٍ لاحق ويصف طلبًا أُلغي.
     */
    setPhase((current) => (current === 'ready' ? current : 'idle'));
  }, []);

  // التفكيك: لا مؤقّت يطلق جلبًا على مكوّن مفكَّك ولا طلب معلّق بلا قارئ.
  useEffect(
    () => () => {
      if (timerRef.current) clearTimeout(timerRef.current);
      abortRef.current?.abort();
    },
    [],
  );

  /*
   * اختصار «/» يركّز حقل البحث — عرفُ المتاجر ومحرّكات البحث.
   * لا يعمل أثناء الكتابة (حقل، منطقة نصّ، قائمة، محرّر)، ولا مع Ctrl/⌘/Alt،
   * ولا خلف حوارٍ مشروط (درج الجوّال، المعاينة السريعة)، ولا حين الحقل غير
   * مرئيّ (ترويسة صفحة المنتج على الجوّال مخفيّة). و`code === 'Slash'` مع
   * `key` معًا: المفتاح نفسه يكتب «ظ» في التخطيط العربيّ.
   */
  useEffect(() => {
    function onKeyDown(event: KeyboardEvent) {
      if (event.defaultPrevented || event.isComposing || event.ctrlKey || event.metaKey || event.altKey) return;
      if (event.key !== '/' && !(event.code === 'Slash' && !event.shiftKey)) return;
      const target = event.target instanceof HTMLElement ? event.target : null;
      if (target?.isContentEditable || target?.closest('input, textarea, select, [contenteditable]:not([contenteditable="false"])')) return;
      const modal = [...document.querySelectorAll<HTMLElement>('dialog[open], [aria-modal="true"]')].some((el) => el.checkVisibility());
      if (modal) return;
      const input = inputRef.current;
      if (!input || !input.checkVisibility()) return;
      event.preventDefault();
      input.focus();
      input.select();
    }
    document.addEventListener('keydown', onKeyDown);
    return () => document.removeEventListener('keydown', onKeyDown);
  }, []);

  // نقرة خارج الصندوق تغلقه — بلا حبس تركيز.
  useEffect(() => {
    function onPointerDown(event: PointerEvent) {
      if (rootRef.current && !rootRef.current.contains(event.target as Node)) close();
    }
    document.addEventListener('pointerdown', onPointerDown);
    return () => document.removeEventListener('pointerdown', onPointerDown);
  }, [close]);

  const fetchSuggestions = useCallback((q: string) => {
    abortRef.current?.abort();
    const controller = new AbortController();
    abortRef.current = controller;

    /*
     * النداء من `lib/search-api` لا بيدٍ هنا: كان هذا الموضع ينسخ مسار
     * النقطة ورأسها بينما `fetchSuggest` قائمةٌ بلا مستهلك — نسختان
     * تتباعدان عند أوّل تغيير في العقد.
     */
    void fetchSuggest(q, { signal: controller.signal })
      .then((res) => {
        if (controller.signal.aborted) return;
        /*
         * غير مهيّأ أو غير متاح: ينصرف الصندوق صامتًا ولا يعلن عطلًا —
         * الاقتراح كماليّ، والنموذج الأساسي يعمل بدونه.
         */
        if (res.data === null) {
          setItems([]);
          setPhase('idle');
          setOpen(false);
          return;
        }
        const next = res.data.items;
        setItems(next);
        setPhase('ready');
        setOpen(true);
        setActive(-1);
      })
      .catch(() => {
        /*
         * الإجهاض ليس عطلًا: هو قرارُنا نحن حين وصل حرفٌ جديد أو أُغلق
         * الصندوق. والعطل الحقيقيّ وحده يُكتب سطرًا.
         */
        if (controller.signal.aborted) return;
        setPhase('error');
        setOpen(true);
      });
  }, []);

  function onChange(next: string) {
    setValue(next);

    if (timerRef.current) clearTimeout(timerRef.current);
    if (next.trim().length < MIN_CHARS) {
      abortRef.current?.abort();
      setItems([]);
      close();
      return;
    }
    /*
     * الانتظار يبدأ مع الحرف لا مع الطلب: المهلة ‎250مل‎ زمنٌ يُحسّ، وسطر
     * «يبحث…» فيه هو الفرق بين «وصلت كتابتي» و«لا شيء يحدث».
     */
    setPhase('loading');
    setOpen(true);
    timerRef.current = setTimeout(() => fetchSuggestions(next.trim()), DEBOUNCE_MS);
  }

  /* القائمة وحدها تُبقى مفتوحة بالاقتراحات؛ وأثناء التحديث تبقى الأقدم مكانها بدل أن تومض. */
  const listOpen = open && items.length > 0 && (phase === 'ready' || phase === 'loading');
  /** السطر تحت القائمة — طَورٌ واحد لا أكثر، أو لا سطر. */
  const note: 'loading' | 'empty' | 'error' | null = !open
    ? null
    : phase === 'error'
      ? 'error'
      : items.length > 0
        ? null
        : phase === 'loading'
          ? 'loading'
          : phase === 'ready'
            ? 'empty'
            : null;
  const popOpen = listOpen || note !== null;

  function onKeyDown(event: React.KeyboardEvent<HTMLInputElement>) {
    if (!listOpen) {
      if (event.key === 'Escape') close();
      return;
    }

    switch (event.key) {
      case 'ArrowDown':
        event.preventDefault();
        setActive((current) => (current + 1) % items.length);
        break;
      case 'ArrowUp':
        event.preventDefault();
        setActive((current) => (current <= 0 ? items.length - 1 : current - 1));
        break;
      case 'Enter':
        // اختيار مفعّل يذهب لصفحة المنتج؛ وإلّا يُرسل النموذج بحثًا كاملًا.
        if (active >= 0 && items[active]) {
          event.preventDefault();
          if (items[active].id) sendSuggestSelect(value, items[active].id);
          window.location.assign(items[active].url);
        }
        break;
      case 'Escape':
        event.preventDefault();
        close();
        break;
    }
  }

  return (
    <div ref={rootRef} className={styles.root}>
      <input
        ref={inputRef}
        id="site-search"
        className={styles.input}
        aria-keyshortcuts="/"
        type="search"
        name="q"
        dir="auto"
        placeholder={compact ? compactPlaceholder : placeholder}
        autoComplete="off"
        role="combobox"
        aria-expanded={listOpen}
        aria-controls={listId}
        aria-autocomplete="list"
        aria-activedescendant={active >= 0 ? `${listId}-${active}` : undefined}
        value={value}
        onChange={(event) => onChange(event.target.value)}
        onKeyDown={onKeyDown}
        onFocus={() => {
          if (items.length > 0 && phase === 'ready') setOpen(true);
        }}
      />
      {/* تلميح الاختصار — للعين وحدها (قارئ الشاشة يسمع ‎aria-keyshortcuts‎)، ويختفي بالتركيز أو الكتابة. */}
      {value === '' ? (
        <kbd className={styles.slashHint} aria-hidden="true" title="اضغط / للبحث">
          /
        </kbd>
      ) : null}

      {/*
        القائمة موجودة دائمًا ومخفيّة حين تُغلق — لا تُنشأ عند الفتح.

        `aria-controls` على `combobox` تشير إلى المنبثقة، وكانت تشير إلى
        معرّفٍ لا وجود له في **كلّ** صفحةٍ من المتجر: القائمة لم تكن تُصيَّر
        إلّا مفتوحة. ومرجعٌ معلّق لا يقول لقارئ الشاشة شيئًا.

        و`hidden` هو الشكل الذي يصفه نمط APG: العنصر قائم، و`aria-expanded`
        يصف حاله. ولا قاعدة `display` على `.list` تُبطله.
      */}
      {/*
        لوحٌ واحد يحمل القائمة وسطرَ الطور معًا: صندوقان منفصلان يفتحان في
        الموضع نفسه كانا سيتراكبان حين يتبدّل الطور بين حرفٍ وحرف.
      */}
      <div
        className={styles.pop}
        data-busy={phase === 'loading' ? 'true' : 'false'}
        hidden={!popOpen}
      >
        <ul
          id={listId}
          role="listbox"
          aria-label="اقتراحات البحث"
          className={styles.list}
          hidden={!listOpen}
        >
          {listOpen
            ? items.map((item, index) => (
              <li
                key={item.url}
                id={`${listId}-${index}`}
                role="option"
                aria-selected={index === active}
                className={index === active ? `${styles.item} ${styles.itemActive}` : styles.item}
              >
                {/* رابط حقيقي: النقر الأوسط وفتح تبويب جديد يعملان كما يُتوقّع. */}
                <a
                  href={item.url}
                  tabIndex={-1}
                  onPointerDown={(event) => event.preventDefault()}
                  onClick={() => {
                    if (item.id) sendSuggestSelect(value, item.id);
                  }}
                >
                  <span className={styles.itemTitle}>{item.title}</span>
                  {typeof item.priceMinor === 'number' ? (
                    <span className={styles.itemPrice}>
                      <bdi dir="ltr">{formatMoney(item.priceMinor).amount}</bdi>{' '}
                      {formatMoney(item.priceMinor).currency}
                    </span>
                  ) : null}
                </a>
              </li>
              ))
            : null}
        </ul>

        {/*
          سطر الطور. الرسالة تصف الحال وتقول ما يبقى ممكنًا: زرّ «بحث» و
          Enter يعملان في الأطوار الثلاثة، والاقتراح وحده هو المتعذّر.
        */}
        {note !== null ? (
          <p className={styles.note} data-kind={note}>
            {note === 'loading'
              ? 'يبحث في ملفّات المتجر…'
              : note === 'empty'
                ? 'لا اقتراح بهذه الكلمات — اضغط Enter للبحث الكامل.'
                : 'تعذّر جلب الاقتراحات. اضغط Enter للبحث الكامل.'}
          </p>
        ) : null}

        {/* دليل المفاتيح مرئيّ لا مضمر — ومخفيّ عن قارئ الشاشة لأنّ العدّاد الحيّ يكفيه. */}
        {listOpen ? (
          <p className={styles.keys} aria-hidden="true">
            {phase === 'loading'
              ? 'يحدّث الاقتراحات…'
              : '↑ ↓ للتنقّل · Enter للفتح · Esc للإغلاق'}
          </p>
        ) : null}
      </div>

      {/* عدّاد حيّ لقارئ الشاشة — يتغيّر مع كل جلب لا مع كل حرف. */}
      <span className={styles.srOnly} aria-live="polite">
        {listOpen
          ? `${items.length} اقتراحات متاحة`
          : note === 'empty'
            ? 'لا اقتراحات'
            : note === 'error'
              ? 'تعذّر جلب الاقتراحات'
              : ''}
      </span>
    </div>
  );
}
