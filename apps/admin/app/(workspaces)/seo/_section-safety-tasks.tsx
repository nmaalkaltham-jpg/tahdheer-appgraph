'use client';
// UNIT: _SECTION-SAFETY-TASKS
// FILE: _section-safety-tasks.tsx
// STYLE: seo.module.css
// PARENT: SEO-OVERVIEW-PANEL
// USES: _KIT
// API: none
// MAP: docs/PROJECT_MAP.md

/**
 * القسمان ٧ و٨: المراقبة والحماية، والمهام والتحقق.
 *
 * الحواجز والبوابات وسير عمل المهام مذهب تشغيلي لا بيانات، فتُعرض نصًّا.
 * أما ما يحتاج قياسًا — أثر الإصلاحات، سجل التغييرات، كشف الشذوذ، اللقطات
 * التاريخية، لوح المهام — فلا مخزن له، فيُعلن غير مُهيأ باسم ما ينقصه.
 */

import { Box, Icon, NotConfigured } from './_kit';
import styles from './seo.module.css';

/** حواجز أمان SEO — نصّ اللوح حرفيًا، وهي قواعد لا تُتجاوز بأي إعداد. */
const RAILS = [
  'لا تعديلات SEO تلقائية إلا بطلب صريح',
  'لا صفحات ضخمة لاستهداف كلمات',
  'لا حشو كلمات',
  'لا صفحات مدخل',
  'لا نص مخفي',
  'لا تقييمات مزيفة',
  'لا Schema لغير الظاهر فعليًا',
  'لا روابط آلية للتلاعب',
  'لا كشط نتائج بحث مخالف',
  'لا ضمان ترتيب — إطلاقًا',
  'الارتباط ليس سببية',
  'الدرجات ليست عوامل ترتيب لدى محرّكات البحث',
];

/**
 * أعمدة سير عمل مهام SEO — التسميات من اللوح، والألوان بالرمز لا بالقيمة:
 * كل لون منها معرَّف أصلًا في سلّم الرموز أو في كتلة .workspace، فتكراره
 * حرفيًا هنا كان يفتح باب انحراف صامت عند أي تعديل على السلّم.
 */
const TASK_STATES: [string, string, string][] = [
  ['new', 'جديدة', 'var(--info)'],
  ['review', 'قيد المراجعة', 'var(--ai)'],
  ['planned', 'مخططة', 'var(--warn-text)'],
  ['inprogress', 'قيد التنفيذ', 'var(--warn)'],
  ['fixed', 'أُصلحت', 'var(--teal)'],
  ['monitoring', 'مراقبة', 'var(--primary)'],
  ['closed', 'مغلقة', 'var(--muted)'],
];

export function SafetySection() {
  return (
    <section className={styles.section}>
      <div className={styles.rails}>
        <span className={styles.railshead}>
          <Icon name="shield" />
          حواجز أمان SEO — قواعد صارمة غير قابلة للتجاوز
        </span>
        <ul className={styles.raillist}>
          {RAILS.map((rail) => (
            <li key={rail}>{rail}</li>
          ))}
        </ul>
      </div>

      <div className={styles.grid3}>
        <Box
          title="حارس ما قبل النشر"
          icon="doc"
          note="مفروض في الخادم"
          foot="هذا الحارس الوحيد المفعَّل فعلًا: مسار النشر يرفض المنتج غير المكتمل ويعيد قائمة الناقص بالاسم. الواجهة تعرض القائمة ولا تملك تجاوزها."
        >
          <div className={styles.boxbody}>
            <ul className={styles.bullets}>
              <li>قائمة جاهزية حقيقية لكل منتج، تُعدّ في قسم «ذكاء المنتجات».</li>
              <li>الرفض يصدر من الخادم لا من الواجهة، فإخفاء زر ليس حماية.</li>
              <li>قابلية الفهرسة تُحسب من السياسة نفسها التي تحكم الخرائط.</li>
            </ul>
            <code className={styles.codeline} dir="ltr">
              PATCH /admin/products/{'{id}'}/publish → readiness.missingLabels
            </code>
          </div>
        </Box>

        <Box title="حارس ترحيل الموقع" icon="map" note="جزئي">
          <div className={styles.boxbody}>
            <ul className={styles.bullets}>
              <li>جدول التحويلات موجود ويُستشار قبل 404 — قابل للفحص من قسم الفهرسة.</li>
              <li>تغطية التحويلات غير مقيسة: قياسها يحتاج جرد الروابط القديمة، ولا مصدر له.</li>
            </ul>
            <code className={styles.codeline} dir="ltr">
              GET /api/v1/redirects/resolve
            </code>
          </div>
        </Box>

        <NotConfigured
          title="بوابة إصدار القالب والإضافات"
          reason="مقارنة عيّنة صفحات قبل التحديث وبعده تحتاج لقطات يومية للعناوين والأوصاف وH1 وcanonical وrobots وSchema. لا مخزن لقطات في هذا النظام، فلا مقارنة ولا فروق."
          needs={['مخزن لقطات يومية', 'GET /admin/seo/snapshots']}
        />
      </div>

      <div className={styles.grid2}>
        <NotConfigured
          title="تتبع أثر الإصلاحات وكشف الشذوذ"
          reason="رسم النقرات الأسبوعية وعلامات التعديلات عليه، ومقارنة اليوم بالأمس وبالأساس المتحرك، كلها تحتاج سلسلة زمنية للأداء العضوي. لا Search Console موصول ولا جدول قياس تاريخي، فلا رسم ولا خط اتجاه ولا نسبة تغيّر — ورسم منحنى بلا مصدر ادّعاء نتيجة لم تُقس."
          needs={['Google Search Console', 'مخزن سلاسل زمنية للأداء', 'GET /admin/seo/timeseries']}
        />
        <NotConfigured
          title="سجل تغييرات SEO ومراقب التراجع"
          reason="«الصفحة · النوع · القيمة القديمة/الجديدة · مَن · متى · لماذا» موجودة في سجل تدقيق الخادم، لكن لا مسار إداري يقرأها بعد. حتى يُفتح ذلك المسار لا يُعرض سجل — ولا يُعاد بناؤه تخمينًا من الحالة الراهنة."
          needs={['GET /admin/audit', 'صلاحية audit.read']}
        />
      </div>

      <NotConfigured
        title="باني قواعد التنبيه"
        reason="القاعدة بلا مُشغِّل زخرفة: التنبيه يحتاج مهمة مجدولة تقيس الشرط وقناة تُرسل عبرها ومخزنًا يحفظ القواعد. لا شيء من ذلك قائم، فلا مفاتيح تفعيل هنا — مفتاح لا يشغّل شيئًا أسوأ من غيابه."
        needs={['GET/POST /admin/seo/alert-rules', 'مجدول مهام', 'قناة إشعار']}
      />
    </section>
  );
}

export function TasksSection() {
  return (
    <section className={styles.section}>
      <Box
        title="سير عمل مهام SEO"
        icon="task"
        note="مذهب تشغيلي — لا مخزن مهام"
        foot="«أُصلحت» ليست نجاحًا: تغيير HTML يُثبت التنفيذ لا الأثر. لذلك تمرّ كل مهمة بـ«مراقبة» حتى تتوفر بيانات أداء كافية، ولا تُغلق قبل ذلك."
      >
        <div className={styles.boxbody}>
          <p className={styles.lede}>
            كل مهمة تولد من فرصة أو مشكلة وتحمل دليلها معها: القاعدة التي أطلقتها، والقياسات التي رصدتها،
            والإجراء الموصى به، والمخاطر. الانتقال بين الأعمدة قرار بشري موثَّق، لا حركة تلقائية.
          </p>
        </div>

        <div className={styles.kanban} style={{ paddingInline: 13, paddingBlockEnd: 13 }}>
          {TASK_STATES.map(([key, label, color]) => (
            <div className={styles.kancol} key={key}>
              <div className={styles.kanhead} style={{ borderBlockEndColor: color }}>
                {label}
                <span className={`${styles.tag}`} style={{ marginInlineStart: 'auto' }}>
                  غير مقيس
                </span>
              </div>
              <div className={styles.kanbody}>لا مخزن مهام — العمود بنية بلا محتوى، ولا بطاقات عيّنة.</div>
            </div>
          ))}
        </div>
      </Box>

      <NotConfigured
        title="لوح المهام"
        reason="أعمدة سير العمل السبعة أعلاه معروضة كبنية لأنها جزء من المذهب، لكن لا نقطة نهاية تنشئ مهمة أو تقرأها أو تنقلها. أزرار «تحويل إلى مهمة» في محرك الفرص معطَّلة لهذا السبب بالذات، ولا تُطلق إشعارًا يوهم بأن شيئًا حُفظ."
        needs={['GET/POST /admin/seo/tasks', 'PATCH /admin/seo/tasks/{id}']}
      />
    </section>
  );
}
