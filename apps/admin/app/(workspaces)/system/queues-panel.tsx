'use client';
// UNIT: QUEUES-PANEL
// FILE: queues-panel.tsx
// STYLE: none
// PARENT: PAGE-SYSTEM
// USES: ADMIN-SESSION, LIB-ADMIN-API, LIB-FORMAT, RESOURCE
// API: /admin/system, /admin/system/outbox/dead, POST /admin/system/outbox
// MAP: docs/PROJECT_MAP.md

/**
 * التشغيل والطوابير — الجواب على «هل يعمل الخلف؟».
 *
 * ─── لماذا وُجدت هذه الشاشة ───
 *
 * صندوق الصادر هو عمود الاعتماديّة: منه تُرسل رسائل الطلبات وتُبنى الفواتير
 * ويُحدَّث فهرس البحث. وكان يعمل بلا سطحٍ واحد يقول ماذا يجري فيه: حدثٌ
 * يموت بعد خمس محاولات، أو نوعُ حدثٍ بلا مستهلك يُعالَج «بنجاح» ولا يفعل
 * شيئًا — كلاهما صامت حتّى يشتكي مشترٍ.
 *
 * كل رقم هنا يأتي من `GET /admin/system` معدودًا من جدوله. الفراغ يُعرض
 * «لا قياس» لا صفرًا، والإعادة فعلٌ مُدقَّق للمالك وحده.
 */

import { useState } from 'react';
import { useSession } from '../../../components/admin-session';
import { EmptyRows, ResourceView, useResource, type ResourceState } from '../../../components/resource';
import {
  api,
  firstError,
  type ApiErr,
  type DeadEventRow,
  type Paginated,
  type SystemHealth,
} from '../../../lib/admin-api';
import { count, dateTime } from '../../../lib/format';

function seconds(value: number | null | undefined): string {
  if (value === null || value === undefined) return '—';
  if (value < 90) return `${count(value)} ث`;
  if (value < 5400) return `${count(Math.round(value / 60))} د`;
  return `${count(Math.round(value / 3600))} س`;
}

/**
 * الحرمان يُقرأ برمزه لا برسالةٍ عامّة.
 *
 * القراءتان محروستان بالصلاحية نفسها — `GET /system` و
 * `GET /system/outbox/dead` كلتاهما `audit.read` — فحارسٌ واحد يكفيهما،
 * والصلاحية تُسمّى كما يسمّيها الخادم ليطلبها الدور. ولا زرّ إعادة مع ٤٠٣:
 * الإعادة لا تمنح صلاحية.
 *
 * والإحياء نفسه (`POST /system/outbox/{id}/replay`) صلاحيةٌ أخرى —
 * `system.manage` — تُقاس بـ`can` قبل عرض الزرّ لا بعد ردّ الخادم. وهي
 * مفصولة لأنّ إعادة بثّ حدث نطاقٍ تلمس **كلّ** مستهلكيه.
 *
 * ولا تُذكر أرقام سطورٍ في `routes/api-admin.php`: الأرقام تتقادم مع أوّل
 * إضافة، فيصير التعليق يشير إلى مسارٍ آخر.
 */
function SystemState<T>({ state, reload, children }: {
  state: ResourceState<T>;
  reload: () => void;
  children: (data: T) => React.ReactNode;
}) {
  if (state.status === 'error' && state.code === 403) {
    return (
      <div className="state-error" role="alert">
        <p>
          لا تملك صلاحية <bdi dir="ltr">audit.read</bdi> لقراءة حالة التشغيل والطوابير.
        </p>
      </div>
    );
  }

  return (
    <ResourceView state={state} reload={reload}>
      {children}
    </ResourceView>
  );
}

export function QueuesPanel() {
  const { can } = useSession();
  const health = useResource<SystemHealth>('/admin/system');
  const dead = useResource<Paginated<DeadEventRow>>('/admin/system/outbox/dead?perPage=50');
  const [busy, setBusy] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);

  const replay = async (row: DeadEventRow) => {
    setBusy(row.id);
    setError(null);
    try {
      await api.post(`/admin/system/outbox/${row.id}/replay`);
      dead.reload();
      health.reload();
    } catch (e) {
      setError(firstError(e as ApiErr));
    } finally {
      setBusy(null);
    }
  };

  return (
    <section>
      {/*
        المعماريّة أوّلًا: «ما هذه المنصّة» يسبق «هل طابورها نظيف». والقسمان
        يقرآن مصدرَين مختلفَين — الرسم من المستودع، والطوابير من القاعدة —
        فلا يُغني أحدهما عن الآخر.
      */}

      <SystemState state={health.state} reload={health.reload}>
        {(data) => (
          <>
            <div className="cards">
              <article className="card">
                <div className="label">أحداث معلّقة</div>
                <div className="value">
                  <bdi dir="ltr">{count(data.outbox.pending)}</bdi>
                </div>
                <p className="card-reason">
                  {data.outbox.oldestPendingSeconds === null
                    ? 'لا شيء ينتظر'
                    : `أقدمها منذ ${seconds(data.outbox.oldestPendingSeconds)}`}
                </p>
              </article>
              <article className={data.outbox.dead > 0 ? 'card card-alert' : 'card'}>
                <div className="label">أحداث ميتة</div>
                <div className="value">
                  <bdi dir="ltr">{count(data.outbox.dead)}</bdi>
                </div>
                <p className="card-reason">بعد خمس محاولات — تحتاج قرارًا</p>
              </article>
              <article className="card">
                <div className="label">قيد المعالجة</div>
                <div className="value">
                  <bdi dir="ltr">{count(data.outbox.processing)}</bdi>
                </div>
                <p className="card-reason">العالق أكثر من ربع ساعة يُستردّ آليًّا</p>
              </article>
              <article className="card">
                <div className="label">مهامّ الطابور</div>
                <div className="value">
                  {data.queue.status === 'MEASURED' ? (
                    <bdi dir="ltr">{count(data.queue.pending)}</bdi>
                  ) : (
                    <span className="absent">لا قياس</span>
                  )}
                </div>
                <p className="card-reason">
                  سائق <bdi dir="ltr">{data.queue.driver}</bdi>
                  {data.queue.status === 'MEASURED' && data.queue.failed !== null
                    ? ` · فاشلة ${count(data.queue.failed)}`
                    : ' · يُدار خارج القاعدة'}
                </p>
              </article>
            </div>

            {data.outbox.stalled ? (
              <p className="form-error" role="alert">
                أقدم حدث معلّق تجاوز ربع ساعة — العامل (<bdi dir="ltr">outbox:process</bdi>) متوقّف على الأرجح.
              </p>
            ) : null}

            {/*
              سجلّ العمليّات ذات الأثر الخارجيّ.

              «المجهولة» هي الرقم الذي يُقرأ أوّلًا: عمليّةٌ لم يُعرف جوابها
              **تحجز موضوعها** — وحدةُ تنفيذٍ لا يُعاد محاولتها، وصفُّ مخزونٍ
              لا يُصحَّح. والتوفيق يحسم أكثرها كلّ دقيقة، فما يبقى هو ما عجز
              المزوّد عن الإجابة عنه ويحتاج إنسانًا.
            */}
            <section className="panel">
              <h2>العمليّات ذات الأثر الخارجيّ</h2>
              <div className="cards">
                <article className={data.operations.uncertain > 0 ? 'card card-alert' : 'card'}>
                  <div className="label">مجهولة النتيجة</div>
                  <div className="value">
                    <bdi dir="ltr">{count(data.operations.uncertain)}</bdi>
                  </div>
                  <p className="card-reason">لا تُعاد قبل أن يُسأل المزوّد</p>
                </article>
                <article className={data.operations.expiredLeases > 0 ? 'card card-alert' : 'card'}>
                  <div className="label">إيجارٌ منتهٍ</div>
                  <div className="value">
                    <bdi dir="ltr">{count(data.operations.expiredLeases)}</bdi>
                  </div>
                  {/* ازديادُه المطّرد يعني أنّ التعافي نفسه لا يعمل. */}
                  <p className="card-reason">عاملٌ مات ولم يلتقطه التعافي بعد</p>
                </article>
                <article className="card">
                  <div className="label">قيد التنفيذ</div>
                  <div className="value">
                    <bdi dir="ltr">{count(data.operations.processing)}</bdi>
                  </div>
                  <p className="card-reason">عاملٌ حيٌّ يحمل إيجارها</p>
                </article>
                <article className="card">
                  <div className="label">فشلت</div>
                  <div className="value">
                    <bdi dir="ltr">{count(data.operations.failed)}</bdi>
                  </div>
                  <p className="card-reason">مصنَّفة — والمؤقّت منها يُعاد</p>
                </article>
              </div>

              {data.operations.uncertainSample.length === 0 ? (
                <p className="page-note">لا عمليّة مجهولة — كلّ أثرٍ خارجيّ محسوم.</p>
              ) : (
                <div className="table-scroll" tabIndex={0} role="region" aria-label="العمليّات المجهولة النتيجة">
                  <table className="data-table">
                    <caption className="sr-only">العمليّات المجهولة النتيجة</caption>
                    <thead>
                      <tr>
                        <th scope="col">النوع</th>
                        <th scope="col">الموضوع</th>
                        <th scope="col">جواب المزوّد</th>
                        <th scope="col">محاولات التوفيق</th>
                        <th scope="col">السبب</th>
                      </tr>
                    </thead>
                    <tbody>
                      {data.operations.uncertainSample.map((row) => (
                        <tr key={row.id}>
                          <td>{row.kind}</td>
                          <td>
                            <bdi dir="ltr">{row.subject ?? '—'}</bdi>
                          </td>
                          <td>{row.providerState === 'UNKNOWN' ? 'عجز عن الجواب' : (row.providerState ?? '—')}</td>
                          <td>
                            <bdi dir="ltr">{count(row.reconcileAttempts)}</bdi>
                          </td>
                          <td>{row.lastError ?? '—'}</td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              )}
            </section>

            <section className="panel">
            <h2>أنواع الأحداث ومستهلكوها</h2>
            <p className="page-note">
              نوعٌ بلا مستهلك يُكتب ويُغلق بنجاح ولا يفعل شيئًا. هذا الجدول هو ما يكشفه.
            </p>
            {/* الطوق منطقةُ بلوغٍ بلوحة المفاتيح: كروم لا يبئّر منطقة تمرير من تلقائه (globals.css). */}
            <div className="table-scroll" tabIndex={0} role="region" aria-label="أنواع أحداث صندوق الصادر ومستهلكوها">
            <table className="data-table">
              <caption className="sr-only">أنواع أحداث صندوق الصادر</caption>
              <thead>
                <tr>
                  <th scope="col">نوع الحدث</th>
                  <th scope="col">مكتوب</th>
                  <th scope="col">آخر كتابة</th>
                  <th scope="col">المستهلكون</th>
                </tr>
              </thead>
              <tbody>
                {data.consumers.length === 0 ? (
                  <EmptyRows colSpan={4} message="لم يُكتب أيّ حدث بعد." />
                ) : (
                  data.consumers.map((row) => (
                    <tr key={row.eventType}>
                      <td>
                        <bdi dir="ltr">{row.eventType}</bdi>
                      </td>
                      <td>
                        <bdi dir="ltr">{count(row.written)}</bdi>
                      </td>
                      <td>{row.lastWrittenAt ? dateTime(row.lastWrittenAt) : <span className="absent">—</span>}</td>
                      <td>
                        {row.consumers === 0 ? (
                          <b className="chip chip-failed">بلا مستهلك</b>
                        ) : (
                          <bdi dir="ltr">{count(row.consumers)}</bdi>
                        )}
                      </td>
                    </tr>
                  ))
                )}
              </tbody>
            </table>
            </div>
            </section>
          </>
        )}
      </SystemState>

      <section className="panel">
      <h2>رسائل الموتى</h2>
      {error ? (
        <p className="form-error" role="alert">
          {error}
        </p>
      ) : null}
      <SystemState state={dead.state} reload={dead.reload}>
        {(page) => (
          <div className="table-scroll" tabIndex={0} role="region" aria-label="أحداث صندوق الصادر الميتة">
          <table className="data-table">
            <caption className="sr-only">أحداث صندوق الصادر الميتة</caption>
            <thead>
              <tr>
                <th scope="col">نوع الحدث</th>
                <th scope="col">الكيان</th>
                <th scope="col">المحاولات</th>
                <th scope="col">آخر محاولة</th>
                <th scope="col">مستهلك</th>
                {can('system.manage') ? <th scope="col">إجراء</th> : null}
              </tr>
            </thead>
            <tbody>
              {page.data.length === 0 ? (
                <EmptyRows colSpan={can('system.manage') ? 6 : 5} message="لا حدث ميت — الطابور نظيف." />
              ) : (
                page.data.map((row) => (
                  <tr key={row.id}>
                    <td>
                      <bdi dir="ltr">{row.eventType}</bdi>
                    </td>
                    <td>
                      <bdi dir="ltr">
                        {row.aggregateType}#{row.aggregateId}
                      </bdi>
                    </td>
                    <td>
                      <bdi dir="ltr">{count(row.attempts)}</bdi>
                    </td>
                    <td>{row.lastAttemptAt ? dateTime(row.lastAttemptAt) : <span className="absent">—</span>}</td>
                    <td>
                      {row.hasConsumer ? 'نعم' : <b className="chip chip-failed">لا أحد يستمع</b>}
                    </td>
                    {can('system.manage') ? (
                      <td>
                        <button
                          type="button"
                          className="btn btn-ghost"
                          disabled={busy === row.id}
                          onClick={() => replay(row)}
                        >
                          {busy === row.id ? 'يُعاد…' : 'أعد المحاولة'}
                        </button>
                      </td>
                    ) : null}
                  </tr>
                ))
              )}
            </tbody>
          </table>
          </div>
        )}
      </SystemState>
      </section>
    </section>
  );
}
