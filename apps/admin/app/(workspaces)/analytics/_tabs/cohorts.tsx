'use client';
// UNIT: COHORTS
// FILE: cohorts.tsx
// STYLE: shared.module.css
// PARENT: PAGE-ANALYTICS
// USES: COHORT-GRID, MEASUREMENT, UI
// API: none
// MAP: docs/PROJECT_MAP.md

/**
 * الاحتفاظ والأفواج.
 *
 * الشبكة مبنية بالكامل ومحكومة بقاعدة «null ليس صفرًا»: الخلية غير المرصودة
 * تُعرَض «—»، لأن صفرًا مكانها يُقرأ تسرّبًا كاملًا وهو غياب رصد. حين يوجد
 * مصدر أفواج تُمرَّر إليه الصفوف فتكون صحيحة من أول يوم بلا تعديل.
 *
 * ولا مصدر اليوم: لا مسار يُرجع أفواجًا، فالشبكة تُعرَض بترويستها وصفٍّ واحد
 * يقول ذلك — لا بصفوف مُختلَقة.
 */

import { CohortGrid, type CohortRow } from '../_shared/cohort-grid';
import { MISSING_FEEDS } from '../_shared/measurement';
import { FeedRow, NotMeasured, Panel, Segmented, type SegmentOption } from '../_shared/ui';
import s from '../_shared/shared.module.css';

const COLUMNS = ['الشهر 0', '+1', '+2', '+3', '+4', '+5', '+6'];

const NO_COHORTS = 'لا مسار يُرجع أفواجًا اليوم، فلا مقياس يُبدَّل.';

const METRICS: SegmentOption[] = [
  { id: 'repeat', label: 'معدّل الشراء المتكرّر', disabledReason: NO_COHORTS },
  { id: 'revenue', label: 'الإيراد التراكمي', disabledReason: NO_COHORTS },
  { id: 'files', label: 'ملفات لكل عميل', disabledReason: NO_COHORTS },
];

/** لا صفوف اليوم. البنية جاهزة لاستقبالها كما هي. */
const ROWS: CohortRow[] = [];

/** ملاحظات قراءة صحيحة في كل حال — ليست بيانات ولا تدّعي وجودها. */
const CAVEATS: string[] = [
  'الفوج يُحدَّد بتاريخ أول شراء لا بتاريخ التسجيل، فحساب بلا شراء لا يدخل أي فوج.',
  'الأفواج الحديثة تبدو أفضل لأن أشهرها الأولى وحدها مرصودة. لا يُقارَن فوج عمره شهر بآخر عمره ستّة.',
  'الفوج الجاري ناقص ما لم ينتهِ شهره: حجمه رقم مؤقّت لا نهائي، ويجب أن يُوسَم كذلك.',
  'المقاطع الصغيرة العدد تقلّبها أعلى، فلا تُبنى عليها قرارات تسعير وحدها.',
];

export default function CohortsTab() {
  const feed = MISSING_FEEDS.find((item) => item.id === 'cohorts');

  return (
    <>
      <NotMeasured
        icon="chart"
        title="لا أفواج مرصودة"
        body="سؤال «هل يعود المعلّم بعد أول ملف؟» يحتاج تجميعًا خادميًا لكل عميل بتاريخ أول شراء، ثم عدّ مشترياته في كل شهر تالٍ. لا يوجد مسار يفعل ذلك: ‏/admin/customers يُرجع عدد طلبات كل عميل بلا تواريخ أفواج."
        requirements={[
          'تجميع خادمي: أول شراء لكل عميل، ثم مصفوفة الأشهر التالية — لا يُبنى في المتصفّح.',
          'تمييز صريح بين خلية مرصودة قيمتها صفر وخلية لم يمرّ شهرها بعد؛ الشبكة أدناه تحترم هذا التمييز أصلًا.',
          'وسم الفوج الجاري بأنه ناقص، وإلا قُرئ انخفاضه الطبيعي تسرّبًا.',
        ]}
      />

      <div className={s.stackTop}>
        <Panel
          title="شبكة الأفواج"
          hint="الصفّ فوج · العمود شهر بعد الشراء الأول"
          actions={
            <Segmented
              label="المقياس"
              options={METRICS}
              value="repeat"
              onChange={() => undefined}
            />
          }
          note="الخلايا «—» أشهر لم تمرّ بعد على ذلك الفوج — لا أصفار. الصفر في خلية غير مرصودة أشهر أكاذيب جداول الأفواج: يُقرأ تسرّبًا وهو في الحقيقة غياب بيانات."
        >
          <CohortGrid
            columns={COLUMNS}
            rows={ROWS}
            caption="كل صفّ فوج عملاء اشتروا أول مرة في ذلك الشهر، وكل عمود ما فعلوه بعده."
            rowHeader="فوج الشراء"
            sizeHeader="الحجم"
            formatCell={(value) => `${value}٪`}
            nullHint="لم يمرّ هذا الشهر بعد على هذا الفوج"
            emptyMessage="لا أفواج تُعرَض: لا مسار يُرجعها. الشبكة معروضة بترويستها لتبقى بنيتها ظاهرة، ولن تُملأ بصفوف تجريبية."
          />
        </Panel>

        <div className={s.split}>
          <Panel title="ماذا يشتري العائدون ثانيًا؟">
            <NotMeasured
              icon="cart"
              title="لا مسار للطلب الثاني"
              body="تسلسل «ما بعد أول شراء» يحتاج ترتيب طلبات كل عميل زمنيًا ثم مطابقة تصنيف المنتج الأول بالثاني. لا تجميع خادمي لذلك، ولن يُشتقّ في المتصفّح."
            />
          </Panel>

          <Panel title="قيمة العميل على المدى">
            <NotMeasured
              icon="coins"
              title="لا قيمة تراكمية لكل عميل"
              body="‏/admin/customers يُرجع عدد الطلبات لكل عميل ولا يُرجع مجموع إيرادها، فلا قيمة تراكمية تُحسب بلا نداء لكل طلب على حدة — وهو رقم غير قابل للتدقيق."
            />
          </Panel>
        </div>

        <Panel title="كيف تُقرأ هذه اللوحة" hint="قواعد صحيحة في كل حال">
          <ul className={s.reasons}>
            {CAVEATS.map((text, index) => (
              <li className={s.reason} key={text}>
                <span aria-hidden="true" className={s.reasonMark}>
                  {index + 1}
                </span>
                <span className={s.reasonText}>{text}</span>
              </li>
            ))}
          </ul>
        </Panel>

        {feed !== undefined ? (
          <Panel title="حالة المصدر">
            <FeedRow feed={feed} />
          </Panel>
        ) : null}
      </div>
    </>
  );
}
