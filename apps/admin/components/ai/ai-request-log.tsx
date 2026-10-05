'use client';
// UNIT: AI-REQUEST-LOG
// FILE: ai-request-log.tsx
// STYLE: ai-request-log.module.css
// PARENT: PAGE-ANALYTICS-OS-AI
// USES: ADMIN-SESSION, LIB-ADMIN-API, LIB-CONTRACT, LIB-FORMAT, RESOURCE
// API: /admin/ai/requests
// MAP: docs/PROJECT_MAP.md

/**
 * دفتر نداءات الذكاء — النداء الواحد بمن طلبه وما كلّفه ولماذا فشل.
 *
 * ─── لماذا سطرٌ لكلّ نداء لا مجموعٌ فقط ───
 *
 * لوحة الإنفاق تقول «كم». وهذا يقول «أيّها»: أيّ حقلٍ استُهلك عليه، ومن
 * طلبه، وكم مرّة أُعيدت المحاولة قبل النجاح، وأيّ نسخةٍ من القالب كانت
 * سارية. ولا يُجاب سؤالُ «لماذا ارتفعت الفاتورة هذا الأسبوع» بمجموع.
 *
 * القدرة (`GET /admin/ai/requests`) كانت مبنيّة بلا ملفٍّ واحد يستدعيها —
 * كشفها محرّك الاكتشاف، وهذا سطحُها.
 *
 * ─── ولماذا صار للدفتر يدٌ لا عينٌ فقط ───
 *
 * كان الدفتر قراءةً محضة: يرى المشغّل «فاشل» ورمزًا مقتضبًا في `title`،
 * ولا يقرأ نصّ الخطأ ولا يُعيد النداء ولا يُلغي واحدًا عالقًا في الطابور.
 * وثلاث نقاطٍ مبنيّة ومُختبَرة (`{id}` و`retry` و`cancel`) لم يكن لها
 * مستهلكٌ واحد. ونداءٌ فاشلٌ لا تُقرأ رسالته طريقٌ مسدود: الرمز يقول
 * «انتهت المهلة» ولا يقول من أيّ مزوّدٍ ولا بعد كم ثانية.
 *
 * فالتفصيل يُفتح تحت الصفّ نفسه لا في صفحةٍ ثانية: المقارنة بين نداءٍ
 * فاشلٍ وجاره في الدفتر هي نصف التشخيص، وصفحةٌ مستقلّة تقطعها.
 *
 * ─── وما لا يُجدوَل من هنا ───
 *
 * `POST /admin/ai/requests` يجدول إثراءَ منتجٍ بمعرّفه. ولا سطح له في هذا
 * الدفتر: الصفّ يحمل `productId` رقمًا مجرّدًا بلا اسمٍ يُتحقّق منه، وزرٌّ
 * يصرف رصيد نموذجٍ على رقمٍ لا يقرأ المشغّل مسمّاه مقامرةٌ لا إجراء.
 * وصندوقُ معرّفٍ حرٍّ أسوأ: يُدخل رقمًا من الذاكرة ويجدول تحليلًا لمنتجٍ
 * آخر. موضعُ الجدولة الصادق شاشةُ المنتج حيث الاسم حاضر.
 */

import { useState } from 'react';
import { useSession } from '../admin-session';
import { ResourceView, useResource } from '../resource';
import { api, firstError, type AiRequestRow, type Paginated } from '../../lib/admin-api';
import { proxyPath } from '../../lib/contract';
import { count, dateTime, money } from '../../lib/format';
import styles from './ai-request-log.module.css';

const STATUS_FILTERS = [
  { value: 'all', label: 'الكلّ' },
  { value: 'ok', label: 'ناجحة' },
  { value: 'failed', label: 'فاشلة' },
];

/**
 * ردّ `GET /admin/ai/requests/{id}` — صفّ القائمة نفسه وفوقه ما لا يُحمل في جدول.
 *
 * النوع محلّيّ لا في `lib/admin-api.ts`: لا يقرؤه غير هذا الملفّ، وعقدُ
 * القائمة (`AiRequestRow`) هو المشترك الذي يستحقّ مكانه هناك.
 *
 * و`inputHash` بصمةٌ لا مدخل: الخادم لا يخزّن نصّ الطلب أصلًا (سياسة
 * خصوصيّة معلنة في `AiRequestLedger`)، فلا تَعِد الواجهة بعرضه.
 */
interface AiRequestDetail extends AiRequestRow {
  promptId: string;
  inputHash: string;
  errorMessage: string | null;
  startedAt: string | null;
  finishedAt: string | null;
  cancelledAt: string | null;
  retryOfId: number | null;
  /** سببُ تعذّر الإعادة بلغة الخادم، أو `null` حين تُقبل. */
  retryBlocker: string | null;
}

export function AiRequestLog() {
  const [status, setStatus] = useState('all');
  /*
   * مجموعةٌ لا معرّفٌ واحد: تشخيص موجةِ فشلٍ يقارن نداءين أو ثلاثة جنبًا
   * إلى جنب (المزوّد نفسه؟ القالب نفسه؟)، وفتحٌ يُغلق سابقه يمنع المقارنة.
   * وكلّ تفصيلٍ مفتوح موردٌ مستقلّ في ذاكرة React Query، فلا طلب يتكرّر.
   */
  const [open, setOpen] = useState<ReadonlySet<number>>(new Set<number>());
  const path = status === 'all' ? '/admin/ai/requests' : `/admin/ai/requests?status=${status}`;
  const { state, reload } = useResource<Paginated<AiRequestRow>>(path);

  const toggle = (id: number) => {
    setOpen((current) => {
      const next = new Set(current);
      if (next.has(id)) next.delete(id);
      else next.add(id);
      return next;
    });
  };

  return (
    <section className="panel">
      <h2>سجلّ النداءات</h2>
      <p className="page-note">
        النداء الواحد بمن طلبه وما كلّفه. المجموع يقول «كم»، وهذا يقول «أيّها» — والتفصيل يقول «لماذا».
      </p>

      <div className="filters" role="group" aria-label="ترشيح حسب الحالة">
        {STATUS_FILTERS.map((option) => (
          <button
            key={option.value}
            type="button"
            className={option.value === status ? 'btn btn-primary' : 'btn btn-ghost'}
            aria-pressed={option.value === status}
            onClick={() => setStatus(option.value)}
          >
            {option.label}
          </button>
        ))}
      </div>

      <ResourceView state={state} reload={reload}>
        {(page) => (
          <>
            <table className="data-table">
              <caption className="sr-only">نداءات المساعد الكتابيّ</caption>
              <thead>
                <tr>
                  <th scope="col">الحالة</th>
                  <th scope="col">الحقل</th>
                  <th scope="col">الطالب</th>
                  <th scope="col">النموذج</th>
                  <th scope="col">الرموز</th>
                  <th scope="col">التكلفة</th>
                  <th scope="col">الزمن</th>
                  <th scope="col">المحاولات</th>
                  <th scope="col">حين</th>
                  <th scope="col">التفصيل</th>
                </tr>
              </thead>
              <tbody>
                {page.data.length === 0 ? (
                  <tr>
                    <td colSpan={10}>لا نداء مسجَّل بهذا الترشيح.</td>
                  </tr>
                ) : (
                  page.data.flatMap((row) => {
                    const expanded = open.has(row.id);

                    return [
                      <tr key={row.id}>
                        {/*
                          * أربع حالاتٍ لا اثنتان.
                          *
                          * صار للنداء دورة حياة: يُحجز في الطابور فيُرى ويُلغى
                          * قبل أن يبدأ. وكان كلّ ما ليس «ناجحًا» يُعرض فاشلًا —
                          * فنداءٌ ينتظر دوره يظهر عطلًا بلا رمز خطأ، وملغًى
                          * بقرار المشغّل يظهر كأنّه انهار.
                          */}
                        <td>
                          {row.status === 'ok' ? (
                            <span className="chip chip-paid">ناجح</span>
                          ) : row.status === 'queued' ? (
                            <span className="chip">في الطابور</span>
                          ) : row.status === 'cancelled' ? (
                            <span className="chip">أُلغي</span>
                          ) : (
                            <span className="chip chip-failed" title={row.errorCode ?? undefined}>
                              {row.errorCode ?? 'فاشل'}
                            </span>
                          )}
                        </td>
                        <td>{row.field ?? <span className="absent">—</span>}</td>
                        <td>
                          {row.actorEmail ? (
                            <bdi dir="ltr">{row.actorEmail}</bdi>
                          ) : (
                            <span className="absent">بلا صاحب</span>
                          )}
                        </td>
                        <td>
                          <bdi dir="ltr">{row.model ?? '—'}</bdi>
                        </td>
                        <td>
                          {row.inputTokens === null ? (
                            <span className="absent">—</span>
                          ) : (
                            <bdi dir="ltr">
                              {count(row.inputTokens)}/{count(row.outputTokens ?? 0)}
                            </bdi>
                          )}
                        </td>
                        <td>
                          {row.costMinor === null ? (
                            <span className="absent">بلا سعر مُعلَن</span>
                          ) : (
                            <>
                              <bdi dir="ltr">{money(row.costMinor)}</bdi> ر.س
                            </>
                          )}
                        </td>
                        <td>
                          {row.latencyMs === null ? (
                            <span className="absent">—</span>
                          ) : (
                            <bdi dir="ltr">{count(row.latencyMs)}</bdi>
                          )}
                        </td>
                        <td>
                          {row.attempts > 1 ? (
                            <b title="أُعيدت المحاولة">{count(row.attempts)}</b>
                          ) : (
                            count(row.attempts)
                          )}
                        </td>
                        <td>{row.createdAt ? dateTime(row.createdAt) : <span className="absent">—</span>}</td>
                        <td>
                          {/*
                            * التفصيل لا يُطلب إلّا حين يُفتح: الدفتر يعرض
                            * خمسين سطرًا، وجلبُ تفصيل كلٍّ منها مقدّمًا خمسون
                            * طلبًا لقراءة واحد.
                            */}
                          <button
                            type="button"
                            className={styles.toggle}
                            aria-expanded={expanded}
                            aria-controls={`ai-request-${row.id}`}
                            onClick={() => toggle(row.id)}
                          >
                            {expanded ? 'أخفِ التفصيل' : 'التفصيل'}
                          </button>
                        </td>
                      </tr>,
                      expanded ? (
                        <tr key={`${row.id}-detail`} id={`ai-request-${row.id}`}>
                          <td className={styles.detailCell} colSpan={10}>
                            <RequestDetail id={row.id} onDone={reload} />
                          </td>
                        </tr>
                      ) : null,
                    ];
                  })
                )}
              </tbody>
            </table>

            <p className="page-note">
              <bdi dir="ltr">{count(page.meta.total)}</bdi> نداءً مسجَّلًا. الفشل يُسجَّل كما يُسجَّل
              النجاح — دفترٌ بلا فشل يُخفي مزوّدًا يتعثّر نصف الوقت.
            </p>
          </>
        )}
      </ResourceView>
    </section>
  );
}

/**
 * تفصيل نداءٍ واحد: ما لا يُحمل في عمود، ثمّ ما يمكن فعله به.
 *
 * ─── القرار من الخادم لا من جدولٍ مكرَّر هنا ───
 *
 * `retryBlocker` يأتي في الحمولة نفسها: إمّا `null` فتُعرض الإعادة، وإمّا
 * جملةٌ تشرح المنع فتُعرض مكانها. ولا تُعاد كتابة شروط `canRetry` في
 * الواجهة — نسختان من قاعدةٍ واحدة تتباعدان عند أوّل تعديلٍ في الخادم،
 * فيبقى الزرّ ظاهرًا لِما صار ممنوعًا أو يُخفى عمّا صار مسموحًا.
 *
 * والإلغاء بلا علَمٍ مقابل، فيُقاس على `lifecycle` الخام الذي أعلنه الخادم
 * لهذا الصفّ نفسه — لا على جدول انتقالاتٍ منسوخ. ويبقى سباقٌ ضيّق: نداءٌ
 * يغادر الطابور بين التصيير والضغط. وهو بالضبط ما تغطّيه رسالة ٤٢٢
 * حرفيًّا، وفيها الحالة الجديدة باسمها.
 */
function RequestDetail({ id, onDone }: { id: number; onDone: () => void }) {
  const { can } = useSession();
  const { state, reload } = useResource<{ data: AiRequestDetail }>(proxyPath('adminAiShowRequest', { id }));

  /*
   * الفعل يُبطل الاثنين: الإعادة تُنشئ صفًّا جديدًا في الدفتر، والإلغاء
   * يقلب حالة هذا الصفّ. وتحديثُ أحدهما دون الآخر يترك المشغّل ينظر إلى
   * «في الطابور» بعد أن ألغاه بيده.
   */
  const refresh = () => {
    reload();
    onDone();
  };

  return (
    <ResourceView state={state} reload={reload}>
      {(payload) => {
        const run = payload.data;

        return (
          <div className={styles.detail}>
            <dl className="pairs">
              <dt>الحالة الخام</dt>
              <dd>
                <bdi dir="ltr">{run.lifecycle}</bdi>
              </dd>

              <dt>المهمّة</dt>
              <dd>
                <bdi dir="ltr">{run.task}</bdi>
              </dd>

              <dt>المزوّد</dt>
              <dd>
                <bdi dir="ltr">{run.provider}</bdi>
                {run.cached ? ' · من الذاكرة' : null}
              </dd>

              <dt>القالب</dt>
              <dd>
                <bdi dir="ltr">{run.promptId}</bdi>
                {run.promptVersion === null ? null : (
                  <>
                    {' · نسخة '}
                    <bdi dir="ltr">{run.promptVersion}</bdi>
                  </>
                )}
              </dd>

              {/* البصمة لا المدخل — الخادم لا يحفظ النصّ، وهي تكفي لمطابقة نداءين. */}
              <dt>بصمة المدخل</dt>
              <dd className="mono">
                <bdi dir="ltr">{run.inputHash}</bdi>
              </dd>

              <dt>المنتج</dt>
              <dd>
                {run.productId === null ? (
                  <span className="absent">لا كيان مرتبط</span>
                ) : (
                  <bdi dir="ltr">#{count(run.productId)}</bdi>
                )}
              </dd>

              <dt>بدأ</dt>
              <dd>
                {run.startedAt === null ? (
                  <span className="absent">لم يبدأ بعد</span>
                ) : (
                  dateTime(run.startedAt)
                )}
              </dd>

              <dt>انتهى</dt>
              <dd>
                {run.finishedAt === null ? (
                  <span className="absent">لم ينتهِ</span>
                ) : (
                  dateTime(run.finishedAt)
                )}
              </dd>

              {run.cancelledAt === null ? null : (
                <>
                  <dt>أُلغي</dt>
                  <dd>{dateTime(run.cancelledAt)}</dd>
                </>
              )}

              {run.retryOfId === null ? null : (
                <>
                  <dt>إعادةٌ للنداء</dt>
                  <dd>
                    <bdi dir="ltr">#{count(run.retryOfId)}</bdi>
                  </dd>
                </>
              )}
            </dl>

            {/*
              * نصّ الخطأ هو المقصود من الفتح كلّه.
              *
              * `dir="auto"` لا `ltr`: المزوّد يردّ إنجليزيّةً غالبًا، والبوّابة
              * تردّ عربيّةً حين تمنع النداء — وفرضُ اتّجاهٍ واحد يقلب أحدهما.
              */}
            {run.errorMessage === null ? null : (
              <div>
                <h4 className={styles.failureHead}>نصّ الخطأ كما ورد</h4>
                <pre className="code-block" dir="auto">
                  {run.errorMessage}
                </pre>
              </div>
            )}

            {run.errorMessage === null && run.status === 'failed' ? (
              <p className={styles.blocker}>
                فشل بلا نصٍّ محفوظ — الرمز وحده:{' '}
                {run.errorCode === null ? 'ولا رمز' : <bdi dir="ltr">{run.errorCode}</bdi>}
              </p>
            ) : null}

            {can('products.update') ? (
              <div className="row-actions">
                {run.retryBlocker === null ? (
                  <RunAction
                    path={proxyPath('adminAiRetryRequest', { id: run.id })}
                    label="أعد النداء"
                    busyLabel="يُجدوَل…"
                    /* الصفّ الجديد لا يظهر تحت «فاشلة» — فيُقال أين يُرى. */
                    doneLabel="جُدوِل نداءٌ جديد — يظهر تحت ترشيح «الكلّ»."
                    onDone={refresh}
                  />
                ) : (
                  /* كلمات الخادم كما هي: «النداء نجح — لا شيء يُعاد» تُغني عن زرٍّ يشرح نفسه. */
                  <p className={styles.blocker}>لا تُعاد: {run.retryBlocker}</p>
                )}

                {/*
                  * `lifecycle` لا `status`: عمود العرض يطوي `QUEUED` و`RUNNING`
                  * في «في الطابور» واحدة، والإلغاء لا يقبل إلّا الأولى. فالقياس
                  * على الحالة المعروضة كان يعرض الزرّ على نداءٍ يعمل بالفعل.
                  */}
                {run.lifecycle === 'QUEUED' ? (
                  <RunAction
                    path={proxyPath('adminAiCancelRequest', { id: run.id })}
                    label="ألغِ قبل أن يبدأ"
                    busyLabel="يُلغى…"
                    onDone={refresh}
                  />
                ) : null}
              </div>
            ) : (
              /*
               * لا زرّ لمن لا يملك، ولا صمتَ أيضًا: الفعل يُحرس بصلاحيّة
               * تعديل المنتجات لأنّ ما يُجدوَل إثراءُ منتج. وزرٌّ يقود إلى ٤٠٣
               * يعلّم المشغّل ألّا يثق باللوحة، وغيابٌ بلا سببٍ يجعله يظنّه عطلًا.
               */
              <p className={styles.blocker}>
                الإعادة والإلغاء يحتاجان صلاحيّة <bdi dir="ltr">products.update</bdi>.
              </p>
            )}
          </div>
        );
      }}
    </ResourceView>
  );
}

/**
 * زرّ فعلٍ واحد ينادي الخادم ثمّ يُبطل ما صار قديمًا.
 *
 * `firstError` لا `result.message`: الرفض هنا استثناءُ تحقّقٍ (٤٢٢) والسبب
 * الحقيقيّ في `errors.request[0]` — وهو جملةٌ تسمّي المانع بعينه («لا يُلغى
 * إلّا نداءٌ ما زال في الطابور. حالته الآن: RUNNING.»). ولا تُستبدل برسالةٍ
 * عامّة: «تعذّر إتمام العمليّة» تُرسل المشغّل يخمّن ما قاله الخادم صراحةً.
 *
 * والزرّ يُعطَّل أثناء الطيران: الإعادة رخيصةُ النقر غاليةُ الأثر — نقرتان
 * متتاليتان تحجزان مقعدَين في الطابور وتصرفان الحساب مرّتين.
 */
/**
 * فعلٌ على نداء — ونجاحُه يُقال.
 *
 * ─── لماذا لا يكفي إعادة التحميل ───
 *
 * «أعد النداء» يُنشئ **صفًّا جديدًا** ولا يغيّر هذا الصفّ: يبقى «فشل»،
 * ويبقى زرّه ظاهرًا وقابلًا للنقر. وتحت ترشيح «فاشلة» — وهو الترشيح الذي
 * يستعمله المشغّل حين يطارد نداءً فاشلًا — يُرشَّح الصفّ الجديد خارج
 * القائمة. فالنتيجة أنّ الشاشة لا تتغيّر بشيء، فيُعاد النقر، فيُجدوَل نداءٌ
 * مدفوعٌ ثانٍ. والتعطيل أثناء التنفيذ لا يمنع ذلك: يمنع النقرة المزدوجة
 * لا النقرة المتعمَّدة بعد صمتٍ يبدو إخفاقًا.
 *
 * فالرسالة هنا ليست تجميلًا بل الفرق بين «لم يقع شيء» و«وقع وذهب إلى
 * مكانٍ آخر» — وهي تسمّي ذلك المكان.
 */
function RunAction({
  path,
  label,
  busyLabel,
  doneLabel,
  onDone,
}: {
  path: string;
  label: string;
  busyLabel: string;
  /** ما يُقال بعد النجاح؛ غيابُه يعني أنّ الصفّ نفسه يُظهر الأثر. */
  doneLabel?: string;
  onDone: () => void;
}) {
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [done, setDone] = useState<string | null>(null);

  const run = async () => {
    setBusy(true);
    setError(null);
    setDone(null);
    const result = await api.post<unknown>(path);
    setBusy(false);

    if (result.ok) {
      if (doneLabel !== undefined) setDone(doneLabel);
      onDone();

      return;
    }

    setError(firstError(result));
  };

  return (
    <div className={styles.action}>
      <button
        type="button"
        className={`btn btn-ghost ${styles.actionButton}`}
        disabled={busy}
        onClick={() => void run()}
      >
        {busy ? busyLabel : label}
      </button>
      {done === null ? null : (
        <p className={styles.actionDone} role="status">
          {done}
        </p>
      )}
      {error === null ? null : (
        <p className={styles.actionError} role="alert">
          {error}
        </p>
      )}
    </div>
  );
}
