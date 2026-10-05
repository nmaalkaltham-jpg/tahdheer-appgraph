'use client';
// UNIT: PAYMENTPROVIDERPANEL
// FILE: PaymentProviderPanel.tsx
// STYLE: none
// PARENT: SETTINGS-OVERVIEW-PANEL
// USES: LIB-ADMIN-API, LIB-FORMAT
// API: GET /admin/payments/provider
// MAP: docs/PROJECT_MAP.md

/**
 * حالة بوابة الدفع (Tap): الوضع «اختبار/حيّ»، اكتمال الإعداد، المفاتيح
 * مقنَّعة كما يعيدها الخادم (بادئة + آخر أربعة رموز)، رابط الإشعارات، وآخر
 * الأحداث. لا يصل مفتاح كامل إلى هذه الصفحة أصلًا.
 */

import { useEffect, useState } from 'react';
import { api, type ApiErr } from '../../lib/admin-api';
import { dateTime } from '../../lib/format';

/**
 * المضبوط والمحلول حقيقتان: `PAYMENTS_PROVIDER=fake` في إنتاجٍ يجعل Tap
 * الحيّ يقبض بينما الإعداد يقول «وهميّ». حقلٌ واحد لا يسع الاثنين.
 */
interface ProviderResolution {
  /** ما في الإعداد. */
  configured: string;
  /** من يقبض فعلًا بعد الحلّ. */
  resolved: string;
  /** المضبوط لم يُحلّ فسقط الاختيار إلى غيره. */
  fellBack: boolean;
  reason: 'configured' | 'forbidden_in_environment' | 'unknown_provider' | string;
  /** وضع المزوّد **المحلول** لا المضبوط. */
  mode: string;
  configuredCredentials: boolean;
}

interface ProviderStatus {
  providerResolution: ProviderResolution;
  appEnv: string;
  tap: {
    mode: 'test' | 'live' | string;
    configured: boolean;
    problems: { errors: Record<string, string>; warnings: Record<string, string> };
    credentials: {
      merchantId: string | null;
      secretKey: string | null;
      publicKey: string | null;
      webhookSecret: string | null;
      hasTestSecret: boolean;
      hasLiveSecret: boolean;
      webhookSecretSource: 'dedicated' | 'secret_key';
    };
    webhookUrl: string;
    baseUrl: string;
    frontendUrl: string | null;
  };
  recent: {
    captured24h: number;
    failed24h: number;
    ignored24h: number;
    duplicateCaptures: number;
    lastWebhookAt: string | null;
    lastFailure: { at: string | null; chargeId: string | null; status: string | null; code: string | null; message: string | null } | null;
  };
}

type State = { status: 'loading' } | { status: 'error'; error: ApiErr } | { status: 'ready'; data: ProviderStatus };

export function PaymentProviderPanel() {
  const [state, setState] = useState<State>({ status: 'loading' });

  useEffect(() => {
    let cancelled = false;
    api.get<ProviderStatus>('/admin/payments/provider').then((result) => {
      if (cancelled) return;
      setState(result.ok ? { status: 'ready', data: result.data } : { status: 'error', error: result });
    });
    return () => {
      cancelled = true;
    };
  }, []);

  if (state.status === 'loading') {
    return (
      <section className="panel">
        <h2>بوابة الدفع</h2>
        <p className="state-line" role="status">
          جارٍ فحص إعداد البوابة…
        </p>
      </section>
    );
  }

  if (state.status === 'error') {
    return (
      <section className="panel">
        <h2>بوابة الدفع</h2>
        <p className="state-line" role="alert">
          تعذّر قراءة حالة البوابة ({state.error.status}): {state.error.message}
        </p>
      </section>
    );
  }

  const { data } = state;
  const { tap, recent } = data;
  const errors = Object.entries(tap.problems.errors);
  const warnings = Object.entries(tap.problems.warnings);
  const live = tap.mode === 'live';

  return (
    <section className="panel">
      <h2>
        بوابة الدفع — Tap Payments{' '}
        <span className="state" style={{ background: live ? 'var(--state-danger-soft)' : 'var(--state-warning-soft)', color: live ? 'var(--state-danger)' : 'var(--state-warning)' }}>
          {live ? 'الوضع المباشر (Live)' : 'وضع الاختبار (Test)'}
        </span>{' '}
        <span className="state">{tap.configured ? 'الإعداد مكتمل' : 'الإعداد ناقص'}</span>
      </h2>
      <p className="page-note">
        المزوّد المضبوط: <bdi dir="ltr">{data.providerResolution.configured}</bdi> · الذي يقبض فعلًا:{' '}
        <bdi dir="ltr">{data.providerResolution.resolved}</bdi> · البيئة:{' '}
        <bdi dir="ltr">{data.appEnv}</bdi>. المفاتيح تُعرض مقنَّعة دائمًا؛ القيم الكاملة لا تغادر خادم
        Laravel.
      </p>

      {data.providerResolution.fellBack ? (
        <p className="state-error" role="alert">
          الإعداد يقول «<bdi dir="ltr">{data.providerResolution.configured}</bdi>» ولا يُحلّ في هذه
          البيئة، فالذي يقبض فعلًا هو «<bdi dir="ltr">{data.providerResolution.resolved}</bdi>»
          {data.providerResolution.reason === 'forbidden_in_environment'
            ? ' — المزوّد الوهميّ ممنوع خارج بيئتَي التطوير والاختبار.'
            : data.providerResolution.reason === 'unknown_provider'
              ? ' — الاسم المضبوط لا يعرفه سجلّ المزوّدين.'
              : '.'}{' '}
          إن كان القصد ألّا يتحرّك مال، فهذا الإعداد لا يحقّقه.
        </p>
      ) : null}

      {errors.length > 0 ? (
        <ul className="permission-list" role="alert">
          {errors.map(([key, message]) => (
            <li key={key}>
              ✗ <bdi dir="ltr">{key}</bdi> — {message}
            </li>
          ))}
        </ul>
      ) : null}
      {warnings.length > 0 ? (
        <ul className="permission-list">
          {warnings.map(([key, message]) => (
            <li key={key}>
              ! <bdi dir="ltr">{key}</bdi> — {message}
            </li>
          ))}
        </ul>
      ) : null}

      <table className="data-table">
        <tbody>
          <tr>
            <th scope="row">معرّف التاجر</th>
            <td>
              <bdi dir="ltr">{tap.credentials.merchantId ?? 'غير مضبوط'}</bdi>
            </td>
          </tr>
          <tr>
            <th scope="row">المفتاح السري ({live ? 'live' : 'test'})</th>
            <td>
              <bdi dir="ltr">{tap.credentials.secretKey ?? 'غير مضبوط'}</bdi>
            </td>
          </tr>
          <tr>
            <th scope="row">المفتاح العام</th>
            <td>
              <bdi dir="ltr">{tap.credentials.publicKey ?? 'غير مضبوط — غير مطلوب للصفحة المستضافة'}</bdi>
            </td>
          </tr>
          <tr>
            <th scope="row">مفاتيح الوضع الآخر</th>
            <td>
              اختبار: {tap.credentials.hasTestSecret ? 'موجود' : 'غير مضبوط'} · مباشر:{' '}
              {tap.credentials.hasLiveSecret ? 'موجود' : 'غير مضبوط'}
            </td>
          </tr>
          <tr>
            <th scope="row">سرّ توقيع الإشعارات</th>
            <td>{tap.credentials.webhookSecretSource === 'dedicated' ? 'سرّ مستقل' : 'المفتاح السري للوضع النشط'}</td>
          </tr>
          <tr>
            <th scope="row">رابط الإشعارات (post.url)</th>
            <td>
              <bdi dir="ltr">{tap.webhookUrl}</bdi>
            </td>
          </tr>
          <tr>
            <th scope="row">رابط العودة</th>
            <td>
              <bdi dir="ltr">{tap.frontendUrl ?? 'FRONTEND_URL غير مضبوط'}</bdi>
            </td>
          </tr>
        </tbody>
      </table>

      <h3>آخر 24 ساعة</h3>
      <table className="data-table">
        <tbody>
          <tr>
            <th scope="row">دفعات ملتقَطة</th>
            <td>{recent.captured24h}</td>
          </tr>
          <tr>
            <th scope="row">دفعات فاشلة</th>
            <td>{recent.failed24h}</td>
          </tr>
          <tr>
            <th scope="row">إشعارات متجاهَلة (مبلغ/عملة مخالفان)</th>
            <td>{recent.ignored24h}</td>
          </tr>
          <tr>
            <th scope="row">التقاطات مزدوجة (تحتاج استردادًا)</th>
            <td>{recent.duplicateCaptures}</td>
          </tr>
          <tr>
            <th scope="row">آخر إشعار</th>
            <td>
              <bdi>{recent.lastWebhookAt ? dateTime(recent.lastWebhookAt) : 'لم يصل إشعار بعد'}</bdi>
            </td>
          </tr>
          <tr>
            <th scope="row">آخر فشل</th>
            <td>
              {recent.lastFailure ? (
                <>
                  <bdi dir="ltr">{recent.lastFailure.chargeId}</bdi> · {recent.lastFailure.status}
                  {recent.lastFailure.code ? ` · ${recent.lastFailure.code}` : ''}
                  {recent.lastFailure.message ? ` · ${recent.lastFailure.message}` : ''}
                </>
              ) : (
                'لا فشل مسجَّل'
              )}
            </td>
          </tr>
        </tbody>
      </table>
    </section>
  );
}
