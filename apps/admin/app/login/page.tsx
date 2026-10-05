'use client';
// UNIT: PAGE-LOGIN
// FILE: page.tsx
// ROUTE: /login
// STYLE: none
// PARENT: none
// USES: ADMIN-SESSION
// API: none
// API-VIA: ADMIN-SESSION
// MAP: docs/PROJECT_MAP.md

/**
 * دخول المشغّل. النموذج يرسل الاعتماديات إلى /admin/auth/login بكوكي الجلسة
 * (credentials: include)؛ الخادم وحده يقرّر، والرسالة المعروضة هي رسالته
 * العربية حرفيًا — لا رسائل مُخترعة ولا تلميح لأي حقل أخفق.
 *
 * حدثان مختلفان لا يجوز أن يُقرآ سواءً:
 *   · إخفاق محاولةٍ **يدويّة** — المشغّل كتب شيئًا فرُفض: خطأ أحمر
 *     `role="alert"` بنصّ الخادم حرفيًّا، كما كان.
 *   · إخفاق الدخول التلقائيّ للتطوير — لم يفعل المشغّل شيئًا أصلًا:
 *     ملحوظة هادئة `role="status"` تقول ما جرى فعلًا، ونصّ الخادم فيها
 *     مقتبسٌ منسوبٌ إليه لا اتّهامًا للمشغّل. عرض «بيانات الدخول غير
 *     صحيحة» لمن لم يكتب حرفًا يتّهمه بخطأٍ لم يقع، ويطمس السبب الحقيقيّ
 *     (ميزة تطوير مضبوطة ومخفقة).
 */

import { useEffect, useRef, useState, type FormEvent } from 'react';
import { useSession } from '../../components/admin-session';

/*
 * دخول تلقائي للتطوير المحلّي فقط — بحاجزين مستقلّين يستحيل اجتماعهما في
 * الإنتاج:
 *   ١ · `process.env.NODE_ENV !== "production"` — بناء الإنتاج يستبدلها
 *       بالثابت "production" وقت الحزم، فالفرع كلّه يُحذف من الحزمة
 *       المنشورة (dead-code elimination). لا يصل كود الدخول التلقائي
 *       متصفّح أي زائر إطلاقًا.
 *   ٢ · `NEXT_PUBLIC_DEV_AUTOLOGIN` — يُضبط في .env.local وحده، وهو
 *       متجاهَل في git ولا وجود له في صور الإنتاج.
 * ولا يتجاوز هذا المصادقة: يملأ النموذج ويرسله إلى نفس نقطة النهاية،
 * والخادم وحده يقرّر كما هو الحال دائمًا.
 */
const DEV_AUTOLOGIN =
  process.env.NODE_ENV !== 'production' && process.env.NEXT_PUBLIC_DEV_AUTOLOGIN === '1';
/*
 * تحت حاجز NODE_ENV نفسه: القراءة العارية كانت تُضمَّن القيمة في حزمة
 * الإنتاج لو وُجد المتغيّر وقت البناء. الفرع الميّت يُحذف بقيمته.
 */
const DEV_EMAIL = process.env.NODE_ENV !== 'production' ? (process.env.NEXT_PUBLIC_DEV_EMAIL ?? '') : '';
const DEV_PASSWORD = process.env.NODE_ENV !== 'production' ? (process.env.NEXT_PUBLIC_DEV_PASSWORD ?? '') : '';

export default function LoginPage() {
  const { signIn, state } = useSession();
  const [email, setEmail] = useState(DEV_AUTOLOGIN ? DEV_EMAIL : '');
  const [password, setPassword] = useState(DEV_AUTOLOGIN ? DEV_PASSWORD : '');
  /*
   * إخفاقٌ واحدٌ معروضٌ في كلّ لحظة، ومعه **مصدره**: `auto` محاولةٌ لم يطلبها
   * المشغّل، و`manual` نتيجة ما كتبه بيده. حالةٌ واحدة لا حالتان متوازيتان،
   * فالحصر بينهما يصير بنيويًّا لا مرهونًا بتذكّر تصفير الأخرى.
   */
  type Notice = { source: 'auto' | 'manual'; message: string };
  const [notice, setNotice] = useState<Notice | null>(null);
  const [pending, setPending] = useState(false);

  async function onSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setPending(true);
    // المحاولة اليدويّة تُلغي ملحوظة المحاولة التلقائيّة: انتهى حديثها.
    setNotice(null);
    const result = await signIn(email, password);
    if (!result.ok) {
      setNotice({ source: 'manual', message: result.message });
      setPending(false);
    }
    // النجاح: مزوّد الجلسة يحوّل إلى اللوحة، فلا نُطفئ حالة الانتظار.
  }

  /*
   * محاولة واحدة فقط (`tried`): لو رفض الخادم الاعتماديات المحلّية بقيت
   * الملحوظة معروضة بنصّ ردّه — لا حلقة إعادة محاولة تُخفي السبب.
   */
  const tried = useRef(false);
  // «تعديل الحالة أثناء الرسم»: يُثبَّت بدء المحاولة هنا، والإرسال نفسه في الأثر أدناه.
  const [autoStarted, setAutoStarted] = useState(false);
  if (DEV_AUTOLOGIN && DEV_EMAIL !== '' && DEV_PASSWORD !== '' && state.status === 'anonymous' && !autoStarted) {
    setAutoStarted(true);
  }
  useEffect(() => {
    if (!autoStarted || tried.current) return;
    tried.current = true;
    void signIn(DEV_EMAIL, DEV_PASSWORD).then((result) => {
      if (result.ok) return;
      setNotice({ source: 'auto', message: result.message });
      /*
       * نظافة النموذج بعد إخفاق تلقائيّ: كلمة المرور المرفوضة تُمسح فلا يبقى
       * سرٌّ ثبت رفضه جاهزًا لنقرةٍ تُعيد الإخفاق نفسه. والبريد يبقى: ليس سرًّا،
       * ويقول أيّ هويّة جُرِّبت — المقايضة المرفوضة هي مسح الحقلين معًا، إذ
       * تحذف السياق الوحيد الذي يشرح ما جرى.
       */
      setPassword('');
    });
  }, [autoStarted, signIn]);
  /*
   * طيرانان: اليدويّ يعلنه `pending`، والتلقائيّ لا علم له — فهو طائرٌ ما دام
   * قد انطلق ولم يُبلَّغ عنه شيء بعد. النجاح يحوّل الصفحة فلا تُطفأ الحالة.
   * وشرطُ انتهائه هو `notice` نفسه، لا راية مستقلّة: مسودّةٌ سابقة جعلت له
   * `autoFailure` خاصًّا تمسحه المحاولة اليدويّة عند انطلاقها، فبقي الزرّ
   * «جارٍ التحقّق…» معطّلًا أبدًا بعد أوّل إخفاقٍ يدويّ (قِيس حيًّا: disabled=true
   * مع ظهور رسالة الاعتماديّات). الحالة الموحّدة تُبطل الفخّ من أصله.
   */
  const busy = pending || (autoStarted && notice === null);

  return (
    <main className="login-page">
      <form className="login-card" onSubmit={onSubmit} noValidate>
        <div className="login-brand">
          <strong>التحاضير الحديثة</strong>
          <small>لوحة التشغيل</small>
        </div>

        <h1 className="login-title">تسجيل الدخول</h1>

        {/*
          * ملحوظة المحاولة التلقائيّة. ثلاث مقايضات مقصودة:
          *   · `role="status"` (مهذّب) لا `role="alert"` (حازم): حالةٌ متوقّعة
          *     في بيئة تطوير لا إنذارٌ يقطع على قارئ الشاشة ما يقرأ. تبقى
          *     مُعلَنة، فالمطلوب تغيير النبرة لا إخفاء الخبر.
          *   · تُركَّب المنطقة فارغةً مع بدء المحاولة لا مع وصول النتيجة، كي
          *     يحلّ النصّ في منطقةٍ حيّةٍ **قائمة** — إدراج منطقةٍ مهذّبة ونصّها
          *     معًا إعلانُه غير مضمون. ثمنها المقيس: فقرة بارتفاع صفر تستهلك
          *     فجوة flex واحدة = ‏16px (‏globals.css:964)، تبقى ما بقيت الصفحة
          *     لا أثناء الطيران وحده — قِيس حيًّا ٤٦px بين عنوان الصفحة
          *     والتنبيه بدل ٢٩px. ثمنٌ في بناء التطوير وحده، لأنّ
          *     `autoStarted` مستحيلٌ في الإنتاج (انظر الحاجزين أعلاه)،
          *     والمقايضة المرفوضة هي التركيب المشروط: يوفّر الفجوة ويقامر
          *     بألّا يُعلَن الخبر أصلًا.
          *   · `.login-note` لا صنفٌ جديد: لا أملك globals.css، والملفّ
          *     `STYLE: none` — وإضافة وحدة أنماط تستوجب `npm run docs`.
          */}
        {autoStarted ? (
          <p className="login-note" role="status">
            {notice?.source === 'auto' ? (
              <>
                لم ينجح الدخول التلقائيّ للتطوير (<bdi dir="ltr">NEXT_PUBLIC_DEV_AUTOLOGIN</bdi>)؛ أكمل
                الدخول يدويًّا. ردّ الخادم: «{notice.message}»
              </>
            ) : null}
          </p>
        ) : null}

        {/*
          * الإخفاق اليدويّ وحده يبقى على حاله: نصّ الخادم حرفيًّا في تنبيهٍ
          * أحمر حازم. هنا وحده تصحّ دلالة «بيانات الدخول غير صحيحة» — المشغّل
          * كتب، والخادم ردّ.
          */}
        {notice?.source === 'manual' ? (
          <p className="form-error" role="alert">
            {notice.message}
          </p>
        ) : null}

        <label className="field" htmlFor="admin-email">
          <span>البريد الإلكتروني</span>
          <bdi dir="ltr">
            <input
              id="admin-email"
              name="email"
              type="email"
              autoComplete="username"
              dir="ltr"
              required
              value={email}
              onChange={(event) => setEmail(event.target.value)}
            />
          </bdi>
        </label>

        <label className="field" htmlFor="admin-password">
          <span>كلمة المرور</span>
          <input
            id="admin-password"
            name="password"
            type="password"
            autoComplete="current-password"
            dir="ltr"
            required
            value={password}
            onChange={(event) => setPassword(event.target.value)}
          />
        </label>

        <button type="submit" className="btn btn-primary" disabled={busy || state.status === 'loading'}>
          {busy ? 'جارٍ التحقّق…' : 'دخول'}
        </button>

        <p className="login-note">
          حساب المشغّل يُنشأ من الخادم عبر <bdi dir="ltr">AdminUserSeeder</bdi> بكلمة مرور
          تُمرَّر في متغيّر البيئة <bdi dir="ltr">ADMIN_SEED_PASSWORD</bdi> — لا توجد كلمة مرور
          افتراضية في الكود.
        </p>
      </form>
    </main>
  );
}
