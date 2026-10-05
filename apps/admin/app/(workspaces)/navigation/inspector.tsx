'use client';
// UNIT: INSPECTOR
// FILE: inspector.tsx
// STYLE: inspector.module.css
// PARENT: PAGE-NAVIGATION
// USES: LIB-ADMIN-API, MODEL, NAVIGATION-ICONS, _LIBRARY-RAIL
// API: POST /admin/navigation/link-check
// MAP: docs/PROJECT_MAP.md

/**
 * العمود الجانبي: المفتش · مكتبة المحتوى الحقيقي · لوحة الصحة.
 *
 * المفتش يحرّر ما يحمله التحديد الموحّد أيًّا كان العرض الذي أنشأه.
 * مكتبة المحتوى لا تخترع وجهات: تقرأ منتجات حقيقية من /admin/products
 * ومصطلحات حقيقية من /catalog/* وتبني الرابط من الـ slug الحقيقي، والصفحات
 * الثابتة من /admin/navigation/pages.
 */

import { useState } from 'react';
import { api, firstError } from '../../../lib/admin-api';
import { Icon, ICON_KEYS } from './icons';
import {
  byId,
  childrenOf,
  descendantIds,
  type HealthSummary,
  type Issue,
  LINK_PROBE_CHECKS,
  type MenuNode,
  type NodeType,
  type ProbeResult,
  rows,
  typeMeta,
} from './model';
import styles from './inspector.module.css';

const ISSUE_CLASS = {
  error: styles.issueError,
  warn: styles.issueWarn,
  info: styles.issueInfo,
} as const;

export type RailTab = 'inspector' | 'library' | 'health';

/* ═══════════════ فاحص الوجهة ═══════════════ */

/**
 * يفحص وجهة واحدة عند الطلب.
 *
 * بالضغط لا تلقائيًّا: فتحُ المفتّش لكل عنصر يجعل تصفّح القائمة سيلَ طلبات
 * إلى مواقع أخرى من خادمنا.
 */
function LinkProbe({ url }: { url: string }) {
  const [result, setResult] = useState<ProbeResult | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  // وجهة جديدة ⇒ نتيجة قديمة لا تخصّها: عرضها يُسند حكمًا لرابط لم يُفحص.
  // يُعاد تركيب المكوّن بمفتاح الوجهة (`key={url}` عند الاستدعاء) فتُصفَّر الحالة كلّها.

  async function probe() {
    setBusy(true);
    setError(null);
    const response = await api.post<{ data: ProbeResult[] }>('/admin/navigation/link-check', {
      urls: [url],
    });
    setBusy(false);
    if (!response.ok) {
      setError(firstError(response));
      return;
    }
    setResult(response.data.data[0] ?? null);
  }

  const verdict = (check: string): string => {
    if (!result) return 'لم يُفحص';
    switch (check) {
      case 'status':
        return result.httpStatus === null ? '—' : String(result.httpStatus);
      case 'chain':
        return result.hops === 0 ? 'بلا تحويل' : `${result.hops} قفزة`;
      case 'canonical':
        return result.canonical ?? 'بلا رابط دائم';
      case 'indexing':
        return result.indexable === null ? 'غير معروف' : result.indexable ? 'مفهرَس' : 'noindex';
      default:
        return result.status === 'OK' ? 'الوجهة تستجيب' : (result.reason ?? result.status);
    }
  };

  return (
    <div className={styles.notConfigured} role="note">
      <span className={styles.ncTitle}>
        <Icon name={result?.status === 'OK' ? 'check' : 'lock'} size={12} />
        فحوص هذه الوجهة
      </span>
      <ul>
        {LINK_PROBE_CHECKS.map((check) => (
          <li key={check.key}>
            <span>{check.label}</span>
            <span className={result === null ? styles.ncTag : undefined}>
              <bdi dir="ltr">{verdict(check.key)}</bdi>
            </span>
          </li>
        ))}
      </ul>
      <button type="button" className={styles.probeBtn} disabled={busy} onClick={() => void probe()}>
        {busy ? 'جارٍ الفحص…' : 'افحص الوجهة الآن'}
      </button>
      {error ? (
        <p role="alert">{error}</p>
      ) : result && result.status !== 'OK' ? (
        <p role="alert">{result.reason ?? 'تعذّر الوصول إلى الوجهة.'}</p>
      ) : null}
    </div>
  );
}

/* ═══════════════ المفتش ═══════════════ */

export interface InspectorProps {
  nodes: MenuNode[];
  selection: string[];
  issues: Issue[];
  expert: boolean;
  onPatch(id: string, patch: Partial<MenuNode>): void;
  onReparent(id: string, parentId: string | null): void;
  onMove(id: string, delta: number): void;
  onNest(id: string): void;
  onUnnest(id: string): void;
  onDuplicate(id: string): void;
  onRemove(id: string): void;
  onRestore(id: string): void;
  onClear(): void;
}

export function Inspector(props: InspectorProps) {
  const { nodes, selection, issues, expert } = props;
  const id = selection.length === 1 ? selection[0] : undefined;
  const node = id ? byId(nodes, id) : null;

  if (selection.length > 1) {
    return (
      <div className={styles.card}>
        <p className={styles.railEmpty}>
          <bdi dir="ltr">{selection.length}</bdi> عناصر محددة. المفتش يحرّر عنصرًا واحدًا؛ استعمل أزرار
          التحديد المتعدد في الشريط العلوي للعمليات الجماعية.
        </p>
      </div>
    );
  }

  if (!node || !id) {
    return (
      <div className={styles.card}>
        <p className={styles.railEmpty}>
          <Icon name="pen" size={24} />
          حدّد عنصرًا من أي عرض لتحريره هنا — التحديد موحّد بين الشجرة والخريطة والجدول والمعاينة.
        </p>
      </div>
    );
  }

  const meta = typeMeta(node.type);
  const nodeIssues = issues.filter((issue) => issue.nodeId === id);
  const forbidden = descendantIds(nodes, id);
  const parentOptions = rows(nodes).filter((row) => row.node.id !== id && !forbidden.has(row.node.id));

  return (
    <div className={styles.card}>
      <header className={styles.cardHead}>
        <span className={styles.typeChip} style={{ color: meta.color }} aria-hidden="true">
          <Icon name={node.icon || meta.icon} size={12} />
        </span>
        <span className={styles.cardTitle}>{node.label || '⟨بلا نص تنقل⟩'}</span>
        <button type="button" className={styles.closeBtn} onClick={props.onClear}>
          <span className={styles.srOnly}>إلغاء التحديد</span>
          <Icon name="x" size={11} />
        </button>
      </header>

      <div className={styles.cardMeta}>
        <span className={styles.metaChip}>{meta.name}</span>
        <span className={styles.metaChip}>{node.deleted ? 'محذوف مؤجلًا' : 'في المسودة'}</span>
        <bdi dir="ltr" className={styles.metaId}>
          {node.id}
        </bdi>
      </div>

      <div className={styles.cardBody}>
        {nodeIssues.length > 0 ? (
          <ul className={styles.issueList}>
            {nodeIssues.map((issue) => (
              <li key={`${issue.code}-${issue.nodeId}`} className={ISSUE_CLASS[issue.severity]}>
                <strong>{issue.title}</strong>
                <span>{issue.detail}</span>
              </li>
            ))}
          </ul>
        ) : null}

        <label className={styles.field}>
          <span>نص التنقل</span>
          <input
            type="text"
            value={node.label}
            onChange={(event) => props.onPatch(id, { label: event.target.value })}
          />
        </label>

        <label className={styles.field}>
          <span>الرابط</span>
          <input
            type="text"
            dir="ltr"
            className={styles.mono}
            value={node.url}
            onChange={(event) => props.onPatch(id, { url: event.target.value })}
          />
        </label>
        {/^\s*javascript:/i.test(node.url) ? (
          <p className={styles.fieldError} role="alert">
            بروتوكول javascript: مرفوض — لن يُقبل عند النشر ولن يُحفظ في أي مسودة تُرسَل للخادم.
          </p>
        ) : null}

        <label className={styles.field}>
          <span>النوع</span>
          <select
            value={node.type}
            onChange={(event) => props.onPatch(id, { type: event.target.value as NodeType })}
          >
            <option value="page">صفحة</option>
            <option value="category">تصنيف</option>
            <option value="product">منتج</option>
            <option value="custom">رابط مخصص</option>
          </select>
        </label>

        <label className={styles.field}>
          <span>الأب</span>
          <select
            value={node.parentId ?? ''}
            onChange={(event) => props.onReparent(id, event.target.value === '' ? null : event.target.value)}
          >
            <option value="">— المستوى الأعلى —</option>
            {parentOptions.map((row) => (
              <option key={row.node.id} value={row.node.id}>
                {'— '.repeat(row.depth)}
                {row.node.label || '⟨بلا نص⟩'}
              </option>
            ))}
          </select>
        </label>

        <div className={styles.moveRow}>
          <button type="button" className={styles.softBtn} onClick={() => props.onMove(id, -1)}>
            تحريك للأعلى
          </button>
          <button type="button" className={styles.softBtn} onClick={() => props.onMove(id, 1)}>
            تحريك للأسفل
          </button>
          <button type="button" className={styles.softBtn} onClick={() => props.onNest(id)}>
            تعشيش
          </button>
          <button type="button" className={styles.softBtn} onClick={() => props.onUnnest(id)}>
            إخراج
          </button>
        </div>

        <fieldset className={styles.fieldset}>
          <legend>سلوك الفتح</legend>
          <div className={styles.segRow}>
            <button
              type="button"
              className={styles.segBtn}
              aria-pressed={node.target === '_self'}
              onClick={() => props.onPatch(id, { target: '_self' })}
            >
              نفس التبويب
            </button>
            <button
              type="button"
              className={styles.segBtn}
              aria-pressed={node.target === '_blank'}
              onClick={() => props.onPatch(id, { target: '_blank', rel: node.rel || 'noopener noreferrer' })}
            >
              تبويب جديد
            </button>
          </div>
        </fieldset>

        <fieldset className={styles.fieldset}>
          <legend>الأيقونة</legend>
          <div className={styles.iconGrid}>
            <button
              type="button"
              className={styles.iconChoice}
              aria-pressed={node.icon === ''}
              onClick={() => props.onPatch(id, { icon: '' })}
            >
              <span className={styles.srOnly}>بلا أيقونة</span>
              <Icon name="x" size={13} />
            </button>
            {ICON_KEYS.map((key) => (
              <button
                key={key}
                type="button"
                className={styles.iconChoice}
                aria-pressed={node.icon === key}
                onClick={() => props.onPatch(id, { icon: key })}
              >
                <span className={styles.srOnly}>أيقونة {key}</span>
                <Icon name={key} size={13} />
              </button>
            ))}
          </div>
        </fieldset>

        <label className={styles.field}>
          <span>الشارة</span>
          <input
            type="text"
            value={node.badge}
            placeholder="جديد · مميز"
            onChange={(event) => props.onPatch(id, { badge: event.target.value })}
          />
        </label>

        {expert ? (
          <div className={styles.advanced}>
            <span className={styles.advancedTitle}>إعدادات متقدمة</span>
            <label className={styles.field}>
              <span>فئات CSS</span>
              <input
                type="text"
                dir="ltr"
                className={styles.mono}
                value={node.classes}
                onChange={(event) => props.onPatch(id, { classes: event.target.value })}
              />
            </label>
            <label className={styles.field}>
              <span>معرّف CSS</span>
              <input
                type="text"
                dir="ltr"
                className={styles.mono}
                value={node.cssId}
                onChange={(event) => props.onPatch(id, { cssId: event.target.value })}
              />
            </label>
            <label className={styles.field}>
              <span>rel</span>
              <input
                type="text"
                dir="ltr"
                className={styles.mono}
                placeholder="noopener noreferrer"
                value={node.rel}
                onChange={(event) => props.onPatch(id, { rel: event.target.value })}
              />
            </label>
            <label className={styles.field}>
              <span>الاسم المتاح (aria-label)</span>
              <input
                type="text"
                value={node.aria}
                onChange={(event) => props.onPatch(id, { aria: event.target.value })}
              />
            </label>
            <label className={styles.field}>
              <span>الوصف</span>
              <textarea
                rows={2}
                value={node.desc}
                onChange={(event) => props.onPatch(id, { desc: event.target.value })}
              />
            </label>
          </div>
        ) : null}

        <LinkProbe key={node.url} url={node.url} />

        {node.sourceApi ? (
          <p className={styles.provenance}>
            مصدر هذا العنصر:{' '}
            <bdi dir="ltr">
              <code>{node.sourceApi}</code>
            </bdi>
          </p>
        ) : (
          <p className={styles.provenance}>رابط مخصص كتبه المشغّل — لا مصدر محتوى مرتبط.</p>
        )}

        <div className={styles.cardActions}>
          <button type="button" className={styles.softBtn} onClick={() => props.onDuplicate(id)}>
            تكرار الفرع
          </button>
          {node.deleted ? (
            <button type="button" className={styles.restoreBtn} onClick={() => props.onRestore(id)}>
              استرجاع العنصر
            </button>
          ) : (
            <button type="button" className={styles.deleteBtn} onClick={() => props.onRemove(id)}>
              حذف مؤجل
            </button>
          )}
        </div>
      </div>
    </div>
  );
}

export { LibraryRail } from './_library-rail';
export type { InsertPosition, LibraryRailProps } from './_library-rail';

/* ═══════════════ لوحة الصحة ═══════════════ */

export function HealthRail({
  summary,
  issues,
  nodes,
  onGo,
}: {
  summary: HealthSummary;
  issues: Issue[];
  nodes: MenuNode[];
  onGo(id: string): void;
}) {
  const empty = childrenOf(nodes, null).length === 0;

  return (
    <div className={styles.card}>
      <header className={styles.cardHead}>
        <span className={styles.scoreBadge}>
          <bdi dir="ltr">{empty ? '—' : summary.score}</bdi>
        </span>
        <span className={styles.cardTitle}>صحة القائمة</span>
        <span className={`${styles.countChip} ${styles.issueError}`}>
          <bdi dir="ltr">{summary.errors}</bdi> حاجب
        </span>
        <span className={`${styles.countChip} ${styles.issueWarn}`}>
          <bdi dir="ltr">{summary.warnings}</bdi> تحذير
        </span>
        <span className={`${styles.countChip} ${styles.issueInfo}`}>
          <bdi dir="ltr">{summary.infos}</bdi> إرشاد
        </span>
      </header>

      <div className={styles.cardBody}>
        {empty ? (
          <p className={styles.railStatus}>
            لا درجة تُحسب لقائمة فارغة — الرقم يظهر بعد أول عنصر، ولا يُعرض ١٠٠ على لا شيء.
          </p>
        ) : issues.length === 0 ? (
          <p className={styles.railOk}>
            <Icon name="check" size={15} />
            لا ملاحظات على الفحوص القابلة للحسم هنا. فحوص الشبكة تُشغَّل بالضغط: «افحص الوجهة
            الآن» في المفتش لعنصر واحد، أو «إعادة الفحص» في قسم التشخيص للمسوّدة كلها.
          </p>
        ) : (
          <ul className={styles.healthList}>
            {issues.map((issue, index) => (
              <li key={`${issue.code}-${issue.nodeId ?? 'menu'}-${index}`}>
                <span className={styles.healthHead}>
                  <span className={`${styles.sevDot} ${ISSUE_CLASS[issue.severity]}`} aria-hidden="true" />
                  <strong>{issue.title}</strong>
                  {issue.nodeId ? (
                    <span className={styles.metaChip}>
                      {byId(nodes, issue.nodeId)?.label || '⟨بلا نص⟩'}
                    </span>
                  ) : (
                    <span className={styles.metaChip}>على مستوى القائمة</span>
                  )}
                </span>
                <span className={styles.healthDetail}>{issue.detail}</span>
                {issue.nodeId ? (
                  <button type="button" className={styles.softBtn} onClick={() => onGo(issue.nodeId as string)}>
                    اذهب إلى العنصر
                  </button>
                ) : null}
              </li>
            ))}
          </ul>
        )}
      </div>
    </div>
  );
}
