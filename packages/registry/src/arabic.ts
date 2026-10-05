// UNIT: ARABIC
// FILE: arabic.ts
// STYLE: none
// PARENT: REGISTRY-INDEX
// USES: none
// API: none
// MAP: docs/PROJECT_MAP.md

/**
 * تطبيع العربيّة للمطابقة — مرآة TypeScript لـ`App\Domain\Search\ArabicNormalizer`.
 *
 * ─── الفجوة المقيسة ───
 *
 * بحث الأطلس كان `toLowerCase().includes()` على نصٍّ عربيّ محرَّر. قياسٌ على
 * الفهرس الحقيقيّ (٩٦ صفًّا، ٦٤٩ صيغة إملائيّة مولَّدة من كلماته نفسها):
 * **٦٤٨ منها تُرجع صفرًا**. «بطاقة» تجد و«بطاقه» لا تجد، و«اشتراك» تجد
 * و«أشتراك» لا تجد. ليست ندرة استرجاع — هي غياب تامّ.
 *
 * ─── لماذا مرآة لا تطبيقٌ ثانٍ ───
 *
 * الخلفيّة تطبّع منذ البداية بجداولها الثلاثة. مطبِّعٌ ثانٍ مكتوبٌ بالحدس
 * يعني أنّ استعلامًا واحدًا يُطابَق بقاعدتين مختلفتين حسب السطح الذي أدخِل
 * فيه — وهو عيبٌ لا يُكتشف إلّا حين يشتكي مستعمل. لذا الجداول منقولة
 * حرفًا بحرف، والانحراف في أحد الطرفين عطبٌ صريح.
 *
 * محافظٌ عمدًا كالأصل: لا جذوع ولا نزع «ال». المتجر يبيع ملفّات مسمّاة،
 * فالدقّة أوجب من الاسترجاع.
 */

/** التشكيل والتطويل وعلامات العرض الصفريّ — تُحذف. */
const STRIP = [
  'ً', // ً  تنوين فتح
  'ٌ', // ٌ  تنوين ضمّ
  'ٍ', // ٍ  تنوين كسر
  'َ', // َ  فتحة
  'ُ', // ُ  ضمّة
  'ِ', // ِ  كسرة
  'ّ', // ّ  شدّة
  'ْ', // ْ  سكون
  'ٓ', // ٓ  مدّة علويّة
  'ٔ', // ٔ  همزة علويّة
  'ٕ', // ٕ  همزة سفليّة
  'ٖ', // ٖ  ألف تحتيّة
  'ٗ', // ٗ  ضمّة مقلوبة
  'ٰ', // ٰ  ألف خنجريّة
  'ـ', // ـ  تطويل
  '​', // مسافة صفريّة العرض
  '‌', // فاصل صفريّ العرض
  '‍', // واصل صفريّ العرض
  '﻿', // علامة ترتيب البايتات
] as const;

/** طيّ الحروف — يُطبَّق بعد حذف التشكيل. */
const FOLD: Readonly<Record<string, string>> = Object.freeze({
  // صور الألف ← ألف مجرّدة.
  أ: 'ا',
  إ: 'ا',
  آ: 'ا',
  ٱ: 'ا',
  ٲ: 'ا',
  ٳ: 'ا',
  // الياء والألف المقصورة ← ياء.
  ى: 'ي',
  ئ: 'ي',
  ۍ: 'ي',
  ې: 'ي',
  ی: 'ي',
  // الواو المهموزة ← واو.
  ؤ: 'و',
  // التاء المربوطة ← هاء، فتلتقي «مادة» و«ماده».
  ة: 'ه',
  // صور الكاف والهاء على لوحات فارسيّة وأرديّة.
  ک: 'ك',
  ھ: 'ه',
  ہ: 'ه',
});

/** الأرقام العربيّة والفارسيّة ← لاتينيّة. */
const DIGITS: Readonly<Record<string, string>> = Object.freeze({
  '٠': '0', '١': '1', '٢': '2', '٣': '3', '٤': '4',
  '٥': '5', '٦': '6', '٧': '7', '٨': '8', '٩': '9',
  '۰': '0', '۱': '1', '۲': '2', '۳': '3', '۴': '4',
  '۵': '5', '۶': '6', '۷': '7', '۸': '8', '۹': '9',
});

/** ترقيمٌ يُعامَل فاصلَ كلمات. */
const SEPARATORS = [
  '،', '؛', '؟', '«', '»', '“', '”', '‘', '’', '—', '–', '_', '/', '\\', '|',
  '(', ')', '[', ']', '{', '}', ':', ';', '!', '?', '"', "'", ',', '.', '+',
  '*', '#', '&', '%', '@', '=', '<', '>', '~', '^', '$',
] as const;

/** الجداول الثلاثة مكشوفة للحارس وحده — لا تُستعمل في الواجهة. */
export const ARABIC_TABLES = Object.freeze({
  strip: STRIP,
  fold: FOLD,
  digits: DIGITS,
  separators: SEPARATORS,
});

const escapeForClass = (chars: readonly string[]) =>
  chars.map((c) => c.replace(/[\\\]^-]/g, '\\$&')).join('');

const STRIP_RE = new RegExp(`[${escapeForClass([...STRIP])}]`, 'gu');
const FOLD_RE = new RegExp(`[${escapeForClass(Object.keys(FOLD))}]`, 'gu');
const DIGITS_RE = new RegExp(`[${escapeForClass(Object.keys(DIGITS))}]`, 'gu');
const SEPARATORS_RE = new RegExp(`[${escapeForClass([...SEPARATORS])}]`, 'gu');

/**
 * يُطبّع نصًّا للمقارنة. المستدعي يحتفظ بالنصّ الخامّ للعرض؛ هذه القيمة
 * للمطابقة ومفاتيح الذاكرة لا غير.
 */
export function normalizeArabic(text: string): string {
  if (text === '') return '';

  return text
    // NFC أوّلًا فتتّفق الصورتان المركّبة والمفكّكة.
    .normalize('NFC')
    .replace(STRIP_RE, '')
    .replace(FOLD_RE, (c) => FOLD[c] ?? c)
    .replace(DIGITS_RE, (c) => DIGITS[c] ?? c)
    .replace(SEPARATORS_RE, ' ')
    // خفضٌ لاتينيّ لا يمسّ العربيّة.
    .toLowerCase()
    .replace(/\s+/gu, ' ')
    .trim();
}

/** يقسم النصّ المطبَّع إلى رموزٍ فريدة محفوظة الترتيب. */
export function tokenizeArabic(text: string, min = 2, limit = 10): string[] {
  const normalized = normalizeArabic(text);
  if (normalized === '') return [];

  const tokens: string[] = [];
  for (const part of normalized.split(/\s+/u)) {
    if (part === '' || [...part].length < min) continue;
    if (!tokens.includes(part)) tokens.push(part);
    if (tokens.length >= limit) break;
  }
  return tokens;
}
