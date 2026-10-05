'use client';
// UNIT: AI-ASSIST-PANEL
// FILE: ai-assist-panel.tsx
// STYLE: ai-assist-panel.module.css
// PARENT: AI-INDEX
// USES: none
// API: none
// MAP: docs/PROJECT_MAP.md

/**
 * لوح المساعدة الكتابية المشترك — العقد ظاهر، والتنفيذ معطَّل بسببه المعلن.
 *
 * لا توجد نقطة نهاية توليد في هذا النظام: لا مسار اقتراح ولا مسار اعتماد.
 * فاللوح يعرض ما كان سيحدث لو وُجدت، ولا يزعم شيئًا:
 *
 *   ١) الحقائق تُرسل ولا تُسأل — ما في السجل يذهب مع الطلب، ولا يُطلب من
 *      النموذج تخمينه.
 *   ٢) الناقص يعود بحالة «مجهول» تسمّي الفجوة — لا يُملأ الفراغ باختراع.
 *   ٣) الردّ المخالف للمخطط يُرفض كاملًا — لا إنقاذ جزئي لحقل من ردّ فاسد.
 *   ٤) خطوتان: اقتراح ثم اعتماد بشري — لا كتابة مباشرة في أي حقل.
 *   ٥) الحقائق المقفلة تُرفض عند الاعتماد ولو مرّت في الاقتراح.
 *   ٦) الثقة وزمن التوليد يُعرضان مع كل اقتراح — لا رقم بلا سياق.
 *   ٧) تعطّل المزوّد لا يمنع شيئًا: الكتابة اليدوية والحفظ والنشر تعمل كما هي.
 *
 * تُصدَّر للاستوديوهات لتُركَّب حيث تُحرَّر النصوص؛ لا تُركِّبها هذه الحزمة
 * في أي مسار بنفسها.
 */

import type { ReactNode } from 'react';
import styles from './ai-assist-panel.module.css';

/** حقيقة من سجل المنتج تُرسل مع الطلب — لا يُسأل النموذج عنها. */
export interface AiFact {
  key: string;
  label: string;
  value: string;
  /** الحقيقة المقفلة يرفض مسار الاعتماد أي اقتراح يغيّرها. */
  locked?: boolean;
}

/** الردّ المتوقَّع من مسار التوليد حين يُبنى — المخطط الذي يُرفض ما خالفه. */
export interface AiSuggestion {
  field: string;
  locale: string;
  value: string;
  /** ثقة النموذج بين ٠ و١ — تُعرض دائمًا مع الاقتراح. */
  confidence: number;
  latencyMs: number;
  usedFactKeys: string[];
  /** ما لم يجده النموذج في الحقائق: يعود مجهولًا مسمًّى لا مملوءًا. */
  unknown: string[];
  /** ادّعاءات بلا سند في الحقائق — تُحذف من القيمة وتُعرض منفصلة. */
  unsupportedClaims: string[];
}

/**
 * حارس المخطط: الردّ يُقبل كاملًا أو يُرفض كاملًا.
 *
 * الإنقاذ الجزئي لحقل من ردّ فاسد هو باب تسرّب البيانات المخترعة، فلا
 * يُستخدم هنا. تُصدَّر ليستعملها مسار الاعتماد حين يُبنى.
 */
export function isValidSuggestion(value: unknown): value is AiSuggestion {
  if (typeof value !== 'object' || value === null) return false;
  const record = value as Record<string, unknown>;
  const strings = ['field', 'locale', 'value'] as const;
  const arrays = ['usedFactKeys', 'unknown', 'unsupportedClaims'] as const;

  for (const key of strings) {
    if (typeof record[key] !== 'string' || (record[key] as string).trim() === '') return false;
  }
  for (const key of arrays) {
    const list = record[key];
    if (!Array.isArray(list) || list.some((item) => typeof item !== 'string')) return false;
  }
  if (typeof record.confidence !== 'number' || record.confidence < 0 || record.confidence > 1) return false;
  if (typeof record.latencyMs !== 'number' || record.latencyMs < 0) return false;

  return true;
}

/**
 * حارس الحقائق المقفلة عند الاعتماد: أي مفتاح مقفل ورد في الاقتراح يُرفض،
 * ولو أجازه الاقتراح نفسه. الرفض عند الاعتماد لا عند العرض — لأن العرض
 * لا يغيّر شيئًا والاعتماد يغيّر.
 */
export function rejectedLockedKeys(suggestion: AiSuggestion, facts: AiFact[]): string[] {
  const locked = new Set(facts.filter((fact) => fact.locked === true).map((fact) => fact.key));
  return suggestion.usedFactKeys.filter((key) => locked.has(key));
}

const ACTIONS = ['اقترح وصفًا', 'حسّن النص', 'اختصر', 'عربية أطبع', 'ابحث عن نقص', 'ادّعاءات بلا سند'];

const CONTRACT: [string, string][] = [
  ['الحقائق', 'ما في السجل يُرسل مع الطلب ولا يُسأل النموذج عنه — لا تخمين لعدد شرائح ولا صيغة ملف.'],
  ['المجهول', 'ما لا يوجد في الحقائق يعود بحالة «مجهول» تسمّي الفجوة، ولا يُملأ الفراغ باختراع.'],
  ['المخطط', 'الردّ المخالف للمخطط يُرفض كاملًا؛ لا إنقاذ حقل واحد من ردّ فاسد.'],
  ['الخطوتان', 'اقتراح ثم اعتماد بشري. لا كتابة مباشرة في أي حقل، ولا حفظ صامت.'],
  ['القفل', 'الحقائق المقفلة تُرفض عند الاعتماد ولو مرّت في الاقتراح — الرفض عند الكتابة لا عند العرض.'],
  ['الشفافية', 'الثقة وزمن التوليد يُعرضان مع كل اقتراح؛ رقم ثقة بلا سياق دعوى لا قياس.'],
  ['التعطّل', 'تعطّل المزوّد لا يمنع شيئًا: الكتابة اليدوية والحفظ والمعاينة والنشر تعمل كما هي.'],
];

export interface AiAssistPanelProps {
  /** اسم الحقل الذي يخدمه اللوح، كما يظهر للمحرّر. */
  field: string;
  /** القيمة الحالية للحقل — تُعرض كمرجع للمقارنة، ولا تُعدَّل من هنا. */
  currentValue?: string | null;
  /** الحقائق الحقيقية من سجل المنتج التي سترافق الطلب. */
  facts?: AiFact[];
  locale?: string;
  /** عنوان اختياري يوضع فوق العقد. */
  children?: ReactNode;
}

export default function AiAssistPanel({
  field,
  currentValue = null,
  facts = [],
  locale = 'ar-SA',
  children,
}: AiAssistPanelProps) {
  const locked = facts.filter((fact) => fact.locked === true);

  return (
    <section className={styles.panel} aria-label={`مساعد الكتابة — ${field}`}>
      <div className={styles.head}>
        <span className={styles.badge} aria-hidden="true">
          <svg viewBox="0 0 24 24">
            <path d="M12 4l1.8 4.7L18.5 10l-4.7 1.4L12 16l-1.8-4.6L5.5 10l4.7-1.3z" />
            <path d="M18.5 15.5l.9 2.3 2.3.7-2.3.7-.9 2.3-.9-2.3-2.3-.7 2.3-.7z" />
          </svg>
        </span>
        <h2 className={styles.title}>مساعد الكتابة — {field}</h2>
        <span className={styles.status}>
          <span className={styles.statusdot} aria-hidden="true" />
          غير مُهيأ
        </span>
        <bdi dir="ltr" className={styles.model}>
          NOT_CONFIGURED
        </bdi>
      </div>

      <div className={styles.offline} role="note">
        <svg viewBox="0 0 24 24" aria-hidden="true">
          <circle cx="12" cy="12" r="9" />
          <path d="M12 11v6M12 7.6v.1" />
        </svg>
        <div>
          <b className={styles.offlinetitle}>المساعد معطّل — وهذا لا يمنع شيئًا</b>
          <span className={styles.offlinebody}>
            لا مزوّد توليد مربوط بهذا النظام ولا مسار اقتراح أو اعتماد. الكتابة اليدوية والحفظ والمعاينة
            والنشر تعمل كلها كما هي، ولا طابور ولا محاولات تلقائية بلا إذنك. العقد أدناه معروض كاملًا لأنه
            شرط التشغيل لا شرحًا اختياريًا: أي مزوّد يُربط لاحقًا يلتزم به قبل أن يكتب حرفًا.
          </span>
          <ul className={styles.needs}>
            <li>
              <bdi dir="ltr">POST /admin/ai/suggest</bdi>
            </li>
            <li>
              <bdi dir="ltr">POST /admin/ai/apply</bdi>
            </li>
            <li>مزوّد نموذج لغوي</li>
          </ul>
        </div>
      </div>

      <div className={styles.actions}>
        {ACTIONS.map((action) => (
          <button key={action} type="button" className={styles.action} disabled aria-describedby="ai-disabled-reason">
            {action}
          </button>
        ))}
      </div>
      <p id="ai-disabled-reason" className={styles.empty} style={{ marginBlock: '0 13px' }}>
        كل الإجراءات معطَّلة لأن مسار التوليد غير موجود — لا زرّ هنا يطلق إشعارًا يوهم بأن شيئًا جرى.
      </p>

      {children}

      <div className={styles.contract}>
        <div className={styles.contracthead}>
          <span style={{ flex: 1, minInlineSize: 0 }}>عقد الطلب والردّ</span>
          <span className={styles.contractmeta}>الثقة: تُعرض مع كل اقتراح</span>
          <span className={styles.contractmeta}>الزمن: يُعرض بالمللي ثانية</span>
          <bdi dir="ltr" className={styles.contractmeta}>
            {locale}
          </bdi>
        </div>

        <div className={styles.split}>
          <div className={styles.col}>
            <span className={styles.collabel}>
              <svg viewBox="0 0 24 24" aria-hidden="true">
                <path d="M5 12.5l4.5 4.5L19 7" />
              </svg>
              حقائق تُرسل من السجل ولا تُسأل
            </span>
            {facts.length === 0 ? (
              <p className={styles.empty}>
                لم يمرّر المستدعي أي حقيقة. اللوح لا يخترع حقائق ليعرضها: قائمة فارغة تعني أن الطلب — لو
                أُرسل — سيذهب بلا سند، وكل ما يخصّ المنتج سيعود «مجهولًا».
              </p>
            ) : (
              <ul className={styles.facts}>
                {facts.map((fact) => (
                  <li key={fact.key} className={fact.locked === true ? styles.locked : undefined}>
                    <bdi dir="rtl">{fact.label}</bdi>
                    <b>
                      <bdi dir={/[؀-ۿ]/.test(fact.value) ? 'rtl' : 'ltr'}>{fact.value}</bdi>
                    </b>
                    {fact.locked === true ? <span className={styles.lockmark}>مقفلة</span> : null}
                  </li>
                ))}
              </ul>
            )}
          </div>

          <div className={styles.col}>
            <span className={`${styles.collabel} ${styles.missing}`}>
              <svg viewBox="0 0 24 24" aria-hidden="true">
                <circle cx="12" cy="12" r="9" />
                <path d="M12 11v6M12 7.6v.1" />
              </svg>
              القيمة الحالية والمقفل منها
            </span>
            <p className={styles.empty}>
              {currentValue === null || currentValue.trim() === ''
                ? 'لا قيمة حالية لهذا الحقل — لا نصّ عيّنة يُعرض مكانها.'
                : currentValue}
            </p>
            {locked.length > 0 ? (
              <ul className={styles.unknowns} style={{ marginBlockStart: 8 }}>
                {locked.map((fact) => (
                  <li key={fact.key}>
                    {fact.label} — مقفلة: أي اقتراح يمسّها يُرفض عند الاعتماد.
                  </li>
                ))}
              </ul>
            ) : null}
          </div>
        </div>

        <ul className={styles.rules}>
          {CONTRACT.map(([key, text]) => (
            <li key={key}>
              <span className={styles.rulekey}>{key}</span>
              <span>{text}</span>
            </li>
          ))}
        </ul>

        <div className={styles.steps}>
          <button type="button" className={styles.step} disabled>
            اقترح
          </button>
          <button type="button" className={styles.step} disabled>
            اعتمد
          </button>
          <p>
            خطوتان لا واحدة: «اقترح» يعرض فرقًا جانب القيمة الحالية، و«اعتمد» وحده يكتب — وهو يرفض أي حقيقة
            مقفلة قبل الكتابة. الزرّان معطَّلان لغياب المسارين.
          </p>
        </div>
      </div>
    </section>
  );
}
