'use client';
// UNIT: HOME-CONTENT-PANEL
// FILE: home-content-panel.tsx
// STYLE: page.module.css
// PARENT: PAGE-HOME-CONTENT
// USES: LIB-ADMIN-API, LIB-CONTRACT, RESOURCE
// API: none
// MAP: docs/PROJECT_MAP.md

/**
 * محتوى الصفحة الرئيسة — سطحُ تحريره.
 *
 * ─── العطل الذي وُلد منه ───
 *
 * `HomeContent` في الخادم كان **يُقرأ ولا يُكتب**: لا مسار إداريّ يمسّ
 * `home`، ولا شاشة هنا تحرّره. فكان عنوان الرئيسة ونصّها وأزرارها وشرائح
 * ثقتها تتغيّر بتحرير البذّار وإعادة البذر — أي بنشرةٍ من مطوّر لا بقرارٍ
 * من مالك. وكان سطح «الرئيسية» مذكورًا في `lib/nav.ts` ضمن خمسة أسطحٍ دُمجت
 * في «استوديو التصميم»، ثمّ حُذف الاستوديو (٢٠٢٦-٠٩-١٧) فذهب السطح معه.
 *
 * ─── وشكل النموذج يأتي من الخادم ───
 *
 * `GET /admin/home-content` يُعيد `schema` و`blocks` معًا: السجلّ نفسه الذي
 * يقرأ منه المتجر ويكتب به الكاتب. فحقلٌ يُضاف في الخادم يظهر هنا حقلًا
 * بلا تعديل، ولا تنحرف نسختان من قائمةٍ واحدة.
 *
 * ─── ولا JSON في وجه المالك ───
 *
 * أوّل صياغةٍ كانت مساحةَ نصٍّ تقبل JSON خامًّا. وهي صحيحةٌ تقنيًّا ولا تصلح
 * لمن كُتبت له: فاصلةٌ ناقصة تمنع حفظ عنوان الرئيسة. فصار لكلّ حقلٍ مدخلُه،
 * ولكلّ قائمةٍ صفوفُها بزرّ إضافةٍ وحذف — والشكل من `schema` لا من قائمةٍ
 * مكتوبة هنا.
 *
 * ─── والحفظ يعرض ما صار مقروءًا ───
 *
 * الردّ يحمل القيمة بعد التطبيع لا ما أُرسل: الخادم يُسقط الفارغ ويشذّب
 * النصّ قبل الحفظ. فما يُعاد رسمه هنا بعد الحفظ هو بالضبط ما يقرؤه المتجر —
 * ولا يُقال «حُفظ» لحقلٍ سقط بصمت.
 */

import { useCallback, useState } from 'react';
import { api } from '../../../lib/admin-api';
import { proxyPath } from '../../../lib/contract';
import { ResourceView, useResource } from '../../../components/resource';
import styles from './page.module.css';
import {
  HeroSlidesEditor,
  SliderSettingsEditor,
  type SliderSettingsSpec,
  type SlidesSpec,
} from './hero-slides-editor';

type BlockKind = 'block' | 'cards' | 'faq' | 'coupon' | 'layout' | 'slides';

interface BlockSpec {
  kind: BlockKind;
  fields?: string[];
  trust?: boolean;
  /** أنواع حقول كتلة إعداداتٍ (مفتاح/عدد/قائمة) — `home.hero_slider` اليوم. */
  settings?: SliderSettingsSpec;
}

interface SectionSpec {
  label: string;
  hero: boolean;
}

interface Payload {
  schema: Record<string, BlockSpec>;
  blocks: Record<string, unknown>;
  sections: Record<string, SectionSpec>;
}

/** تسميات الحقول — وما لا تسمية له يُعرض بمفتاحه لا يُخفى. */
const FIELD_LABELS: Record<string, string> = {
  title: 'العنوان',
  title_accent: 'العنوان المميّز',
  subtitle: 'النصّ التعريفيّ',
  eyebrow: 'السطر العلويّ',
  cta_label: 'نصّ الزرّ الرئيس',
  cta_url: 'وجهة الزرّ الرئيس',
  cta_secondary_label: 'نصّ الزرّ الثانوي',
  cta_secondary_url: 'وجهة الزرّ الثانوي',
  code: 'رمز القسيمة',
  variant: 'تصميم الهيرو',
  media_url: 'رابط صورة الخلفيّة',
  media_alt: 'وصف الصورة (لقارئ الشاشة)',
  overlay: 'شدّة الحجاب فوق الصورة',
  align: 'محاذاة النصّ',
  animation: 'أسلوب الحركة',
  /* شريط الإعلانات (home.announcements) */
  announcements_mode: 'أسلوب العرض',
  announcement_1: 'الرسالة الأولى',
  announcement_2: 'الرسالة الثانية',
  announcement_3: 'الرسالة الثالثة',
  announcements_link_label: 'نصّ رابط الشريط',
  announcements_link_url: 'وجهة رابط الشريط',
  announcements_bg: 'لون الخلفيّة (hex)',
  announcements_fg: 'لون النصّ (hex)',
  announcements_height: 'ارتفاع الشريط (px)',
  /* هويّة المتجر (home.identity) */
  document_label: 'اسم الوثيقة',
  document_number: 'رقم الوثيقة',
  social_x_url: 'رابط إكس',
  social_instagram_url: 'رابط إنستغرام',
  social_whatsapp_url: 'رابط واتساب',
  social_tiktok_url: 'رابط تيك توك',
  social_snapchat_url: 'رابط سناب شات',
  /* شارة العرض الموسميّ (home.promo) */
  badge: 'شارة العرض (السطر العلويّ)',
};

/**
 * حقولٌ مفرداتُها مغلقة — تُعرض قائمةً لا حقلَ نصّ.
 *
 * الخادم يخزّنها نصًّا (`HomeContent::HERO_FIELDS`) والمتجر يُسقط ما لا
 * يعرفه ويقع على افتراضه. فحقلُ نصّ هنا يعني أنّ مطبعةً واحدة تُرجع
 * الهيرو إلى تصميمه الافتراضيّ بلا رسالة — يراها المالك «لم يُحفظ».
 * والقائمة تجعل الخطأ غير ممكن أصلًا.
 *
 * والخيار الأوّل في كلٍّ منها فارغ: «غير محدّد» ليس قيمةً تُكتب بل غيابُ
 * تجاوزٍ — وهو ما يعنيه حذف المفتاح في الخلفيّة.
 */
const CHOICE_FIELDS: Record<string, readonly { value: string; label: string }[]> = {
  variant: [
    { value: '', label: 'الافتراضيّ (شرائح متتالية)' },
    { value: 'slider', label: 'شرائح متتالية — العلامة والعرض والبحث' },
    { value: 'institutional', label: 'مؤسسيّ — كتالوج محكم بحبرٍ كحليّ' },
    { value: 'bold', label: 'جريء — كتل لونيّة وتيبوغرافيا ضخمة' },
    { value: 'luxe', label: 'داكن راقٍ — سطحٌ ليليّ بلمسةٍ نحاسيّة' },
    { value: 'brand', label: 'هويّة الشعار — تدرّج العلامة وبطاقة معاينة' },
  ],
  overlay: [
    { value: '', label: 'الافتراضيّ (خفيف)' },
    { value: 'none', label: 'بلا حجاب' },
    { value: 'soft', label: 'خفيف' },
    { value: 'strong', label: 'قويّ — لصورةٍ فاتحة أو مزدحمة' },
  ],
  align: [
    { value: '', label: 'الافتراضيّ (بداية السطر)' },
    { value: 'start', label: 'بداية السطر' },
    { value: 'center', label: 'توسيط' },
  ],
  announcements_mode: [
    { value: '', label: 'الافتراضيّ (قائمة ثابتة)' },
    { value: 'static', label: 'قائمة ثابتة' },
    { value: 'marquee', label: 'شريط متحرّك' },
  ],
  animation: [
    { value: '', label: 'الافتراضيّ (صعودٌ خفيف)' },
    { value: 'none', label: 'بلا حركة' },
    { value: 'fade', label: 'ظهورٌ متدرّج' },
    { value: 'rise', label: 'صعودٌ خفيف' },
    { value: 'parallax', label: 'عمقٌ عند التمرير' },
  ],
};

/** ما يُكتب في سطرٍ واحد — وما عداه منطقة نصّ. */
const SHORT_FIELDS = new Set([
  'title',
  'title_accent',
  'eyebrow',
  'cta_label',
  'cta_url',
  'cta_secondary_label',
  'cta_secondary_url',
  'code',
  'media_url',
  'media_alt',
  'announcement_1',
  'announcement_2',
  'announcement_3',
  'announcements_link_label',
  'announcements_link_url',
  'announcements_bg',
  'announcements_fg',
  'announcements_height',
  'document_label',
  'document_number',
  'social_x_url',
  'social_instagram_url',
  'social_whatsapp_url',
  'social_tiktok_url',
  'social_snapchat_url',
  'badge',
]);

const BLOCK_LABELS: Record<string, string> = {
  'home.hero': 'الهيرو',
  'home.cta': 'الدعوة الختاميّة',
  'home.why': 'بطاقات «لماذا نحن»',
  'home.benefits': 'بطاقات المزايا',
  'home.faq': 'الأسئلة الشائعة',
  'home.promo_coupon': 'القسيمة المُعلَنة',
  'home.promo': 'شارة العرض الموسميّ',
  'home.announcements': 'شريط الإعلانات أعلى الصفحة',
  'home.identity': 'هويّة المتجر — الوثيقة والحسابات',
  'home.layout': 'ترتيب أقسام الرئيسة',
  'home.hero_slides': 'شرائح الهيرو',
  'home.hero_slider': 'إعدادات حركة الهيرو',
};

const HINTS: Record<BlockKind, string> = {
  block: 'العنوان إلزاميّ — وبدونه تُحذف الكتلة ولا تظهر في المتجر. وما تتركه فارغًا لا يُعرض.',
  cards: 'البطاقة بلا عنوان تُسقَط. والوصف اختياريّ.',
  faq: 'السؤال بلا جواب وعدٌ فارغ فيُسقَط — وكذلك الجواب بلا سؤال.',
  coupon: 'رمز قسيمةٍ قائمةٍ فعلًا في المتجر. وفراغُه يزيل الإعلان.',
  slides: 'حتّى ثماني شرائح بالترتيب الذي تضعه.',
  layout:
    'اختر ما يظهر ورتّبه. وقسما الهيرو لا يُفعَّلان معًا — كلاهما يحمل العنوان الرئيس. وبلا ضبطٍ هنا يعرض المتجر ترتيبه الافتراضيّ.',
};

/* ─────────────────────────── شكل المسودّة ─────────────────────────── */

interface BlockDraft {
  kind: 'block';
  fields: Record<string, string>;
  trust: string[];
}
interface RowsDraft {
  kind: 'cards' | 'faq';
  rows: Record<string, string>[];
}
interface CouponDraft {
  kind: 'coupon';
  code: string;
}
interface LayoutDraft {
  kind: 'layout';
  order: string[];
}
type Draft = BlockDraft | RowsDraft | CouponDraft | LayoutDraft;

const ROW_FIELDS: Record<'cards' | 'faq', string[]> = {
  cards: ['title', 'description'],
  faq: ['question', 'answer'],
};

const ROW_LABELS: Record<string, string> = {
  title: 'العنوان',
  description: 'الوصف',
  question: 'السؤال',
  answer: 'الجواب',
};

function text(value: unknown): string {
  return typeof value === 'string' ? value : '';
}

/** المخزَّن ← مسودّة. وما ليس نصًّا يُعرض فارغًا كما يقرؤه المتجر. */
function toDraft(spec: BlockSpec, value: unknown): Draft {
  if (spec.kind === 'layout') {
    return { kind: 'layout', order: Array.isArray(value) ? value.map(text).filter(Boolean) : [] };
  }

  if (spec.kind === 'coupon') {
    const object = (value ?? {}) as Record<string, unknown>;
    return { kind: 'coupon', code: text(object.code) };
  }

  if (spec.kind === 'cards' || spec.kind === 'faq') {
    const list = Array.isArray(value) ? value : [];
    const keys = ROW_FIELDS[spec.kind];
    return {
      kind: spec.kind,
      rows: list.map((row) => {
        const object = (row ?? {}) as Record<string, unknown>;
        return Object.fromEntries(keys.map((key) => [key, text(object[key])]));
      }),
    };
  }

  const object = (value ?? {}) as Record<string, unknown>;
  return {
    kind: 'block',
    fields: Object.fromEntries((spec.fields ?? []).map((field) => [field, text(object[field])])),
    trust: Array.isArray(object.trust) ? object.trust.map(text) : [],
  };
}

/** مسودّة ← حمولة. ولا تطبيع هنا: الخادم هو المُطبِّع، واللوحة لا تُكرّر قواعده. */
function toPayload(draft: Draft): unknown {
  if (draft.kind === 'layout') return draft.order;
  if (draft.kind === 'coupon') return { code: draft.code };
  if (draft.kind === 'block') return { ...draft.fields, trust: draft.trust };
  return draft.rows;
}

/* ──────────────────────────── المكوّنات ──────────────────────────── */

export function HomeContentPanel() {
  const { state, reload } = useResource<{ data: Payload }>(proxyPath('adminHomeContentIndex'));

  return (
    <div className={styles.page}>
      <header className={styles.head}>
        <h1 className={styles.title}>محتوى الصفحة الرئيسة</h1>
        <p className={styles.lede}>
          ما تكتبه هنا هو ما يعرضه المتجر حرفيًّا. والحقل الفارغ يُحذف ولا يُعرض — لا نصَّ
          افتراضيّ خلف ما تتركه.
        </p>
      </header>

      <ResourceView state={state} reload={reload}>
        {(payload) => (
          <div className={styles.blocks}>
            {Object.entries(payload.data.schema).map(([key, spec]) =>
              /* الهيرو المتحرّك محرّرٌ مستقلّ: قائمةُ شرائح بأنواعها، وإعداداتٌ غير نصّيّة. */
              spec.kind === 'slides' ? (
                <HeroSlidesEditor
                  key={key}
                  blockKey={key}
                  spec={spec as unknown as SlidesSpec}
                  value={payload.data.blocks[key]}
                  title={BLOCK_LABELS[key] ?? key}
                />
              ) : spec.settings !== undefined ? (
                <SliderSettingsEditor
                  key={key}
                  blockKey={key}
                  settings={spec.settings}
                  value={payload.data.blocks[key]}
                  title={BLOCK_LABELS[key] ?? key}
                />
              ) : (
                <BlockEditor
                  key={key}
                  blockKey={key}
                  spec={spec}
                  value={payload.data.blocks[key]}
                  sections={payload.data.sections}
                />
              ),
            )}
          </div>
        )}
      </ResourceView>
    </div>
  );
}

function BlockEditor({
  blockKey,
  spec,
  value,
  sections,
}: {
  blockKey: string;
  spec: BlockSpec;
  value: unknown;
  sections: Record<string, SectionSpec>;
}) {
  const [draft, setDraft] = useState<Draft>(() => toDraft(spec, value));
  const [busy, setBusy] = useState(false);
  const [outcome, setOutcome] = useState<{ tone: 'ok' | 'error'; text: string } | null>(null);

  const save = useCallback(async () => {
    setBusy(true);
    const result = await api.put<{ data: { value: unknown } }>(
      proxyPath('adminHomeContentUpdate', { block: blockKey.replace(/^home\./, '') }),
      { payload: toPayload(draft) },
    );
    setBusy(false);
    if (!result.ok) {
      /* رسالة الخادم حرفيًّا — هو وحده يعرف ما منع الحفظ. */
      setOutcome({ tone: 'error', text: result.message });
      return;
    }
    /*
     * المعروض يصير ما خزّنه الخادم — لا ما أُرسل ولا إعادةَ جلب.
     *
     * ولا `useEffect` يزامن خاصّيةً بحالة: ذلك ما يرفضه
     * `react-hooks/set-state-in-effect` وقانون المستودع (§٢٢) — والحالة
     * التابعة للخادم تُشتقّ لا تُنسخ.
     */
    const stored = result.data.data.value;
    setDraft(toDraft(spec, stored));
    setOutcome({
      tone: 'ok',
      text:
        stored === null
          ? 'أُفرغت الكتلة — لن تظهر في المتجر.'
          : 'حُفظ. والمعروض أعلاه هو ما سيقرؤه المتجر.',
    });
  }, [draft, blockKey, spec]);

  return (
    <section className={styles.block} aria-labelledby={`b-${blockKey}`}>
      <div className={styles.blockHead}>
        <h2 className={styles.blockTitle} id={`b-${blockKey}`}>
          {BLOCK_LABELS[blockKey] ?? blockKey}
        </h2>
        <code className={styles.blockKey}>{blockKey}</code>
      </div>
      <p className={styles.hint}>{HINTS[spec.kind]}</p>

      {draft.kind === 'block' ? (
        <BlockFields
          blockKey={blockKey}
          draft={draft}
          showTrust={spec.trust === true}
          onChange={setDraft}
        />
      ) : draft.kind === 'layout' ? (
        <LayoutEditor draft={draft} sections={sections} onChange={setDraft} />
      ) : draft.kind === 'coupon' ? (
        <Field
          id={`f-${blockKey}-code`}
          label={FIELD_LABELS.code ?? 'code'}
          value={draft.code}
          onChange={(next) => setDraft({ ...draft, code: next })}
        />
      ) : (
        <Rows blockKey={blockKey} draft={draft} onChange={setDraft} />
      )}

      <div className={styles.actions}>
        <button type="button" className={styles.save} onClick={() => void save()} disabled={busy}>
          {busy ? 'جارٍ الحفظ…' : 'احفظ'}
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

function BlockFields({
  blockKey,
  draft,
  showTrust,
  onChange,
}: {
  blockKey: string;
  draft: BlockDraft;
  showTrust: boolean;
  onChange: (next: Draft) => void;
}) {
  return (
    <>
      <div className={styles.grid}>
        {Object.entries(draft.fields).map(([name, value]) =>
          CHOICE_FIELDS[name] !== undefined ? (
            <Choice
              key={name}
              id={`f-${blockKey}-${name}`}
              label={FIELD_LABELS[name] ?? name}
              value={value}
              options={CHOICE_FIELDS[name] ?? []}
              onChange={(next) => onChange({ ...draft, fields: { ...draft.fields, [name]: next } })}
            />
          ) : (
          <Field
            key={name}
            id={`f-${blockKey}-${name}`}
            label={FIELD_LABELS[name] ?? name}
            value={value}
            /* العنوان وحده إلزاميّ — والخادم يفرضه، والعلامة تُعلنه قبل الخطأ. */
            required={name === 'title'}
            multiline={!SHORT_FIELDS.has(name)}
            /* الوجهات تُكتب لاتينيّةً فتُعزل اتّجاهيًّا. */
            ltr={name.endsWith('_url')}
            onChange={(next) => onChange({ ...draft, fields: { ...draft.fields, [name]: next } })}
          />
          ),
        )}
      </div>

      {showTrust ? (
        <ListEditor
          legend="شرائح الثقة"
          addLabel="أضف شريحة"
          items={draft.trust}
          idPrefix={`t-${blockKey}`}
          itemLabel={(index) => `الشريحة ${index + 1}`}
          onChange={(trust) => onChange({ ...draft, trust })}
        />
      ) : null}
    </>
  );
}

function Rows({
  blockKey,
  draft,
  onChange,
}: {
  blockKey: string;
  draft: RowsDraft;
  onChange: (next: Draft) => void;
}) {
  const keys = ROW_FIELDS[draft.kind];

  const set = (index: number, name: string, value: string) => {
    onChange({
      ...draft,
      rows: draft.rows.map((row, at) => (at === index ? { ...row, [name]: value } : row)),
    });
  };

  return (
    <div className={styles.rows}>
      {draft.rows.map((row, index) => (
        <fieldset className={styles.row} key={`${blockKey}-row-${index}`}>
          <legend className={styles.rowLegend}>العنصر {index + 1}</legend>
          {keys.map((name) => (
            <Field
              key={name}
              id={`r-${blockKey}-${index}-${name}`}
              label={ROW_LABELS[name] ?? name}
              value={row[name] ?? ''}
              multiline={name === 'description' || name === 'answer'}
              onChange={(next) => set(index, name, next)}
            />
          ))}
          <button
            type="button"
            className={styles.remove}
            onClick={() => onChange({ ...draft, rows: draft.rows.filter((_, at) => at !== index) })}
          >
            احذف العنصر {index + 1}
          </button>
        </fieldset>
      ))}

      <button
        type="button"
        className={styles.add}
        onClick={() =>
          onChange({
            ...draft,
            rows: [...draft.rows, Object.fromEntries(keys.map((key) => [key, '']))],
          })
        }
      >
        أضف عنصرًا
      </button>
    </div>
  );
}

/**
 * ترتيب أقسام الرئيسة — تفعيلٌ وترتيب.
 *
 * ─── لماذا لا سحبٌ وإفلات ───
 *
 * السحب يحتاج بديلًا للوحة المفاتيح وإلّا صار الترتيب حكرًا على الفأرة.
 * وزرّا «أعلى/أسفل» يعملان باللمس والفأرة والمفتاح معًا بلا طبقةٍ ثانية،
 * ويُعلنان موضعهما لقارئ الشاشة. القائمة اثنا عشر عنصرًا لا مئة.
 *
 * ─── وقاعدة الهيرو تُعرض قبل الحفظ ───
 *
 * الخادم يرفض هيروين بردٍّ ٤٢٢ يشرح السبب — وهو الحارس الحقيقيّ. وهذه
 * العلامة تُري المالك التعارض وهو يختار، فلا يكتشفه بعد ضغطة حفظ.
 */
function LayoutEditor({
  draft,
  sections,
  onChange,
}: {
  draft: LayoutDraft;
  sections: Record<string, SectionSpec>;
  onChange: (next: Draft) => void;
}) {
  const known = Object.keys(sections);
  const enabled = draft.order.filter((id) => known.includes(id));
  const heroes = enabled.filter((id) => sections[id]?.hero);

  const toggle = (id: string) =>
    onChange({
      ...draft,
      order: enabled.includes(id) ? enabled.filter((at) => at !== id) : [...enabled, id],
    });

  const move = (index: number, step: number) => {
    const next = [...enabled];
    const target = index + step;
    const a = next[index];
    const b = next[target];
    if (a === undefined || b === undefined) return;
    next[index] = b;
    next[target] = a;
    onChange({ ...draft, order: next });
  };

  return (
    <div className={styles.rows}>
      {heroes.length > 1 ? (
        <p role="alert" className={styles.error}>
          قسما هيرو مُفعَّلان معًا ({heroes.map((id) => sections[id]?.label ?? id).join(' و')}) —
          سيرفض الخادم الحفظ: كلاهما يحمل العنوان الرئيس.
        </p>
      ) : null}

      <ol className={styles.order}>
        {enabled.map((id, index) => (
          <li className={styles.orderRow} key={id}>
            <span className={styles.orderName}>
              {sections[id]?.label ?? id}
              {sections[id]?.hero ? <span className={styles.required}> (هيرو)</span> : null}
            </span>
            <span className={styles.orderControls}>
              <button
                type="button"
                className={styles.move}
                disabled={index === 0}
                aria-label={`انقل «${sections[id]?.label ?? id}» لأعلى`}
                onClick={() => move(index, -1)}
              >
                ↑
              </button>
              <button
                type="button"
                className={styles.move}
                disabled={index === enabled.length - 1}
                aria-label={`انقل «${sections[id]?.label ?? id}» لأسفل`}
                onClick={() => move(index, 1)}
              >
                ↓
              </button>
              <button type="button" className={styles.remove} onClick={() => toggle(id)}>
                أخفِ
              </button>
            </span>
          </li>
        ))}
      </ol>

      <fieldset className={styles.row}>
        <legend className={styles.rowLegend}>أقسام غير مُفعَّلة</legend>
        <div className={styles.offList}>
          {known
            .filter((id) => !enabled.includes(id))
            .map((id) => (
              <button type="button" className={styles.add} key={id} onClick={() => toggle(id)}>
                أظهر «{sections[id]?.label ?? id}»
              </button>
            ))}
          {known.every((id) => enabled.includes(id)) ? (
            <p className={styles.hint}>كلّ الأقسام مُفعَّلة.</p>
          ) : null}
        </div>
      </fieldset>
    </div>
  );
}

/** قائمة نصوصٍ مفردة — شرائح الثقة اليوم، وما يشبهها غدًا. */
function ListEditor({
  legend,
  addLabel,
  items,
  idPrefix,
  itemLabel,
  onChange,
}: {
  legend: string;
  addLabel: string;
  items: string[];
  idPrefix: string;
  itemLabel: (index: number) => string;
  onChange: (next: string[]) => void;
}) {
  return (
    <fieldset className={styles.row}>
      <legend className={styles.rowLegend}>{legend}</legend>
      {items.map((item, index) => (
        <div className={styles.listRow} key={`${idPrefix}-${index}`}>
          <Field
            id={`${idPrefix}-${index}`}
            label={itemLabel(index)}
            value={item}
            onChange={(next) => onChange(items.map((old, at) => (at === index ? next : old)))}
          />
          <button
            type="button"
            className={styles.remove}
            onClick={() => onChange(items.filter((_, at) => at !== index))}
          >
            احذف {itemLabel(index)}
          </button>
        </div>
      ))}
      <button type="button" className={styles.add} onClick={() => onChange([...items, ''])}>
        {addLabel}
      </button>
    </fieldset>
  );
}

/** قائمةُ مفرداتٍ مغلقة — بديل حقل النصّ لحقول `CHOICE_FIELDS`. */
function Choice({
  id,
  label,
  value,
  options,
  onChange,
}: {
  id: string;
  label: string;
  value: string;
  options: readonly { value: string; label: string }[];
  onChange: (next: string) => void;
}) {
  return (
    <div className={styles.field}>
      <label className={styles.label} htmlFor={id}>
        {label}
      </label>
      <select
        id={id}
        className={styles.input}
        value={value}
        onChange={(event) => onChange(event.target.value)}
      >
        {options.map((option) => (
          <option key={option.value} value={option.value}>
            {option.label}
          </option>
        ))}
      </select>
    </div>
  );
}

function Field({
  id,
  label,
  value,
  onChange,
  required = false,
  multiline = false,
  ltr = false,
}: {
  id: string;
  label: string;
  value: string;
  onChange: (next: string) => void;
  required?: boolean;
  multiline?: boolean;
  ltr?: boolean;
}) {
  return (
    <div className={styles.field}>
      <label className={styles.label} htmlFor={id}>
        {label}
        {required ? <span className={styles.required}> (إلزاميّ)</span> : null}
      </label>
      {multiline ? (
        <textarea
          id={id}
          className={styles.input}
          value={value}
          rows={3}
          {...(ltr ? { dir: 'ltr' as const } : {})}
          onChange={(event) => onChange(event.target.value)}
        />
      ) : (
        <input
          id={id}
          className={styles.input}
          type="text"
          value={value}
          {...(ltr ? { dir: 'ltr' as const } : {})}
          onChange={(event) => onChange(event.target.value)}
        />
      )}
    </div>
  );
}
