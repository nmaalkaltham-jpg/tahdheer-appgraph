/**
 * «ابدأ من مرحلتك» — بطاقات المراحل تُفتح على صفوفها.
 *
 * منقولٌ عن `HeroEducationStages` في «إعادة تصميم واجهة المتجر»
 * (Home Components.dc.html)، بتعديلاتٍ أوجبها ما يملكه المتجر فعلًا:
 *
 *  ١· **لا عدّاد موادّ لكلّ مرحلة.** التصميم يعرض «٧ موادّ» لكلّ بطاقة،
 *     و`subjects` في قاعدتنا بلا مفتاح أب — فـ«موادّ الابتدائية» ليست حقيقةً
 *     يملكها النظام. العدّاد الوحيد المعروض هو `productsCount` من الكتالوج.
 *
 *  ٢· **لا خطوة «المسار».** لا `tracks` في القاعدة، فتسقط رقاقاتها.
 *
 *  ٣· **الكشف بـ`<details>` الأصيل لا بحالة عميل.** المكوّن خادميّ بالكامل،
 *     فيصل الزائرَ مفتوحَ البنية بلا جافاسكربت — والصفوف في أوّل HTML يجدها
 *     محرّك البحث. رأس البطاقة كلّه `<summary>` كما هو زرُّ القالب كلّه.
 *
 * والصفوف تُجمَّع من `/catalog/grades` لا من نداءٍ لكلّ مرحلة: كلّ صفٍّ يحمل
 * `stage` في حمولته، فالتجميع حقيقةٌ مبثوثة لا استنتاج.
 */
import { formatNumber, qty, type PluralForms } from '@tahdheer/design-system/format';
import { routes } from '../../lib/routes';
import { termPath } from '../../app/(catalog)/_components/dimensions';
import { Icon, pick, type IconName } from './icons';
import styles from './StagesExplorer.module.css';

export interface StageTerm {
  slug: string;
  name: string;
  subtitle?: string;
  productsCount?: number;
}

export interface GradeTerm {
  slug: string;
  name: string;
  productsCount?: number;
  stage?: { slug: string; name: string } | null;
}

const GRADE_FORMS: PluralForms = {
  zero: 'لا صفوف',
  one: 'صفّ واحد',
  two: 'صفّان',
  few: 'صفوف',
  many: 'صفًّا',
};

/* ترتيب القالب: الابتدائيّ بنفسجيّ · المتوسّط بترولي · الثانويّ كحليّ. */
const STAGE_ICONS: readonly IconName[] = ['blocks', 'book', 'cap'];
const STAGE_TINTS: readonly string[] = [styles.tintLilac ?? '', styles.tintTeal ?? '', styles.tintNavy ?? ''];

export function StagesExplorer({ stages, grades }: { stages: StageTerm[]; grades: GradeTerm[] }) {
  return (
    <div className={styles.inner}>
      <div className={styles.head}>
        <div>
          <p className={styles.eyebrow}>تصفّح</p>
          <h2 className={styles.title}>ابدأ من مرحلتك</h2>
        </div>
        <p className={styles.lede}>اختر المرحلة لتظهر صفوفها، ثمّ الصفّ للانتقال مباشرة إلى ملفّاته.</p>
      </div>

      <ul className={styles.cards} role="list">
        {stages.map((stage, index) => {
          /*
           * الصفوف المنتمية إلى هذه المرحلة — بالسبيكة لا بالاسم: سبيكة
           * `stage.slug` هي نفسها مفتاح الأرشيف، والاسم قد يتكرّر أو يتغيّر.
           */
          const owned = grades.filter((grade) => grade.stage?.slug === stage.slug);
          const hasProducts = typeof stage.productsCount === 'number';
          const stageHref = termPath('stages', stage.slug);

          return (
            <li key={stage.slug}>
              <details className={`${styles.card} ${pick(STAGE_TINTS, index, '')}`}>
                <summary className={styles.summary}>
                  <span className={styles.cardHead}>
                    <span className={styles.icon}>
                      <Icon name={pick(STAGE_ICONS, index, 'book')} size={26} strokeWidth={1.9} />
                    </span>
                    {/* الشارة تُصيَّر حين توجد صفوف فعلًا — لا «٠ صفًّا». */}
                    {owned.length > 0 ? <span className={styles.badge}>{qty(owned.length, GRADE_FORMS)}</span> : null}
                  </span>
                  <span className={styles.titleBlock}>
                    <b className={styles.cardTitle}>المرحلة {stage.name}</b>
                    {stage.subtitle ? <span className={styles.range}>{stage.subtitle}</span> : null}
                  </span>
                  <span className={styles.stats}>
                    {hasProducts ? (
                      <span>
                        <b className={styles.statValue}>{formatNumber(stage.productsCount ?? 0)}</b>
                        منتجًا
                      </span>
                    ) : null}
                    <span className={styles.hint}>
                      <span className={styles.hintClosed}>{owned.length > 0 ? 'اختر الصف' : 'استعرض'}</span>
                      <span className={styles.hintOpen}>إغلاق</span>
                      <Icon name="chev" size={14} strokeWidth={2.2} className={styles.chevron} />
                    </span>
                  </span>
                </summary>

                <div className={styles.body}>
                  {owned.length > 0 ? (
                    <>
                      <span className={styles.label}>اختر الصف</span>
                      <ul className={styles.grades} role="list">
                        {owned.map((grade) => (
                          <li key={grade.slug}>
                            <a className={styles.grade} href={termPath('grades', grade.slug)}>
                              {grade.name}
                              {typeof grade.productsCount === 'number' ? (
                                <span className={styles.gradeCount}>{formatNumber(grade.productsCount)}</span>
                              ) : null}
                            </a>
                          </li>
                        ))}
                      </ul>
                    </>
                  ) : null}
                  <a className={styles.cta} href={stageHref}>
                    استعرض المرحلة
                    <Icon name="chev" size={15} strokeWidth={2.2} />
                  </a>
                </div>
              </details>
            </li>
          );
        })}
      </ul>

      <a className={styles.allLink} href={routes.stages}>
        تصفّح جميع المراحل
      </a>
    </div>
  );
}
