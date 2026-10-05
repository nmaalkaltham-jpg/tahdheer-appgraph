'use client';
/**
 * «متصفّح المناهج» — أعمدةٌ متجاورة: مرحلة ← صفّ ← فصل دراسيّ ← مادّة.
 *
 * منقولٌ عن `CurriculumNavigator` في «Home Components.dc.html»، بما يملكه
 * المتجر فعلًا:
 *  · القالب ينزل حتّى الكتاب والوحدة والدرس، ولا جدول لها في قاعدتنا ولا
 *    طرفَ واجهةٍ يعيدها؛ فالعمق هنا ينتهي بالمادّة، والوجهة أرشيفُ الصفّ
 *    بمرشّحَي المادّة والفصل — وهو ما يقبله `catalogHref` فعلًا.
 *  · لا خطوة «المسار»: لا `tracks` في القاعدة.
 *  · موادّ الصفّ من وجه البحث (`/search?grade=`) بعدّاداتها الحقيقيّة، لا من
 *    قائمة الموادّ العامّة: `subjects` بلا مفتاح أب.
 *  · الأعداد في عمود المرحلة هي `productsCount` من الكتالوج حين يبثّه.
 */
import { useEffect, useId, useRef, useState } from 'react';
import { formatNumber } from '@tahdheer/design-system/format';
import { termPath } from '../../app/(catalog)/_components/dimensions';
import { catalogHref } from '../../app/(catalog)/_components/query';
import { Icon } from './icons';
import { facetValues, searchCatalog, type FacetValue } from './searchClient';
import type { GradeTerm, StageTerm } from './StagesExplorer';
import styles from './widgets.module.css';

export interface SemesterTerm {
  slug: string;
  name: string;
}

/* النتيجة تحمل مفتاح طلبها (صفّ + فصل): ما لا يطابق المعروض يُقرأ «تحميل». */
type SubjectsResult = { key: string; kind: 'ready'; items: FacetValue[] } | { key: string; kind: 'failed' };
type SubjectsState = SubjectsResult | { kind: 'idle' } | { kind: 'loading' };

interface ColumnProps {
  title: string;
  items: { key: string; name: string; meta?: string }[];
  selected: string | null;
  onPick: (key: string) => void;
  emptyText: string;
}

function Column({ title, items, selected, onPick, emptyText }: ColumnProps) {
  const labelId = useId();
  return (
    <div className={styles.column}>
      <div className={styles.columnHead} id={labelId}>
        <span>{title}</span>
        {items.length > 0 ? <span className={styles.columnCount}>{formatNumber(items.length)}</span> : null}
      </div>
      <div role="listbox" aria-labelledby={labelId} className={styles.listbox}>
        {items.map((item) => (
          <button
            key={item.key}
            type="button"
            role="option"
            aria-selected={selected === item.key}
            className={styles.option}
            onClick={() => onPick(item.key)}
          >
            <span className={styles.optionName}>{item.name}</span>
            {item.meta ? <span className={styles.optionMeta}>{item.meta}</span> : null}
          </button>
        ))}
        {items.length === 0 ? <div className={styles.columnEmpty}>{emptyText}</div> : null}
      </div>
    </div>
  );
}

export function CurriculumNavigator({
  stages,
  grades,
  semesters,
}: {
  stages: StageTerm[];
  grades: GradeTerm[];
  semesters: SemesterTerm[];
}) {
  const [stage, setStage] = useState<string | null>(null);
  const [grade, setGrade] = useState<string | null>(null);
  const [semester, setSemester] = useState<string | null>(null);
  const [subject, setSubject] = useState<string | null>(null);
  const [result, setResult] = useState<SubjectsResult | null>(null);
  const seq = useRef(0);
  const hasSemesters = semesters.length > 0;
  /* عمود المادّة يُملأ حين يكتمل ما قبله: الصفّ، والفصل إن وُجدت فصول. */
  const wanted = grade !== null && (!hasSemesters || semester !== null);
  const key = `${grade ?? ''}|${semester ?? ''}`;
  const subjects: SubjectsState = !wanted
    ? { kind: 'idle' }
    : result && result.key === key
      ? result
      : { kind: 'loading' };

  const ownedGrades = stage === null ? [] : grades.filter((g) => g.stage?.slug === stage);
  const gradeName = grades.find((g) => g.slug === grade)?.name;
  const semesterName = semesters.find((s) => s.slug === semester)?.name;

  useEffect(() => {
    if (!wanted || grade === null) return;
    const mine = ++seq.current;
    void searchCatalog({ grade, semester: semester ?? undefined }).then((res) => {
      if (mine !== seq.current) return;
      setResult(res.ok ? { key, kind: 'ready', items: facetValues(res.payload, 'subject') } : { key, kind: 'failed' });
    });
  }, [wanted, grade, semester, key]);

  const reset = () => {
    setStage(null);
    setGrade(null);
    setSemester(null);
    setSubject(null);
  };

  const pickStage = (slug: string) => {
    setStage(slug);
    setGrade(null);
    setSemester(null);
    setSubject(null);
  };

  const pickGrade = (slug: string) => {
    setGrade(slug);
    setSemester(null);
    setSubject(null);
  };

  const pickSemester = (slug: string) => {
    setSemester(slug);
    setSubject(null);
  };

  const done = grade !== null && subject !== null;
  const href = done
    ? catalogHref(termPath('grades', grade), { facets: { subject, semester: semester ?? undefined } })
    : null;

  const subjectEmpty =
    subjects.kind === 'loading'
      ? 'جارٍ جلب موادّ هذا الصفّ…'
      : subjects.kind === 'failed'
        ? 'تعذّر جلب الموادّ — أعد المحاولة.'
        : subjects.kind === 'ready'
          ? 'لا موادّ منشورة لهذا الاختيار بعد.'
          : hasSemesters
            ? 'اختر الفصل الدراسي أولًا'
            : 'اختر الصف أولًا';

  return (
    <>
      <div className={styles.head}>
        <div>
          <p className={styles.eyebrow}>متصفح المناهج السعودية</p>
          <h2 className={styles.h2}>
            {hasSemesters ? 'من المرحلة إلى المادة في أربع خطوات' : 'من المرحلة إلى المادة في ثلاث خطوات'}
          </h2>
        </div>
        <button type="button" className={styles.resetBtn} onClick={reset}>
          إعادة الاختيار
        </button>
      </div>

      <div className={styles.columns}>
        <Column
          title="المرحلة"
          items={stages.map((s) => ({
            key: s.slug,
            name: s.name,
            meta: typeof s.productsCount === 'number' ? formatNumber(s.productsCount) : undefined,
          }))}
          selected={stage}
          onPick={pickStage}
          emptyText="لا مراحل منشورة"
        />
        <Column
          title="الصف"
          items={ownedGrades.map((g) => ({
            key: g.slug,
            name: g.name,
            meta: typeof g.productsCount === 'number' ? formatNumber(g.productsCount) : undefined,
          }))}
          selected={grade}
          onPick={pickGrade}
          emptyText="اختر من العمود السابق"
        />
        {hasSemesters ? (
          <Column
            title="الفصل الدراسي"
            items={grade === null ? [] : semesters.map((s) => ({ key: s.slug, name: s.name }))}
            selected={semester}
            onPick={pickSemester}
            emptyText="اختر من العمود السابق"
          />
        ) : null}
        <Column
          title="المادة"
          items={
            subjects.kind === 'ready'
              ? subjects.items.map((s) => ({ key: s.value, name: s.value, meta: formatNumber(s.count) }))
              : []
          }
          selected={subject}
          onPick={(key) => setSubject(key === subject ? null : key)}
          emptyText={subjectEmpty}
        />
      </div>

      {href !== null ? (
        <div className={styles.done} aria-live="polite">
          <span className={styles.doneText}>
            {[subject, gradeName, semesterName].filter(Boolean).join(' · ')}
          </span>
          <a className={styles.doneCta} href={href}>
            عرض منتجات المادة
            <Icon name="chev" size={15} strokeWidth={2.2} />
          </a>
        </div>
      ) : null}
    </>
  );
}
