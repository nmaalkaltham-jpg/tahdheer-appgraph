'use client';
// UNIT: AI-USAGE-PANEL
// FILE: ai-usage-panel.tsx
// STYLE: none
// PARENT: PAGE-ANALYTICS-OS-AI
// USES: LIB-FORMAT, RESOURCE
// API: /admin/ai
// MAP: docs/PROJECT_MAP.md

/**
 * محاسبة استهلاك الذكاء.
 *
 * ─── لماذا زالت البوّابة ───
 *
 * كانت تقول «لا مزوّد ولا كلفة ولا زمن محفوظ». وصار الثلاثة محفوظةً في
 * `ai_runs`: المزوّد والنموذج ونسخة الموجّه والزمن ووحدات الدخل والخرج،
 * لكلّ نداءٍ على حدة.
 *
 * ─── والكلفة تبقى معلَنة الغياب ───
 *
 * النموذج محلّيّ بلا سعرٍ مُعلَن للرمز. فالعمود موجود ويبقى `null`، وتُعرض
 * «بلا سعر مُعلَن» لا صفرًا — صفرٌ يُقرأ «مجّانيّ ومقيس»، وهو ادّعاءٌ لا سند
 * له: الكهرباء والعتاد كلفةٌ لا نعرف توزيعها على النداء.
 *
 * وهذا هو الفرق بين بوّابةٍ صادقة وبوّابةٍ بائتة: الأولى تحمي من نقصٍ قائم،
 * والثانية تُخفي ما بُني.
 */

import { useResource, ResourceView } from '../resource';
import { count } from '../../lib/format';

interface AiUsage {
  ai: {
    enabled: boolean;
    model: string;
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
  };
}

/** نسبةٌ بمقامٍ صفريّ تُقال «لا مقام» — لا «٠٪» التي تُقرأ فشلًا تامًّا. */
function ratio(value: number | null): string {
  return value === null ? 'لا مقام' : `${value}%`;
}

export function AiUsagePanel() {
  const { state, reload } = useResource<AiUsage>('/admin/ai');

  return (
    <section className="panel">
      <h2>محاسبة الاستهلاك</h2>

      <ResourceView state={state} reload={reload}>
        {(payload) => {
          const u = payload.ai.usage;

          return (
            <>
              <p className="page-note">
                آخر <bdi dir="ltr">{count(u.windowHours)}</bdi> ساعة · النموذج{' '}
                <bdi dir="ltr">{payload.ai.model}</bdi>
                {payload.ai.enabled ? '' : ' — المحرّك مطفأ حاليًّا.'}
              </p>

              <div className="cards">
                <article className="card">
                  <div className="label">نداءات</div>
                  <div className="value">
                    <bdi dir="ltr">{count(u.total)}</bdi>
                  </div>
                  <p className="card-reason">
                    نجح {count(u.succeeded)} · فشل {count(u.failed)} · رُفض خرجه {count(u.rejected)}
                  </p>
                </article>

                <article className={u.successRate !== null && u.successRate < 90 ? 'card card-alert' : 'card'}>
                  <div className="label">نسبة النجاح</div>
                  <div className="value">
                    <bdi dir="ltr">{ratio(u.successRate)}</bdi>
                  </div>
                  <p className="card-reason">من كلّ النداءات في المدى</p>
                </article>

                <article className="card">
                  <div className="label">الإصابة من المخزون</div>
                  <div className="value">
                    <bdi dir="ltr">{ratio(u.cacheHitRate)}</bdi>
                  </div>
                  <p className="card-reason">نداءٌ أُجيب بلا استدلال</p>
                </article>

                <article className="card">
                  <div className="label">متوسّط الزمن</div>
                  <div className="value">
                    {u.avgLatencyMs === null ? (
                      <span className="absent">لا مقام</span>
                    ) : (
                      <bdi dir="ltr">{count(u.avgLatencyMs)}</bdi>
                    )}
                  </div>
                  <p className="card-reason">للنداءات التي استدلّت فعلًا</p>
                </article>

                <article className="card">
                  <div className="label">الكلفة</div>
                  <div className="value">
                    <span className="absent">بلا سعر مُعلَن</span>
                  </div>
                  <p className="card-reason">
                    نموذجٌ محلّيّ: لا سعر للرمز. وصفرٌ هنا يُقرأ «مجّانيّ ومقيس» وهو ادّعاءٌ لا سند له.
                  </p>
                </article>
              </div>
            </>
          );
        }}
      </ResourceView>
    </section>
  );
}
