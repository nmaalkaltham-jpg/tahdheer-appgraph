'use client';
// UNIT: ATTENTION-MAP
// FILE: attention-map.tsx
// STYLE: shared.module.css
// PARENT: PAGE-ANALYTICS
// USES: MEASUREMENT, SHARED-ICONS, UI
// API: none
// MAP: docs/PROJECT_MAP.md

/**
 * خريطة الانتباه — غير مُقاسة.
 *
 * اللوحة نفسها تُلزِم هذا السلوك: حين لا بيانات، لا تُرسَم خريطة محاكاة مكان
 * الخريطة الحقيقية. خريطة مولَّدة تبدو مقنعة وتقود إلى قرارات خاطئة، والفراغ
 * المعلَن أنفع منها.
 *
 * وأدوات التحكّم (نوع الخريطة، الجهاز، العيّنة) غائبة لا معطّلة: لا شيء
 * تتحكّم فيه، وإظهارها يوحي بوجود بيانات خلفها.
 */

import { Icon } from '../_shared/icons';
import { MISSING_FEEDS } from '../_shared/measurement';
import { Chip, FeedRow, NotMeasured, Notice, Panel } from '../_shared/ui';
import s from '../_shared/shared.module.css';

/** أنواع الخرائط التي ستُبنى — تُعرَض نصًّا لأنها لا تتحكّم في شيء اليوم. */
const MAP_KINDS: { label: string; body: string }[] = [
  { label: 'النقرات', body: 'مجمَّعة على مستوى العنصر لا على إحداثيات البكسل، فلا يُعاد بناء مسار مؤشّر شخص بعينه.' },
  { label: 'عمق التمرير', body: 'نسبة من وصل إلى كل عنصر — تكشف عنصرًا مهمًّا في موضع لا يُرى.' },
  { label: 'النقرات الميتة', body: 'نقر على عنصر غير تفاعلي: توقّع مشروع لم يُلبَّ، لا خطأ عارض.' },
  { label: 'النقرات الغاضبة', body: 'نقر متكرّر سريع على العنصر نفسه — يؤكّد أن المشكلة توقّع لا عطب.' },
  { label: 'العناصر المُهمَلة', body: 'ظاهرة لأكثر من نصف الزوّار وتفاعلها ضئيل — وليس بالضرورة عيبًا.' },
];

/** شروط تشغيل لازمة قبل أي تسجيل — التزامات مستقبلية لا وصف لواقع قائم. */
const CONDITIONS: string[] = [
  'موافقة الزائر شرط لا خيار: لا تسجيل قبلها ولا بأثر رجعي.',
  'تجميع على مستوى العنصر بلا إحداثيات بكسل ولا معرّف شخصي.',
  'لا كلمات مرور ولا بيانات دفع ولا محتوى حقول، وصفحات الحساب والدفع مستبعَدة كليًّا.',
  'جلسات الإدارة مستبعَدة، ومدّة الحفظ ونسبة العيّنة معلنتان في الواجهة لا مضمرتين.',
];

export default function AttentionMapTab() {
  const feed = MISSING_FEEDS.find((item) => item.id === 'attention');

  return (
    <>
      <Notice tone="warn" icon="lock">
        هذه الشاشة لا تعرض شيئًا لأن لا شيء يُسجَّل. لم يُبنَ أي تسجيل تفاعل، ولم تُطلب أي موافقة من أي
        زائر — والشروط أدناه واجبة قبل أول حدث، لا بعده.
      </Notice>

      <div className={s.stackTop}>
        <NotMeasured
          icon="eye"
          title="لا بيانات تفاعل — ولن نرسم خريطة محاكاة مكانها"
          body="لا جدول أحداث تفاعل، ولا مسار تحت /api/v1/admin يُرجعها. خريطة مولَّدة عشوائيًا تبدو مقنعة تمامًا وتقود إلى إعادة ترتيب صفحة بناءً على لا شيء — لذلك يبقى المكان فارغًا معلَنًا."
          requirements={[
            'مسار تسجيل من طرف أول يكتب الأحداث مجمَّعة على مستوى العنصر، بعد موافقة صريحة.',
            'مسار قراءة يُرجع التجميع للوحة — لا صفوفًا خامًا ولا معرّفات زوّار.',
            'إعلان نسبة العيّنة ومدّة الحفظ في الواجهة، لأن عيّنة مُضمَرة تجعل كل رقم غير قابل للتفسير.',
          ]}
        />

        <div className={s.split}>
          <Panel
            title="ما ستعرضه هذه الشاشة"
            hint="بعد بناء خطّ الأنابيب"
            note="كل خريطة تقرأ مجموعة العناصر نفسها ويتغيّر المقياس وحده، حتى يبقى العنصر متتبَّعًا عبر الخرائط."
          >
            <div className={s.stack}>
              {MAP_KINDS.map((kind) => (
                <div className={s.sourceRow} key={kind.label}>
                  <span className={s.sourceMain}>
                    <b className={s.sourceName}>{kind.label}</b>
                    <span className={s.sourceNote}>{kind.body}</span>
                  </span>
                  <Chip tone="warn">غير مُقاس</Chip>
                </div>
              ))}
            </div>
          </Panel>

          <Panel title="شروط التشغيل" hint="واجبة قبل أول حدث">
            <ul className={s.reasons}>
              {CONDITIONS.map((text, index) => (
                <li className={s.reason} key={text}>
                  <span aria-hidden="true" className={s.reasonMark}>
                    {index + 1}
                  </span>
                  <span className={s.reasonText}>{text}</span>
                </li>
              ))}
            </ul>
            <p className={s.panelNote}>
              <Icon name="lock" size={11} strokeWidth={2.2} /> هذه شروط لِما سيُبنى، لا وصف لِما يعمل: لا
              تسجيل قائمًا اليوم فلا موافقة مطلوبة بعد.
            </p>
          </Panel>
        </div>

        {feed !== undefined ? (
          <Panel title="حالة المصدر">
            <FeedRow feed={feed} />
          </Panel>
        ) : null}
      </div>
    </>
  );
}
