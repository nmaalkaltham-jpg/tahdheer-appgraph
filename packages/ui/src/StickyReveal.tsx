'use client';
// UNIT: STICKYREVEAL
// FILE: StickyReveal.tsx
// STYLE: StickyReveal.module.css
// PARENT: UI-INDEX
// USES: none
// API: none
// MAP: docs/PROJECT_MAP.md

/*
 * StickyReveal — لوحة ثابتة تتبدّل مع تمرير الأقسام بجانبها.
 *
 * بديل أصليّ لـ‎aceternity/sticky-scroll-reveal‎، مكتوب من الصفر. انتقل إلى
 * ‎@tahdheer/ui‎ لأنّه قسم صفحة حقيقيّ يُدرَج ويُحفَظ ويُصيَّر في المتجر.
 *
 * الرصد بـ‎IntersectionObserver‎ لا بحساب ‎scrollY‎ في مستمع تمرير: المستمع
 * يوقظ الخيط الرئيسيّ في كلّ إطار تمرير ويجبره على قراءة الهندسة، والمراقب
 * يعمل خارجه ولا يُبلّغ إلّا عند تغيّر فعليّ.
 *
 * ولا يُشترط ‎position: sticky‎ في المتصفّح لكي يُقرأ المحتوى: الأقسام نصّ
 * كامل في DOM بترتيبه، واللوحة الثابتة تحسينٌ بصريّ فوقه. من يعطّل CSS أو
 * JavaScript يقرأ كلّ شيء بالترتيب.
 *
 * ‎prefers-reduced-motion‎: التبديل بلا تلاشٍ.
 */
import { useEffect, useRef, useState, type ReactNode } from 'react';
import styles from './StickyReveal.module.css';

export interface StickyRevealSection {
  readonly id: string;
  readonly title: string;
  readonly body: string;
  /**
   * اللوحة المقابلة — اختياريّة عمدًا.
   *
   * مستند الاستوديو يحمل نصًّا لا شجرة React، فلا يستطيع تمرير عقدة. وبدل
   * مكوّنٍ ثانٍ «نسخة الاستوديو» تُصبح اللوحة اختياريّة: غيابها يُصيَّر رقم
   * الخطوة. مكوّن واحد يخدم السطحين، ولا تنشقّ نسختان تنحرفان.
   */
  readonly panel?: ReactNode;
}

export interface StickyRevealProps {
  sections: readonly StickyRevealSection[];
  className?: string;
}

export function StickyReveal({ sections, className }: StickyRevealProps) {
  const [activeId, setActiveId] = useState(sections[0]?.id ?? '');
  const refs = useRef(new Map<string, HTMLElement>());

  useEffect(() => {
    if (typeof IntersectionObserver === 'undefined') return;
    const io = new IntersectionObserver(
      (entries) => {
        /* الأكثر ظهورًا يفوز — لا أوّل متقاطع: قسمان قد يتقاطعان معًا. */
        let best: { id: string; ratio: number } | null = null;
        for (const e of entries) {
          const id = (e.target as HTMLElement).dataset.sectionId;
          if (!id || !e.isIntersecting) continue;
          if (!best || e.intersectionRatio > best.ratio) {
            best = { id, ratio: e.intersectionRatio };
          }
        }
        if (best) setActiveId(best.id);
      },
      { threshold: [0.25, 0.5, 0.75], rootMargin: '-20% 0px -20% 0px' },
    );
    for (const el of refs.current.values()) io.observe(el);
    return () => io.disconnect();
  }, [sections]);

  const active = sections.find((s) => s.id === activeId) ?? sections[0];
  if (!active) return null;

  return (
    <div className={[styles.root, className].filter(Boolean).join(' ')}>
      <div className={styles.sections}>
        {sections.map((s) => (
          <section
            key={s.id}
            data-section-id={s.id}
            data-active={s.id === active.id || undefined}
            ref={(el) => {
              if (el) refs.current.set(s.id, el);
              else refs.current.delete(s.id);
            }}
            className={styles.section}
          >
            <h3 className={styles.title}>{s.title}</h3>
            <p className={styles.body}>{s.body}</p>
          </section>
        ))}
      </div>

      {/* aria-hidden: اللوحة مرآةٌ بصريّة لما قُرئ نصًّا بجانبها، فلا تُكرَّر للقارئ. */}
      <div aria-hidden className={styles.panelWrap}>
        <div key={active.id} className={styles.panel}>
          {active.panel ?? (
            /* رقمٌ لاتينيّ يولّده المكوّن نفسه (رتبة الخطوة) — يُعزل كبقيّة
               الأرقام في الحزمة، فلا ينقلب إن جاوره نصٌّ عربيّ يومًا. */
            <span className={styles.step}>
              <bdi dir="ltr">{sections.indexOf(active) + 1}</bdi>
            </span>
          )}
        </div>
      </div>
    </div>
  );
}
