'use client';
// UNIT: PAGE-TABS
// FILE: page-tabs.tsx
// STYLE: page-tabs.module.css
// PARENT: PAGE-MEDIA, PAGE-OPERATIONS-FULFILLMENT, PAGE-SEO, PAGE-SEO-INTERNATIONAL, PAGE-SETTINGS, PAGE-STUDIO-CATALOG +1
// USES: COMPONENTS-ICONS, LIB-LAYOUT-PREFS, REORDERABLE
// API: none
// MAP: docs/PROJECT_MAP.md

/**
 * تبويبات الصفحة — بدائيّةٌ واحدة لكلّ الأسطح المدموجة.
 *
 * ─── لماذا في الرابط لا في الحالة ───
 *
 * التبويبان القائمان في اللوحة (`/analytics` و`/search`) يحفظان اختيارهما
 * في `localStorage` أو في حالةٍ محلّيّة. والنتيجة أنّ المشغّل يرى عطلًا في
 * «الروابط المكسورة» فينسخ الرابط إلى زميله — فيفتح الزميل «نظرة عامة».
 * ثمّ يتناقشان في شاشتين وهما يظنّانهما واحدة.
 *
 * ولذلك `?t=` هو المصدر: يُشارَك، ويُحفظ في المفضّلة، ويعيده زرّ الرجوع.
 *
 * ─── ولماذا `replace` لا `push` ───
 *
 * تبديل تبويبٍ ليس صفحةً جديدة. و`push` كان يجعل زرّ الرجوع يمرّ على كلّ
 * تبويبٍ لُمس قبل أن يخرج من الشاشة.
 *
 * ─── واللوح النشط وحده يُركَّب ───
 *
 * ستّة ألواحٍ تُركَّب معًا تعني ستّة طلباتٍ في كلّ فتحة، خمسةٌ منها لا
 * يراها أحد. الكسل هنا شرطُ الدمج لا تحسينُه: بدونه يصير السطح المدموج
 * أبطأ من الشاشات المنفصلة التي حلّ محلّها.
 */

import { useCallback, useMemo, useRef, useState, type KeyboardEvent, type ReactNode } from 'react';
import { usePathname, useRouter, useSearchParams } from 'next/navigation';
import { Icon } from '../icons';
import { useLayoutScope } from '../../lib/layout-prefs';
import { useReorder } from './reorderable';
import styles from './page-tabs.module.css';

export interface PageTab {
  id: string;
  label: string;
  /** الصلاحية اللازمة؛ التبويب المحجوب لا يُعرض أصلًا. */
  permission?: string;
  /** عدّادٌ مقيس — لا يُعرض حين يكون صفرًا أو غير معروف. */
  badge?: number;
  /** اللوح — دالّة لا عقدة، فلا يُبنى إلّا حين يُختار. */
  render: () => ReactNode;
}

export function PageTabs({
  tabs,
  can,
  label,
  /** مفتاح الاستعلام — `t` إلّا حين يتعارض مع مرشّحٍ في الصفحة نفسها. */
  param = 't',
  /** نطاق التفضيلات — غيابُه يُعطّل التخصيص في هذا الشريط. */
  scope,
}: {
  tabs: readonly PageTab[];
  can?: (permission: string) => boolean;
  label: string;
  param?: string;
  scope?: string;
}) {
  const router = useRouter();
  const pathname = usePathname();
  const params = useSearchParams();
  const barRef = useRef<HTMLDivElement | null>(null);

  const prefs = useLayoutScope(scope ?? 'tabs:none');
  const [editing, setEditing] = useState(false);

  /*
   * الصلاحية أوّلًا ثمّ التفضيل.
   *
   * وترتيبهما مهمّ: تبويبٌ محجوبٌ بالصلاحية لا يُعرض في وضع التحرير أصلًا،
   * فلا يستطيع المشغّل «إظهار» ما لا يملكه — وإلّا لَبدا الإخفاء صلاحيةً
   * تُمنح بالنقر.
   */
  const allowed = useMemo(
    () => tabs.filter((tab) => tab.permission === undefined || can === undefined || can(tab.permission)),
    [tabs, can],
  );

  /* في وضع التحرير يُعرض المخفيّ أيضًا — وإلّا لَما أمكن إعادته. */
  const visible = useMemo(
    () => (scope === undefined || editing ? allowed : prefs.arrange(allowed, (tab) => tab.id)),
    [allowed, prefs, scope, editing],
  );

  const orderedKeys = useMemo(
    () => prefs.arrange(allowed, (tab) => tab.id).map((tab) => tab.id),
    [prefs, allowed],
  );

  const reorder = useReorder({
    /* الترتيب المعروض هو ما يُعاد ترتيبه — لا القائمة الأصليّة. */
    keys: editing ? allowed.map((tab) => tab.id) : orderedKeys,
    onReorder: prefs.reorder,
  });

  const requested = params.get(param);
  /*
   * التبويب المخفيّ يبقى مفتوحًا برابطه المباشر.
   *
   * الإخفاء ترتيبٌ بصريّ لا منع: من أخفى «سجلّ التدقيق» ثمّ فتح رابطه يجب
   * أن يراه. وإلّا صار التفضيل التجميليّ يحجب محتوًى ويبدو عطلًا.
   */
  const active =
    allowed.find((tab) => tab.id === requested)
    ?? visible[0]
    ?? allowed[0];

  const select = useCallback(
    (id: string) => {
      const search = new URLSearchParams(params.toString());
      search.set(param, id);
      /*
       * المرشّحات تُمسح عند تبديل التبويب.
       *
       * `?status=FAILED` يخصّ جدول التبويب الذي كُتب فيه، وحملُه إلى تبويبٍ
       * آخر يُرشِّح بقيمةٍ لا يعرفها — فتظهر «لا نتائج» ويظنّها المشغّل فراغًا.
       */
      for (const key of ['q', 'page', 'sort', 'dir', 'status', 'lowOnly']) search.delete(key);
      router.replace(`${pathname}?${search.toString()}`, { scroll: false });
    },
    [params, pathname, router, param],
  );

  /** تنقّل لوحة المفاتيح — نمط ARIA للتبويبات، معكوسًا لاتّجاه RTL. */
  const onKeyDown = (event: KeyboardEvent<HTMLDivElement>) => {
    if (active === undefined) return;

    const index = visible.findIndex((tab) => tab.id === active.id);
    let next = -1;

    if (event.key === 'ArrowLeft') next = (index + 1) % visible.length;
    else if (event.key === 'ArrowRight') next = (index - 1 + visible.length) % visible.length;
    else if (event.key === 'Home') next = 0;
    else if (event.key === 'End') next = visible.length - 1;

    if (next === -1) return;

    event.preventDefault();
    const target = visible[next];
    if (target === undefined) return;

    select(target.id);
    barRef.current?.querySelector<HTMLButtonElement>(`#tab-${target.id}`)?.focus();
  };

  if (active === undefined) {
    return (
      <div className="state-error" role="status">
        <p>لا تبويب متاحًا لدورك في هذه الصفحة.</p>
      </div>
    );
  }

  return (
    <div className={styles.wrap}>
      <div className={styles.barRow}>
        <div
          ref={barRef}
          className={styles.bar}
          role={editing ? 'group' : 'tablist'}
          aria-label={editing ? `${label} — وضع الترتيب` : label}
          onKeyDown={editing ? undefined : onKeyDown}
        >
          {visible.map((tab) => {
            const on = tab.id === active.id;
            const hidden = prefs.isHidden(tab.id);

            /*
             * وضع الترتيب يستبدل الزرّ بمقبض: زرٌّ يفتح التبويب ويُسحب في آنٍ
             * واحد يجعل كلّ سحبةٍ قصيرة تبديلَ شاشة — وهو ما يجعل الترتيب
             * مستحيلًا على الشاشات اللمسيّة.
             */
            if (editing) {
              return (
                <div
                  key={tab.id}
                  className={styles.chip}
                  data-reorder-key={tab.id}
                  data-hidden={hidden ? '' : undefined}
                  data-dragging={reorder.dragging === tab.id ? '' : undefined}
                  data-over={reorder.over === tab.id && reorder.dragging !== tab.id ? '' : undefined}
                >
                  <button
                    type="button"
                    className={styles.grip}
                    aria-label={`حرّك «${tab.label}» — أو استعمل Ctrl مع الأسهم`}
                    onKeyDown={(event) => {
                      /*
                       * `Ctrl+سهم` بديلُ السحب للوحة المفاتيح. والاتّجاه
                       * منطقيّ: في RTL «اليمين» هو أوّل الشريط.
                       */
                      if (!event.ctrlKey && !event.metaKey) return;
                      const back = event.key === 'ArrowRight';
                      const forward = event.key === 'ArrowLeft';
                      if (!back && !forward) return;

                      event.preventDefault();
                      prefs.move(tab.id, back ? -1 : 1, allowed.map((t) => t.id));
                    }}
                    {...reorder.handleProps(tab.id)}
                  >
                    <Icon name="grid" size={13} />
                  </button>

                  <span className={styles.chipLabel}>{tab.label}</span>

                  <button
                    type="button"
                    className={styles.eye}
                    aria-pressed={!hidden}
                    aria-label={hidden ? `أظهر «${tab.label}»` : `أخفِ «${tab.label}»`}
                    onClick={() => prefs.toggleHidden(tab.id)}
                  >
                    <Icon name={hidden ? 'unlink' : 'check'} size={13} />
                  </button>
                </div>
              );
            }

            return (
              <button
                key={tab.id}
                id={`tab-${tab.id}`}
                type="button"
                role="tab"
                className={styles.tab}
                aria-selected={on}
                aria-controls={`panel-${tab.id}`}
                /* غير النشط خارج ترتيب التبويب: الأسهم هي ما ينقّل داخل الشريط. */
                tabIndex={on ? 0 : -1}
                onClick={() => select(tab.id)}
              >
                {tab.label}
                {tab.badge !== undefined && tab.badge > 0 ? (
                  <span className={styles.badge}>
                    <bdi dir="ltr">{tab.badge}</bdi>
                  </span>
                ) : null}
              </button>
            );
          })}
        </div>

        {scope === undefined ? null : (
          <div className={styles.barTools}>
            {editing && prefs.customized ? (
              <button type="button" className={styles.tool} onClick={prefs.reset}>
                استعادة الافتراضي
              </button>
            ) : null}
            <button
              type="button"
              className={styles.tool}
              aria-pressed={editing}
              onClick={() => setEditing((was) => !was)}
            >
              <Icon name={editing ? 'check' : 'cog'} size={14} />
              {editing ? 'تمّ' : 'ترتيب'}
            </button>
          </div>
        )}
      </div>

      {editing ? (
        <p className={styles.editNote} role="status">
          اسحب المقبض لإعادة الترتيب، أو استعمل <bdi dir="ltr">Ctrl</bdi> مع الأسهم. والعين تُخفي
          التبويب من الشريط — ولا تمنع فتحه برابطه المباشر: الإخفاء ترتيبٌ لا صلاحية.
        </p>
      ) : null}

      <div
        id={`panel-${active.id}`}
        role="tabpanel"
        aria-labelledby={`tab-${active.id}`}
        /* هدف تركيزٍ بعد التبديل، وخارج ترتيب التبويب دائمًا. */
        tabIndex={-1}
        className={styles.panel}
      >
        {active.render()}
      </div>
    </div>
  );
}
