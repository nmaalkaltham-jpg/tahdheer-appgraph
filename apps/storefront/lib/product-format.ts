/**
 * صيغ الملفّ لمنتجٍ واحد — القاعدة التي تمنع صفحة المنتج من الكذب.
 *
 * `products.format` عمودٌ واحد وُضع حين كان المنتج صيغةً واحدة. ومنذ صار
 * المنتج مجموعةً تحتها صيغ (Word · PowerPoint · PDF · الحزمة)، صار ذلك
 * العمود **واحدةً منها لا الحقيقة كلّها**: فجملة «ملف DOCX قابل للتعديل»
 * تحت مُبدِّلٍ اختار منه الزائر PowerPoint كذبٌ صريح — وقد ظهر فعلًا على
 * الصفحة قبل أن يُصلَح.
 *
 * وحدةٌ مستقلّة لا دالّتان داخل الصفحة: الصفحة مكوّن خادم بـJSX لا يُستورد
 * في اختبار، والقاعدة أهمّ من أن تبقى بلا اختبار.
 */

/** أقلّ ما تحتاجه القاعدة من شكل المنتج — لا الـDTO كاملًا. */
export interface FormatSource {
  format?: string;
  variants?: { format?: string | null }[] | null;
}

/**
 * الصيغ التي يأتي بها المنتج فعلًا، بلا تكرار وبترتيب العرض.
 *
 * الصيغ تغلب العمود القديم حين توجد؛ والعمود يبقى للمنتجات التي لم تدخل
 * النموذج الكونيّ بعد. وصيغةٌ بلا امتداد مسجَّل تُسقَط: «مجهول» لا يُعرض
 * ولا يُخترع له اسم.
 */
export function formatsOf(product: FormatSource): string[] {
  const fromVariants = (product.variants ?? [])
    .map((variant) => variant.format?.trim())
    .filter((format): format is string => format != null && format !== '');

  if (fromVariants.length > 0) return [...new Set(fromVariants)];

  const single = product.format?.trim();

  return single != null && single !== '' ? [single] : [];
}

/**
 * الصيغة الوحيدة، أو `null` حين يحمل المنتج أكثر من واحدة.
 *
 * `null` يُسكِت كلّ ادّعاءٍ بصيغةٍ بعينها خارج المُبدِّل: هو وحده يعرف ما
 * اختاره الزائر، وهو وحده من يحقّ له تسميتها.
 */
export function singleFormat(product: FormatSource): string | null {
  /*
   * شرطان لا واحد: أن تُعرف صيغةُ **كلّ** خيار، وأن تكون واحدة.
   *
   * كان الحكم على `formatsOf` وحدها، فمنتجٌ بخيارين أحدهما بلا امتداد
   * مسجَّل يعطي قائمةً من عنصر — فتعود الصفحة إلى ادّعاء «ملف DOCX قابل
   * للتعديل» وتحته مُبدِّلٌ فيه خياران أحدهما قد لا يكون DOCX. وهو العطب
   * نفسه الذي وُجدت هذه الوحدة لمنعه، عاد من باب البيانات الناقصة.
   *
   * وخياران بالامتداد نفسه (نسختان بترخيصين مثلًا) يبقى ادّعاؤهما صادقًا:
   * كلاهما PDF فعلًا، والادّعاء عن الصيغة لا عن عدد الخيارات.
   */
  const variants = product.variants ?? [];
  const labelled = variants.filter(
    (variant) => variant.format != null && variant.format.trim() !== '',
  );

  if (variants.length > 0 && labelled.length !== variants.length) return null;

  const formats = formatsOf(product);

  return formats.length === 1 ? (formats[0] ?? null) : null;
}
