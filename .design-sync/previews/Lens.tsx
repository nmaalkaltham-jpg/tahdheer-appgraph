/**
 * Lens — مكبِّرةٌ على الصورة.
 *
 * ─── ما لا تُظهره اللقطة، ويُقال صراحةً ───
 *
 * المكبِّرة تتبع المؤشّر: بلا مؤشّرٍ لا دائرةَ تكبير. فالبطاقة تعرض **الحالة
 * الساكنة** — الصورة كما يراها الزائر قبل أن يقترب — وهي الحالة الصحيحة
 * الوحيدة التي تنتجها لقطة. وإظهار الدائرة بحيلةٍ كان سيعرض ما لا يصيّره
 * المكوّن بلا تفاعل.
 *
 * وما تُثبته البطاقة رغم ذلك حقيقيّ: أنّ الصورة تُصيَّر بنسبتها داخل إطار
 * المكوّن، وأنّ `size` و`zoom` خاصّيتان مضبوطتان لا ثابتتان.
 *
 * ─── والصورة مضمَّنة لا بعيدة ───
 *
 * متصفّح التحقّق معزولٌ بلا شبكة (مسجَّل في NOTES): أيّ `https://` يُصيَّر
 * مكسورًا ولا سبيل لإثبات العكس. فالغلاف هنا SVG حقيقيّ مضمَّن في العنوان
 * نفسه، بالألوان والنسبة اللتين يستعملهما الكتالوج (1200×900).
 */

import { Lens } from '@tahdheer/ui';

/** غلافٌ مضمَّن — نفس نسبة أغلفة الكتالوج، بلا أيّ نداء شبكة. */
const COVER = `data:image/svg+xml;utf8,${encodeURIComponent(
  `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1200 900" width="1200" height="900">
    <defs><linearGradient id="g" x1="0" y1="0" x2="1" y2="1">
      <stop offset="0" stop-color="#eaf5ef"/><stop offset="1" stop-color="#ffffff"/>
    </linearGradient></defs>
    <rect width="1200" height="900" fill="url(#g)"/>
    <rect width="1200" height="14" fill="#1e7355"/>
    <g font-family="sans-serif" direction="rtl" text-anchor="start">
      <text x="1090" y="230" font-size="42" font-weight="700" fill="#1e7355" opacity="0.75">تحضير</text>
      <text x="1090" y="360" font-size="80" font-weight="900" fill="#14261d">الرياضيات</text>
      <text x="1090" y="470" font-size="44" font-weight="500" fill="#3b4d43">الصف الرابع الابتدائي</text>
    </g>
    <g transform="translate(110,690)">
      <rect rx="14" width="190" height="64" fill="#1e7355"/>
      <text x="95" y="42" font-family="ui-monospace, monospace" font-size="30" font-weight="700" fill="#fff" text-anchor="middle">DOCX</text>
    </g>
    <circle cx="150" cy="200" r="70" fill="#1e7355" opacity="0.10"/>
    <circle cx="255" cy="300" r="38" fill="#1e7355" opacity="0.07"/>
  </svg>`,
)}`;

const ALT = 'تحضير الرياضيات — الصف الرابع الابتدائي — الفصل الأول';

const cell: React.CSSProperties = { direction: 'rtl', maxWidth: 420 };

/** الافتراض — مكبِّرةٌ بقطرٍ وقوّةٍ متوسّطين، تظهر عند اقتراب المؤشّر. */
export function Default() {
  return (
    <div style={cell}>
      <Lens src={COVER} alt={ALT} />
    </div>
  );
}

/** تكبيرٌ أقوى بقطرٍ أصغر — لقراءة سطرٍ دقيق في ورقة عمل. */
export function StrongZoom() {
  return (
    <div style={cell}>
      <Lens src={COVER} alt={ALT} zoom={3} size={160} />
    </div>
  );
}

/** قطرٌ واسع بتكبيرٍ خفيف — لتفقّد التخطيط لا النصّ. */
export function WideSoft() {
  return (
    <div style={cell}>
      <Lens src={COVER} alt={ALT} zoom={1.6} size={280} />
    </div>
  );
}
