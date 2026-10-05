/**
 * SpotlightLayer — ضوءٌ يتبع المؤشّر فوق المحتوى.
 *
 * ─── حاويةٌ لا زخرفة: الأبناء هم البطاقة ───
 *
 * العقد صريح: «حاويةٌ بلا أبناء تُصيَّر فارغة، والمحرّر يحذّر منها». فكلّ
 * خليّةٍ أدناه تلفّ محتوًى حقيقيًّا — لا مستطيلًا فارغًا يُثبت اللون ولا
 * يُثبت الاستعمال.
 *
 * ─── وما لا تُظهره اللقطة ───
 *
 * موضع الضوء يأتي من المؤشّر (`--sl-x` / `--sl-y` تُضبطان عند الحركة). بلا
 * مؤشّرٍ تبقى البقعة في موضعها الابتدائيّ، وهو ما يراه الزائر قبل أن يقترب.
 * الخلايا تُثبت ما يمكن إثباته ساكنًا: أنّ الأبناء يُصيَّرون فوق الطبقة،
 * وأنّ `color` و`opacity` و`size` تُغيّر الضوء فعلًا.
 *
 * ─── واللون رمزٌ لا قيمة مكتوبة ───
 *
 * الافتراض `var(--green-700)` — رمز العلامة. والخليّة الذهبيّة تمرّر رمزًا
 * آخر من النظام نفسه، لا سداسيًّا مخترَعًا: لونٌ يُكتب بيده يخرج عن السمة
 * عند أوّل تبديلٍ لها.
 */

import { SpotlightLayer } from '@tahdheer/ui';

/** محتوًى حقيقيّ داخل الحاوية — دعوةٌ كالتي تحملها الرئيسة. */
function Invite() {
  return (
    <div style={{ display: 'grid', gap: 10, padding: '28px 26px', textAlign: 'start' }}>
      <span style={{ fontSize: 12.5, fontWeight: 800, color: 'var(--green-700, #1e7355)' }}>
        التحاضير الحديثة
      </span>
      <b style={{ fontSize: 22, lineHeight: 1.5, color: 'var(--ink, #14261d)' }}>
        تحضيرٌ جاهزٌ لحصّتك — بصيغةٍ تختارها أنت
      </b>
      <p style={{ margin: 0, fontSize: 13.5, lineHeight: 1.9, color: 'var(--text-secondary, #5b6b62)' }}>
        ضيّق بالمرحلة والصفّ والمادّة، واستلِم الملفّ فور إتمام الدفع.
      </p>
    </div>
  );
}

const cell: React.CSSProperties = { direction: 'rtl', maxWidth: 480 };

/** الافتراض — ضوءٌ بلون العلامة بشدّةٍ خفيفة. */
export function Default() {
  return (
    <div style={cell}>
      <SpotlightLayer>
        <Invite />
      </SpotlightLayer>
    </div>
  );
}

/** ذهبيّ أوسع — رمزٌ ثانٍ من النظام، لا لونٌ مكتوب بيده. */
export function GoldWide() {
  return (
    <div style={cell}>
      <SpotlightLayer color="var(--gold-600, #c99700)" size={460} opacity={0.22}>
        <Invite />
      </SpotlightLayer>
    </div>
  );
}

/** ضوءٌ ضيّقٌ خافت — حين تكون الحاوية بطاقةً صغيرة لا قسمًا كاملًا. */
export function TightSubtle() {
  return (
    <div style={{ ...cell, maxWidth: 320 }}>
      <SpotlightLayer size={180} opacity={0.1}>
        <Invite />
      </SpotlightLayer>
    </div>
  );
}
