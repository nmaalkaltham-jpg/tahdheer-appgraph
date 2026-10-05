'use client';
// UNIT: HIGHLIGHTGRID
// FILE: HighlightGrid.tsx
// STYLE: HighlightGrid.module.css
// PARENT: UI-INDEX
// USES: none
// API: none
// MAP: docs/PROJECT_MAP.md

/*
 * HighlightGrid — شبكة بطاقات بخلفيّة تنزلق إلى المُحوَّم عليه.
 *
 * بديل أصليّ لـ‎aceternity/card-hover-effect‎، مكتوب من الصفر. انتقل إلى
 * ‎@tahdheer/ui‎ لأنّه قسم صفحة حقيقيّ: يُدرَج من الاستوديو ويُحفظ في المستند
 * ويصيّره المتجر. بقاؤه في منطقة المورّدين كان يحبسه في الأطلس.
 *
 * ‎icon‎ عقدة React، وهي غير قابلة للتسلسل، فلا تدخل مخطّط الاستوديو: المستند
 * يحمل نصًّا لا شجرة. تبقى الخاصيّة للاستعمال البرمجيّ المباشر.
 *
 * الإبراز بـCSS خالصة لا بمكتبة حركة — شرطٌ لا تفضيل.
 *
 * حزمة الدار هي ما يصيّره متجر العملاء، وقانونه المكتوب في ‎StoreMotion‎
 * صريح: «لا مكتبة حركة». النسخة الأولى كانت تنزلق بـ‎layoutId‎، فكان انتقالها
 * إلى هنا يجرّ محرّك حركة كاملًا إلى حزمة الزائر من أجل إبرازٍ عند التحويم.
 * الإبراز الآن طبقة لكلّ بطاقة تظهر بـ‎opacity‎ — يفقد الانزلاق بين البطاقات،
 * ويكسب ألّا يدفع الزائر ثمن مكتبة لا يحتاجها.
 *
 * البنية قائمة ‎<ul>‎ وروابط حقيقيّة لا ‎<div onClick>‎: التنقّل بلوحة المفاتيح
 * يعمل بلا سطر إضافيّ، والتحويم يُبرز عبر ‎onFocus‎ أيضًا فيرى مستعمِل لوحة
 * المفاتيح ما يراه مستعمِل الفأرة.
 *
 * ‎prefers-reduced-motion‎: الخلفيّة تظهر بلا انتقال — لا تختفي.
 */
import { useState, type ReactNode } from 'react';
import styles from './HighlightGrid.module.css';

export interface HighlightGridItem {
  readonly id: string;
  readonly title: string;
  readonly description: string;
  /** وجهة الرابط. بدونها يُصيَّر عنصرًا غير تفاعليّ — لا رابط بلا وجهة. */
  readonly href?: string;
  readonly icon?: ReactNode;
}

export interface HighlightGridProps {
  items: readonly HighlightGridItem[];
  /** أعمدة الشاشة الواسعة؛ الجوّال عمود واحد دائمًا. */
  columns?: 1 | 2 | 3;
  className?: string;
}

export function HighlightGrid({
  items,
  columns = 2,
  className,
}: HighlightGridProps) {
  const [active, setActive] = useState<string | null>(null);

  return (
    <ul
      className={[styles.grid, className].filter(Boolean).join(' ')}
      data-columns={columns}
      data-theme-component="highlight-grid"
    >
      {items.map((item) => {
        const Tag = item.href ? 'a' : 'div';
        return (
          <li key={item.id} className={styles.cell}>
            <Tag
              {...(item.href ? { href: item.href } : {})}
              className={styles.link}
              onMouseEnter={() => setActive(item.id)}
              onMouseLeave={() => setActive(null)}
              onFocus={() => setActive(item.id)}
              onBlur={() => setActive(null)}
            >
<span
                aria-hidden
                data-on={active === item.id || undefined}
                className={styles.highlight}
              />
              <span className={styles.body}>
                {item.icon ? (
                  <span aria-hidden className={styles.icon}>
                    {item.icon}
                  </span>
                ) : null}
                <span className={styles.title}>{item.title}</span>
                <span className={styles.description}>{item.description}</span>
              </span>
            </Tag>
          </li>
        );
      })}
    </ul>
  );
}
