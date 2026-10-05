/**
 * أقسام «Home Components.dc.html» الأربعة — مسجَّلة لا مفعَّلة:
 *   `lesson-bundle` ← HeroLessonBundle · `curriculum-navigator` ← CurriculumNavigator
 *   `product-types` ← ProductTypeWidget · `popular-by-grade` ← PopularByGradeWidget
 *
 * الهندسة من القالب حرفيًّا (widgets.module.css)؛ والبيانات كلّها من الواجهة
 * البرمجية. عقيدة الصدق نفسها في `design.tsx`: قيم القالب عيّناتُ تخطيط —
 * «١٠ ملفات»، «٤.٩ (٣١٢)»، «اشتراها ١٬٨٤٠»، «١٨٤٠ منتجًا» — لا يُنسخ منها
 * شيء؛ وكلّ عنصرٍ يحمل رقمًا يُصيَّر بحقله وحده أو يُحذف.
 *   · ٢٠٠ + لا شيء منشور ⇒ القسم لا يُصيَّر. و`status != 200` ⇒ `<Failed />`.
 */
import type { ReactNode } from 'react';
import { formatMoney, qty } from '@tahdheer/design-system/format';
import { apiGetCollection } from '../../lib/api';
import { routes } from '../../lib/routes';
import type { HomepageSectionId } from '../../lib/homepage';
import { termPath } from '../../app/(catalog)/_components/dimensions';
import { FILE_FORMS, PRODUCT_FORMS } from '../../app/(catalog)/_components/query';
import { fetchBundle, fetchBundles, type BundleDto } from '../../app/bundles/_lib/bundles-api';
import { CurriculumNavigator, type SemesterTerm } from './CurriculumNavigator';
import { Failed, failed } from './Failed';
import { Icon, pick, type IconName } from './icons';
import { PopularByGrade } from './PopularByGrade';
import type { GradeTerm, StageTerm } from './StagesExplorer';
import styles from './widgets.module.css';

interface KindTerm {
  slug: string;
  name: string;
  productsCount?: number;
}

function Fault() {
  return (
    <div className={styles.wrap}>
      <Failed />
    </div>
  );
}

/* ═══════════════════════════ HeroLessonBundle ═══════════════════════════ */

/** أوّل حزمةٍ منشورة — أو لا شيء: لا طرفَ للحزم اليوم فيسقط القسم صامتًا. */
async function firstBundle(): Promise<BundleDto | null> {
  const list = await fetchBundles();
  if (list.state !== 'ok' || list.bundles.length === 0) return null;
  const first = list.bundles[0];
  return first ? fetchBundle(first.slug) : null;
}

/** «PDF · قابل للتعديل» ⇒ «PDF»: أوّل مقطعٍ من سطر التعريف هو الصيغة. */
function kindOf(meta?: string): string | null {
  const head = meta?.split('·')[0]?.trim();
  return head ? head : null;
}

/**
 * ما يُصيَّر من القالب بلا مصدر يُحذف: سطر التقييم وعدد المشترين (لا حقل
 * لهما في `BundleDto`)، وسطر «الصيغ» يُشتقّ من تعريفات العناصر إن كُتبت.
 */
async function LessonBundleSection() {
  const bundle = await firstBundle();
  if (!bundle) return null;

  const listMinor = bundle.itemsTotalMinor ?? bundle.items.reduce((sum, item) => sum + item.priceMinor, 0);
  const now = formatMoney(bundle.priceMinor);
  const was = listMinor > bundle.priceMinor ? formatMoney(listMinor) : null;
  const kinds = [...new Set(bundle.items.map((item) => kindOf(item.meta)).filter(Boolean))];

  return (
    <div className={styles.wrap}>
      <div className={styles.bundle}>
        <div>
          <p className={`${styles.eyebrow} ${styles.eyebrowGold}`}>حزمة الدرس الكامل</p>
          <h2 className={styles.bundleTitle}>{bundle.title}</h2>
          {bundle.intro ? <p className={styles.bundleLede}>{bundle.intro}</p> : null}
          <ul className={styles.bundleList} role="list">
            {bundle.items.map((item) => (
              <li key={item.id} className={styles.bundleItem}>
                <span className={styles.tick} aria-hidden="true">
                  <Icon name="check" size={12} strokeWidth={2.6} />
                </span>
                {item.title}
              </li>
            ))}
          </ul>
          <div className={styles.ctas}>
            <a className={styles.btnNavy} href={routes.bundle(bundle.slug)}>
              عرض الحزمة
            </a>
            <a className={styles.btnGhost} href={routes.bundles}>
              استعرض الحزم الكاملة
            </a>
          </div>
        </div>

        <div className={styles.bundleCard}>
          <div className={styles.bundleHead}>
            <div className={styles.bundleHeadCopy}>
              <b className={styles.bundleName}>{bundle.title}</b>
            </div>
            <span className={styles.bundleCount}>{qty(bundle.items.length, FILE_FORMS)}</span>
          </div>
          <div className={styles.previews}>
            {bundle.items.map((item) => {
              const kind = kindOf(item.meta);
              return (
                <a key={item.id} className={styles.preview} href={routes.product(item.slug)} aria-label={item.title}>
                  {kind ? <span className={styles.previewKind}>{kind}</span> : null}
                  <span className={styles.previewLine} />
                  <span className={styles.previewLine} style={{ inlineSize: '78%' }} />
                  <span className={styles.previewLine} style={{ inlineSize: '56%' }} />
                  <span className={styles.previewName}>{item.title}</span>
                </a>
              );
            })}
          </div>
          <div className={styles.bundleFoot}>
            {kinds.length > 0 ? (
              <div className={styles.bundleFacts}>
                <span>{kinds.join(' · ')}</span>
              </div>
            ) : null}
            <div className={styles.bundlePrice}>
              <b className={styles.bundleNow} dir="ltr">
                {now.amount} <span className={styles.bundleCur}>{now.currency}</span>
              </b>
              {was ? (
                <s className={styles.bundleWas}>
                  <span className={styles.srOnly}>بدل </span>
                  <bdi dir="ltr">{was.amount}</bdi>
                </s>
              ) : null}
            </div>
          </div>
        </div>
      </div>
    </div>
  );
}

/* ═══════════════════════════ CurriculumNavigator ════════════════════════ */

async function loadTree(): Promise<{
  stages: StageTerm[];
  grades: GradeTerm[];
  semesters: SemesterTerm[];
  status: number;
}> {
  const [stages, grades, semesters] = await Promise.all([
    apiGetCollection<StageTerm>('/api/v1/catalog/stages'),
    apiGetCollection<GradeTerm>('/api/v1/catalog/grades'),
    apiGetCollection<SemesterTerm>('/api/v1/catalog/semesters'),
  ]);
  /* أسوأ الحالتين يحكم: نصفُ شجرةٍ عطلٌ لا شجرة. والفصول اختياريّة. */
  const status = stages.status !== 200 ? stages.status : grades.status;
  return { stages: stages.data, grades: grades.data, semesters: semesters.data, status };
}

async function CurriculumNavigatorSection() {
  const { stages, grades, semesters, status } = await loadTree();
  if (failed(status)) return <Fault />;
  if (stages.length === 0) return null;

  return (
    <div className={styles.wrap}>
      <CurriculumNavigator stages={stages} grades={grades} semesters={semesters} />
    </div>
  );
}

/* ═══════════════════════════ ProductTypeWidget ═══════════════════════════ */

/* أيقونة كلّ نوعٍ باسمه كما في القالب، لا بترتيبه. */
const KIND_ICONS: { match: RegExp; icon: IconName }[] = [
  { match: /تحضير|تحاضير/, icon: 'pen' },
  { match: /عرض|بوربوينت|شرائح/, icon: 'grid' },
  { match: /ورق|أوراق/, icon: 'files' },
  { match: /اختبار/, icon: 'check' },
  { match: /واجب/, icon: 'plus' },
  { match: /نشاط|أنشطة/, icon: 'palette' },
  { match: /ملخص/, icon: 'book' },
  { match: /بنك|أسئلة/, icon: 'globe' },
  { match: /حل/, icon: 'download' },
  { match: /خرائط|خريطة|مفاهيم/, icon: 'medal' },
  { match: /إنجاز/, icon: 'trophy' },
  { match: /حزم/, icon: 'tag' },
];

const KIND_TONES: readonly string[] = [
  styles.toneTeal ?? '',
  styles.toneNavy ?? '',
  styles.toneSand ?? '',
  styles.toneLilac ?? '',
];

/**
 * «الأعداد لكلّ المراحل» تُكتب فقط حين يبثّ الكتالوج عدّادًا لنوعٍ واحد على
 * الأقلّ؛ وبلا عدّادات لا سطرَ نطاق ولا أرقام — الأسماء والوجهات وحدها.
 */
async function ProductTypesSection() {
  const { data, status } = await apiGetCollection<KindTerm>('/api/v1/catalog/kinds');
  if (failed(status)) return <Fault />;
  if (data.length === 0) return null;

  const counted = data.some((kind) => typeof kind.productsCount === 'number');

  return (
    <div className={styles.wrap}>
      <div className={styles.head}>
        <div>
          <p className={styles.eyebrow}>اختر نوع المحتوى</p>
          <h2 className={styles.h2}>ما الذي تبحث عنه اليوم؟</h2>
        </div>
        {counted ? <span className={styles.scope}>الأعداد لكل المراحل</span> : null}
      </div>
      <ul className={styles.tiles} role="list">
        {data.map((kind, index) => {
          const icon = KIND_ICONS.find((look) => look.match.test(kind.name))?.icon ?? 'files';
          return (
            <li key={kind.slug}>
              <a className={styles.tile} href={termPath('kinds', kind.slug)}>
                <span className={`${styles.tileIcon} ${pick(KIND_TONES, index, '')}`}>
                  <Icon name={icon} size={20} strokeWidth={1.9} />
                </span>
                <span className={styles.tileText}>
                  <b className={styles.tileName}>{kind.name}</b>
                  {typeof kind.productsCount === 'number' ? (
                    <bdi className={styles.tileCount}>{qty(kind.productsCount, PRODUCT_FORMS)}</bdi>
                  ) : null}
                </span>
              </a>
            </li>
          );
        })}
      </ul>
    </div>
  );
}

/* ═══════════════════════════ PopularByGradeWidget ═══════════════════════ */

async function PopularByGradeSection() {
  const { data, status } = await apiGetCollection<GradeTerm>('/api/v1/catalog/grades');
  if (failed(status)) return <Fault />;
  if (data.length === 0) return null;

  return (
    <div className={styles.wrap}>
      <PopularByGrade grades={data} />
    </div>
  );
}

/* --------------------------------------------------------------- registry */

export const WIDGET_SECTIONS = {
  'lesson-bundle': () => <LessonBundleSection />,
  'curriculum-navigator': () => <CurriculumNavigatorSection />,
  'product-types': () => <ProductTypesSection />,
  'popular-by-grade': () => <PopularByGradeSection />,
} satisfies Partial<Record<HomepageSectionId, () => ReactNode>>;
