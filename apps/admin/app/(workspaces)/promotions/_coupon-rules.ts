// UNIT: _COUPON-RULES
// FILE: _coupon-rules.ts
// STYLE: none
// PARENT: PAGE-PROMOTIONS
// USES: LIB-EXPORT-CSV, LIB-FORMAT
// API: none
// API-VIA: LIB-EXPORT-CSV
// MAP: docs/PROJECT_MAP.md

import type { CsvColumn } from '../../../lib/export-csv';
import { count, dateTime, localInputToIso, minorToRiyals, riyalsToMinor } from '../../../lib/format';

export interface CouponRow {
  id: number;
  code: string;
  type: 'percent' | 'fixed';
  value: number;
  label: string;
  usageLimit: number | null;
  usageCount: number;
  expiresAt: string | null;
  firstOrderPerAccount: boolean;
  usable: boolean;
  reason: string | null;
}

export interface CouponsPayload {
  data: CouponRow[];
  counts: { all: number; active: number };
}

/*
 * القيمة تُنسَّق بحسب النوع كما في الجدول تمامًا: النسبة عددٌ صحيح، والمبلغ
 * الثابت مخزَّنٌ بالهللة ويُقسم على مئة. عمودٌ واحد بوحدتين يُقرأ خطأً، فالنوع
 * يسبقه في الملفّ.
 */
export const COUPON_CSV: readonly CsvColumn<CouponRow>[] = [
  { header: 'الرمز', value: (c) => c.code },
  { header: 'النوع', value: (c) => (c.type === 'percent' ? 'نسبة (٪)' : 'مبلغ ثابت (ر.س)') },
  { header: 'القيمة', value: (c) => (c.type === 'fixed' ? minorToRiyals(c.value) : String(c.value)) },
  { header: 'الوصف', value: (c) => c.label },
  { header: 'حدّ الاستعمال', value: (c) => c.usageLimit },
  { header: 'مرّات الاستعمال', value: (c) => c.usageCount },
  { header: 'ينتهي (ISO)', value: (c) => c.expiresAt },
  { header: 'أوّل طلب للحساب فقط', value: (c) => (c.firstOrderPerAccount ? 'نعم' : 'لا') },
  { header: 'قابلة للاستعمال', value: (c) => (c.usable ? 'نعم' : 'لا') },
  { header: 'سبب التعطّل', value: (c) => c.reason },
];

export const EMPTY = {
  code: '',
  type: 'percent' as 'percent' | 'fixed',
  value: '',
  usageLimit: '',
  expiresAt: '',
  firstOrderPerAccount: false,
};

export type Form = typeof EMPTY;

/** نفس تعبير الخادم حرفيًّا (CouponController::validated) — لا تعبير ثانٍ أوسع. */
export const CODE_PATTERN = /^[A-Za-z0-9_-]+$/;

/** نافذة «ينتهي قريبًا» للتنبيه وحدها — لا تغيّر سلوكًا، تصف قربًا فقط. */
export const SOON_MS = 24 * 60 * 60 * 1000;

/**
 * القيمة كما يقبلها الخادم: النسبة عدد صحيح، والمبلغ الثابت بالهللات.
 * `null` تعني «غير صالحة» — لا صفر يُرسَل عن مدخل فارغ أو غير رقمي.
 */
export function outgoingValue(form: Form): number | null {
  const raw = form.value.trim();
  if (raw === '') return null;
  if (form.type === 'fixed') return riyalsToMinor(raw);
  const percent = Number(raw);

  return Number.isInteger(percent) ? percent : null;
}

export interface Check {
  id: string;
  level: 'blocking' | 'advisory';
  title: string;
  body: string;
  /** معرّف الحقل الذي يُصلَح فيه التعارض — ينقل التركيز إليه. */
  field?: string;
}

/**
 * فحص ما قبل الإصدار.
 *
 * كل بند هنا يعكس قاعدة تحقّق قائمة في الخادم أو سلوكًا مثبتًا في السلة —
 * لا شرط يخترعه المتصفّح. الحاجز يمنع طلبًا سيُرفَض أو رمزًا سيُولَد ميتًا؛
 * والتنبيه يصف أثرًا حقيقيًّا ولا يمنع.
 */
export function inspect(form: Form, rows: CouponRow[], editing: CouponRow | null): Check[] {
  const checks: Check[] = [];
  const code = form.code.trim();
  const value = outgoingValue(form);

  if (code === '') {
    checks.push({
      id: 'code-empty',
      level: 'blocking',
      field: 'coupon-code',
      title: 'الرمز غير مكتوب',
      body: 'لا رمز بلا نصّ يكتبه المشتري في السلة.',
    });
  } else if (!CODE_PATTERN.test(code)) {
    checks.push({
      id: 'code-shape',
      level: 'blocking',
      field: 'coupon-code',
      title: 'الرمز يحوي محارف يرفضها الخادم',
      body: 'الرمز حروف وأرقام وشرطات فقط — المسافة والرموز تُكتب خطأً في السلة.',
    });
  } else {
    const clash = rows.find(
      (row) => row.id !== editing?.id && row.code.toLowerCase() === code.toLowerCase(),
    );
    if (clash) {
      checks.push({
        id: 'code-clash',
        level: 'blocking',
        field: 'coupon-code',
        title:
          clash.code === code ? 'الرمز مستعمَل بالفعل' : `يصطدم بالرمز القائم ${clash.code}`,
        body:
          clash.code === code
            ? 'رمز بهذا النصّ موجود — عدّله من صفّه في الجدول بدل إصدار ثانٍ.'
            : 'السلة تطابق الرموز بلا تمييز بين الحروف الكبيرة والصغيرة، فرمزان لا يفترقان إلا في الحالة يصل المشتري إلى أحدهما فقط.',
      });
    }
  }

  if (value === null) {
    checks.push({
      id: 'value-shape',
      level: 'blocking',
      field: 'coupon-value',
      title: form.value.trim() === '' ? 'قيمة الخصم غير مكتوبة' : 'قيمة الخصم غير صالحة',
      body:
        form.type === 'percent'
          ? 'النسبة عدد صحيح — الكسور لا يقبلها الخادم.'
          : 'المبلغ رقم بالريالات، ويُحوَّل إلى هللات عند الإرسال.',
    });
  } else if (value < 1) {
    checks.push({
      id: 'value-min',
      level: 'blocking',
      field: 'coupon-value',
      title: 'قيمة الخصم أقلّ من الحدّ الأدنى',
      body: form.type === 'percent' ? 'أقلّ نسبة مقبولة ‎١٪.' : 'أقلّ مبلغ مقبول هللة واحدة.',
    });
  } else if (form.type === 'percent' && value > 100) {
    checks.push({
      id: 'value-max',
      level: 'blocking',
      field: 'coupon-value',
      title: 'النسبة تتجاوز ‎١٠٠٪',
      body: 'نسبة الخصم لا تتجاوز ‎١٠٠٪ — الخادم يرفضها.',
    });
  } else if (form.type === 'percent' && value === 100) {
    checks.push({
      id: 'value-free',
      level: 'advisory',
      field: 'coupon-value',
      title: 'خصم ‎١٠٠٪ يُنزل السعر إلى صفر',
      body: 'الرمز يجعل كل منتج في السلة مجّانيًّا — تأكّد من حدّ الاستعمال وتاريخ الانتهاء قبل إصداره.',
    });
  }

  const limit = form.usageLimit.trim();
  if (limit !== '') {
    const parsed = Number(limit);
    if (!Number.isInteger(parsed) || parsed < 1) {
      checks.push({
        id: 'limit-shape',
        level: 'blocking',
        field: 'coupon-limit',
        title: 'حدّ الاستعمال غير صالح',
        body: 'الحدّ عدد صحيح لا يقلّ عن ‎١، أو يُترك فارغًا لرمز بلا حدّ.',
      });
    } else if (editing !== null && parsed <= editing.usageCount) {
      checks.push({
        id: 'limit-below-usage',
        level: 'advisory',
        title: 'الحدّ الجديد بلغه العدّاد أصلًا',
        body: `استُعمل هذا الرمز ${count(editing.usageCount)} مرّة — بحدّ ${count(parsed)} يتوقّف فور الحفظ.`,
        field: 'coupon-limit',
      });
    }
  }

  const iso = localInputToIso(form.expiresAt);
  if (iso === false) {
    checks.push({
      id: 'expiry-shape',
      level: 'blocking',
      field: 'coupon-expires',
      title: 'تاريخ الانتهاء غير صالح',
      body: 'أعد اختيار اللحظة من الحقل — القيمة الحالية لا تُقرأ تاريخًا.',
    });
  } else if (typeof iso === 'string') {
    const at = new Date(iso).getTime();
    const now = Date.now();
    if (at <= now) {
      checks.push({
        id: 'expiry-past',
        level: 'blocking',
        field: 'coupon-expires',
        title: 'لحظة الانتهاء في الماضي',
        body: 'الخادم يقبل التاريخ لكن السلة تقرؤه منتهيًا فورًا — الرمز يُولد ميتًا.',
      });
    } else if (at - now <= SOON_MS) {
      checks.push({
        id: 'expiry-soon',
        level: 'advisory',
        field: 'coupon-expires',
        title: 'الرمز يعمل أقلّ من يوم',
        body: `يتوقّف في ${dateTime(iso)} — أقلّ من ‎٢٤ ساعة من الآن.`,
      });
    }
  } else if (limit === '') {
    checks.push({
      id: 'no-ceiling',
      level: 'advisory',
      title: 'رمز بلا سقف',
      body: 'لا لحظة انتهاء ولا حدّ استعمال — يبقى ساريًا في السلة حتى تنهيه بيدك.',
    });
  }

  if (editing !== null && editing.usageCount > 0 && code !== '' && code !== editing.code) {
    checks.push({
      id: 'code-renamed',
      level: 'advisory',
      field: 'coupon-code',
      title: 'تغيير رمز استُعمل في طلبات',
      body: `استُعمل ${editing.code} ${count(editing.usageCount)} مرّة — أي رابط أو إعلان يحمل النصّ القديم يتوقّف عن العمل بعد الحفظ.`,
    });
  }

  return checks;
}

/**
 * الشروط التي تُقيّمها السلة فعلًا (CouponRules::usable و::usableFor).
 * ما لا يُقيَّم لا يُعرض: شرطٌ تتجاهله السلة أسوأ من شرط غائب.
 */
export function conditionsOf(form: Form): { op: string; text: string; on: boolean }[] {
  const iso = localInputToIso(form.expiresAt);
  const limit = form.usageLimit.trim();

  return [
    {
      op: 'حتى',
      on: typeof iso === 'string',
      text: typeof iso === 'string' ? `يتوقّف الرمز في ${dateTime(iso)}` : 'بلا لحظة انتهاء — يعمل حتى يُنهى بيدك',
    },
    {
      op: 'حدّ',
      on: limit !== '',
      text:
        limit !== ''
          ? `يتوقّف بعد ${count(Number(limit))} استعمالًا`
          : 'بلا حدّ استعمال — لا يتوقّف بالعدّ',
    },
    {
      op: 'حساب',
      on: form.firstOrderPerAccount,
      text: form.firstOrderPerAccount
        ? 'للطلب الأوّل لكل حساب فقط — يُفحص بالبريد عند الدفع'
        : 'لكل الطلبات — لا فحص على تاريخ الحساب',
    },
  ];
}
