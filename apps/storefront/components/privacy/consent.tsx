'use client';
/**
 * شريط الموافقة على القياس — الواجهة التي تُنشئ المعرّف، وتمحوه.
 *
 * ─── لماذا يُصيَّر في العميل ───
 *
 * قراءةُ الكعكة في التخطيط الجذري تُخرج **كلّ** صفحةٍ في المتجر من التصيير
 * المسبق: التخطيط يصير ديناميكيًّا، فصفحة المنتج التي تُبنى مرّة تُبنى لكلّ
 * زائر. ثمنُ ذلك أغلى بكثير من ومضةٍ قصيرة يظهر فيها الشريط بعد الترطيب —
 * والشريط ليس محتوى الصفحة أصلًا.
 *
 * ─── وما لا يظهر ───
 *
 * من أرسل متصفّحه إشارة رفضٍ لا يُسأل: السؤال يوحي بأنّ في الموقع إعدادًا
 * يتجاوز الإشارة، والسياسة تقول إنّه لا يوجد. ومن قرّر سابقًا لا يُسأل
 * ثانيةً — قرارُه في كعكةٍ اسمها `tahdheer_consent`، وهي «ضرورية» في
 * سياسة الكوكيز المنشورة لهذا السبب بالذات.
 *
 * ─── والنصّ يأتي من الخادم ───
 *
 * لا يُكتب هنا ولا يُترجم هنا. عمود `wording_shown` في سجلّ الموافقة دليلُ
 * امتثال، وقيمتُه أن يكون **ما عُرض فعلًا**. ونسخةٌ في العميل ونسخةٌ في
 * الخادم تفترقان بتحريرٍ واحد، فيصير السجلّ يشهد بما لم يُعرض.
 */
import { useEffect, useState } from 'react';
import { apiBase } from '../../lib/api';
import { refusesTracking } from '../../lib/telemetry';
import styles from './consent.module.css';

/** الحدث الذي يفتح الشريط من «إعدادات الخصوصية» في التذييل. */
const OPEN_EVENT = 'tahdheer:privacy-settings';

/** اسم كعكة القرار — مطابقٌ لـ`VisitorIdentity::CONSENT_COOKIE`. */
const DECISION_COOKIE = 'tahdheer_consent';

interface ConsentCopy {
  title: string;
  wording: string;
  granted: boolean | null;
  refusedBySignal: boolean;
}

export function ConsentBanner() {
  const [copy, setCopy] = useState<ConsentCopy | null>(null);
  /*
   * الفتح يقع في حالتين: زائرٌ لم يُسأل بعد، أو زائرٌ طلب مراجعة قراره من
   * التذييل. والثانية تتجاوز الكعكة عمدًا — «من رابط إعدادات الخصوصية، في
   * أي وقت» وعدٌ منشور.
   *
   * والأولى تُحسم في القيمة الابتدائيّة لا في أثر: ضبطُ الحالة داخل الأثر
   * يفرض تصييرًا ثانيًا بلا فائدة. ولا اختلاف في الترطيب: أوّلُ تصييرٍ يردّ
   * `null` في الحالتين ما دام `copy` فارغًا — النصّ يأتي من الخادم بعدُ.
   */
  const [open, setOpen] = useState(
    () => typeof window !== 'undefined' && readDecision() === null && !refusesTracking(),
  );
  /* القرار الذي ينتظر ردّ الخادم (‎true‎ موافقة · ‎false‎ رفض)، أو ‎null‎. */
  const [busy, setBusy] = useState<boolean | null>(null);

  useEffect(() => {
    const onRequest = () => setOpen(true);
    window.addEventListener(OPEN_EVENT, onRequest);
    return () => window.removeEventListener(OPEN_EVENT, onRequest);
  }, []);

  useEffect(() => {
    if (!open || copy !== null) return;

    const base = apiBase();
    if (base === null) return;

    let live = true;
    fetch(`${base}/api/v1/consent`, { credentials: 'include', headers: { Accept: 'application/json' } })
      .then((res) => (res.ok ? res.json() : null))
      .then((body: { data?: ConsentCopy } | null) => {
        if (!live || !body?.data) return;
        /*
         * إشارة الرفض تُغلق الشريط ولو فُتح يدويًّا: لا قرار يُتّخذ هنا
         * يستطيع تجاوزها، فعرضُ زرّ «أوافق» يكذب على الزائر.
         */
        if (body.data.refusedBySignal) {
          setOpen(false);
          return;
        }
        setCopy(body.data);
      })
      .catch(() => {
        // تعذّر جلب النصّ: لا شريط. سؤالٌ بلا نصٍّ ليس موافقةً مستنيرة.
        if (live) setOpen(false);
      });

    return () => {
      live = false;
    };
  }, [open, copy]);

  if (!open || copy === null) return null;

  const decide = (granted: boolean) => {
    const base = apiBase();
    if (base === null) return;

    setBusy(granted);
    fetch(`${base}/api/v1/consent`, {
      method: 'POST',
      credentials: 'include',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
      body: JSON.stringify({ granted }),
    })
      .then((res) => {
        /*
         * الإغلاق بعد نجاح الخادم لا قبله: إغلاقٌ متفائل يُخفي الشريط بينما
         * لا كعكة ولا سطر — فيعود السؤال في الصفحة التالية بلا تفسير،
         * والأسوأ أن يظنّ الزائر أنّه رفض وهو لم يُسجَّل له رفض.
         */
        if (res.ok) setOpen(false);
      })
      .catch(() => undefined)
      .finally(() => setBusy(null));
  };

  return (
    <aside className={styles.bar} data-bottom-chrome="base" role="dialog" aria-modal="false" aria-labelledby="consent-title">
      <div className={styles.inner}>
        <div className={styles.text}>
          <h2 className={styles.title} id="consent-title">
            {copy.title}
          </h2>
          <p className={styles.body}>{copy.wording}</p>
        </div>
        <div className={styles.actions}>
          {/*
            الرفض أوّلًا وبوزنٍ بصريٍّ مساوٍ: زرّ رفضٍ باهتٌ بجانب زرّ قبولٍ
            بارز نمطُ ضغطٍ معروف، والموافقة المنتزَعة ليست موافقة.
          */}
          <button
            type="button"
            className={styles.decline}
            disabled={busy !== null}
            aria-busy={busy === false}
            onClick={() => decide(false)}
          >
            <span>أرفض</span>
          </button>
          <button
            type="button"
            className={styles.accept}
            disabled={busy !== null}
            aria-busy={busy === true}
            onClick={() => decide(true)}
          >
            <span>أوافق</span>
          </button>
        </div>
      </div>
    </aside>
  );
}

/**
 * رابط «إعدادات الخصوصية» في التذييل.
 *
 * زرٌّ لا رابط: لا وجهة له — يفتح الشريط في مكانه. ورابطٌ إلى `#` كذبٌ على
 * قارئ الشاشة وعلى من يفتحه في لسانٍ جديد.
 */
export function PrivacySettingsLink({ className }: { className?: string }) {
  return (
    <button
      type="button"
      className={className}
      onClick={() => window.dispatchEvent(new CustomEvent(OPEN_EVENT))}
    >
      إعدادات الخصوصية
    </button>
  );
}

/** `null` = لم يُسأل بعد. وهو ليس رفضًا: الأوّل يَسأل والثاني يصمت. */
function readDecision(): boolean | null {
  if (typeof document === 'undefined') return null;

  const match = document.cookie.match(new RegExp(`(?:^|; )${DECISION_COOKIE}=([^;]*)`));
  if (match === null) return null;

  return match[1] === 'granted';
}
