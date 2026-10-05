/**
 * العيّنات المجّانيّة — «لن تشتري ملفًّا لم تره».
 *
 * الصفحة لا تعرض إلّا ما تقدر الواجهة البرمجية على خدمته فعلًا: الشرط
 * الثلاثيّ (دورٌ `sample` · مرئيّة `public_sample` · رابطٌ أمكن إصداره)
 * مطبَّقٌ في الخادم، وهنا يُصيَّر ما وصل بلا إضافة ولا تخمين.
 *
 * ومجموعاتها تُشتقّ من المعروض لا من قائمةٍ مكتوبة: نوعٌ بلا عيّنةٍ منشورة
 * لا يظهر عنوانًا فارغًا ينتظر محتوًى. وصفرُ عيّنةٍ يعني حالةً صريحة تدعو
 * إلى التصفّح، لا شبكةَ حشوٍ ولا صفحةً بيضاء.
 */
import type { Metadata } from 'next';
import { ProductGrid } from '../(catalog)/_components/ProductGrid';
import { apiGet, toCardProps } from '../../lib/api';
import { ConnectionError } from '../../components/search/ConnectionError';
import { routes } from '../../lib/routes';
import type { ProductCardDto } from '@tahdheer/api-sdk/types';
import { qty } from '@tahdheer/design-system/format';
import { SectionHeading } from '@tahdheer/ui';
import styles from './page.module.css';

export const metadata: Metadata = {
  title: 'عيّنات مجّانية',
  description:
    'حمّل عيّنة كاملة من كل نوع قبل الشراء — تحضير وعروض وأوراق عمل واختبارات، بلا حساب وبلا بريد.',
  /* صفحةٌ مفهرسة بلا canonical كانت الوحيدة بين صفحات القوائم. */
  alternates: { canonical: routes.samples },
};

interface SampleFileDto {
  url: string;
  name: string;
  mime?: string | null;
}

interface SampleItemDto {
  product: ProductCardDto;
  resourceType?: string | null;
  previews: SampleFileDto[];
}

interface SamplesPayload {
  items: SampleItemDto[];
  resourceTypes: string[];
  count: number;
}

/** نوعٌ لم يسجّله الكتالوج يجمع تحت عنوانٍ محايد لا يدّعي تصنيفًا. */
const UNTYPED = 'ملفّات أخرى';

/**
 * صيغ الجمع العربيّة للعدّاد. «٤ عيّنة» خطأٌ صريح — الثلاثة إلى العشرة
 * تأخذ الجمع، وما فوقها المفرد المنصوب. الحالة الصفريّة لا تُصيَّر أصلًا
 * (المجموعة الفارغة تُرشَّح قبل العرض) لكنّها مذكورة كي تبقى الصيغ كاملة.
 */
/**
 * صيغة الملفّ من نوعه المُعلَن. الاسم المخزَّن لا يُعرض: هو اسم الرفع
 * («غلاف معاينة — اختبارات الأحياء — الصف الثالث…svg») فيكرّر عنوان البطاقة
 * فوقه ثمّ يُقصّ، فلا يضيف معلومةً ويزاحم ما يضيف.
 *
 * ونوعٌ غير معروف يُسقِط الشريحة ولا يُخمَّن له امتداد.
 */
const MIME_LABEL: Record<string, string> = {
  'application/pdf': 'PDF',
  'application/vnd.openxmlformats-officedocument.wordprocessingml.document': 'DOCX',
  'application/vnd.openxmlformats-officedocument.presentationml.presentation': 'PPTX',
  'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet': 'XLSX',
  'image/svg+xml': 'SVG',
  'image/png': 'PNG',
  'image/jpeg': 'JPG',
};

function formatLabel(file: SampleFileDto): string | null {
  const mime = file.mime?.toLowerCase();
  if (mime && MIME_LABEL[mime]) return MIME_LABEL[mime];

  const extension = file.name.split('.').pop();

  return extension && extension !== file.name ? extension.toUpperCase() : null;
}

const SAMPLE_FORMS = {
  zero: 'لا عيّنات',
  one: 'عيّنة واحدة',
  two: 'عيّنتان',
  few: 'عيّنات',
  many: 'عيّنة',
} as const;

export default async function SamplesPage() {
  const { data, status } = await apiGet<SamplesPayload>('/api/v1/samples');

  /*
   * عطلُ الخادم ليس «لا عيّنة منشورة». `apiGet` تُعيد `data: null` على ٥٠٠
   * كما تُعيدها على الفراغ، فكان انقطاعُ الواجهة البرمجية يُترجَم إلى إعلانٍ
   * عن الكتالوج ثمّ يدفع الزائر إلى سطحٍ آخر بحجّةٍ كاذبة.
   */
  if (status !== 200) {
    return (
      <div className={styles.page}>
        <ConnectionError href={routes.samples} failure={status === 0 ? 'network' : 'http'} />
      </div>
    );
  }

  const items = data?.items ?? [];

  /*
   * التجميع بترتيب `resourceTypes` القادم من الخادم — وهو ترتيب الظهور
   * نفسه، فلا يُعاد فرزه هنا فيختلف ترتيب الصفحة عن ترتيب مصدرها.
   */
  const groups = [...(data?.resourceTypes ?? []), UNTYPED]
    .map((type) => ({
      type,
      entries: items.filter((item) =>
        type === UNTYPED ? !item.resourceType : item.resourceType === type,
      ),
    }))
    .filter((group) => group.entries.length > 0);

  return (
    <div className={styles.page}>
      <header className={styles.head}>
        {/* عنوانُ الصفحة الرئيس: كانت تبدأ بـ`h2` فلا `h1` فيها البتّة. */}
        <SectionHeading as="h1" eyebrow="قبل أن تدفع" title="جرّب عيّنة مجّانية من كل نوع" />
        <p className={styles.lede}>
          لن تشتري ملفًّا لم تره. حمّل العيّنة كاملةً بلا حساب وبلا بريد، ثمّ قرّر.
        </p>
      </header>

      {groups.length === 0 ? (
        /*
         * الحالة الصريحة. لا «قريبًا» ولا شبكة حشو: لا عيّنة منشورة الآن،
         * والزائر يُدلّ على أقرب سطحٍ يفيده بدل أن يُترك أمام فراغ.
         */
        <section className={styles.empty}>
          <h2 className={styles.emptyTitle}>لا عيّنات منشورة الآن</h2>
          <p className={styles.emptyBody}>
            نضيف العيّنات مع الملفّات أوّلًا بأوّل. تصفّح المتجر ريثما تُنشر، أو اطلب ملفًّا
            بعينه ونجهّزه لك.
          </p>
          <div className={styles.emptyActions}>
            <a className={styles.emptyPrimary} href={routes.shop}>
              تصفّح الملفّات
            </a>
            <a className={styles.emptySecondary} href={routes.request}>
              اطلب ملفًّا
            </a>
          </div>
        </section>
      ) : (
        /*
         * المجموعات شبكةٌ لا عمود: مجموعةٌ بعيّنةٍ واحدة لا تترك صفًّا كاملًا
         * فارغًا خلف بطاقتها؛ وذات العيّنات الكثيرة تأخذ الصفّ كلّه (`data-many`).
         */
        <div className={styles.groups}>
        {groups.map((group) => (
          <section
            className={styles.group}
            key={group.type}
            data-many={group.entries.length > 1 ? '' : undefined}
          >
            <h2 className={styles.groupTitle}>
              {group.type}
              {/* العدد حقيقة معدودة لا زينة، ولاتينيّ معزول داخل العربيّة. */}
              <span className={styles.groupCount}>{qty(group.entries.length, SAMPLE_FORMS)}</span>
            </h2>

            <div className={styles.groupBody}>
            {/* «يتضمن نموذجًا» هنا وحدها: قائمة العيّنات نفسها هي الدليل، لا وسمٌ يُخمَّن. */}
            <ProductGrid items={group.entries.map((entry) => ({ ...toCardProps(entry.product), hasSample: true }))} />

            <div className={styles.groupFiles}>
            <h3 className={styles.filesTitle}>حمّل العيّنة مباشرةً</h3>
            <ul className={styles.files} role="list">
              {group.entries.map((entry) =>
                entry.previews.map((file) => (
                  <li className={styles.file} key={file.url}>
                    <span className={styles.fileIcon} aria-hidden="true">
                      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
                        <path d="M12 4v11m0 0l-4.5-4.5M12 15l4.5-4.5M5 19h14" />
                      </svg>
                    </span>
                    {/*
                      `download` مقصود: العيّنة ملفٌّ يُحمَّل لا صفحةٌ تُتصفَّح،
                      وفتحُ PDF في تبويبٍ جديد يترك الزائر خارج المتجر.

                      واسم الرابط يحمل عنوان المنتج كاملًا: القائمة تحت شبكةٍ
                      من البطاقات نفسها، فـ«تحميل العيّنة» وحدها تتكرّر بلا
                      تمييز على كلّ مُدرِّج شاشة.
                    */}
                    <a className={styles.fileLink} href={file.url} download>
                      تحميل عيّنة {entry.product.title}
                    </a>
                    {(() => {
                      const label = formatLabel(file);
                      return label ? <span className={styles.fileFormat}>{label}</span> : null;
                    })()}
                  </li>
                )),
              )}
            </ul>
            </div>
            </div>
          </section>
        ))}
        </div>
      )}
    </div>
  );
}
