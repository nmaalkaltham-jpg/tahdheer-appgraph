'use client';
// UNIT: URL-PLATFORM-PANEL
// FILE: url-platform-panel.tsx
// STYLE: none
// PARENT: PAGE-SEO
// USES: ADMIN-SESSION, LIB-ADMIN-API, LIB-FORMAT, RESOURCE, STATUS-BADGE
// API: /admin/url/health, /admin/url/inspect, /admin/url/slug/suggest, POST /admin/url/registry
// MAP: docs/PROJECT_MAP.md

/**
 * منصّة العناوين — الصحّة، والمفتّش، والسجلّ بتاريخه وأفعاله.
 *
 * كلّ رقمٍ هنا من `/admin/url/health` مقيسٌ من الجداول، وما لم يُقَس يقول
 * ذلك باسمه. تغييرُ رابطٍ يحمل سببًا ونسخةً متوقّعة: بلا سببٍ لا تاريخَ
 * يُقرأ، وبلا نسخةٍ يُكتب فوق ما لا نعرفه.
 */

import { useRef, useState } from 'react';
import { useSession } from '../../../components/admin-session';
import { EmptyRows, ResourceView, useResource } from '../../../components/resource';
import { StatusBadge, type StatusTone } from '../../../components/ui/status-badge';
import { api } from '../../../lib/admin-api';
import { count, dateTime } from '../../../lib/format';

type Health = {
  registry: { total: number; byStatus: Record<string, number>; indexable: number };
  redirects: { total: number; chains: { id: number; from: string; to: string }[]; loops: { id: number; from: string; to: string }[]; to404: { id: number; from: string; to: string }[] };
  canonical: { count: number; issues: { path: string; issues: { code: string; message: string }[] }[] };
  collisions: { paths: string[]; reserved: string[] };
  notFound: { distinct: number; hits: number; top: { path: string; hits: number; last_seen_at: string | null }[] };
  history: { total: number; slugChanges: number };
  hreflang: { state: string; reason: string };
  orphans: { state: string; reason: string };
};

type Entry = {
  id: number; entityType: string; entityId: number; locale: string; slug: string; path: string;
  canonicalUrl: string | null; status: string; indexable: boolean; version: number; updatedAt: string | null;
};

type HistoryRow = {
  id: number; event: string; previousPath: string | null; newPath: string | null; actorId: number | null;
  reason: string | null; changeSource: string; redirectRuleId: number | null; createdAt: string | null;
};

type Inspection = {
  input: string; normalized: string | null; outcome: string; httpStatus: number; steps: string[];
  entity: Entry | null; redirect: { ruleId: number | null; target: string; statusCode: number } | null;
  canonical: string | null; suggestions: { path: string; score: number; why: string }[];
  canonicalIssues: { code: string; message: string }[]; sitemap: { included: boolean }; indexability: string | null;
  aliases: HistoryRow[];
};

const STATUS_TONE: Record<string, StatusTone> = {
  published: 'success', locked: 'info', reserved: 'neutral', draft: 'neutral',
  replaced: 'warning', archived: 'warning', deleted: 'danger', tombstoned: 'danger',
};

const STATUS_LABEL: Record<string, string> = {
  draft: 'مسوّدة', reserved: 'محجوز', published: 'منشور', locked: 'مقفل',
  replaced: 'مستبدَل', archived: 'مؤرشف', deleted: 'محذوف', tombstoned: '٤١٠',
};

const OUTCOME_LABEL: Record<string, string> = {
  entity: 'كيانٌ منشور', redirect: 'تحويل', historical: 'رابطٌ تاريخيّ', gone: 'مُزال (٤١٠)',
  not_found: 'غير موجود (٤٠٤)', reserved: 'محجوز للنظام', unpublished: 'كيانٌ غير منشور', invalid: 'مسارٌ مرفوض',
};

export function UrlPlatformPanel() {
  const { can } = useSession();
  const canEdit = can('url.edit');

  return (
    <section className="stack">
      <HealthSection />
      <InspectorSection />
      <RegistrySection canEdit={canEdit} />
    </section>
  );
}

function HealthSection() {
  const { state, reload } = useResource<{ data: Health }>('/admin/url/health');

  return (
    <section className="panel">
      <h2>صحّة العناوين</h2>
      <p className="page-note">عدّاداتٌ مقيسة من سجلّ العناوين وقواعد التحويل وسجلّ ٤٠٤ — وما لم يُقَس يُقال.</p>
      <ResourceView state={state} reload={reload}>
        {({ data }) => (
          <>
            <dl className="list-stats">
              <Stat label="عناوين مسجّلة" value={count(data.registry.total)} sub={`${count(data.registry.indexable)} قابلة للفهرسة`} />
              <Stat label="منشورة" value={count(data.registry.byStatus.published ?? 0)} sub={`${count(data.registry.byStatus.locked ?? 0)} مقفلة`} />
              <Stat label="قواعد تحويل" value={count(data.redirects.total)} sub={`${count(data.redirects.chains.length)} سلسلة · ${count(data.redirects.loops.length)} حلقة`} bad={data.redirects.loops.length > 0} />
              <Stat label="تحويلات إلى ٤٠٤" value={count(data.redirects.to404.length)} bad={data.redirects.to404.length > 0} />
              <Stat label="علل القانونيّ" value={count(data.canonical.count)} bad={data.canonical.count > 0} />
              <Stat label="تصادمات" value={count(data.collisions.paths.length + data.collisions.reserved.length)} bad={data.collisions.paths.length + data.collisions.reserved.length > 0} />
              <Stat label="مسارات ٤٠٤ متميّزة" value={count(data.notFound.distinct)} sub={`${count(data.notFound.hits)} إصابة`} />
              <Stat label="تغييرات روابط" value={count(data.history.slugChanges)} sub={`${count(data.history.total)} صفّ تاريخ`} />
            </dl>
            <p className="page-note"><span className="chip">NOT MEASURED</span> hreflang — {data.hreflang.reason}</p>
            <p className="page-note"><span className="chip">NOT MEASURED</span> الصفحات اليتيمة — {data.orphans.reason}</p>
            {data.redirects.chains.length + data.redirects.loops.length + data.redirects.to404.length > 0 ? (
              <div className="table-scroll">
                <table className="data-table">
                  <caption className="sr-only">قواعد تحويل تحتاج انتباهًا</caption>
                  <thead><tr><th scope="col">النوع</th><th scope="col">من</th><th scope="col">إلى</th></tr></thead>
                  <tbody>
                    {data.redirects.loops.map((r) => <tr key={`l${r.id}`}><td><span className="chip chip-failed">حلقة</span></td><td dir="ltr">{r.from}</td><td dir="ltr">{r.to}</td></tr>)}
                    {data.redirects.chains.map((r) => <tr key={`c${r.id}`}><td><span className="chip chip-pending">سلسلة</span></td><td dir="ltr">{r.from}</td><td dir="ltr">{r.to}</td></tr>)}
                    {data.redirects.to404.map((r) => <tr key={`n${r.id}`}><td><span className="chip chip-failed">إلى ٤٠٤</span></td><td dir="ltr">{r.from}</td><td dir="ltr">{r.to}</td></tr>)}
                  </tbody>
                </table>
              </div>
            ) : null}
          </>
        )}
      </ResourceView>
    </section>
  );
}

function Stat({ label, value, sub, bad }: { label: string; value: string; sub?: string; bad?: boolean }) {
  return (
    <div className="list-stat">
      <dt>{label}</dt>
      <dd style={bad ? { color: 'var(--state-danger)' } : undefined}>
        {value}
        {sub ? <span className="list-stat-sub"> · {sub}</span> : null}
      </dd>
    </div>
  );
}

function InspectorSection() {
  const [input, setInput] = useState('');
  const [path, setPath] = useState<string | null>(null);

  return (
    <section className="panel">
      <h2>مفتّش العناوين</h2>
      <p className="page-note">أدخل أيّ مسارٍ ليشرح النظام كيف يُحلّ: كيانًا أو تحويلًا أو ٤١٠ أو ٤٠٤ باقتراحات.</p>
      <form
        className="filters list-toolbar"
        onSubmit={(event) => {
          event.preventDefault();
          if (input.trim() !== '') setPath(input.trim());
        }}
      >
        <label className="field field-wide" htmlFor="url-inspect-path">
          <span>المسار</span>
          <input id="url-inspect-path" dir="ltr" value={input} onChange={(e) => setInput(e.target.value)} placeholder="/products/…" />
        </label>
        <button type="submit" className="btn btn-primary">افحص</button>
      </form>
      {path === null ? null : <InspectionResult path={path} />}
    </section>
  );
}

function InspectionResult({ path }: { path: string }) {
  const { state, reload } = useResource<{ data: Inspection }>(`/admin/url/inspect?path=${encodeURIComponent(path)}`);

  return (
    <ResourceView state={state} reload={reload}>
      {({ data }) => (
        <div className="panel-inset">
          <h3>
            <StatusBadge label={`HTTP ${data.httpStatus} · ${OUTCOME_LABEL[data.outcome] ?? data.outcome}`} tone={data.httpStatus === 200 ? 'success' : data.httpStatus === 301 ? 'info' : 'danger'} />
          </h3>
          <ol className="checklist">
            {data.steps.map((step, i) => <li key={i} className="done">{step}</li>)}
          </ol>
          <dl className="pairs">
            <dt>المسار المُطبَّع</dt><dd dir="ltr">{data.normalized ?? '—'}</dd>
            <dt>القانونيّ</dt><dd dir="ltr">{data.canonical ?? '—'}</dd>
            <dt>الفهرسة</dt><dd>{data.indexability ?? '—'}</dd>
            <dt>خريطة الموقع</dt><dd>{data.sitemap.included ? 'مُدرَج' : 'غير مُدرَج'}</dd>
            {data.redirect ? <><dt>التحويل</dt><dd dir="ltr">{data.redirect.target} ({data.redirect.statusCode})</dd></> : null}
          </dl>
          {data.canonicalIssues.length > 0 ? (
            <ul className="checklist">{data.canonicalIssues.map((i) => <li key={i.code} className="todo">{i.message}</li>)}</ul>
          ) : null}
          {data.suggestions.length > 0 ? (
            <>
              <h3>اقتراحات</h3>
              <ul className="permission-list">
                {data.suggestions.map((s) => <li key={s.path}><bdi dir="ltr">{s.path}</bdi> · {Math.round(s.score * 100)}% · {s.why}</li>)}
              </ul>
            </>
          ) : null}
          {data.aliases.length > 0 ? (
            <>
              <h3>روابط تاريخيّة</h3>
              <ul className="permission-list">
                {data.aliases.map((a) => <li key={a.id}><bdi dir="ltr">{a.previousPath}</bdi> → <bdi dir="ltr">{a.newPath}</bdi> · {a.createdAt ? dateTime(a.createdAt) : '—'}</li>)}
              </ul>
            </>
          ) : null}
        </div>
      )}
    </ResourceView>
  );
}

function RegistrySection({ canEdit }: { canEdit: boolean }) {
  const [query, setQuery] = useState('');
  const [status, setStatus] = useState('');
  const [applied, setApplied] = useState({ q: '', status: '', page: 1 });
  const params = new URLSearchParams({ page: String(applied.page) });
  if (applied.q) params.set('q', applied.q);
  if (applied.status) params.set('status', applied.status);
  const { state, reload } = useResource<{ data: Entry[]; meta: { page: number; lastPage: number; total: number } }>(`/admin/url/registry?${params.toString()}`);
  const [open, setOpen] = useState<number | null>(null);

  return (
    <section className="panel">
      <h2>سجلّ العناوين</h2>
      <form
        className="filters list-toolbar"
        onSubmit={(event) => {
          event.preventDefault();
          setApplied({ q: query.trim(), status, page: 1 });
        }}
      >
        <label className="field" htmlFor="url-registry-q"><span>بحث بالمسار</span><input id="url-registry-q" dir="ltr" value={query} onChange={(e) => setQuery(e.target.value)} /></label>
        <label className="field" htmlFor="url-registry-status">
          <span>الحالة</span>
          <select id="url-registry-status" value={status} onChange={(e) => setStatus(e.target.value)}>
            <option value="">الكلّ</option>
            {Object.entries(STATUS_LABEL).map(([k, v]) => <option key={k} value={k}>{v}</option>)}
          </select>
        </label>
        <button type="submit" className="btn btn-ghost">رشّح</button>
      </form>
      <ResourceView state={state} reload={reload}>
        {(report) => (
          <>
            <div className="table-scroll">
              <table className="data-table">
                <caption className="sr-only">العناوين المسجّلة</caption>
                <thead>
                  <tr><th scope="col">الكيان</th><th scope="col">المسار</th><th scope="col">الحالة</th><th scope="col">الفهرسة</th><th scope="col">النسخة</th><th scope="col">آخر تحديث</th><th scope="col"></th></tr>
                </thead>
                <tbody>
                  {report.data.length === 0 ? <EmptyRows colSpan={7} message="لا عناوين تطابق المرشِّح." /> : report.data.map((entry) => (
                    <tr key={entry.id}>
                      <td>{entry.entityType} #{entry.entityId}</td>
                      <td dir="ltr">{entry.path}</td>
                      <td><StatusBadge label={STATUS_LABEL[entry.status] ?? entry.status} tone={STATUS_TONE[entry.status] ?? 'neutral'} /></td>
                      <td>{entry.indexable ? 'index' : 'noindex'}</td>
                      <td>{entry.version}</td>
                      <td>{entry.updatedAt ? dateTime(entry.updatedAt) : '—'}</td>
                      <td><button type="button" className="btn btn-ghost" onClick={() => setOpen(open === entry.id ? null : entry.id)}>{open === entry.id ? 'أغلق' : 'التاريخ'}</button></td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
            <div className="list-foot">
              <p className="page-note">{count(report.meta.total)} عنوان · صفحة {report.meta.page} من {report.meta.lastPage}</p>
              <span className="list-toolbar-end">
                <button type="button" className="btn btn-ghost" disabled={report.meta.page <= 1} onClick={() => setApplied({ ...applied, page: applied.page - 1 })}>السابق</button>
                <button type="button" className="btn btn-ghost" disabled={report.meta.page >= report.meta.lastPage} onClick={() => setApplied({ ...applied, page: applied.page + 1 })}>التالي</button>
              </span>
            </div>
            {open !== null ? <EntryDetail id={open} canEdit={canEdit} onChanged={reload} /> : null}
          </>
        )}
      </ResourceView>
    </section>
  );
}

/** ردّ `POST /admin/url/slug/suggest` — بدائل، وحكمٌ على المكتوب. */
type SlugAdvice = {
  suggestions: string[];
  candidate: { slug: string; errors: string[] } | null;
};

/* المهلة قبل السؤال: طلبٌ لكلّ حرفٍ يُغرق نقطةً لا تُسرَّع بشيء. */
const ADVICE_DEBOUNCE_MS = 300;

function EntryDetail({ id, canEdit, onChanged }: { id: number; canEdit: boolean; onChanged: () => void }) {
  const { state, reload } = useResource<{ data: { entry: Entry; history: HistoryRow[] } }>(`/admin/url/registry/${id}/history`);
  const [slug, setSlug] = useState('');
  const [reason, setReason] = useState('');
  const [force, setForce] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const [advice, setAdvice] = useState<SlugAdvice | null>(null);
  const adviceTimer = useRef<ReturnType<typeof setTimeout> | null>(null);
  const adviceSeq = useRef(0);

  /*
   * ─── حكم الخادم على الرابط قبل الإرسال لا بعده ───
   *
   * `POST /admin/url/slug/suggest` مبنيّةٌ ومولَّدةٌ في العقد ولم يكن لها
   * مستدعٍ واحد في اللوحة. وهذا الحقل — الموضع الوحيد الذي يُكتب فيه رابطٌ
   * بيد إنسان — كان نصًّا حرًّا بلا حكم: يُكتب «باب/جديد» فيُرسَل، فيُردّ
   * ٤٢٢، ثمّ يُخمَّن ما العيب. والقواعد ليست بديهيّة (محارف عنوانٍ ذات
   * معنى، أسماء محجوزة، طولٌ أقصى، مقاطع اجتياز).
   *
   * والحكم من `SlugEngine` نفسها التي سترفض عند الحفظ — لا نسخةٌ ثانية من
   * التعبير النمطيّ هنا تتباعد عنها.
   *
   * ─── ولماذا بمؤقّتٍ لا بأثر ───
   *
   * الأثر يُلزمه قيدُ «لا ضبط حالةٍ متزامن داخل أثر» (قاعدة ٢٢) ويجرّ معه
   * تنظيفًا وسباقًا. والمؤقّت في المعالِج أبسط: كلّ ضغطةٍ تُلغي سابقتها،
   * ورقمُ التسلسل يُسقط ردًّا بطيئًا وصل بعد أحدث منه.
   */
  const askAdvice = (next: string, basis: string) => {
    if (adviceTimer.current !== null) clearTimeout(adviceTimer.current);

    const typed = next.trim();

    if (typed === '') {
      setAdvice(null);

      return;
    }

    const seq = adviceSeq.current + 1;
    adviceSeq.current = seq;

    adviceTimer.current = setTimeout(() => {
      void api
        .post<{ data: SlugAdvice }>('/admin/url/slug/suggest', { title: basis, candidate: typed })
        .then((result) => {
          /* ردٌّ تجاوزه ما بعده يُهمَل — وإلّا عادت اقتراحاتٌ لنصٍّ مُسح. */
          if (seq !== adviceSeq.current) return;
          /* تعذّر السؤال ⇒ لا رأي. الحفظ يبقى ممكنًا والخادم يبقى الحكم. */
          setAdvice(result.ok ? result.data.data : null);
        });
    }, ADVICE_DEBOUNCE_MS);
  };

  const run = async (action: () => Promise<{ ok: boolean; message?: string }>) => {
    setBusy(true);
    setError(null);
    const result = await action();
    setBusy(false);
    if (!result.ok) {
      setError(result.message ?? 'تعذّر التنفيذ.');
      return;
    }
    setSlug('');
    setReason('');
    setAdvice(null);
    reload();
    onChanged();
  };

  return (
    <ResourceView state={state} reload={reload}>
      {({ data }) => (
        <div className="panel-inset">
          <h3><bdi dir="ltr">{data.entry.path}</bdi> · <StatusBadge label={STATUS_LABEL[data.entry.status] ?? data.entry.status} tone={STATUS_TONE[data.entry.status] ?? 'neutral'} /></h3>
          {canEdit ? (
            <form
              className="filters list-toolbar"
              onSubmit={(event) => {
                event.preventDefault();
                void run(() => api.post(`/admin/url/registry/${id}/slug`, { slug, reason, expectedVersion: data.entry.version, force }));
              }}
            >
              <label className="field" htmlFor={`slug-${id}`}>
                <span>رابطٌ جديد</span>
                <input
                  id={`slug-${id}`}
                  dir="ltr"
                  value={slug}
                  aria-describedby={advice === null ? undefined : `slug-advice-${id}`}
                  onChange={(e) => {
                    setSlug(e.target.value);
                    /* الأساس هو الرابط القائم: الاشتقاق يبدأ ممّا يُعاد تسميته. */
                    askAdvice(e.target.value, data.entry.slug);
                  }}
                  required
                />
              </label>
              <label className="field field-wide" htmlFor={`reason-${id}`}><span>السبب (يُكتب في التاريخ)</span><input id={`reason-${id}`} value={reason} onChange={(e) => setReason(e.target.value)} required /></label>
              <label className="inline-check"><input type="checkbox" checked={force} onChange={(e) => setForce(e.target.checked)} /> تجاوز القفل</label>
              <button type="submit" className="btn btn-primary" disabled={busy}>غيّر الرابط</button>
              {data.entry.status === 'locked' ? (
                <button type="button" className="btn btn-ghost" disabled={busy} onClick={() => void run(() => api.post(`/admin/url/registry/${id}/unlock`, { expectedVersion: data.entry.version }))}>افتح القفل</button>
              ) : data.entry.status === 'published' ? (
                <button type="button" className="btn btn-ghost" disabled={busy} onClick={() => void run(() => api.post(`/admin/url/registry/${id}/lock`, { expectedVersion: data.entry.version }))}>اقفل الرابط</button>
              ) : null}
            </form>
          ) : (
            <p className="page-note">تغيير الروابط يحتاج صلاحيّة url.edit.</p>
          )}

          {/*
            * حكمُ الخادم يُعرض بنصّه: «اسمٌ محجوز للنظام» تقول ما يُفعل،
            * و«رابط غير صالح» تُرسل المشغّل يخمّن. والصمت هنا نتيجةٌ أيضًا:
            * لا أخطاء يعني أنّ `SlugEngine` قبِلته — وهي نفسها من ستقبله عند
            * الحفظ، لا نسخةٌ ثانية منها.
            */}
          {advice === null || advice.candidate === null ? null : advice.candidate.errors.length > 0 ? (
            <p className="form-error" role="status">
              لا يصلح رابطًا: {advice.candidate.errors.join(' · ')}
            </p>
          ) : (
            <p id={`slug-advice-${id}`} className="page-note" role="status">
              رابطٌ صالح — ويبقى التفرّد حكمَ الحفظ.
            </p>
          )}

          {/*
            * البدائل مشتقّةٌ من الرابط القائم، فتُعرض ما دامت تختلف عنه وعن
            * المكتوب: اقتراحٌ يطابق ما بين يديك ضجيجٌ لا مساعدة.
            */}
          {advice === null
            ? null
            : (() => {
                const fresh = advice.suggestions.filter(
                  (option) => option !== data.entry.slug && option !== slug.trim(),
                );

                return fresh.length === 0 ? null : (
                  <p className="page-note">
                    بدائل:{' '}
                    {fresh.map((option) => (
                      <button
                        key={option}
                        type="button"
                        className="btn btn-ghost"
                        onClick={() => {
                          setSlug(option);
                          askAdvice(option, data.entry.slug);
                        }}
                      >
                        <bdi dir="ltr">{option}</bdi>
                      </button>
                    ))}
                  </p>
                );
              })()}

          {error ? <p className="form-error" role="alert">{error}</p> : null}
          <div className="table-scroll">
            <table className="data-table">
              <caption className="sr-only">تاريخ العنوان</caption>
              <thead><tr><th scope="col">الحدث</th><th scope="col">من</th><th scope="col">إلى</th><th scope="col">السبب</th><th scope="col">المصدر</th><th scope="col">التحويل</th><th scope="col">الوقت</th><th scope="col"></th></tr></thead>
              <tbody>
                {data.history.map((h) => (
                  <tr key={h.id}>
                    <td>{h.event}</td>
                    <td dir="ltr">{h.previousPath ?? '—'}</td>
                    <td dir="ltr">{h.newPath ?? '—'}</td>
                    <td>{h.reason ?? '—'}</td>
                    <td>{h.changeSource}</td>
                    <td>{h.redirectRuleId === null ? '—' : `#${h.redirectRuleId}`}</td>
                    <td>{h.createdAt ? dateTime(h.createdAt) : '—'}</td>
                    <td>
                      {canEdit && h.event === 'slug_changed' && h.previousPath !== null ? (
                        <button type="button" className="btn btn-ghost" disabled={busy} onClick={() => void run(() => api.post(`/admin/url/registry/${id}/rollback`, { historyId: h.id }))}>ارجع</button>
                      ) : null}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </div>
      )}
    </ResourceView>
  );
}
