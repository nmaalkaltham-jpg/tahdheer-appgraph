'use client';
// UNIT: OPS
// FILE: ops.tsx
// STYLE: ops.module.css
// PARENT: PAGE-OPERATIONS-CASES, PAGE-OPERATIONS-CASES-ID, PAGE-OPERATIONS-INVENTORY, PAGE-PAYMENTS-WEBHOOKS, PAGE-SHIPPING, QUALITY-PANEL +5
// USES: LIB-ADMIN-API
// API: none
// MAP: docs/PROJECT_MAP.md

/**
 * أساسيّات منصّة العمليّات.
 *
 * ستّ شاشاتٍ تعرض جداول عملٍ متشابهة الشكل مختلفة المضمون. المشترك هنا
 * مرّةً واحدة: حبّة الحالة، وسطر السبب، وشريط العدّ.
 *
 * والقاعدة التي تحكم اللون: **الأحمر للمعطوب وحده، والرماديّ للمنتظر**.
 * تلوينُ المنتظر تنبيهًا يملأ الشاشة بما لا يحتاج إصلاحًا، فتُهمَل الشاشة
 * كلّها — وهي الآليّة نفسها التي تجعل لوحات التنبيهات بلا قيمة.
 */

import { useId, useState, type ReactNode } from 'react';
import { api } from '../../../../lib/admin-api';
import styles from './ops.module.css';

/** نبرات الحالة الثلاث. */
export type Tone = 'danger' | 'wait' | 'done';

/*
 * `string | undefined` لا `string`: وحدات CSS مُصرَّحٌ بها فهرسًا غير
 * مُتحقَّق، فادّعاءُ الوجود يكذب على المدقّق ويكسر البناء عند إعادة تسمية
 * صنفٍ لا عند استعماله.
 */
const TONE_CLASS: Record<Tone, string | undefined> = {
  danger: styles.danger,
  wait: styles.wait,
  done: styles.done,
};

/** يضمّ أصنافًا قد يغيب بعضها بلا أن يكتب `undefined` في السمة. */
function cx(...parts: readonly (string | undefined)[]): string {
  return parts.filter(Boolean).join(' ');
}

export function StatusPill({ tone, label }: { tone: Tone; label: string }) {
  return <span className={cx(styles.pill, TONE_CLASS[tone])}>{label}</span>;
}

/**
 * سطر السبب تحت الصفّ.
 *
 * يُعرض في الجدول نفسه لا في نافذةٍ تُفتح: المشغّل يمسح الطابور بالعين
 * ليقرّر ما يبدأ به، وإخفاءُ السبب خلف نقرةٍ يعني فتح كلّ صفّ ليعرف أيّها
 * يستحقّ الفتح.
 */
export function ReasonLine({ children }: { children: ReactNode }) {
  if (children === null || children === undefined || children === '') return null;

  return <p className={styles.reason}>{children}</p>;
}

/** شريط العدّ فوق الجدول — أرقامٌ تُقرأ لا رسوم. */
export function CountBar({ items }: { items: readonly { label: string; value: number | string; tone?: Tone }[] }) {
  return (
    <ul className={styles.counts}>
      {items.map((item) => (
        <li key={item.label} className={item.tone ? TONE_CLASS[item.tone] : undefined}>
          <span className={styles.countValue}>{item.value}</span>
          <span className={styles.countLabel}>{item.label}</span>
        </li>
      ))}
    </ul>
  );
}

/**
 * جدول عملٍ بسيط.
 *
 * `scope="col"` ليس زينة: بدونه لا يربط قارئ الشاشة الخليّة بعمودها، فيُقرأ
 * الجدول سلسلةَ أرقامٍ بلا معنى.
 */
export function OpsTable({ columns, children }: { columns: readonly string[]; children: ReactNode }) {
  return (
    <div className={styles.tableWrap}>
      <table className={styles.table}>
        <thead>
          <tr>
            {columns.map((c) => (
              <th key={c} scope="col">
                {c}
              </th>
            ))}
          </tr>
        </thead>
        <tbody>{children}</tbody>
      </table>
    </div>
  );
}

/** نبرة حالة وحدة التنفيذ (§١٠). */
export function unitTone(status: string): Tone {
  if (status === 'FAILED') return 'danger';
  if (status === 'FULFILLED' || status === 'CANCELLED' || status === 'REVOKED') return 'done';

  return 'wait';
}

/**
 * زرّ فعلٍ واحد ينادي الخادم ثمّ يُعيد تحميل الجدول.
 *
 * الخطأ يُعرض بنصّ الخادم كما هو لا برسالةٍ عامّة: «لا عنوان شحنٍ على
 * الطلب» تُخبر المشغّل بما يفعل، و«تعذّر إتمام العمليّة» تُرسله يخمّن.
 *
 * والزرّ يُعطَّل أثناء التنفيذ: نقرتان متتاليتان على «إعادة المحاولة»
 * تُشغّلان المنسّق مرّتين متوازيتين على الطلب نفسه.
 */
export function ActionButton({
  path,
  label,
  busyLabel,
  onDone,
}: {
  path: string;
  label: string;
  busyLabel: string;
  onDone: () => void;
}) {
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  async function run() {
    setBusy(true);
    setError(null);
    const result = await api.post<unknown>(path);
    setBusy(false);

    if (result.ok) {
      onDone();

      return;
    }

    setError(result.message);
  }

  return (
    <>
      <button type="button" className="btn btn-ghost" disabled={busy} onClick={run}>
        {busy ? busyLabel : label}
      </button>
      {error === null ? null : (
        <p className={styles.reason} role="alert">
          {error}
        </p>
      )}
    </>
  );
}

/**
 * نموذج انتقالٍ على صفٍّ — الوجه المقابل لآلة الحالات في النطاق.
 *
 * ─── لماذا الخيارات تأتي من الخادم ───
 *
 * `options` هو `allowedNext` الذي يبثّه الصفّ، لا قائمةٌ مكتوبة هنا. وخريطة
 * الانتقالات تعيش في خدمة النطاق وحدها (`ShipmentTransitionService` و
 * `ReturnTransitionService`)، فنسخُها إلى الواجهة يُنتج خريطتين تتباعدان —
 * وأوّل تباعدٍ زرٌّ يَعِد المشغّل بانتقالٍ يرفضه الخادم.
 *
 * وصفٌّ بلا انتقالٍ مسموح لا يُصيَّر له نموذج: نهايةٌ بلغت نهايتها، وإظهار
 * قائمةٍ فارغة يوحي بعطل.
 *
 * ─── وخطأ الخادم يُعرض بنصّه ───
 *
 * «لا يُنقل من DELIVERED إلى PICKED_UP» تُخبر المشغّل بما جرى، و«تعذّر
 * إتمام العمليّة» تُرسله يخمّن. والحقل الإضافيّ يُرسَل فقط حين يُملأ، فلا
 * يُكتب فراغٌ مكان قيمةٍ قائمة.
 */
export function TransitionForm({
  path,
  /** `allowedNext` من الصفّ — لا قائمةٌ مكتوبة في الواجهة. */
  options,
  labels,
  label,
  /**
   * ما يميّز هذا الصفّ عن جاره في القراءة الصوتيّة (رقم الشحنة، رقم الإرجاع).
   *
   * جدولٌ بعشرين صفًّا يحمل عشرين مجموعةً اسمُها «نقل الحالة» يجعل قارئ
   * الشاشة يُعلن العنوان نفسه عشرين مرّة: يُسمَع الجدول قائمةَ نسخٍ متطابقة
   * ولا يُعرف أيّها تحت المؤشّر. والتمييز يلحق بالاسم ولا يُعرض بصريًّا —
   * العمود المجاور يقوله للعين أصلًا.
   */
  subject,
  /** حقلٌ نصّيّ اختياريّ يُرسَل مع الانتقال (سببٌ أو رقم تتبّع أو ملاحظة). */
  text,
  onDone,
}: {
  path: string;
  options: readonly string[];
  labels?: Readonly<Record<string, string>>;
  label: string;
  subject?: string;
  text?: { name: string; placeholder: string; required?: boolean; maxLength?: number };
  onDone: () => void;
}) {
  const [to, setTo] = useState('');
  const [value, setValue] = useState('');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const selectId = useId();
  /* الاسم المسموع يحمل هويّة الصفّ؛ والمعروض يبقى قصيرًا كما يقتضي عمودٌ ضيّق. */
  const accessibleLabel = subject === undefined ? label : `${label} — ${subject}`;

  if (options.length === 0) return null;

  async function run() {
    if (to === '') return;

    setBusy(true);
    setError(null);

    const payload: Record<string, string> = { status: to };
    /* الفراغ لا يُرسَل: حقلٌ فارغ يمحو قيمةً قائمة على الخادم. */
    if (text !== undefined && value.trim() !== '') payload[text.name] = value.trim();

    const result = await api.patch<unknown>(path, payload);
    setBusy(false);

    if (result.ok) {
      setTo('');
      setValue('');
      onDone();

      return;
    }

    setError(result.message);
  }

  const blocked = busy || to === '' || (text?.required === true && value.trim() === '');

  return (
    <>
      <div className={styles.transition}>
        <label className="sr-only" htmlFor={selectId}>
          {accessibleLabel}
        </label>
        <select
          id={selectId}
          className={styles.transitionField}
          value={to}
          onChange={(event) => setTo(event.target.value)}
        >
          <option value="">{label}</option>
          {options.map((option) => (
            <option key={option} value={option}>
              {labels?.[option] ?? option}
            </option>
          ))}
        </select>

        {text === undefined ? null : (
          <input
            className={cx(styles.transitionField, styles.transitionText)}
            type="text"
            value={value}
            placeholder={text.placeholder}
            aria-label={subject === undefined ? text.placeholder : `${text.placeholder} — ${subject}`}
            /* حدّ الخادم يُعلَن هنا فيقصّه المتصفّح قبل رحلةٍ تُردّ ٤٢٢. */
            maxLength={text.maxLength}
            onChange={(event) => setValue(event.target.value)}
          />
        )}

        <button type="button" className="btn btn-ghost" disabled={blocked} onClick={run}>
          {busy ? 'يُنفَّذ…' : 'نفّذ'}
        </button>
      </div>

      {error === null ? null : (
        <p className={styles.reason} role="alert">
          {error}
        </p>
      )}
    </>
  );
}
