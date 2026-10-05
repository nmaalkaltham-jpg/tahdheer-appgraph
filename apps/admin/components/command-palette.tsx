'use client';
// UNIT: COMMAND-PALETTE
// FILE: command-palette.tsx
// STYLE: command-palette.module.css
// PARENT: ADMIN-SHELL
// USES: LIB-ADMIN-API
// API: /admin/customers, /admin/orders, /admin/products
// MAP: docs/PROJECT_MAP.md

/**
 * «مركز الاختصارات» (Ctrl K) — القيم الحرفية من Tahdheer - Unified Console.dc.html.
 *
 * اللوحة تفهرس سجلّ التنقّل الحقيقي نفسه الذي يرسم الشريط الجانبي (NAV_GROUPS
 * بعد ترشيح الصلاحيات) — لا قائمة مسارات منسوخة تتعفّن مع كل مساحة جديدة.
 *
 * ─── طبقتان: الوجهات ثمّ السجلّات ───
 *
 * الطبقة الأولى الوجهات (فورية، من السجلّ). والثانية — المضافة لاحقًا —
 * **السجلّات نفسها**: منتجٌ باسمه أو رمزه، وطلبٌ برقمه أو بريده، وعميلٌ باسمه.
 * وهي الميزة التي تملكها كل لوحة متجر مقارَنة (Medusa وSaleor وBagisto) وكانت
 * تنقص هنا: من يبحث عن الطلب `ORD-1042` كان يفتح «الطلبات» ثمّ يكتبه ثانيةً.
 *
 * والوجهات تسبق السجلّات لا العكس: الوجهة مطابقةٌ محلّية فورية، فلو تصدّرت
 * السجلّاتُ القائمةَ لتغيّر ما يفعله Enter بعد كل نداء شبكة — والاختصار الذي
 * يتحرّك تحت الإصبع يُهجَر.
 *
 * ما لم يُنقَل من شريط التصميم، عمدًا لا سهوًا:
 * - «التخطيط» (سبعة تخطيطات + «عرض متعدّد»): أدوات كونسول أحادي التركيب يعيد
 *   ترتيب لوحاته على العميل، بينما كل مساحة عمل هنا تملك مسارًا حقيقيًا خاصًا
 *   بها — وهو ما يطلبه التصميم نفسه تحت «حفظ الروابط» بصيغة أقوى.
 * - «إجراءات سريعة» (PDF): لا نظير خادميًا له. أمّا CSV فبُني وله موضعه
 *   الصحيح: زرٌّ في ذيل كل قائمة يعرف مرشِّحها، لا زرّ عائم بلا سياق.
 */

import { usePathname, useRouter } from 'next/navigation';
import { useCallback, useEffect, useId, useMemo, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import Fuse from 'fuse.js';
import { api, type CustomerRow, type OrderRow, type Paginated, type ProductRow } from '../lib/admin-api';
import styles from './command-palette.module.css';

interface PaletteGroup {
  label: string;
  items: { label: string; href: string }[];
}

/** حرفان: حرفٌ واحد يطابق نصف الكتالوج فيكلّف نداءً بلا فائدة. */
const MIN_QUERY = 2;
/** ٢٥٠ms — أطول من إيقاع الكتابة، وأقصر من أن يُحسّ تأخّرًا. */
const DEBOUNCE_MS = 250;
/** خمسة لكل نوع: اللوحة اختصارٌ لا شاشة نتائج. */
const PER_TYPE = 5;

/**
 * مصادر البحث — كلٌّ منها نقطة نهاية قائمة تقبل `q` أصلًا (تحقّقتُ منها في
 * ‎ProductController/OrderController/CustomerController‎)، وصلاحيةٌ تحرسها.
 *
 * والعميل يقصد بطاقته `‎/customers/{id}‎` منذ أن بُنيت. كان يقصد القائمة
 * مرشَّحةً على بريده لأنّه لم تكن ثمّة بطاقة — وذلك أصدق ما كان متاحًا وقتها،
 * لا الأفضل. والترشيح بـ`?q=` باقٍ يعمل: القائمة تقرأه من الرابط.
 */
const SOURCES = [
  {
    key: 'products',
    label: 'منتجات',
    permission: 'products.read',
    path: '/admin/products',
    map: (row: ProductRow) => ({ label: row.title, href: `/studio/catalog/${row.id}`, hint: row.sku }),
  },
  {
    key: 'orders',
    label: 'طلبات',
    permission: 'orders.read',
    path: '/admin/orders',
    map: (row: OrderRow) => ({ label: row.number, href: `/orders/${row.id}`, hint: row.email }),
  },
  {
    key: 'customers',
    label: 'عملاء',
    permission: 'customers.read',
    path: '/admin/customers',
    map: (row: CustomerRow) => ({
      label: row.name,
      href: `/customers/${row.id}`,
      hint: row.email,
    }),
  },
] as const;

interface RecordGroup {
  label: string;
  items: { label: string; href: string; hint: string }[];
}

/*
 * مطابقة ضبابيّة لمركز الاختصارات.
 *
 * كان الترشيح ‎label.includes(q)‎ حرفيًّا وحسّاسًا لحالة الأحرف: من يكتب
 * «تحليلات» لا يجد «التحليلات والنمو» لأنّ أل التعريف تسبقها، ومن يكتب
 * ‎ANALYTICS‎ لا يجد ‎/analytics‎. ومركز اختصارات لا يسامح حرفًا واحدًا هو
 * قائمةٌ يجب حفظها لا بحثٌ.
 *
 * الأوزان ليست اعتباطًا: التسمية هي ما يقرؤه المشغّل ويكتبه، والمسار مساعدٌ
 * يجده من يعرف بنية اللوحة. و‎threshold: 0.35‎ حدٌّ متوسّط — أعلى منه يُدخل
 * ضجيجًا في قائمة قصيرة، وأدنى منه يعيدنا إلى المطابقة الحرفيّة.
 *
 * ‎ignoreLocation‎ لازم للعربيّة تحديدًا: الافتراض يعاقب التطابق البعيد عن
 * أوّل النصّ، و«التحليلات» تبدأ بأل التعريف في كلّ تسمية تقريبًا.
 */
const FUSE_OPTIONS = {
keys: [
  { name: 'label', weight: 0.75 },
  { name: 'href', weight: 0.25 },
],
threshold: 0.35,
ignoreLocation: true,
minMatchCharLength: 2,
/* بلا ‎as const‎: Fuse يطلب ‎keys‎ قابلة للتغيير في نوعها المعلن. */
};

export default function CommandPalette({
  groups,
  can,
}: {
  groups: PaletteGroup[];
  /** صلاحيات المستعمل — مصدرٌ لا يملكه المستعمل يُسقَط قبل النداء لا بعده. */
  can: (permission: string) => boolean;
}) {
  const router = useRouter();
  const pathname = usePathname();
  const [open, setOpen] = useState(false);
  const [query, setQuery] = useState('');
  const [active, setActive] = useState(0);
  const openerRef = useRef<HTMLButtonElement>(null);
  const inputRef = useRef<HTMLInputElement>(null);
  const closeRef = useRef<HTMLButtonElement>(null);
  const wasOpen = useRef(false);
  const [records, setRecords] = useState<RecordGroup[]>([]);
  const [searching, setSearching] = useState(false);
  const [searchError, setSearchError] = useState<string | null>(null);
  const debounce = useRef<ReturnType<typeof setTimeout> | null>(null);
  /* آخر نداءٍ يفوز: ردٌّ متأخّر لاستعلامٍ قديم يجب ألّا يدهس نتائج الأحدث. */
  const runId = useRef(0);
  const baseId = useId();
  const listId = `${baseId}-list`;
  const inputId = `${baseId}-input`;
  const optionId = (index: number) => `${baseId}-option-${index}`;

  /*
   * التصفير في مُعالِج الفتح لا في تأثير.
   *
   * كان تأثير `[open]` يستدعي خمسة `setState` عند كل فتح — تصييرٌ متتالٍ يحذّر
   * منه مصرّف React صراحةً (`set-state-in-effect`)، وهو تحذيرٌ في محلّه: الحالة
   * تُصفَّر **لأنّ المستعمل فتح**، وهذا حدثٌ لا مزامنةُ نظامٍ خارجي. فالتصفير
   * انتقل إلى الحدث نفسه، وبقي في التأثير ما هو تأثيرٌ حقًّا: التركيز — وهو
   * لمسُ DOM لا تعديلُ حالة.
   */
  const openPalette = useCallback(() => {
    setQuery('');
    setActive(0);
    setRecords([]);
    setSearchError(null);
    setSearching(false);
    // إبطال أي نداء طائر من فتحةٍ سابقة قبل أن يصل ردّه.
    runId.current += 1;
    if (debounce.current !== null) clearTimeout(debounce.current);
    setOpen(true);
  }, []);

  const closePalette = useCallback(() => setOpen(false), []);

  // Ctrl/⌘+K من أي مكان. `event.code` احتياط للوحة المفاتيح العربية حيث قد لا
  // يكون `event.key` حرفًا لاتينيًا.
  useEffect(() => {
    const onKey = (event: KeyboardEvent) => {
      if ((event.metaKey || event.ctrlKey) && (event.key.toLowerCase() === 'k' || event.code === 'KeyK')) {
        event.preventDefault();
        if (open) closePalette();
        else openPalette();
      }
    };
    document.addEventListener('keydown', onKey);
    return () => document.removeEventListener('keydown', onKey);
  }, [open, openPalette, closePalette]);

  // التركيز وحده: إلى الحقل عند الفتح، وإلى الزرّ الفاتح عند الإغلاق — وذلك
  // إن كانت مفتوحةً فعلًا لا عند أوّل تركيب.
  useEffect(() => {
    if (open) {
      wasOpen.current = true;
      inputRef.current?.focus();
    } else if (wasOpen.current) {
      wasOpen.current = false;
      openerRef.current?.focus();
    }
  }, [open]);



  const fuse = useMemo(
    () =>
      new Fuse(
        groups.flatMap((g) => g.items.map((item) => ({ ...item, group: g.label }))),
        FUSE_OPTIONS,
      ),
    [groups],
  );

  const filtered = useMemo(() => {
    const q = query.trim();
    if (q === '') return groups;

    /* ترتيب Fuse هو ترتيب الصلة؛ تُعاد المجموعات بترتيبها الأصليّ لتبقى مألوفة. */
    const hits = new Set(fuse.search(q).map((r) => r.item.href));
    return groups
      .map((group) => ({ ...group, items: group.items.filter((item) => hits.has(item.href)) }))
      .filter((group) => group.items.length > 0);
  }, [fuse, groups, query]);

  /*
   * السجلّات تُلحَق بمجموعات الوجهات فتَرِث كامل منطق لوحة المفاتيح والفهرسة
   * و`aria-activedescendant` بلا سطرٍ ثانٍ منه — كان البديل مسارًا موازيًا
   * يتقادم أحد شقّيه عند أوّل تعديل.
   */
  const shown = useMemo(
    () => [...filtered, ...records.map((g) => ({ label: g.label, items: g.items }))],
    [filtered, records],
  );

  const flat = useMemo(() => shown.flatMap((group) => group.items), [shown]);

  const searchRecords = async (raw: string) => {
    const id = runId.current + 1;
    runId.current = id;

    const allowed = SOURCES.filter((source) => can(source.permission));
    const responses = await Promise.all(
      allowed.map((source) =>
        api.get<Paginated<Record<string, never>>>(
          `${source.path}?q=${encodeURIComponent(raw)}&perPage=${PER_TYPE}`,
        ),
      ),
    );

    if (id !== runId.current) return;

    const next: RecordGroup[] = [];
    let failed = 0;
    responses.forEach((response, index) => {
      if (!response.ok) {
        failed += 1;
        return;
      }
      const source = allowed[index]!;
      const items = response.data.data.map((row) =>
        (source.map as (r: unknown) => { label: string; href: string; hint: string })(row),
      );
      if (items.length > 0) next.push({ label: source.label, items });
    });

    setRecords(next);
    // الفشل يُقال ولا يُبتلع: نتائج ناقصة بلا خبر تُقرأ «لا يوجد».
    setSearchError(
      failed === 0 ? null : `تعذّر البحث في ${failed} من ${allowed.length} مصادر — النتائج ناقصة.`,
    );
    setSearching(false);
  };

  const onQuery = (value: string) => {
    setQuery(value);
    setActive(0);
    if (debounce.current !== null) clearTimeout(debounce.current);

    const raw = value.trim();
    if (raw.length < MIN_QUERY) {
      // إبطال أي نداء طائر: نتيجته لم تعد مطلوبة.
      runId.current += 1;
      setRecords([]);
      setSearchError(null);
      setSearching(false);
      return;
    }

    setSearching(true);
    debounce.current = setTimeout(() => void searchRecords(raw), DEBOUNCE_MS);
  };

  // إبقاء الخيار النشط ظاهرًا أثناء التنقّل بالأسهم.
  useEffect(() => {
    if (!open) return;
    document.getElementById(optionId(active))?.scrollIntoView({ block: 'nearest' });
    // optionId مشتق من useId الثابت؛ لا يتغيّر بين التصييرات.
    // eslint-disable-next-line react-hooks/exhaustive-deps -- optionId مشتقّ من useId الثابت
  }, [active, open]);

  const go = (href: string) => {
    closePalette();
    router.push(href);
  };

  const onDialogKeyDown = (event: React.KeyboardEvent) => {
    if (event.key === 'Escape') {
      event.preventDefault();
      closePalette();
      return;
    }
    if (event.key === 'ArrowDown') {
      event.preventDefault();
      setActive((index) => Math.min(index + 1, Math.max(flat.length - 1, 0)));
      return;
    }
    if (event.key === 'ArrowUp') {
      event.preventDefault();
      setActive((index) => Math.max(index - 1, 0));
      return;
    }
    if (event.key === 'Enter' && event.target === inputRef.current) {
      event.preventDefault();
      const item = flat[active];
      if (item) go(item.href);
      return;
    }
    // مصيدة التركيز: عنصران قابلان للتركيز فقط (الحقل وزر الإغلاق)،
    // فالتبديل بينهما يغلق الدائرة في الاتجاهين.
    if (event.key === 'Tab') {
      event.preventDefault();
      const next = document.activeElement === inputRef.current ? closeRef.current : inputRef.current;
      next?.focus();
    }
  };

  const isCurrent = (href: string) => pathname === href || pathname.startsWith(`${href}/`);

  let optionIndex = -1;

  return (
    <div className={styles.bar}>
      <button
        ref={openerRef}
        type="button"
        className={styles.opener}
        onClick={openPalette}
        aria-haspopup="dialog"
        aria-expanded={open}
        /* التسمية تُخفى بصريًّا تحت 900px فيبقى الزرّ أيقونةً — ولولا هذه لصار بلا اسم. */
        aria-label="مركز الاختصارات"
      >
        {/*
          * عدسةٌ في المُشغّل: صار حقلًا بعرض ٢٤٠px في الرأس، وحقلٌ بهذا الشكل
          * بلا عدسة يُقرأ «زرّ» فيُتجاوَز — والعين تبحث عن العدسة قبل النصّ.
          */}
        <svg viewBox="0 0 24 24" aria-hidden="true" className={styles.searchIcon}>
          <circle cx="11" cy="11" r="7" />
          <path d="M21 21l-4-4" />
        </svg>
        <span className={styles.openerLabel}>مركز الاختصارات</span>
        <kbd className={styles.kbd} dir="ltr">
          Ctrl K
        </kbd>
      </button>

      {/*
        * الحوار في `<body>` لا تحت الرأس: الرأس يحمل `backdrop-filter`، وهو
        * يجعل كلّ `position: fixed` تحته محبوسًا في صندوقه — فكان الغشاء
        * يغطّي شريط الرأس وحده ويُرى الجدول من خلف اللوح في الداكن.
        */}
      {open && createPortal(
        <div
          role="dialog"
          aria-modal="true"
          aria-label="مركز الاختصارات"
          className={styles.overlay}
          onKeyDown={onDialogKeyDown}
          onMouseDown={(event) => {
            if (event.target === event.currentTarget) closePalette();
          }}
        >
          <div className={styles.panel}>
            <div className={styles.head}>
              <svg viewBox="0 0 24 24" aria-hidden="true" className={styles.searchIcon}>
                <circle cx="11" cy="11" r="7" />
                <path d="M21 21l-4-4" />
              </svg>
              <label className="sr-only" htmlFor={inputId}>
                ابحث عن قسم، أو عن منتج أو طلب أو عميل بالاسم أو الرقم أو البريد
              </label>
              <input
                ref={inputRef}
                id={inputId}
                type="text"
                className={styles.input}
                placeholder="ابحث عن قسم، أو منتج أو طلب أو عميل…"
                value={query}
                onChange={(event) => onQuery(event.target.value)}
                role="combobox"
                aria-expanded="true"
                aria-controls={listId}
                aria-activedescendant={flat.length > 0 ? optionId(active) : undefined}
                aria-autocomplete="list"
                autoComplete="off"
                spellCheck={false}
              />
              <button
                ref={closeRef}
                type="button"
                className={styles.close}
                onClick={closePalette}
                aria-label="إغلاق"
              >
                ×
              </button>
            </div>
            <div className={styles.list} role="listbox" id={listId} aria-label="النتائج">
              {flat.length === 0 && !searching && (
                <p className={styles.empty}>
                  {query.trim().length > 0 && query.trim().length < MIN_QUERY
                    ? `اكتب ${MIN_QUERY} أحرف على الأقل للبحث في السجلّات.`
                    : 'لا نتائج مطابقة.'}
                </p>
              )}
              {searching && (
                <p className={styles.empty} role="status" aria-live="polite">
                  جارٍ البحث في السجلّات…
                </p>
              )}
              {searchError !== null && (
                <p className={styles.empty} role="alert">
                  {searchError}
                </p>
              )}
              {shown.map((group, groupIdx) => (
                <div
                  key={group.label}
                  className={styles.group}
                  role="group"
                  aria-labelledby={`${baseId}-group-${groupIdx}`}
                >
                  <span id={`${baseId}-group-${groupIdx}`} className={styles.groupLabel}>
                    {group.label}
                  </span>
                  {/*
                    * ‎role="none"‎ على الغلاف: ‎listbox‎ يملك ‎option‎ و‎group‎ وحدهما،
                    * وغلافُ تخطيطٍ عامّ بينهما يكسر ملكيّة المجموعة لخياراتها
                    * فيضطرب حساب «الخيار س من ص». الغلاف يبقى للتخطيط ويختفي
                    * من شجرة الوصول.
                    */}
                  <div className={styles.groupItems} role="none">
                    {group.items.map((item) => {
                      optionIndex += 1;
                      const index = optionIndex;
                      return (
                        <button
                          key={item.href}
                          type="button"
                          role="option"
                          id={optionId(index)}
                          tabIndex={-1}
                          aria-selected={index === active}
                          className={styles.option}
                          onClick={() => go(item.href)}
                          onMouseEnter={() => setActive(index)}
                        >
                          <span
                            aria-hidden="true"
                            className={isCurrent(item.href) ? `${styles.dot} ${styles.dotCurrent}` : styles.dot}
                          />
                          <span className={styles.optionLabel}>{item.label}</span>
                          <bdi dir="ltr" className={styles.hint}>
                            {'hint' in item ? (item as { hint: string }).hint : item.href}
                          </bdi>
                        </button>
                      );
                    })}
                  </div>
                </div>
              ))}
            </div>
            {/*
              * ذيلٌ يُعلّم لوحة المفاتيح.
              *
              * اللوحة تعمل بالأسهم وEnter وEsc منذ بُنيت، ولا شيء كان يقول
              * ذلك — فتُستعمل بالفأرة وحدها، وهي أبطأ من الشريط الجانبيّ
              * نفسه. و`aria-hidden` لأنّ قارئ الشاشة يُعلَن له النمط
              * (`combobox`/`listbox`) فيعرف مفاتيحه أصلًا؛ إعادتُها نصًّا
              * ثرثرةٌ في كلّ فتحة.
              */}
            <div className={styles.legend} aria-hidden="true">
              <span>
                <kbd className={styles.kbd} dir="ltr">↑↓</kbd> تنقّل
              </span>
              <span>
                <kbd className={styles.kbd} dir="ltr">Enter</kbd> فتح
              </span>
              <span>
                <kbd className={styles.kbd} dir="ltr">Esc</kbd> إغلاق
              </span>
            </div>
          </div>
        </div>,
        document.body,
      )}
    </div>
  );
}
