'use client';
/**
 * «الوصول السريع» — مرحلة ← صفّ ← مادّة، ثمّ انتقالٌ إلى أرشيفٍ حقيقيّ.
 *
 * منقولٌ عن `HeroSmartFinder` في «إعادة تصميم واجهة المتجر»
 * (Home Components.dc.html)، بثلاثة تعديلات أوجبها ما يملكه المتجر:
 *
 *  ١· **لا خطوة «المسار»**. التصميم ينزل مرحلة ← صفّ ← **مسار** ← فصل ← مادّة،
 *     ولا جدول `tracks` في قاعدتنا ولا عمود مسار على المنتج. خطوةٌ لا مصدر
 *     لها تُحذف ولا تُملأ بقيمٍ مخترَعة.
 *
 *  ٢· **الموادّ من وجوه البحث لا من المنهج.** `subjects` بلا مفتاح أب، فـ«موادّ
 *     الصفّ الخامس» ليست حقيقةً يملكها النظام. والمعروض هنا أضيق وأصدق:
 *     **الموادّ التي لها منتجات منشورة في هذا الصفّ** — تأتي من وجه `subject`
 *     في `/api/v1/search?grade=<slug>` بعدّاداتها الحقيقيّة.
 *
 *  ٣· **مفردات المرشّحات ليست واحدة.** وجه `grade` يبثّ **سبيكة**
 *     (`أول-ابتدائي`) ووجه `subject` يبثّ **اسمًا** (`الرياضيات`). وإعادةُ قيمة
 *     الوجه كما جاءت هي الطريق الآمن الوحيد: تمريرُ اسم صفٍّ من
 *     `/catalog/grades` إلى البحث يُرجع «لا نتائج» صامتة (مقيس).
 *
 * والفشل يُعرض ولا يُبتلع: خطوة الموادّ لها حالاتها الأربع — تحميل، ونجاحٌ
 * بقائمة، ونجاحٌ بلا موادّ، وعطل — ولكلٍّ نصّه.
 */
import { useCallback, useId, useRef, useState } from 'react';
import { apiBase } from '../../lib/api';
import { routes } from '../../lib/routes';
import { termPath } from '../../app/(catalog)/_components/dimensions';
import { Icon } from './icons';
import { catalogHref } from '../../app/(catalog)/_components/query';
import type { GradeTerm, StageTerm } from './StagesExplorer';
import styles from './SmartFinder.module.css';

/** مادّةٌ كما يبثّها وجه البحث: القيمة اسمٌ عربيّ، والعدّاد حقيقيّ. */
interface SubjectFacet {
  value: string;
  count: number;
}

type SubjectsState =
  | { kind: 'idle' }
  | { kind: 'loading' }
  | { kind: 'ready'; items: SubjectFacet[] }
  | { kind: 'failed' };

interface FacetGroup {
  key: string;
  values?: { value: string; count: number }[];
}

export function SmartFinder({ stages, grades }: { stages: StageTerm[]; grades: GradeTerm[] }) {
  const groupId = useId();
  const [stage, setStage] = useState<string | null>(null);
  const [grade, setGrade] = useState<string | null>(null);
  const [subject, setSubject] = useState<string | null>(null);
  const [subjects, setSubjects] = useState<SubjectsState>({ kind: 'idle' });

  /*
   * آخر طلبٍ يفوز. الزائر ينقر صفوفًا متتابعة أسرع من الشبكة، فردٌّ بطيء
   * لصفٍّ سابق كان سيحلّ محلّ ردّ الصفّ المعروض — قائمةُ موادّ لا تخصّه.
   */
  const requestSeq = useRef(0);

  const ownedGrades = stage === null ? [] : grades.filter((g) => g.stage?.slug === stage);

  const loadSubjects = useCallback(async (gradeSlug: string) => {
    const base = apiBase();
    if (!base) {
      setSubjects({ kind: 'failed' });
      return;
    }
    const seq = ++requestSeq.current;
    setSubjects({ kind: 'loading' });
    try {
      const res = await fetch(`${base}/api/v1/search?grade=${encodeURIComponent(gradeSlug)}`, {
        headers: { Accept: 'application/json' },
        cache: 'no-store',
      });
      if (seq !== requestSeq.current) return;
      if (!res.ok) {
        setSubjects({ kind: 'failed' });
        return;
      }
      const payload = (await res.json()) as { facets?: { taxonomy?: FacetGroup[] } };
      if (seq !== requestSeq.current) return;
      const group = payload.facets?.taxonomy?.find((f) => f.key === 'subject');
      setSubjects({ kind: 'ready', items: group?.values ?? [] });
    } catch {
      if (seq !== requestSeq.current) return;
      setSubjects({ kind: 'failed' });
    }
  }, []);

  const pickStage = (slug: string) => {
    setStage(slug === stage ? null : slug);
    setGrade(null);
    setSubject(null);
    setSubjects({ kind: 'idle' });
  };

  const pickGrade = (slug: string) => {
    const next = slug === grade ? null : slug;
    setGrade(next);
    setSubject(null);
    if (next === null) {
      setSubjects({ kind: 'idle' });
      return;
    }
    void loadSubjects(next);
  };

  /*
   * الوجهة أعمقُ أرشيفٍ حقيقيّ بلغه الاختيار — ولا يُخترع مسارُ تقاطعٍ لا
   * وجود له. صفٌّ + مادّة ⇒ أرشيف الصفّ بمرشّح المادّة (وهو ما يقبله
   * `catalogHref` فعلًا)؛ ومرحلةٌ وحدها ⇒ أرشيف المرحلة.
   */
  const href =
    grade !== null
      ? catalogHref(termPath('grades', grade), subject !== null ? { facets: { subject } } : {})
      : stage !== null
        ? termPath('stages', stage)
        : null;

  const step =
    grade !== null ? 'الخطوة ٣ من ٣' : stage !== null ? 'الخطوة ٢ من ٣' : 'الخطوة ١ من ٣';

  return (
    <div className={styles.panel}>
      <div className={styles.panelHead}>
        <b className={styles.panelTitle}>الوصول السريع</b>
        <span className={styles.step}>{step}</span>
      </div>

      <div className={styles.row}>
        <span className={styles.rowLabel} id={`${groupId}-stage`}>
          المرحلة
        </span>
        <div className={styles.chips} role="group" aria-labelledby={`${groupId}-stage`}>
          {stages.map((s) => (
            <button
              key={s.slug}
              type="button"
              aria-pressed={stage === s.slug}
              className={stage === s.slug ? `${styles.chip} ${styles.chipOn}` : styles.chip}
              onClick={() => pickStage(s.slug)}
            >
              {s.name}
            </button>
          ))}
        </div>
      </div>

      {stage !== null && ownedGrades.length > 0 ? (
        <div className={styles.row}>
          <span className={styles.rowLabel} id={`${groupId}-grade`}>
            الصفّ
          </span>
          <div className={styles.chips} role="group" aria-labelledby={`${groupId}-grade`}>
            {ownedGrades.map((g) => (
              <button
                key={g.slug}
                type="button"
                aria-pressed={grade === g.slug}
                className={grade === g.slug ? `${styles.chip} ${styles.chipOn}` : styles.chip}
                onClick={() => pickGrade(g.slug)}
              >
                {g.name}
              </button>
            ))}
          </div>
        </div>
      ) : null}

      {grade !== null ? (
        <div className={styles.row}>
          <span className={styles.rowLabel} id={`${groupId}-subject`}>
            المادّة
          </span>
          {/*
            منطقةٌ حيّة قائمةٌ دائمًا في هذا الفرع: تغيّرُ محتواها يُعلَن، بخلاف
            عنصرٍ يُضاف إلى الشجرة ممتلئًا فلا تقرؤه قارئة الشاشة.
          */}
          <div className={styles.chips} role="group" aria-labelledby={`${groupId}-subject`} aria-live="polite">
            {subjects.kind === 'loading' ? (
              <span className={styles.note}>جارٍ جلب موادّ هذا الصفّ…</span>
            ) : subjects.kind === 'failed' ? (
              <span className={styles.note}>تعذّر جلب الموادّ — تابع بالصفّ وحده.</span>
            ) : subjects.kind === 'ready' && subjects.items.length === 0 ? (
              <span className={styles.note}>لا موادّ منشورة في هذا الصفّ بعد.</span>
            ) : subjects.kind === 'ready' ? (
              subjects.items.map((s) => (
                <button
                  key={s.value}
                  type="button"
                  aria-pressed={subject === s.value}
                  className={subject === s.value ? `${styles.chip} ${styles.chipOn}` : styles.chip}
                  onClick={() => setSubject(s.value === subject ? null : s.value)}
                >
                  {s.value}
                  <span className={styles.chipCount}>{s.count}</span>
                </button>
              ))
            ) : null}
          </div>
        </div>
      ) : null}

      <div className={styles.actions}>
        {/*
          لا زرَّ معطَّلًا يُنقر بلا أثر: قبل أيّ اختيار لا تُصيَّر الوجهة أصلًا،
          ويُعرض بدلها سطرٌ يقول ما ينقص. زرٌّ بلا فعل ادّعاء.
        */}
        {href !== null ? (
          <a className={styles.cta} href={href}>
            عرض المحتوى
            <Icon name="chev" size={15} strokeWidth={2.2} />
          </a>
        ) : (
          /* هيئة القالب المعطَّلة — لا زرًّا ولا رابطًا: لا يُبلغ بالتبويب ولا يُنقر بلا أثر. */
          <span className={`${styles.cta} ${styles.ctaOff}`} aria-disabled="true">
            عرض المحتوى
            <Icon name="chev" size={15} strokeWidth={2.2} />
          </span>
        )}
        <a className={styles.ghost} href={routes.stages}>
          تصفّح جميع المناهج
        </a>
      </div>
    </div>
  );
}
