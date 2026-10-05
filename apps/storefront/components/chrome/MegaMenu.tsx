'use client';
/**
 * اللوحة الكبرى لـ«المراحل» — كلّ مرحلةٍ عمودٌ بصفوفها، وعمود المواد، وصفّ
 * «الكلّ» — تحت الترويسة بعرض المحتوى.
 *
 * الفتح والإغلاق يملكهما `PrimaryNav` (زرّ إفصاح بـ`aria-expanded` +
 * `aria-controls`، و`Escape`، والنقر خارجًا، وتغيّر المسار، ونيّة التحويم).
 * وهذا المكوّن يملك المحتوى ونموذج الأسهم داخله:
 *  · ↓/↑ بين روابط العمود، وHome/End إلى طرفيه.
 *  · ←/→ إلى العمود المجاور بالاتّجاه البصريّ (في RTL السهم الأيسر = العمود
 *    التالي في القراءة)، على الصفّ نفسه أو آخر ما فيه.
 *  · Tab يبقى ترتيب المستند كما هو — الأسهم اختصارٌ لا بديل.
 *
 * نمط إفصاحٍ لا `menu`: هذه روابط تنقّل، و`role="menu"` يَعِد قارئ الشاشة
 * بنموذج أوامر (انظر تعليق `PrimaryNav`).
 */
import type { CSSProperties, KeyboardEvent } from 'react';
import { formatNumber, qty } from '@tahdheer/design-system/format';
import { PRODUCT_FORMS } from '../../app/(catalog)/_components/query';
import { routes } from '../../lib/routes';
import type { BrowseLink, BrowseTree } from './browse-tree';
import styles from './MegaMenu.module.css';

/** اتّفاق «مادة» مع العدد — مصدرُه `qty` الواحد. */
const SUBJECT_FORMS = { zero: 'لا مواد', one: 'مادة واحدة', two: 'مادتان', few: 'مواد', many: 'مادةً' } as const;

const NAV_KEYS = new Set(['ArrowDown', 'ArrowUp', 'ArrowLeft', 'ArrowRight', 'Home', 'End']);

/** نموذج الأسهم — مصدَّرٌ ليستعمله زرّ الفتح (↓ يفتح ويُركّز أوّل رابط). */
export function focusFirstMegaLink(panel: HTMLElement | null): void {
  panel?.querySelector<HTMLAnchorElement>('[data-mega-col] a[href]')?.focus();
}

function onPanelKeyDown(event: KeyboardEvent<HTMLDivElement>) {
  if (!NAV_KEYS.has(event.key) || event.altKey || event.ctrlKey || event.metaKey || event.shiftKey) return;
  const panel = event.currentTarget;
  const columns = [...panel.querySelectorAll<HTMLElement>('[data-mega-col]')]
    .map((col) => [...col.querySelectorAll<HTMLAnchorElement>('a[href]')])
    .filter((links) => links.length > 0);

  const current = document.activeElement;
  const ci = columns.findIndex((links) => links.includes(current as HTMLAnchorElement));
  if (ci === -1) return;
  const links = columns[ci] ?? [];
  const li = links.indexOf(current as HTMLAnchorElement);

  let target: HTMLAnchorElement | undefined;
  switch (event.key) {
    case 'ArrowDown':
      target = links[Math.min(li + 1, links.length - 1)];
      break;
    case 'ArrowUp':
      target = links[Math.max(li - 1, 0)];
      break;
    case 'Home':
      target = links[0];
      break;
    case 'End':
      target = links[links.length - 1];
      break;
    default: {
      const rtl = getComputedStyle(panel).direction === 'rtl';
      const step = (event.key === 'ArrowLeft') === rtl ? 1 : -1;
      const next = columns[Math.min(Math.max(ci + step, 0), columns.length - 1)] ?? links;
      target = next[Math.min(li, next.length - 1)];
    }
  }
  event.preventDefault();
  target?.focus();
}

/** العدد رقمٌ هادئ للعين، وجملةٌ كاملة لقارئ الشاشة («٣ منتجات»). */
function Count({ n }: { n: number | undefined }) {
  if (n === undefined) return null;
  return (
    <span className={styles.count}>
      <span aria-hidden="true">{formatNumber(n)}</span>
      <span className={styles.srOnly}>{`، ${qty(n, PRODUCT_FORMS)}`}</span>
    </span>
  );
}

function LinkItem({ link, active }: { link: BrowseLink; active: boolean }) {
  return (
    <li>
      <a className={styles.link} href={link.href} aria-current={active ? 'page' : undefined}>
        <span className={styles.linkLabel}>{link.label}</span>
        <Count n={link.count} />
      </a>
    </li>
  );
}

function Chevron() {
  return (
    <svg aria-hidden="true" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.2" strokeLinecap="round" strokeLinejoin="round">
      <path d="M14 6l-6 6 6 6" />
    </svg>
  );
}

export interface MegaMenuProps {
  id: string;
  open: boolean;
  tree: BrowseTree;
  pathname: string;
  /** عنوان الإفصاح لقارئ الشاشة (اسم البند في الشريط). */
  label: string;
}

export function MegaMenu({ id, open, tree, pathname, label }: MegaMenuProps) {
  const isHere = (href: string) => decodeURI(pathname) === decodeURI(href);
  const columns = { '--_stages': Math.max(tree.stages.length, 1) } as CSSProperties;

  return (
    <div id={id} className={styles.wrap} data-mega="" data-open={open ? 'true' : 'false'}>
      <div className={styles.panel} role="group" aria-label={label} onKeyDown={onPanelKeyDown}>
        <div className={styles.grid} style={columns}>
          {tree.stages.map((stage) => (
            <div key={stage.href} className={styles.col} data-mega-col="">
              <a className={styles.stageHead} href={stage.href} aria-current={isHere(stage.href) ? 'page' : undefined}>
                <span className={styles.stageName}>
                  {stage.label}
                  <Chevron />
                </span>
                {/* العدد وحده: مدى الصفوف (subtitle) يكرّر القائمة تحته حرفيًّا ويلتفّ سطرين. */}
                {stage.count !== undefined ? <span className={styles.stageMeta}>{qty(stage.count, PRODUCT_FORMS)}</span> : null}
              </a>
              {stage.grades.length > 0 ? (
                <ul className={styles.list} role="list">
                  {stage.grades.map((grade) => (
                    <LinkItem key={grade.href} link={grade} active={isHere(grade.href)} />
                  ))}
                </ul>
              ) : null}
            </div>
          ))}

          {tree.subjects.length > 0 ? (
            <div className={`${styles.col} ${styles.subjects}`} data-mega-col="">
              <a className={styles.stageHead} href={routes.subjects} aria-current={isHere(routes.subjects) ? 'page' : undefined}>
                <span className={styles.stageName}>
                  المواد الدراسية
                  <Chevron />
                </span>
                <span className={styles.stageMeta}>{qty(tree.subjects.length, SUBJECT_FORMS)}</span>
              </a>
              <ul className={`${styles.list} ${styles.subjectList}`} role="list">
                {tree.subjects.map((subject) => (
                  <LinkItem key={subject.href} link={subject} active={isHere(subject.href)} />
                ))}
              </ul>
            </div>
          ) : null}
        </div>

        <div className={styles.foot} data-mega-col="">
          <a className={styles.footLink} href={routes.stages}>
            كل المراحل <Chevron />
          </a>
          <a className={styles.footLink} href={routes.grades}>
            كل الصفوف <Chevron />
          </a>
          <a className={styles.footLink} href={routes.subjects}>
            كل المواد <Chevron />
          </a>
          <a className={`${styles.footLink} ${styles.footShop}`} href={routes.shop}>
            تصفّح كل المنتجات <Chevron />
          </a>
        </div>
      </div>
    </div>
  );
}
