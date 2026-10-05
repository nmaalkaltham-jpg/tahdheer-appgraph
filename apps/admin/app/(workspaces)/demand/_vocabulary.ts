// UNIT: _VOCABULARY
// FILE: _vocabulary.ts
// STYLE: none
// PARENT: DEMAND-PANEL, PAGE-ANALYTICS-OS-ACTIONS
// USES: none
// API: none
// MAP: docs/PROJECT_MAP.md

/**
 * مفردات حالة الطلب الفائت — بيتٌ واحد لها.
 *
 * القيَم نفسها يملكها الخادم وحده (`MaterialRequest::STATUSES`، تصل في
 * `payload.statuses` وتتحقّق في `DemandController@index`)؛ هذا الملفّ لا
 * يعلن قائمةً موازية بل يترجم ما يصل. ولهذا القراءة تبقى `statuses` من
 * الاستجابة، و`?? key` يُبقي حالةً جديدة مقروءةً برمزها بدل أن تختفي.
 *
 * أُخرج من `page.tsx` إلى وحدةٍ مستقلّة ليقرأ منه كاتبٌ ثانٍ بدل أن يعيد
 * كتابته: بطاقة «قرار في أعلى طلب فائت» في
 * ‎/analytics/os/actions‎ تكتب اليوم `['planned', 'declined']` بنصٍّ خاصّ
 * بها، فافترقت التسمية أصلًا («مُخطَّط» هنا مقابل «مخطَّط له» هناك). نسختان
 * لمفردةٍ واحدة تفترقان أوّل مرّة تُحدَّث إحداهما — كما هو حال
 * `growth/_governance.ts` الذي يقرأ منه ‎/analytics/os/flows‎.
 */

/** `all` مفتاح تصفيةٍ لا حالة صفّ — يُعرض في شريط التبويب وحده. */
export const DEMAND_STATUS_LABELS: Record<string, string> = {
  all: 'الكل',
  new: 'بلا قرار',
  planned: 'مُخطَّط',
  published: 'نُشر',
  declined: 'مرفوض',
};
