/**
 * PopupShell — «النافذة المنبثقة» (STUDIO-PHASE3-CONTRACT §2.7).
 *
 * ثلاثة قيود في العقد تحكم شكل هذه المعاينة، وليست خيارات أسلوب:
 *
 *  · **الأبناء لا يُركَّبون قبل التحفيز.** المكوّن يُعيد `null` حتى يفتح، فلا
 *    تُصيَّر خليّة ساكنة إلا بمحفّز يقع فورًا: `trigger: 'delay'` بـ
 *    `delaySec: 0`. (`scroll` يحتاج تمريرًا و`exit` يحتاج مؤشّرًا يغادر —
 *    كلاهما لا يقع في لقطة ساكنة، فلا يُعرَض هنا بدل تزييفه.)
 *  · **منبثقة واحدة مفتوحة في آن** — مزلاج على مستوى الوحدة. خليّتان في
 *    صفحة واحدة تُبقيان الأولى وحدها مفتوحة، فكل خليّة تُلتقط منفردة
 *    (`?story=`)، والبطاقة `cardMode: "single"` في `cfg.overrides`.
 *  · **الغشاء `position: fixed; inset: 0`** — لكنّ حاضنة المعاينة تحتويه،
 *    فيرسو على الغلاف لا على النافذة (انظر تعليق `rtl` أدناه).
 *
 * و`frequency: 'always'` في كل خليّة: السقوف تُخزَّن في localStorage تحت
 * `tah.popup.{pageKey}:{nodeId}`، فسقفٌ أدنى يجعل اللقطة الثانية فارغة.
 *
 * الحاضنة LTR، فكل خليّة ملفوفة بـ`dir="rtl"` — بدونها ترسو المنبثقة
 * معكوسةً عن المتجر الحقيقي.
 */

import { PopupShell, type PopupShellSettings } from '@tahdheer/ui';

/** إعداد يفتح فورًا وبلا سقف — الأساس المشترك لكل خليّة. */
function opensNow(extra: Partial<PopupShellSettings>): Partial<PopupShellSettings> {
  return { trigger: 'delay', delaySec: 0, frequency: 'always', ...extra };
}

/*
 * غلافٌ بارتفاع صريح — لا تجميل.
 *
 * الغشاء `position: fixed; inset: 0`، لكنّ حاضنة المعاينة تحتويه (سلف ذو
 * `transform`/`contain` يجعل المثبَّت يرسو عليه لا على النافذة). وكل أبناء
 * الخليّة داخل الغشاء نفسه، فالغلاف يبقى بلا محتوى في التدفّق وينهار إلى
 * صفر — فتُقصّ اللوحة إلى شريط لا يظهر منه إلا زرّ الإغلاق (قِسته في اللقطة
 * الأولى). ارتفاعٌ صريح يعطي الغشاء المحتوى مساحته، وهو نمط `ToastViewport`
 * نفسه في هذا المستودع (منطقة مثبَّتة الموضع كذلك).
 *
 * الأبعاد: 820 تسع أوسع لوحة (`lg` = 760) بهوامشها، و460 تسع أطول محتوى.
 */
const rtl: React.CSSProperties = {
  position: 'relative',
  minHeight: 460,
  minWidth: 820,
  direction: 'rtl',
};

const title: React.CSSProperties = {
  margin: 0,
  fontSize: 'var(--tp-h3-size)',
  fontWeight: 'var(--tp-h3-weight)' as React.CSSProperties['fontWeight'],
  lineHeight: 'var(--tp-h3-leading)',
  color: 'var(--text)',
};

const body: React.CSSProperties = {
  margin: 'var(--space-3) 0 0',
  fontSize: 'var(--tp-body-size)',
  lineHeight: 'var(--tp-body-leading)',
  color: 'var(--text-secondary)',
};

const cta: React.CSSProperties = {
  display: 'inline-block',
  marginBlockStart: 'var(--space-5)',
  padding: 'var(--space-3) var(--space-6)',
  borderRadius: 'var(--radius-md)',
  background: 'var(--green-700)',
  color: 'var(--bg)',
  fontSize: 'var(--tp-button-size)',
  fontWeight: 'var(--tp-button-weight)' as React.CSSProperties['fontWeight'],
  textDecoration: 'none',
};

/** عرض متوسّط (540px) — عرض الحملة الموسمية، وهو الاستعمال الغالب. */
export function OfferPopup() {
  return (
    <div style={rtl}>
      <PopupShell
        nodeId="pop-offer"
        pageKey="preview-offer"
        settings={opensNow({
          width: 'md',
          ariaLabel: 'عرض الفصل الدراسي الثالث',
          dismissLabel: 'إغلاق العرض',
        })}
      >
        <h2 style={title}>خصم ١٥٪ على تحاضير الفصل الثالث</h2>
        <p style={body}>
          كل ملفّات الفصل الدراسي الثالث — تحضير وتوزيع وأوراق عمل — لجميع المراحل، بخصم ١٥٪ حتى
          نهاية الأسبوع.
        </p>
        <a style={cta} href="#offers">
          تصفّح عروض الفصل الثالث
        </a>
      </PopupShell>
    </div>
  );
}

/** عرض ضيّق (360px) — رسالة واحدة وفعل واحد. */
export function NarrowNotice() {
  return (
    <div style={rtl}>
      <PopupShell
        nodeId="pop-narrow"
        pageKey="preview-narrow"
        settings={opensNow({
          width: 'sm',
          ariaLabel: 'تنبيه تحديث المناهج',
          dismissLabel: 'إغلاق التنبيه',
        })}
      >
        <h2 style={title}>حُدِّثت مناهج الصف السادس</h2>
        <p style={body}>أُعيد رفع ملفّات الرياضيات والعلوم وفق طبعة هذا العام. التحديث مجّاني لمن اشترى سابقًا.</p>
        <a style={cta} href="#library">
          افتح مكتبتي
        </a>
      </PopupShell>
    </div>
  );
}

/** عرض واسع (760px) — إعلان بسطرين وقائمة نقاط. */
export function WideAnnouncement() {
  return (
    <div style={rtl}>
      <PopupShell
        nodeId="pop-wide"
        pageKey="preview-wide"
        settings={opensNow({
          width: 'lg',
          ariaLabel: 'إعلان الباقة السنوية',
          dismissLabel: 'إغلاق الإعلان',
        })}
      >
        <h2 style={title}>الباقة السنوية للمعلّم</h2>
        <p style={body}>
          اشتراك واحد يفتح كل التحاضير والتوازيع وأوراق العمل للمراحل الثلاث، طوال العام الدراسي.
        </p>
        <ul
          style={{
            margin: 'var(--space-4) 0 0',
            paddingInlineStart: 'var(--space-5)',
            color: 'var(--text-secondary)',
            fontSize: 'var(--tp-body-size)',
            lineHeight: 'var(--tp-body-leading)',
          }}
        >
          <li>تحديث مجّاني مع كل طبعة جديدة</li>
          <li>ملفّات قابلة للتحرير بصيغة Word وPowerPoint</li>
          <li>تحميل فوري بلا حدّ للمرّات</li>
        </ul>
        <a style={cta} href="#plans">
          اشترك في الباقة السنوية
        </a>
      </PopupShell>
    </div>
  );
}
