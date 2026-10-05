/**
 * قواعد حارس مصائد الاستعلام — دوالّ خالصة مختبَرة (scripts/__tests__).
 *
 * ─── لماذا خرجت من الحارس ───
 *
 * كان الكشف تعبيرًا نمطيًّا واحدًا بمكمِّمٍ كسولٍ داخل تكرار:
 *   DB::table\([^)]*\)\s*(?:->[a-zA-Z]+\([^;]*?\))*?\s*->whereKey\s*\(
 * و`[^;]*?` يعبر الأقواس، فسلسلةُ بانٍ طويلة بلا `;` تُنتج تراجعًا أُسّيًّا.
 * ملفٌّ مشروع واحد (استعلامات صحّة الدفع) علّق الحارس أكثر من عشرين دقيقة
 * — أي أنّ الحارس كان يستطيع ألّا يُبلّغ أبدًا.
 *
 * الآن مسحٌ خطّيّ: من كلّ `DB::table(` إلى نهاية العبارة (`;` خارج نصٍّ
 * حرفيّ)، ثمّ بحثٌ عن `->whereKey(` فيها. القاعدة نفسها وأشدّ: `whereKey`
 * داخل مُغلِقٍ على البانِي الخامّ خطأٌ أيضًا (`Query\Builder` لا يعرفها).
 */

/** نهاية العبارة التي تبدأ عند `from`: أوّل `;` خارج نصٍّ حرفيّ. */
function statementEnd(src, from) {
  let quote = null;
  for (let i = from; i < src.length; i++) {
    const c = src[i];
    if (quote !== null) {
      if (c === '\\') i++;
      else if (c === quote) quote = null;
      continue;
    }
    if (c === "'" || c === '"') quote = c;
    else if (c === ';') return i;
  }
  return src.length;
}

/**
 * مواضع `whereKey` على بانٍ خامّ (`DB::table(...)`) — إزاحاتٌ في النصّ.
 *
 * @param {string} src
 * @returns {number[]}
 */
export function whereKeyOnRawBuilder(src) {
  const hits = [];
  let at = src.indexOf('DB::table(');
  while (at !== -1) {
    const end = statementEnd(src, at);
    const statement = src.slice(at, end);
    const k = statement.search(/->whereKey\s*\(/);
    if (k !== -1) hits.push(at + k);
    at = src.indexOf('DB::table(', end);
  }
  return hits;
}
