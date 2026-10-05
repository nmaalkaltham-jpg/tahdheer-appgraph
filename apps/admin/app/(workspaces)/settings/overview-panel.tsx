'use client';
// UNIT: SETTINGS-OVERVIEW-PANEL
// FILE: overview-panel.tsx
// STYLE: none
// PARENT: PAGE-SETTINGS
// USES: ADMIN-SESSION, LIB-FORMAT, PAYMENTPROVIDERPANEL
// API: none
// API-VIA: ADMIN-SESSION, PAYMENTPROVIDERPANEL
// MAP: docs/PROJECT_MAP.md

/**
 * الإعدادات. تعرض ما يمكن إثباته فقط: هوية المشغّل الحالي وصلاحياته
 * الفعلية كما أعادها الخادم. كل ما لم يُبْنَ يُسمّى صراحةً بدل عرض مفاتيح
 * وهمية قابلة للتبديل (MIP §1.3).
 *
 * ─── لا حالة ٤٠٣ على مستوى الصفحة، ولوحٌ واحد له حالة غياب ───
 *
 * الصفحة نفسها لا تقرأ إلا `‎GET /admin/me‎` (`routes/api-admin.php:70` —
 * `Route::get('/me', [AuthController::class, 'me']);`)، وهو المسار الوحيد
 * تحت `AdminAuthenticate` بلا `AdminAuthorize` أصلًا: كلّ من دخل يقرؤه. فمن
 * وصل إلى هنا لن يُمنع، ولا معنى لبابٍ يحرس ما لا يُمنع.
 *
 * والذي يُمنع لوحٌ واحد: `PaymentProviderPanel` يقرأ
 * `‎GET /admin/payments/provider‎` المحروس بـ`orders.read`
 * (`routes/api-admin.php:172-173` — `Route::get('/payments/provider', PaymentProviderController::class)`
 * `->middleware(AdminAuthorize::class.':orders.read');`). فحالته حالة **لوح**
 * لا حالة صفحة: من يفتقد `orders.read` يقرأ هويّته وصلاحياته كما يقرؤها
 * المالك، ويقرأ سطرًا واحدًا يقول أيّ لوحٍ غاب ولماذا. إقفال الصفحة كلّها
 * لأجل لوحٍ واحد يحجب معلومةً غير محروسة أصلًا.
 *
 * وغيابُ اللوح يُعلَن ولا يُطوى صامتًا: لوحٌ يختفي بلا أثر يجعل المشغّل يظنّ
 * أنّ فحص البوابة غير مبنيّ، فيبحث عمّا هو موجود ومحجوب عنه وحده.
 */

import { useSession } from '../../../components/admin-session';
import { PaymentProviderPanel } from '../../../components/settings/PaymentProviderPanel';
import { dateTime } from '../../../lib/format';

/*
 * «المستخدمون والأدوار» خرجت من هذه القائمة لأنّها بُنيت — لا لأنّ الإقرار
 * صار محرجًا. إبقاء سببٍ صار كاذبًا أسوأ من عدم كتابته أصلًا.
 *
 * وبالقاعدة نفسها خرجت «المظهر والقوائم» (٢٠٢٦-٠٩-١٠): كانت تُعلن أنّ
 * استوديو المظهر و«ستوديو التنقّل» «خارج نطاق هذه المرحلة» بينما المسارات
 * `‎/navigation‎` مبنيّ ومخدوم — إعلان
 * عجزٍ عن قدرةٍ قائمة يُخفي عن المشغّل ما يملكه.
 *
 * وضُيّق سبب التكاملات: صحّة البحث صارت مفحوصة حيًّا في «ذكاء البحث»
 * (المزوّد المرصود ونسبة التدهور)، فبقي البريد والتخزين وحدهما.
 *
 * هذه قائمةٌ مكتوبة باليد بطبيعتها — لا مِسبار لها — فمراجعتها عند كلّ
 * توسعة جزءٌ من التوسعة.
 */
const UNBUILT_SECTIONS = [
  { label: 'سجل التدقيق', reason: 'السجل يُكتب فعليًا في admin_audit_log، لكن واجهة استعراضه لم تُبْنَ بعد.' },
  {
    label: 'تكاملا البريد والتخزين',
    reason: 'حالة الدفع تُفحص أدناه، وصحّة البحث في «ذكاء البحث»؛ البريد والتخزين وحدهما بلا فحص حيّ.',
  },
];

export function SettingsOverviewPanel() {
  const { state } = useSession();

  if (state.status !== 'authenticated') {
    return (
      <section>
        <p className="state-line" role="status">
          جارٍ التحقّق من الجلسة…
        </p>
      </section>
    );
  }

  const { user, permissions } = state.identity;

  return (
    <section>

      <section className="panel">
        <h2>حسابك</h2>
        <div className="table-scroll" tabIndex={0} role="region" aria-label="هوية المشغّل الحالي">
          <table className="data-table">
            <caption className="sr-only">اسم المشغّل وبريده ودوره وآخر دخول</caption>
            <tbody>
              <tr>
                <th scope="row">الاسم</th>
                <td>{user.name}</td>
              </tr>
              <tr>
                <th scope="row">البريد</th>
                <td>
                  <bdi dir="ltr">{user.email}</bdi>
                </td>
              </tr>
              <tr>
                <th scope="row">الدور</th>
                <td>
                  {user.roleLabel} (<bdi dir="ltr">{user.role}</bdi>)
                </td>
              </tr>
              <tr>
                <th scope="row">آخر دخول</th>
                {/* لا ‎<bdi dir="ltr">‎ حول ‎dateTime‎: مُنسِّق `ar-SA` يغرس علامات
                    RLM في مخرجه، وفرضُ الاتجاه فوقها يقلب ترتيب اليوم/الشهر/السنة. */}
                <td>{dateTime(user.lastLoginAt)}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>

      {permissions.includes('orders.read') ? (
        <PaymentProviderPanel />
      ) : (
        <section className="panel">
          <h2>بوابة الدفع</h2>
          <p className="absent">
            حالة البوابة تُقرأ من <bdi dir="ltr">GET /admin/payments/provider</bdi> خلف صلاحية{' '}
            <bdi dir="ltr">orders.read</bdi>، ولا تملكها. اللوح محجوب عنك لا غير مبنيّ — وبقيّة هذه
            الصفحة لا تتطلّب هذه الصلاحية، فلم تُقفل لأجله.
          </p>
        </section>
      )}

      <section className="panel">
        <h2>صلاحياتك الفعلية</h2>
        <p className="page-note">
          هذه القائمة يفرضها الخادم على كل مسار؛ إخفاء زر في الواجهة ليس حماية، والمنع الحقيقي
          يحدث في الطبقة الخلفية.
        </p>
        <ul className="permission-list">
          {permissions.map((permission) => (
            <li key={permission}>
              <bdi dir="ltr">{permission}</bdi>
            </li>
          ))}
        </ul>
      </section>

      <section className="panel">
        <h2>أقسام لم تُبْنَ بعد</h2>
        <div className="table-scroll" tabIndex={0} role="region" aria-label="أقسام لم تُبْنَ بعد">
          <table className="data-table">
            <caption className="sr-only">الأقسام غير المبنيّة وسبب غيابها</caption>
            <thead>
              <tr>
                <th scope="col">القسم</th>
                <th scope="col">الحالة</th>
                <th scope="col">السبب</th>
              </tr>
            </thead>
            <tbody>
              {UNBUILT_SECTIONS.map((section) => (
                <tr key={section.label}>
                  <th scope="row">{section.label}</th>
                  <td>
                    <span className="state">غير مُهيأ</span>
                  </td>
                  <td>{section.reason}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </section>
    </section>
  );
}
