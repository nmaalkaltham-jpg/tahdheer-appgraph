'use client';
// UNIT: INTEGRATIONS-PANEL
// FILE: integrations-panel.tsx
// STYLE: integrations.module.css
// PARENT: PAGE-SETTINGS
// USES: ADMIN-SESSION, COMPONENTS-ICONS, CONFIRM-DIALOG, LIB-ADMIN-API, LIB-FORMAT, STATES +1
// API: DELETE /admin/integrations/credentials, GET /admin/integrations
// MAP: docs/PROJECT_MAP.md

/**
 * مفاتيح الربط — بوّابات الدفع ومنصّات التواصل.
 *
 * ─── لماذا هذه الصفحة ───
 *
 * كانت المفاتيح كلّها في `.env` على الخادم: لا يراها المالك، ولا يعرف أيّها
 * مضبوط، ولا يستطيع تدويرها بلا مهندس. فمفتاحٌ مسرَّب يبقى صالحًا حتّى
 * يفرغ ذلك المهندس.
 *
 * ─── العقد الذي تلتزمه ───
 *
 * ١ — **السرّ يُعرض مرّةً واحدة**، لحظة الإدخال، من الحقل الذي كتبه المستعمل.
 *     ولا يعود من الخادم بعدها أبدًا: طلبُ «أرِني المفتاح» يجعل كلّ جلسةٍ
 *     مسروقة تُخرج كلّ مفاتيح المتجر دفعةً واحدة، ويجعل المفتاح يمرّ في سجلّ
 *     وكيلٍ وذاكرة متصفّحٍ ولقطة شاشة. من نسيه يُدوّره — والتدوير أرخص من
 *     التسريب.
 *
 * ٢ — **ما ليس سرًّا يُعرض كاملًا.** معرّف التاجر والمفتاح العلنيّ يُرسَلان
 *     إلى متصفّح كلّ زبون أصلًا. وتقنيعُهما يجعل كلّ حقلٍ يبدو خطيرًا، فيتوقّف
 *     المستعمل عن قراءة التحذيرات.
 *
 * ٣ — **البيئة الحيّة تحتاج تأكيدًا.** لصقةٌ في الحقل الخطأ تُحوّل مال
 *     الزبائن إلى حسابٍ آخر، وهو عطلٌ لا يُكتشف إلّا عند التسوية.
 *
 * ٤ — **لا لصق في الحقل الخطأ.** الخادم يفحص البادئة (`sk_` مقابل `pk_`)
 *     وتطابق البيئة. و`sk_` في خانة النشر يُسرّب المفتاح السرّيّ إلى كلّ
 *     زائر، وهو خطأٌ لا يُصدر رسالةً من المزوّد.
 */

import { useCallback, useMemo, useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { useSession } from '../../../../components/admin-session';
import { Icon } from '../../../../components/icons';
import { ConfirmDialog } from '../../../../components/ui/confirm-dialog';
import { EmptyState, ErrorState, Skeleton } from '../../../../components/ui/states';
import { StatusBadge } from '../../../../components/ui/status-badge';
import { api, firstError } from '../../../../lib/admin-api';
import { dateTime } from '../../../../lib/format';
import styles from './integrations.module.css';

type Environment = 'test' | 'live';

interface FieldSpec {
  field: string;
  label: string;
  secret: boolean;
  prefix: string | null;
  hint: string | null;
}

interface ProviderSpec {
  provider: string;
  title: string;
  kind: 'payment' | 'social' | 'analytics';
  docs: string | null;
  fields: FieldSpec[];
}

interface Credential {
  provider: string;
  environment: Environment;
  field: string;
  isSecret: boolean;
  value: string | null;
  preview: string | null;
  fingerprint: string | null;
  revokedAt: string | null;
  rotatedAt: string | null;
  lastUsedAt: string | null;
  updatedAt: string | null;
}

interface Payload {
  providers: ProviderSpec[];
  environments: Environment[];
  credentials: Credential[];
  policy: { secretsReturned: boolean; note: string };
}

const KIND_LABELS: Record<ProviderSpec['kind'], string> = {
  payment: 'دفع',
  social: 'تواصل',
  analytics: 'تحليلات',
};

export function IntegrationsPanel() {
  const { can } = useSession();
  const mayManage = can('integrations.manage');

  const [environment, setEnvironment] = useState<Environment>('test');
  const [drafts, setDrafts] = useState<Record<string, string>>({});
  const [busyKey, setBusyKey] = useState<string | null>(null);
  const [notice, setNotice] = useState<{ tone: 'ok' | 'error'; text: string } | null>(null);
  const [fieldError, setFieldError] = useState<{ key: string; message: string } | null>(null);
  const [pendingRevoke, setPendingRevoke] = useState<{ provider: string; field: string; label: string } | null>(null);

  const query = useQuery<Payload>({
    queryKey: ['admin-resource', '/admin/integrations'],
    enabled: mayManage,
    queryFn: async () => {
      const result = await api.get<Payload>('/admin/integrations');
      if (!result.ok) {
        const error = new Error(result.message) as Error & { code?: number };
        error.code = result.status;
        throw error;
      }
      return result.data;
    },
  });

  const saved = useMemo(() => {
    const map = new Map<string, Credential>();
    for (const credential of query.data?.credentials ?? []) {
      map.set(`${credential.provider}:${credential.environment}:${credential.field}`, credential);
    }
    return map;
  }, [query.data]);

  const save = useCallback(
    async (provider: string, spec: FieldSpec) => {
      const key = `${provider}:${environment}:${spec.field}`;
      const value = (drafts[key] ?? '').trim();

      if (value === '') return;

      setBusyKey(key);
      setNotice(null);
      setFieldError(null);

      const result = await api.post<{ data: Credential }>('/admin/integrations/credentials', {
        provider,
        environment,
        field: spec.field,
        value,
        ...(environment === 'live' ? { confirmLive: true } : {}),
      });

      setBusyKey(null);

      if (!result.ok) {
        setFieldError({ key, message: firstError(result) });

        return;
      }

      /*
       * الحقل يُفرَّغ فور النجاح.
       *
       * إبقاء السرّ في مربّع نصٍّ بعد الحفظ يجعله يعيش في ذاكرة الصفحة وفي
       * أيّ لقطة شاشة لاحقة — والخادم لن يُعيده، فلا فائدة من بقائه.
       */
      setDrafts((was) => ({ ...was, [key]: '' }));
      setNotice({
        tone: 'ok',
        text: spec.secret
          ? `حُفظ «${spec.label}». لن يُعرض ثانيةً — إن احتجته فدوّره.`
          : `حُفظ «${spec.label}».`,
      });
      void query.refetch();
    },
    [drafts, environment, query],
  );

  const revoke = useCallback(async () => {
    if (pendingRevoke === null) return;

    const result = await api.delete('/admin/integrations/credentials', {
      provider: pendingRevoke.provider,
      environment,
      field: pendingRevoke.field,
    });

    setPendingRevoke(null);

    if (!result.ok) {
      setNotice({ tone: 'error', text: firstError(result) });

      return;
    }

    setNotice({ tone: 'ok', text: `سُحب «${pendingRevoke.label}». القيمة مُحيت والسجلّ باقٍ.` });
    void query.refetch();
  }, [pendingRevoke, environment, query]);

  if (!mayManage) {
    return (
      <section className={styles.page}>
        <ErrorState
          code={403}
          message="إدارة مفاتيح الربط للمالك وحده. من يملك مفتاح بوّابة الدفع يملك تحويل مال الزبائن، ومن يملك رمز منصّةٍ اجتماعيّة ينشر باسم المتجر."
        />
      </section>
    );
  }

  return (
    <section className={styles.page}>

      <div className={styles.policy} role="note">
        <Icon name="shield" size={18} />
        <p>
          السرّ يُعرض مرّةً واحدة لحظة الإدخال ولا يعود من الخادم بعدها. ما تراه لاحقًا معاينةٌ
          مقنَّعة تكفي للتمييز ولا تكفي للاستعمال. ومن نسي مفتاحًا فليُدوّره — التدوير أرخص من
          التسريب.
        </p>
      </div>

      {/* البيئة قبل كلّ شيء: المفتاح الحيّ في بيئة تجربة يُحصّل مالًا حقيقيًّا. */}
      <div className={styles.envBar} role="group" aria-label="البيئة">
        {(query.data?.environments ?? ['test', 'live']).map((env) => (
          <button
            key={env}
            type="button"
            className={styles.env}
            aria-pressed={environment === env}
            data-live={env === 'live' ? '' : undefined}
            onClick={() => {
              setEnvironment(env);
              setNotice(null);
              setFieldError(null);
            }}
          >
            {env === 'live' ? 'الإنتاج (حيّ)' : 'التجربة'}
          </button>
        ))}
        {environment === 'live' ? (
          <span className={styles.liveWarn}>
            <Icon name="alert" size={14} />
            ما يُكتب هنا يُحصّل مالًا حقيقيًّا.
          </span>
        ) : null}
      </div>

      {notice !== null ? (
        <p
          className={notice.tone === 'ok' ? 'state-ok' : 'state-error'}
          role={notice.tone === 'ok' ? 'status' : 'alert'}
        >
          {notice.text}
        </p>
      ) : null}

      {query.isPending ? (
        <Skeleton rows={6} columns={3} />
      ) : query.isError ? (
        <ErrorState message={query.error.message} onRetry={() => void query.refetch()} />
      ) : query.data.providers.length === 0 ? (
        <EmptyState icon="link" title="لا مزوّدين" body="لا مزوّد معرَّف في الكتالوج." />
      ) : (
        <div className={styles.providers}>
          {query.data.providers.map((provider) => (
            <article key={provider.provider} className={styles.provider}>
              <header className={styles.providerHead}>
                <div>
                  <h2 className={styles.providerTitle}>{provider.title}</h2>
                  <span className={styles.kind}>{KIND_LABELS[provider.kind]}</span>
                </div>
                {provider.docs === null ? null : (
                  <a
                    className={styles.docs}
                    href={provider.docs}
                    target="_blank"
                    rel="noopener noreferrer"
                  >
                    الوثائق
                    <span aria-hidden="true">↗</span>
                    <span className="sr-only">(يفتح في تبويب جديد)</span>
                  </a>
                )}
              </header>

              <ul className={styles.fields}>
                {provider.fields.map((spec) => {
                  const key = `${provider.provider}:${environment}:${spec.field}`;
                  const current = saved.get(key);
                  const active = current !== undefined && current.revokedAt === null;
                  const inputId = `cred-${key.replace(/[^a-z0-9]/gi, '-')}`;

                  return (
                    <li key={spec.field} className={styles.field}>
                      <div className={styles.fieldHead}>
                        <label htmlFor={inputId} className={styles.fieldLabel}>
                          {spec.label}
                        </label>
                        {spec.secret ? (
                          <StatusBadge label="سرّ" tone="danger" />
                        ) : (
                          <StatusBadge label="علنيّ" tone="neutral" />
                        )}
                        {active ? (
                          <StatusBadge label="مضبوط" tone="success" />
                        ) : current !== undefined ? (
                          <StatusBadge label="مسحوب" tone="warning" />
                        ) : (
                          <StatusBadge label="غير مضبوط" tone="neutral" />
                        )}
                      </div>

                      {spec.hint === null ? null : <p className={styles.hint}>{spec.hint}</p>}

                      {active ? (
                        <p className={styles.currentValue}>
                          {/* الظاهر كاملًا؛ والسرّ مقنَّعًا. */}
                          <bdi dir="ltr">{current.isSecret ? current.preview : current.value}</bdi>
                          {current.fingerprint === null ? null : (
                            <span className={styles.fingerprint}>
                              بصمة <bdi dir="ltr">{current.fingerprint}</bdi>
                            </span>
                          )}
                          {current.lastUsedAt === null ? null : (
                            <span className={styles.stamp}>
                              آخر استعمال <bdi>{dateTime(current.lastUsedAt)}</bdi>
                            </span>
                          )}
                        </p>
                      ) : null}

                      <div className={styles.row}>
                        <input
                          id={inputId}
                          /*
                           * `password` للسرّ: يمنع عرضه على الشاشة ويمنع
                           * حفظه في تعبئة المتصفّح التلقائيّة.
                           */
                          type={spec.secret ? 'password' : 'text'}
                          dir="ltr"
                          autoComplete="off"
                          spellCheck={false}
                          placeholder={
                            active ? 'قيمة جديدة للتدوير…' : spec.prefix === null ? '' : `${spec.prefix}…`
                          }
                          value={drafts[key] ?? ''}
                          disabled={busyKey !== null}
                          onChange={(event) => {
                            setFieldError(null);
                            setDrafts((was) => ({ ...was, [key]: event.target.value }));
                          }}
                        />
                        <button
                          type="button"
                          className="btn btn-primary"
                          disabled={busyKey !== null || (drafts[key] ?? '').trim() === ''}
                          onClick={() => void save(provider.provider, spec)}
                        >
                          {busyKey === key ? 'جارٍ…' : active ? 'تدوير' : 'حفظ'}
                        </button>
                        {active ? (
                          <button
                            type="button"
                            className="btn btn-ghost"
                            disabled={busyKey !== null}
                            onClick={() =>
                              setPendingRevoke({
                                provider: provider.provider,
                                field: spec.field,
                                label: spec.label,
                              })
                            }
                          >
                            سحب
                          </button>
                        ) : null}
                      </div>

                      {fieldError !== null && fieldError.key === key ? (
                        <p className={styles.fieldError} role="alert">
                          {fieldError.message}
                        </p>
                      ) : null}
                    </li>
                  );
                })}
              </ul>
            </article>
          ))}
        </div>
      )}

      <ConfirmDialog
        open={pendingRevoke !== null}
        title={`سحب «${pendingRevoke?.label ?? ''}»؟`}
        consequence={
          environment === 'live'
            ? 'القيمة تُمحى فورًا وتتوقّف كلّ عمليّة تعتمد عليها في الإنتاج. السجلّ والبصمة يبقيان للتدقيق. لا يمكن استرجاع القيمة — ستحتاج مفتاحًا جديدًا من المزوّد.'
            : 'القيمة تُمحى فورًا في بيئة التجربة. السجلّ والبصمة يبقيان للتدقيق، والقيمة لا تُسترجع.'
        }
        confirmLabel="اسحب"
        onConfirm={revoke}
        onCancel={() => setPendingRevoke(null)}
      />
    </section>
  );
}
