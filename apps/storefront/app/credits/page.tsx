/**
 * مصادر الرسوم والشعارات — ‎/credits‎.
 *
 * ترخيص Freepik المجّانيّ يشترط الإسناد «Designed by <author> / Freepik»، فهذه
 * الصفحة تُبنى من السجلّ نفسه (`lib/art.ts`) لا من قائمةٍ مكتوبة: كلّ مفتاحٍ في
 * `CREDITS` يظهر، ومواضع استعماله تُشتقّ من المجموعات التي تحمله — رسمٌ يُضاف
 * إلى السجلّ يظهر إسناده هنا تلقائيًّا، ولا إسنادَ لرسمٍ غير موجود.
 *
 * الصدق: إسنادان لم يُثبتهما ملفّ ترخيصٍ مرافق (ASSET-MAP §٤) يُوسمان «غير
 * مؤكَّد» مع السبب، لا يُعرضان يقينًا. وشعار «مدى» علامةٌ تجاريّة لا رسمٌ
 * مرخَّص — له قسمه ونصّه.
 */
import type { Metadata } from 'next';
import Link from 'next/link';
import { ThemedArt } from '../../components/home/ThemedArt';
import {
  CREDITS,
  EMPTY_ART,
  HERO_ART,
  PAYMENT_ART,
  STAGE_ART,
  SUBJECT_ART,
  TEACHER_ART,
  type Art,
  type CreditKey,
} from '../../lib/art';
import { routes } from '../../lib/routes';
import styles from './page.module.css';

export const metadata: Metadata = {
  title: 'مصادر الرسوم والشعارات',
  description: 'إسناد الرسوم التوضيحيّة المستعملة في المتجر إلى مصمّميها، وبيان العلامات التجاريّة المعروضة.',
  alternates: { canonical: routes.credits },
  robots: { index: true, follow: true },
};

/** مجموعات السجلّ بأسماء مواضعها في المتجر — مصدر «أين تُستعمل». */
const GROUPS: readonly { label: string; items: readonly Art[] }[] = [
  { label: 'أيقونات الموادّ الدراسيّة', items: Object.values(SUBJECT_ART) },
  { label: 'رسوم المراحل الدراسيّة', items: Object.values(STAGE_ART) },
  { label: 'رسوم الحالات الفارغة (السلة، المكتبة، الطلبات، الصفحات غير الموجودة)', items: Object.values(EMPTY_ART) },
  { label: 'تركيب واجهة الصفحة الرئيسيّة', items: Object.values(HERO_ART) },
  { label: 'رسوم المعلّمين', items: Object.values(TEACHER_ART) },
  { label: 'شارات وسائل الدفع', items: Object.values(PAYMENT_ART) },
];

/** ما لم يُثبته ملفّ ترخيصٍ مرافق — ASSET-MAP-2026-09-25 §٤ (UNVERIFIED). */
const UNVERIFIED: Partial<Record<CreditKey, string>> = {
  freepik:
    'حزمتا الكتب والكرة الأرضيّة وصلتا بلا ملفّ ترخيص؛ نسبناهما إلى Freepik من الشعار المطبوع على الصورة نفسها.',
  rawpixel:
    'مشهد رفوف المختبر نُسب إلى rawpixel.com استدلالًا: معرّفه يطابق محتوى حزمةٍ تحمل ترخيص rawpixel.com، ولم يرافقه ملفّ ترخيصٍ خاصّ به.',
};

/** نصّ الإسناد كما يشترطه الترخيص حرفًا. */
function attribution(key: CreditKey): string {
  const { author, source } = CREDITS[key];
  return author === source ? `Designed by ${author}` : `Designed by ${author} / ${source}`;
}

/** أقصى عددٍ من الرسوم المصغّرة لكلّ مصدر — عيّنةٌ تعرّف بالأسلوب لا معرضٌ كامل. */
const PREVIEW = 4;

export default function CreditsPage() {
  const illustrators = (Object.keys(CREDITS) as CreditKey[])
    .filter((key) => key !== 'mada')
    .map((key) => {
      const groups = GROUPS.filter((group) => group.items.some((art) => art.credit === key));
      const preview = GROUPS.flatMap((group) => group.items.filter((art) => art.credit === key)).slice(0, PREVIEW);
      return { key, credit: CREDITS[key], groups, preview, unverified: UNVERIFIED[key] };
    });
  const mada = CREDITS.mada;

  return (
    <div className={styles.page}>
      <div className={styles.wrap}>
        <nav aria-label="مسار التنقّل">
          <ol className={styles.crumbs}>
            <li>
              <Link href={routes.home}>الرئيسية</Link>
            </li>
            <li aria-current="page">مصادر الرسوم والشعارات</li>
          </ol>
        </nav>

        <header className={styles.head}>
          <p className={styles.eyebrow}>الإسناد</p>
          <h1 className={styles.h1}>مصادر الرسوم والشعارات</h1>
          <p className={styles.lede}>
            الرسوم التوضيحيّة في المتجر من مكتبة <bdi dir="ltr">Freepik</bdi> بترخيصها المجّانيّ الذي يشترط
            ذكر المصمّم. هنا كلّ مصدرٍ ومواضع استعماله، وما لم نتحقّق منه بملفّ ترخيصٍ مذكورٌ صراحةً.
          </p>
        </header>

        <section className={styles.section} aria-labelledby="credits-art">
          <h2 className={styles.h2} id="credits-art">
            الرسوم التوضيحيّة
          </h2>
          <ul className={styles.list} role="list">
            {illustrators.map(({ key, credit, groups, preview, unverified }) => (
              <li key={key} className={styles.card}>
                <div className={styles.cardHead}>
                  <h3 className={styles.author}>
                    <bdi dir="ltr">{credit.author}</bdi>
                  </h3>
                  {unverified ? (
                    <span className={`${styles.status} ${styles.statusWarn}`}>غير مؤكَّد</span>
                  ) : (
                    <span className={`${styles.status} ${styles.statusOk}`}>بملفّ ترخيص</span>
                  )}
                </div>

                {preview.length > 0 ? (
                  <div className={styles.thumbs} aria-hidden="true">
                    {preview.map((art) => (
                      <span key={art.src} className={styles.thumb}>
                        <ThemedArt art={art} alt="" sizes="56px" className={styles.thumbImg} />
                      </span>
                    ))}
                  </div>
                ) : null}

                <dl className={styles.facts}>
                  <div className={styles.fact}>
                    <dt>الإسناد</dt>
                    <dd>
                      <a className={styles.link} href={credit.url} rel="noopener noreferrer" target="_blank">
                        <bdi dir="ltr">{attribution(key)}</bdi>
                        <span className={styles.srOnly}> (يفتح في نافذة جديدة)</span>
                      </a>
                    </dd>
                  </div>
                  <div className={styles.fact}>
                    <dt>يُستعمل في</dt>
                    <dd>
                      {groups.length > 0 ? (
                        <ul className={styles.uses} role="list">
                          {groups.map((group) => (
                            <li key={group.label}>{group.label}</li>
                          ))}
                        </ul>
                      ) : (
                        'لا رسم منه معروضٌ حاليًّا.'
                      )}
                    </dd>
                  </div>
                </dl>

                {unverified ? <p className={styles.note}>{unverified}</p> : null}
              </li>
            ))}
          </ul>
        </section>

        <section className={styles.section} aria-labelledby="credits-marks">
          <h2 className={styles.h2} id="credits-marks">
            العلامات التجاريّة
          </h2>
          <div className={`${styles.card} ${styles.markCard}`}>
            <span className={styles.markLogo}>
              <ThemedArt art={PAYMENT_ART.mada} alt="شعار مدى" sizes="96px" className={styles.markImg} />
            </span>
            <div>
              <h3 className={styles.author}>مدى</h3>
              <p className={styles.markBody}>
                شعار «مدى» علامةٌ تجاريّة مملوكة لـ{mada.author}. يُعرض في التذييل وصفحة الدفع وصفحة طرق
                الدفع لبيان وسيلة دفعٍ مقبولة فقط، ولا يعني رعايةً أو شراكة.
              </p>
              <a className={styles.link} href={mada.url} rel="noopener noreferrer" target="_blank">
                <bdi dir="ltr">mada.com.sa</bdi>
                <span className={styles.srOnly}> (يفتح في نافذة جديدة)</span>
              </a>
            </div>
          </div>
        </section>

        <p className={styles.footNote}>
          هل ترى رسمًا بإسنادٍ ناقص أو خاطئ؟{' '}
          <Link className={styles.link} href={routes.contact}>
            راسلنا
          </Link>{' '}
          ونصحّحه.
        </p>
      </div>
    </div>
  );
}
