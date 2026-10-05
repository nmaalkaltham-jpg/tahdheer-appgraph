'use client';
// UNIT: PAGE-SYSTEM-AI
// FILE: page.tsx
// ROUTE: /system/ai
// STYLE: ai.module.css
// PARENT: none
// USES: ADMIN-SESSION, COMPONENTS-ICONS, LIB-ADMIN-API, LIB-FORMAT, RESOURCE, STATES +1
// API: /admin/ai/cache/clear, /admin/ai/pause, /admin/ai/probe, /admin/ai/resume, GET /admin/ai, GET /admin/ai/suggestions
// MAP: docs/PROJECT_MAP.md

/**
 * مركز تحكّم الذكاء — حال المحرّك، وما يستهلكه، وما يقترحه.
 *
 * ─── لماذا تحت «النظام» لا تحت «النمو» ───
 *
 * هذه شاشة بنية تشغيلٍ لا شاشة تحليلات: ذاكرةٌ وطابورٌ وقاطع دائرةٍ وأزرار
 * إيقاف. ومكانها الطبيعيّ بجوار صندوق الصادر وحال النظام، بالصلاحية نفسها
 * التي تحرسهما — `audit.read` للقراءة و`system.manage` للأفعال.
 *
 * ─── ولا زرّ يستدلّ ───
 *
 * لا شيء في هذه الشاشة ينادي النموذج. التحليل يُجدول من شاشة المنتج،
 * والاستدلال يمرّ بالبوّابة من داخل الميزات. زرٌّ هنا يستدلّ كان بابًا خلفيًّا
 * يتجاوز الرايات وحدود التوازي.
 */

import { useState } from 'react';
import Link from 'next/link';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { PageHeader } from '../../../../components/resource';
import { useSession } from '../../../../components/admin-session';
import { api } from '../../../../lib/admin-api';
import { count, dateTime } from '../../../../lib/format';
import { DashboardWidget } from '../../../../components/ui/widget';
import { EmptyState, ErrorState, Skeleton } from '../../../../components/ui/states';
import { Icon } from '../../../../components/icons';
import styles from './ai.module.css';

interface AiStatus {
  enabled: boolean;
  paused: boolean;
  engine: string;
  configured: boolean;
  healthy: boolean | null;
  model: string;
  context: number;
  limits: { maxConcurrent: number; activeSlots: number; perActorPerMinute: number; maxQueueDepth: number };
  breaker: { open: boolean; failures: number; threshold: number };
  features: Record<string, boolean>;
  prompts: string[];
  queue: { state: 'MEASURED' | 'NOT_MEASURABLE'; driver: string; pending?: number; reason?: string };
  usage: {
    windowHours: number;
    total: number;
    succeeded: number;
    failed: number;
    rejected: number;
    cached: number;
    successRate: number | null;
    cacheHitRate: number | null;
    avgLatencyMs: number | null;
  };
  recentFailures: { id: number; task: string; promptId: string; status: string; errorCode: string | null; errorMessage: string | null; createdAt: string | null }[];
}

interface SuggestionRow {
  id: number;
  entityType: string;
  entityId: number;
  field: string;
  value: Record<string, unknown>;
  promptId: string;
  promptVersion: number;
  confidencePct: number | null;
  status: string;
  createdAt: string | null;
}

/** أسماء الرايات كما يفهمها المشغّل — لا مفاتيح بيئةٍ عارية. */
const FEATURE_LABELS: Record<string, string> = {
  product_enrichment: 'إثراء المنتجات',
  search_assist: 'إعانة البحث',
  seo: 'مقترحات الظهور',
  ocr_enrichment: 'إثراء الاستخراج',
  admin_copilot: 'مساعد المشرف',
  customer_assistant: 'مساعد العميل',
  recommendations: 'التوصيات',
};

export default function AiControlPage() {
  const { can } = useSession();
  const mayRead = can('audit.read');
  const mayManage = can('system.manage');
  const mayApprove = can('products.update');
  const client = useQueryClient();
  const [note, setNote] = useState<string | null>(null);

  const status = useQuery<{ ai: AiStatus }>({
    queryKey: ['admin-resource', '/admin/ai'],
    enabled: mayRead,
    refetchInterval: 15_000,
    queryFn: async () => {
      const result = await api.get<{ ai: AiStatus }>('/admin/ai');
      if (!result.ok) throw new Error(result.message);
      return result.data;
    },
  });

  const suggestions = useQuery<{ data: SuggestionRow[]; meta: { total: number } }>({
    queryKey: ['admin-resource', '/admin/ai/suggestions?status=PENDING'],
    enabled: can('products.read'),
    queryFn: async () => {
      const result = await api.get<{ data: SuggestionRow[]; meta: { total: number } }>('/admin/ai/suggestions?status=PENDING&perPage=25');
      if (!result.ok) throw new Error(result.message);
      return result.data;
    },
  });

  /*
   * فعلٌ واحد لكلّ الأزرار: كلّها POST بلا حمولةٍ تقريبًا، وتختلف في المسار
   * والرسالة وحدهما. ستّ طفراتٍ متطابقة كانت ستتباعد عند أوّل تعديل.
   */
  const act = useMutation({
    mutationFn: async ({ path, body }: { path: string; body?: Record<string, unknown> }) => {
      const result = await api.post(path, body ?? {});
      if (!result.ok) throw new Error(result.message);
      return result.data;
    },
    onSuccess: () => {
      void client.invalidateQueries({ queryKey: ['admin-resource', '/admin/ai'] });
      void client.invalidateQueries({ queryKey: ['admin-resource', '/admin/ai/suggestions?status=PENDING'] });
    },
    onError: (error: Error) => setNote(error.message),
  });

  const ai = status.data?.ai;

  return (
    <>
      <PageHeader trail="النظام › الذكاء" title="مركز تحكّم الذكاء" />

      <div className={styles.page}>
        {!mayRead ? (
          <ErrorState code={403} message="حال الذكاء محجوز لصلاحية audit.read." />
        ) : status.isPending ? (
          <Skeleton rows={5} columns={3} />
        ) : status.isError ? (
          <ErrorState message={status.error.message} onRetry={() => void status.refetch()} />
        ) : (
          <>
            {note === null ? null : (
              <p className={styles.note} role="status">
                {note}
                <button type="button" className="btn btn-ghost" onClick={() => setNote(null)}>
                  إخفاء
                </button>
              </p>
            )}

            <div className={styles.grid}>
              <DashboardWidget title="حال المحرّك" description="النموذج والصحّة والحدود." span={1}>
                <ul className={styles.rows}>
                  <Row
                    label="الحالة"
                    value={!ai!.enabled ? 'مطفأ' : ai!.paused ? 'موقوف' : ai!.healthy === true ? 'يعمل' : 'لا يستجيب'}
                    note={!ai!.enabled ? 'AI_ENABLED غير مفعّلة — المتجر يعمل كاملًا' : `المحرّك: ${ai!.engine}`}
                    bad={ai!.enabled && (ai!.paused || ai!.healthy !== true)}
                  />
                  <Row label="النموذج" value={ai!.model} note={`نافذة السياق ${count(ai!.context)} رمزًا`} bad={false} />
                  <Row
                    label="الاستدلال المتزامن"
                    value={`${count(ai!.limits.activeSlots)} / ${count(ai!.limits.maxConcurrent)}`}
                    note="مقعدٌ مشغول من المقاعد المتاحة"
                    bad={ai!.limits.activeSlots >= ai!.limits.maxConcurrent}
                  />
                  <Row
                    label="قاطع الدائرة"
                    value={ai!.breaker.open ? 'مفتوح' : 'مغلق'}
                    note={`${count(ai!.breaker.failures)} من ${count(ai!.breaker.threshold)} فشلًا`}
                    bad={ai!.breaker.open}
                  />
                  <Row
                    label="الطابور"
                    /*
                     * سائق Redis لا يُعدّ من القاعدة، والخادم يقولها صراحةً.
                     * صفرٌ هنا كان سيقول «لا شيء عالق» ولا أحد يعلم.
                     */
                    value={ai!.queue.state === 'MEASURED' ? count(ai!.queue.pending ?? 0) : 'NOT MEASURABLE'}
                    note={ai!.queue.reason ?? `السائق: ${ai!.queue.driver}`}
                    bad={(ai!.queue.pending ?? 0) >= ai!.limits.maxQueueDepth}
                  />
                </ul>
              </DashboardWidget>

              <DashboardWidget title="الاستهلاك" description={`آخر ${count(ai!.usage.windowHours)} ساعة.`} span={1}>
                <ul className={styles.rows}>
                  <Row label="نداءات" value={count(ai!.usage.total)} note={`نجح ${count(ai!.usage.succeeded)} · فشل ${count(ai!.usage.failed)} · رُفض خرجه ${count(ai!.usage.rejected)}`} bad={ai!.usage.failed > 0} />
                  {/*
                    * نسبةٌ بمقامٍ صفريّ تُقال «لا مقام» لا «٠٪» — الثاني يُقرأ
                    * فشلًا تامًّا بينما الحقيقة أنّه لم يُنادَ أحد.
                    */}
                  <Row label="نسبة النجاح" value={ai!.usage.successRate === null ? 'لا مقام' : `${ai!.usage.successRate}%`} note="من كلّ النداءات في المدى" bad={(ai!.usage.successRate ?? 100) < 90} />
                  <Row label="نسبة الإصابة من المخزون" value={ai!.usage.cacheHitRate === null ? 'لا مقام' : `${ai!.usage.cacheHitRate}%`} note="نداءٌ أُجيب بلا استدلال" bad={false} />
                  <Row label="متوسّط الزمن" value={ai!.usage.avgLatencyMs === null ? 'لا مقام' : `${count(ai!.usage.avgLatencyMs)} مللي`} note="للنداءات التي استدلّت فعلًا" bad={(ai!.usage.avgLatencyMs ?? 0) > 10_000} />
                </ul>
              </DashboardWidget>

              <DashboardWidget title="الرايات" description="إطفاء واحدةٍ لا يمسّ البقيّة." span={1}>
                <ul className={styles.flags}>
                  {Object.entries(ai!.features).map(([key, on]) => (
                    <li key={key} data-on={on && ai!.enabled ? 'true' : 'false'}>
                      <Icon name={on && ai!.enabled ? 'check' : 'shield'} size={14} />
                      <span>{FEATURE_LABELS[key] ?? key}</span>
                      <em>{!ai!.enabled ? 'المحرّك مطفأ' : on ? 'مفعّلة' : 'مطفأة'}</em>
                    </li>
                  ))}
                </ul>
                {/*
                  * الرايات تُقرأ ولا تُبدَّل من هنا: هي قرار نشرٍ في ملفّ
                  * البيئة، وزرٌّ يغيّرها في الذاكرة كان يجعل الشاشة تخالف
                  * الملفّ بعد أوّل إعادة تشغيل.
                  */}
                <p className={styles.hint}>تُضبط في ملفّ البيئة. الإيقاف أدناه فوريٌّ ويعلوها جميعًا.</p>
              </DashboardWidget>

              <DashboardWidget title="الأفعال" description="إيقافٌ فوريّ بلا نشر." span={1}>
                {!mayManage ? (
                  <EmptyState icon="shield" title="لا صلاحية" body="الأفعال محجوزة لصلاحية system.manage. القراءة تعمل." />
                ) : (
                  <div className={styles.actions}>
                    <button type="button" className="btn btn-ghost" disabled={act.isPending} onClick={() => act.mutate({ path: '/admin/ai/probe' })}>
                      افحص الآن
                    </button>
                    {ai!.paused ? (
                      <button type="button" className="btn btn-primary" disabled={act.isPending} onClick={() => act.mutate({ path: '/admin/ai/resume' })}>
                        استئناف
                      </button>
                    ) : (
                      <button type="button" className="btn btn-ghost" disabled={act.isPending} onClick={() => act.mutate({ path: '/admin/ai/pause' })}>
                        إيقاف
                      </button>
                    )}
                    <button type="button" className="btn btn-ghost" disabled={act.isPending} onClick={() => act.mutate({ path: '/admin/ai/cache/clear' })}>
                      امسح مخزون الخرج
                    </button>
                    <p className={styles.hint}>
                      المسح يطال مخرجات النموذج وحدها — لا الجلسات ولا الثيم ولا ذاكرة المتجر.
                    </p>
                  </div>
                )}
              </DashboardWidget>

              <DashboardWidget
                title="اقتراحات تنتظر قرارًا"
                description="لا اقتراح يصير بياناتٍ بلا اعتماد مشرف."
                span={2}
                action={
                  <Link className="btn btn-ghost" href="/studio/catalog">
                    الكتالوج
                  </Link>
                }
              >
                {suggestions.isPending ? (
                  <Skeleton rows={3} columns={4} />
                ) : suggestions.isError ? (
                  <ErrorState message={suggestions.error.message} onRetry={() => void suggestions.refetch()} />
                ) : suggestions.data.data.length === 0 ? (
                  <EmptyState icon="check" title="لا اقتراح معلّق" body="كلّ ما اقترحه النموذج بُتَّ فيه." />
                ) : (
                  <ul className={styles.suggestions}>
                    {suggestions.data.data.map((row) => (
                      <li key={row.id}>
                        <div className={styles.sugHead}>
                          <Link href={`/studio/catalog/${row.entityId}`}>
                            {row.entityType} #{count(row.entityId)}
                          </Link>
                          <span className={styles.sugField}>{row.field}</span>
                          {/* ثقةٌ أعلنها النموذج، أو «لم يعلنها» — لا صفرٌ مخترع. */}
                          <span className={styles.sugConf}>
                            {row.confidencePct === null ? 'بلا ثقة معلنة' : `ثقة ${row.confidencePct}%`}
                          </span>
                          <bdi dir="ltr" className={styles.sugMeta}>
                            {row.promptId}@{row.promptVersion}
                          </bdi>
                        </div>
                        <pre className={styles.sugValue} dir="auto">
                          {JSON.stringify(row.value, null, 2)}
                        </pre>
                        {!mayApprove ? (
                          <p className={styles.hint}>الاعتماد يحتاج صلاحية products.update.</p>
                        ) : (
                          <div className={styles.sugActions}>
                            <button
                              type="button"
                              className="btn btn-primary"
                              disabled={act.isPending}
                              onClick={() => act.mutate({ path: `/admin/ai/suggestions/${row.id}/approve` })}
                            >
                              اعتماد
                            </button>
                            <button
                              type="button"
                              className="btn btn-ghost"
                              disabled={act.isPending}
                              onClick={() => {
                                /*
                                 * السبب إلزاميّ في الخادم. يُطلب هنا صراحةً
                                 * بدل إرسال طلبٍ يُرَدّ ٤٢٢ ويبدو عطلًا.
                                 */
                                const reason = window.prompt('لماذا تُرفض؟ السبب يُحسّن الموجّه.');
                                if (reason !== null && reason.trim().length >= 3) {
                                  act.mutate({ path: `/admin/ai/suggestions/${row.id}/reject`, body: { reason: reason.trim() } });
                                }
                              }}
                            >
                              رفض
                            </button>
                          </div>
                        )}
                      </li>
                    ))}
                  </ul>
                )}
              </DashboardWidget>

              <DashboardWidget title="آخر الإخفاقات" description="ما فشل أو رُفض خرجه." span={2}>
                {ai!.recentFailures.length === 0 ? (
                  <EmptyState icon="check" title="لا إخفاق مسجَّل" body="كلّ النداءات الأخيرة نجحت." />
                ) : (
                  <div className="table-scroll">
                    <table className={styles.table}>
                      <thead>
                        <tr>
                          <th scope="col">المهمّة</th>
                          <th scope="col">الموجّه</th>
                          <th scope="col">الحالة</th>
                          <th scope="col">السبب</th>
                          <th scope="col">حين</th>
                        </tr>
                      </thead>
                      <tbody>
                        {ai!.recentFailures.map((row) => (
                          <tr key={row.id}>
                            <td>{row.task}</td>
                            <td>
                              <bdi dir="ltr">{row.promptId}</bdi>
                            </td>
                            <td>{row.status === 'REJECTED' ? 'رُفض خرجه' : 'فشل'}</td>
                            <td className={styles.reason}>{row.errorMessage ?? row.errorCode ?? '—'}</td>
                            <td>
                              <bdi dir="ltr">{dateTime(row.createdAt)}</bdi>
                            </td>
                          </tr>
                        ))}
                      </tbody>
                    </table>
                  </div>
                )}
              </DashboardWidget>
            </div>
          </>
        )}
      </div>
    </>
  );
}

/** سطرُ حالٍ واحد — اسمٌ وقيمةٌ وسبب. الحالة من `data-bad` لا من صنفٍ ثانٍ. */
function Row({ label, value, note, bad }: { label: string; value: string; note: string; bad: boolean }) {
  return (
    <li className={styles.row} data-bad={bad ? 'true' : 'false'}>
      <Icon name={bad ? 'alert' : 'check'} size={16} className={bad ? styles.alertIcon : styles.calmIcon} />
      <span className={styles.rowText}>
        <strong>{label}</strong>
        <em>{note}</em>
      </span>
      <bdi dir="ltr" className={styles.rowValue}>
        {value}
      </bdi>
    </li>
  );
}
