/**
 * BeamBackdrop — خلفيّةُ أشعّةٍ بطيئة خلف المحتوى.
 *
 * ─── خلفيّةٌ لمحتوًى، لا لوحةٌ بذاتها ───
 *
 * `children` هي الغرض: الأشعّة تعيش **خلف** شيءٍ يُقرأ. فكلّ خليّةٍ تلفّ
 * محتوًى حقيقيًّا — بطاقةٌ فارغة كانت ستُثبت أنّ الحركة تعمل ولا تُثبت أنّ
 * المكوّن صالحٌ للاستعمال.
 *
 * ─── وما لا تُظهره اللقطة ───
 *
 * الحركة دورةٌ كاملة مدّتها `duration` ثانية (١٨ افتراضًا). اللقطة إطارٌ
 * واحد منها: تُثبت التدرّج والشدّة والتركيب، لا الدوران. و`prefers-reduced-motion`
 * يوقف الحركة في النظام كلّه — فالإطار الساكن هو أيضًا ما يراه من طلب تقليل
 * الحركة.
 *
 * ─── و`intensity` هو المقياس الذي يُختبَر هنا ───
 *
 * ثلاث خلايا بثلاث شدّات: الخافتة لخلفيّة قسمٍ طويل، والمتوسّطة هي الافتراض،
 * والقويّة لرأس صفحةٍ قصير. الفرق بينها يُقرأ في اللقطة — وهو ما يجعل هذه
 * الخلايا ذات معنى بعكس ما لو سُحب `duration` وحده.
 */

import { BeamBackdrop } from '@tahdheer/ui';

/** رأسُ قسمٍ حقيقيّ — هو ما توجد الخلفيّة لأجله. */
function Headline({ note }: { note: string }) {
  return (
    <div style={{ display: 'grid', gap: 10, padding: '34px 28px', textAlign: 'center' }}>
      <b style={{ fontSize: 24, lineHeight: 1.5, color: 'var(--ink, #14261d)' }}>
        كلّ ما تحتاجه لحصّتك القادمة
      </b>
      <p style={{ margin: 0, fontSize: 13.5, lineHeight: 1.9, color: 'var(--text-secondary, #5b6b62)' }}>
        {note}
      </p>
    </div>
  );
}

const cell: React.CSSProperties = { direction: 'rtl', maxWidth: 520 };

/** الافتراض — شدّةٌ متوسّطة ودورةٌ بطيئة (١٨ ثانية). */
export function Default() {
  return (
    <div style={cell}>
      <BeamBackdrop>
        <Headline note="تحاضير وعروض وأوراق عمل، مصنّفة بالمنهج السعوديّ." />
      </BeamBackdrop>
    </div>
  );
}

/** خافتة — لخلفيّة قسمٍ طويل يُقرأ فيه نصٌّ كثير. */
export function Subtle() {
  return (
    <div style={cell}>
      <BeamBackdrop intensity={0.2} duration={26}>
        <Headline note="الأشعّة هنا تكاد لا تُرى — النصّ هو البطل." />
      </BeamBackdrop>
    </div>
  );
}

/** قويّة وأسرع — لرأس صفحةٍ قصير يُنظَر إليه لحظةً. */
export function Intense() {
  return (
    <div style={cell}>
      <BeamBackdrop intensity={0.85} duration={10}>
        <Headline note="شدّةٌ عالية ودورةٌ أقصر — لرأسٍ قصير لا لقسمٍ يُقرأ." />
      </BeamBackdrop>
    </div>
  );
}
