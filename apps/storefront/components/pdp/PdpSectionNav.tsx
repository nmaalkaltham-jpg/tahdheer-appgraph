'use client';
import { useEffect, useState, type MouseEvent } from 'react';
import { tabInputId } from './PdpTabs';
import styles from './pdp.module.css';

export interface SectionLink {
  key: string;
  label: string;
  /** معرّف القسم الهدف (`#id`) — الرابط يعمل بلا سكربت أيضًا. */
  target: string;
  /** تبويبٌ يُفتح قبل التمرير (الوصف/المحتويات يسكنان لوحة التبويبات). */
  tab?: string;
}

/* حدّ الشريط: ارتفاعه (‎56‎) + فسحة ‎8‎ — القسم الذي عبر هذا الخطّ هو الحاضر. */
const LINE = 64;

/**
 * شريط أقسام صفحة المنتج — سطح المكتب وحده (‎≥1024px‎). يظهر مثبّتًا أعلى
 * الشاشة حين يصل أوّل قسمٍ إلى أعلاها (أي بعد تجاوز أزرار الشراء)، ويختفي
 * بالعودة إلى أعلى الصفحة؛ وينير الرابط الذي يقرأ الزائر قسمه.
 *
 * الروابط مراسي حقيقيّة (`href="#…"`)، وأمّا «الوصف» و«المحتويات» فيفتحان
 * تبويبهما (الراديو نفسه) ثمّ يمرّران إلى لوحة التبويبات. مخفيًّا يُحجب
 * بـ`visibility` فلا يقف عليه التنقّل بلوحة المفاتيح.
 */
export function PdpSectionNav({ links, title }: { links: SectionLink[]; title: string }) {
  const [active, setActive] = useState<string | null>(null);

  useEffect(() => {
    let frame = 0;
    const measure = () => {
      frame = 0;
      let current: SectionLink | null = null;
      for (const link of links) {
        const el = document.getElementById(link.target);
        if (el && el.getBoundingClientRect().top <= LINE + 1) current = link;
      }
      if (current?.tab) {
        /* داخل لوحة التبويبات: المنار هو التبويب المفتوح إن كان له رابط. */
        const open = links.find((link) => link.target === current?.target && link.tab && (document.getElementById(tabInputId(link.tab)) as HTMLInputElement | null)?.checked);
        current = open ?? current;
      }
      setActive(current?.key ?? null);
    };
    const onScroll = () => {
      if (!frame) frame = window.requestAnimationFrame(measure);
    };
    measure();
    window.addEventListener('scroll', onScroll, { passive: true });
    window.addEventListener('resize', onScroll);
    document.addEventListener('change', onScroll);
    return () => {
      window.removeEventListener('scroll', onScroll);
      window.removeEventListener('resize', onScroll);
      document.removeEventListener('change', onScroll);
      if (frame) window.cancelAnimationFrame(frame);
    };
  }, [links]);

  function go(event: MouseEvent<HTMLAnchorElement>, link: SectionLink) {
    if (!link.tab) return;
    const radio = document.getElementById(tabInputId(link.tab)) as HTMLInputElement | null;
    if (!radio) return;
    event.preventDefault();
    radio.checked = true;
    radio.dispatchEvent(new Event('change', { bubbles: true }));
    const reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    document.getElementById(link.target)?.scrollIntoView({ behavior: reduce ? 'auto' : 'smooth', block: 'start' });
    history.replaceState(null, '', `#${link.target}`);
  }

  if (links.length < 2) return null;

  return (
    <nav className={styles.sectionNav} aria-label="أقسام المنتج" data-shown={active ? '' : undefined}>
      <div className={styles.sectionNavInner}>
        {links.map((link) => (
          <a
            key={link.key}
            className={styles.sectionLink}
            href={`#${link.target}`}
            aria-current={active === link.key ? 'location' : undefined}
            onClick={(event) => go(event, link)}
          >
            {link.label}
          </a>
        ))}
        {/* اسم المنتج في الطرف المقابل: سياقٌ لمن نسي أين هو، لا رابطٌ آخر. */}
        <span className={styles.sectionNavTitle} aria-hidden="true">
          {title}
        </span>
      </div>
    </nav>
  );
}
