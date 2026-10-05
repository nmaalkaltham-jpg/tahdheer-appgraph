'use client';

/**
 * نموذج طلب ملف غير متوفّر.
 *
 * قاعدتان تحكمان هذا المكوّن:
 *
 *  · **لا نجاح قبل نجاح الخادم.** الحالة لا تنتقل إلى «سُجّل» إلا بعد استجابة
 *    ناجحة فعلًا. زرٌّ يقول «تم» ثم لا شيء في الجدول أسوأ من زرٍّ معطّل.
 *
 *  · **التصنيف اختيار من الكتالوج لا كتابة حرّة.** الغرض من الصفحة هو العدّ،
 *    و«سادس» و«الصف السادس» و«٦» تُفسد العدّ لو قُبلت نصًّا. الخانة الحرّة
 *    الوحيدة هي الملاحظة، وهي وصف لا تصنيف.
 */

import { useEffect, useRef, useState, type FormEvent } from 'react';
import styles from './page.module.css';
import { budget } from '../../lib/fetch-budget';

export interface TermOption {
  slug: string;
  name: string;
}

export interface RequestFormProps {
  apiBase: string | null;
  stages: TermOption[];
  grades: TermOption[];
  subjects: TermOption[];
  semesters: TermOption[];
  kinds: TermOption[];
  /** استعلام جاء من صفحة بحث بلا نتائج — يُملأ في الملاحظة ابتداءً. */
  initialNote?: string;
  /** ما فهمه البحث (slug لكلّ حقل) — مُتحقَّقٌ منه في الصفحة (`initialSelection`). */
  initial?: Partial<Record<string, string>>;
}

type Status =
  | { kind: 'idle' }
  | { kind: 'sending' }
  | { kind: 'done'; alsoRequested: number; contactStored: boolean }
  | { kind: 'error'; message: string };

const FIELDS = [
  { name: 'stage', label: 'المرحلة', key: 'stages' },
  { name: 'grade', label: 'الصف', key: 'grades' },
  { name: 'subject', label: 'المادة', key: 'subjects' },
  { name: 'semester', label: 'الفصل الدراسي', key: 'semesters' },
  { name: 'kind', label: 'نوع الملف', key: 'kinds' },
] as const;

export function RequestForm(props: RequestFormProps) {
  const { apiBase, initialNote } = props;
  const [status, setStatus] = useState<Status>({ kind: 'idle' });
  /*
   * النجاح يستبدل النموذج كلّه بمنطقةٍ تُضاف إلى الشجرة **ممتلئة**، فلا
   * تُعلنها قارئات الشاشة (المنطقة الحيّة تُعلن ما يتغيّر فيها بعد وجودها)،
   * والتركيز الذي كان على زرّ الإرسال يسقط إلى <body>. نقلُ التركيز إلى عنوان
   * النجاح يحلّ الأمرين معًا: يُقرأ العنوان، ويستأنف التنقّل من موضعه.
   */
  const doneRef = useRef<HTMLElement>(null);
  /*
   * وعند الخطأ: الزرّ يُعطَّل أثناء الإرسال فيسقط التركيز منه إلى <body>،
   * فيُعاد إليه بعد أن تُعلن رسالة الخطأ (role=alert) — ليستأنف التنقّل من موضعه.
   */
  const submitRef = useRef<HTMLButtonElement>(null);

  useEffect(() => {
    if (status.kind === 'done') doneRef.current?.focus();
    if (status.kind === 'error') submitRef.current?.focus();
  }, [status.kind]);

  const options: Record<string, TermOption[]> = {
    stages: props.stages,
    grades: props.grades,
    subjects: props.subjects,
    semesters: props.semesters,
    kinds: props.kinds,
  };

  async function onSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (!apiBase || status.kind === 'sending') return;

    const data = new FormData(event.currentTarget);
    const payload: Record<string, string> = {};
    for (const [key, value] of data.entries()) {
      if (typeof value === 'string' && value.trim() !== '') payload[key] = value;
    }

    setStatus({ kind: 'sending' });

    try {
      const res = await fetch(`${apiBase}/api/v1/material-requests`, {
        signal: budget('action'),
        method: 'POST',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
        body: JSON.stringify(payload),
        cache: 'no-store',
      });

      const body = (await res.json().catch(() => null)) as
        | { alsoRequested?: number; contactStored?: boolean; error?: { message?: string } }
        | null;

      if (!res.ok) {
        /*
         * سبب الخادم يُعرض كما هو — «تعذّر الإرسال» وحدها تترك الطالب بلا
         * تصرّف، ورسالة 422 هنا تقول له بالضبط ما ينقص.
         */
        setStatus({
          kind: 'error',
          message:
            body?.error?.message ??
            (res.status === 429
              ? 'أرسلت طلبات كثيرة في وقت قصير — انتظر قليلًا ثم أعد المحاولة.'
              : 'تعذّر تسجيل الطلب على الخادم. أعد المحاولة بعد قليل.'),
        });

        return;
      }

      setStatus({
        kind: 'done',
        alsoRequested: body?.alsoRequested ?? 0,
        contactStored: body?.contactStored === true,
      });
    } catch {
      setStatus({ kind: 'error', message: 'تعذّر الاتصال بالخادم — تحقّق من الشبكة وأعد المحاولة.' });
    }
  }

  if (!apiBase) {
    return (
      <p className={styles.blocked}>
        نموذج الطلب غير مربوط بخادم في هذه النسخة، ولن نعرض زرًّا يبتلع طلبك بلا وجهة.
      </p>
    );
  }

  if (status.kind === 'done') {
    return (
      <div className={styles.done} role="status">
        <b className={styles.doneTitle} tabIndex={-1} ref={doneRef}>
          سُجّل طلبك
        </b>
        <p className={styles.doneText}>
          {status.alsoRequested > 0
            ? `طلب هذا الملفَّ ${status.alsoRequested} قبلك — كلّما تكرّر الطلب تقدّم في ترتيب الإنتاج.`
            : 'أنت أوّل من يطلب هذا الملف. الطلبات تُرتَّب بعدد من طلبوها، فطلبك يبدأ العدّ.'}
        </p>
        <p className={styles.doneText}>
          {status.contactStored
            ? 'حفظنا وسيلة تواصلك لنُعلمك عند نشره. لا نَعِد بموعد لأننا لا نعرفه بعد.'
            : 'لم تترك وسيلة تواصل، فلن يصلك إشعار عند النشر — تابع صفحة المادة أو أعد الطلب بوسيلة تواصل.'}
        </p>
        <button className={styles.again} type="button" onClick={() => setStatus({ kind: 'idle' })}>
          أرسل طلبًا آخر
        </button>
      </div>
    );
  }

  return (
    <form className={styles.form} onSubmit={onSubmit}>
      <div className={styles.fields}>
        {FIELDS.map((field) => (
          <label className={styles.field} key={field.name}>
            <span className={styles.label}>{field.label}</span>
            <select className={styles.select} name={field.name} defaultValue={props.initial?.[field.name] ?? ''}>
              <option value="">— غير محدّد —</option>
              {options[field.key]?.map((term) => (
                <option key={term.slug} value={term.slug}>
                  {term.name}
                </option>
              ))}
            </select>
          </label>
        ))}

        <label className={`${styles.field} ${styles.fieldWide}`}>
          <span className={styles.label}>ما الذي تبحث عنه بالضبط؟</span>
          <textarea
            className={styles.textarea}
            name="note"
            rows={3}
            maxLength={1000}
            defaultValue={initialNote}
            placeholder="مثال: أوراق عمل للوحدة الثالثة مع نموذج إجابة"
          />
        </label>

        <label className={`${styles.field} ${styles.fieldWide}`}>
          <span className={styles.label}>
            وسيلة تواصل <span className={styles.optional}>اختيارية — بريد أو جوال</span>
          </span>
          <input className={styles.input} type="text" name="contact" dir="auto" maxLength={190} />
        </label>
      </div>

      {status.kind === 'error' ? (
        <p className={styles.error} role="alert">
          {status.message}
        </p>
      ) : null}

      <button
        className={styles.submit}
        type="submit"
        ref={submitRef}
        disabled={status.kind === 'sending'}
        aria-busy={status.kind === 'sending' || undefined}
      >
        {status.kind === 'sending' ? 'يُرسَل…' : 'أرسل الطلب'}
      </button>

      <p className={styles.privacy}>
        نحفظ التصنيف والملاحظة لترتيب ما نُنتجه، ووسيلة التواصل — إن تركتها — لإشعارك وحده. لا
        تُرسَل إلى أي طرف ثالث ولا تظهر في أي صفحة عامّة.
      </p>
    </form>
  );
}
