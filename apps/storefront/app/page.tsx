import { Fragment } from 'react';
import type { Metadata } from 'next';
/**
 * Homepage (MIP §3.3) — «Storefront Home.dc.html» / «… Mobile.dc.html».
 * A layout published from the admin wins; with none published (204) the
 * design's own composition ships: hero slider · trust · stages · bestsellers
 * · samples · reviews · CTA. The seven design sections render through
 * `components/home/design.tsx`; any other id an owner publishes keeps its
 * legacy renderer from `sections.tsx`.

 *
 * شريط الجوّال السفليّ (السلّة · إتمام الشراء · التبويبات) عامٌّ في
 * `app/layout.tsx` — لا نسخة له هنا.
 */
import { DESIGN_SECTIONS, DesignSection } from '../components/home/design';
import { HOMEPAGE_SECTIONS, Section } from '../components/home/sections';
import { WIDGET_SECTIONS } from '../components/home/widgets';
import { RecentlyViewed } from '../components/home/RecentlyViewed';
import { apiGet } from '../lib/api';
import { recentAnchor, resolveSections } from '../lib/homepage';
import styles from './page.module.css';

/**
 * الرئيسية هي الصفحة الأقوى في الموقع، وكانت الوحيدة بلا رابط معياري.
 *
 * بلا canonical تصير كل صيغة تصل إليها (‎/‎ مع معاملات تتبّع، ‎/index‎،
 * نسخة بشرطة مائلة زائدة) عنوانًا مستقلًّا في نظر محرّك البحث، فتتوزّع
 * إشارات الصفحة على نسخ بدل أن تتجمّع في واحدة.
 */
export const metadata: Metadata = {
  alternates: { canonical: '/' },
};

export default async function HomePage() {
  /*
   * الترتيب من اللوحة حين يُنشر، ومن الافتراض حين لا يُنشر.
   *
   * ٢٠٤ تعني «لم يُضبط» فيقع المتجر على ترتيبه المدمج؛ وقائمةٌ فارغة محفوظة
   * تعني إخلاءً مقصودًا فتُحترم. وعطلُ الشبكة يُقرأ «لم يُضبط» لا «أخلِ
   * الصفحة»: صفحةٌ رئيسة تُفرَّغ بانقطاعٍ عابر أسوأ من ترتيبٍ بائت بدقائق.
   */
  const { data, status } = await apiGet<{ sections?: unknown[] }>('/api/v1/home/layout');
  const sections = resolveSections(status === 200 && Array.isArray(data?.sections) ? data.sections : null);
  /* «شاهدتَ مؤخّرًا» (ذاكرة الزائر، على المتصفّح وحده) بعد «الأكثر مبيعًا» — انظر `recentAnchor`. */
  const anchor = recentAnchor(sections);

  return (
    <div className={styles.home}>
      {anchor === null ? <RecentlyViewed /> : null}
      {sections.map((id) => (
        <Fragment key={id}>
          {/* أقسام القالبين تُغلَّف بغلاف التصميم (أساسُ الطباعة بلا حشوٍ عامّ). */}
          {id in DESIGN_SECTIONS || id in WIDGET_SECTIONS ? (
            <DesignSection id={id}>{HOMEPAGE_SECTIONS[id]({ sections })}</DesignSection>
          ) : (
            <Section id={id}>{HOMEPAGE_SECTIONS[id]({ sections })}</Section>
          )}
          {id === anchor ? <RecentlyViewed /> : null}
        </Fragment>
      ))}
    </div>
  );
}
