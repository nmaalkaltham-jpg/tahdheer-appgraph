'use client';
// UNIT: WIDGET
// FILE: widget.tsx
// STYLE: widget.module.css
// PARENT: PAGE-SYSTEM-AI
// USES: STATES
// API: none
// MAP: docs/PROJECT_MAP.md

/**
 * ودجة لوحة — بطاقةٌ معزولة، عطلُها لا يُسقط الصفحة.
 *
 * ─── لماذا حدّ خطأ لكلّ ودجة ───
 *
 * اللوحة تجمع ثمانية مصادر. ورميةٌ واحدة في مُصيِّر «أفضل المنتجات» كانت
 * ستُبيّض الشاشة كلّها — فيفقد المشغّل المؤشّرات والطلبات ولوحة الانتباه
 * لأجل جدولٍ واحد. الحدّ يحصر السقوط في بطاقته ويقول أيّها سقطت.
 */

import { Component, type ErrorInfo, type ReactNode } from 'react';
import { ErrorState } from './states';
import styles from './widget.module.css';

class WidgetBoundary extends Component<
  { title: string; children: ReactNode },
  { failed: boolean; message: string }
> {
  constructor(props: { title: string; children: ReactNode }) {
    super(props);
    this.state = { failed: false, message: '' };
  }

  static getDerivedStateFromError(error: unknown): { failed: boolean; message: string } {
    return { failed: true, message: error instanceof Error ? error.message : 'عطل غير متوقّع' };
  }

  componentDidCatch(error: Error, info: ErrorInfo): void {
    // يُسجَّل ولا يُبتلع: بطاقةٌ تسقط صامتةً تبدو «فارغة» لا «معطوبة».
    console.error(`[widget] ${this.props.title}`, error, info.componentStack);
  }

  render(): ReactNode {
    if (this.state.failed) {
      return <ErrorState message={`تعذّر عرض «${this.props.title}»: ${this.state.message}`} />;
    }

    return this.props.children;
  }
}

export function DashboardWidget({
  title,
  description,
  action,
  span = 1,
  children,
}: {
  title: string;
  description?: string;
  action?: ReactNode;
  /** عرض الودجة بأعمدة الشبكة — ٢ لما يحتاج مساحةً أفقيّة كالمخطّط. */
  span?: 1 | 2 | 3;
  children: ReactNode;
}) {
  return (
    <section className={styles.widget} data-span={span}>
      <header className={styles.head}>
        <div className={styles.headText}>
          <h2 className={styles.title}>{title}</h2>
          {description !== undefined ? <p className={styles.desc}>{description}</p> : null}
        </div>
        {action !== undefined ? <div className={styles.headAction}>{action}</div> : null}
      </header>
      <div className={styles.body}>
        <WidgetBoundary title={title}>{children}</WidgetBoundary>
      </div>
    </section>
  );
}
