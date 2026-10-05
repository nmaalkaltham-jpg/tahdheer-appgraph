'use client';
// UNIT: REGISTRY-PANEL
// FILE: registry-panel.tsx
// STYLE: international.module.css
// PARENT: PAGE-SEO-INTERNATIONAL
// USES: ADMIN-SESSION, LIB-ADMIN-API, LIB-FORMAT, RESOURCE, _I18N-KIT
// API: /admin/i18n/overview, PATCH /admin/i18n/locales
// MAP: docs/PROJECT_MAP.md

/**
 * سجل المواقع المحلية — لوح فوق GET /admin/i18n/overview حرفيًا.
 *
 * الكتابة الوحيدة: PATCH /admin/i18n/locales/{code} بالتفعيل والترتيب فقط.
 * `is_routable` و`is_default` حقيقتا خادم تُعرضان ولا تُعدَّلان — دمج قرار
 * التفعيل التحريري مع واقع التوجيه التقني يُعلن للعالم صفحاتِ 404.
 */

import { useState } from 'react';
import { useSession } from '../../../../components/admin-session';
import { useResource } from '../../../../components/resource';
import { api, firstError } from '../../../../lib/admin-api';
import { count } from '../../../../lib/format';
import { AbsentNote, GatedView, StudioHeader, type I18nOverview, type I18nOverviewPayload, type LocaleRow } from './_i18n-kit';
import styles from './international.module.css';

export function RegistryPanel() {
  const { state, reload } = useResource<I18nOverviewPayload>('/admin/i18n/overview');
  const { can } = useSession();

  return (
    <div className={styles.wrap}>
      <StudioHeader />
      <GatedView
        state={state}
        reload={reload}
        permission="analytics.read"
        reads="سجلّ اللغات وأعدادها يُقرأ من ‎GET /admin/i18n/overview‎ وحدها (routes/api-admin.php:226-227)"
      >
        {(payload) => (
          <Registry overview={payload.data} canManage={can('appearance.manage')} reload={reload} />
        )}
      </GatedView>
    </div>
  );
}

function Registry({
  overview,
  canManage,
  reload,
}: {
  overview: I18nOverview;
  canManage: boolean;
  reload: () => void;
}) {
  const [pendingCode, setPendingCode] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);
  const rows = overview.locales;
  const defaultLocale = rows.find((row) => row.isDefault) ?? null;
  const busy = pendingCode !== null;

  async function toggle(row: LocaleRow) {
    setPendingCode(row.code);
    setError(null);
    const result = await api.patch(`/admin/i18n/locales/${row.code}`, { isActive: !row.isActive });
    setPendingCode(null);
    if (!result.ok) {
      setError(firstError(result));
      return;
    }
    reload();
  }

  /**
   * تبادل صفّين متجاورين: موضعان صريحان بفهرس القائمة (نداءان متتاليان).
   * فشل الثاني بعد نجاح الأول لا يُخفى — تُعرض رسالة الخادم ويُعاد التحميل
   * فيعرض السجل ترتيبه الحقيقي كما استقرّ.
   */
  async function move(index: number, delta: -1 | 1) {
    const moved = rows[index];
    const neighbor = rows[index + delta];
    if (!moved || !neighbor) return;
    setPendingCode(moved.code);
    setError(null);
    const first = await api.patch(`/admin/i18n/locales/${moved.code}`, { position: index + delta });
    if (!first.ok) {
      setPendingCode(null);
      setError(firstError(first));
      return;
    }
    const second = await api.patch(`/admin/i18n/locales/${neighbor.code}`, { position: index });
    setPendingCode(null);
    if (!second.ok) setError(firstError(second));
    reload();
  }

  return (
    <section className={styles.card} aria-label="سجل المواقع المحلية">
      <h2 className={styles.cardTitle}>سجل المواقع المحلية</h2>

      <div className={styles.tallies} role="list" aria-label="عدّادات السجل">
        <Tally value={count(rows.length)} label="لغة مسجَّلة" />
        <Tally value={count(rows.filter((row) => row.isActive).length)} label="نشطة" />
        <Tally value={count(rows.filter((row) => row.isRoutable).length)} label="تُخدم مسارًا" />
        <Tally
          value={defaultLocale ? defaultLocale.code : '—'}
          label="الافتراضية"
          ltrValue={defaultLocale !== null}
        />
        <Tally value={count(overview.publishedProducts)} label="منتج منشور" />
      </div>

      {!canManage ? (
        <p className={styles.metaLine} role="note">
          السجل للقراءة فقط — التفعيل والترتيب يحتاجان صلاحية إدارة المظهر{' '}
          <bdi dir="ltr">appearance.manage</bdi>.
        </p>
      ) : null}

      {error ? (
        <div className="state-error" role="alert">
          <p>{error}</p>
        </div>
      ) : null}

      {rows.length === 0 ? (
        <AbsentNote title="لا لغات مسجَّلة">
          جدول <bdi dir="ltr">locales</bdi> فارغ — لا صفوف تُعرض ولا صفوف تُخترع.
        </AbsentNote>
      ) : (
        <ul className={styles.rows}>
          {rows.map((row, index) => {
            const defaultLocked = row.isDefault && row.isActive;
            const toggleDisabled = busy || !canManage || defaultLocked;

            return (
              <li
                key={row.code}
                className={row.isActive ? `${styles.row} ${styles.rowActive}` : styles.row}
              >
                <code className={styles.code}>
                  <bdi dir="ltr">{row.code}</bdi>
                </code>
                <span className={styles.scope}>
                  {row.nameAr} · hreflang <bdi dir="ltr">{row.hreflang}</bdi> · الاتجاه{' '}
                  <bdi dir="ltr">{row.direction}</bdi> ·{' '}
                  {row.urlPrefix ? (
                    <>
                      بادئة المسار <bdi dir="ltr">/{row.urlPrefix}</bdi>
                    </>
                  ) : (
                    'بلا بادئة — الجذر'
                  )}
                  {row.translations ? (
                    <>
                      {' '}
                      · ترجمات: {count(row.translations.published)} منشورة و
                      {count(row.translations.draft)} مسوّدة
                    </>
                  ) : (
                    <> · الافتراضية — نصّها أعمدة المنتج نفسها</>
                  )}{' '}
                  · يجتاز بوّابتها الآن {count(row.alternateUrls.expected)} من{' '}
                  {count(row.alternateUrls.potentialMax)} منتجًا منشورًا
                </span>

                {row.isDefault ? (
                  <span className={`${styles.pill} ${styles.pillInfo}`}>الافتراضية · x-default</span>
                ) : null}
                <span
                  className={
                    row.isActive ? `${styles.pill} ${styles.pillOn}` : `${styles.pill} ${styles.pillGate}`
                  }
                >
                  {row.isActive ? 'نشط' : 'معطل بالبوابة'}
                </span>
                <span
                  className={
                    row.isRoutable ? `${styles.pill} ${styles.pillOn}` : `${styles.pill} ${styles.pillOff}`
                  }
                  title="حقيقة خادم — للقراءة فقط"
                >
                  {row.isRoutable ? 'تُخدم مسارًا' : 'لا يُخدم مسارها'}
                </span>

                <span className={styles.rowActions}>
                  <button
                    type="button"
                    role="switch"
                    aria-checked={row.isActive}
                    aria-label={`تفعيل ${row.nameAr}`}
                    className={styles.switch}
                    disabled={toggleDisabled}
                    onClick={() => void toggle(row)}
                  >
                    <span className={styles.track} aria-hidden="true">
                      <span className={styles.knob} />
                    </span>
                    <span className={styles.switchLabel}>
                      {pendingCode === row.code ? 'جارٍ…' : row.isActive ? 'تعطيل' : 'تفعيل'}
                    </span>
                  </button>
                  <button
                    type="button"
                    className={styles.moveBtn}
                    disabled={busy || !canManage || index === 0}
                    onClick={() => void move(index, -1)}
                    aria-label={`تقديم ${row.nameAr} في الترتيب`}
                  >
                    <span aria-hidden="true">↑</span>
                  </button>
                  <button
                    type="button"
                    className={styles.moveBtn}
                    disabled={busy || !canManage || index === rows.length - 1}
                    onClick={() => void move(index, 1)}
                    aria-label={`تأخير ${row.nameAr} في الترتيب`}
                  >
                    <span aria-hidden="true">↓</span>
                  </button>
                </span>

                {defaultLocked ? (
                  <span className={styles.reason}>
                    اللغة الافتراضية لا تُعطَّل — هي لغة المتجر الأصلية.
                  </span>
                ) : null}
              </li>
            );
          })}
        </ul>
      )}

      <div className={styles.gateNote}>
        <b>قاعدة التفعيل:</b> لا يُفعَّل موقع محلي جديد قبل: محتوى بشري حقيقي + طلب مُتحقق + قدرة
        تشغيلية (شحن/دفع) — لا صفحات إقليمية لأجل hreflang فقط.
      </div>
      <div className={styles.gateNote}>
        <b>قابلية التوجيه حقيقة خادم:</b> التفعيل هنا قرار تحريري ولا يجعل الواجهة تخدم مسار اللغة —
        دمج الحقيقتين يُعلن للعالم صفحاتٍ غير موجودة، لذا <bdi dir="ltr">is_routable</bdi> للقراءة
        فقط ويرفض الخادم تعديله (422).
      </div>
    </section>
  );
}

function Tally({ value, label, ltrValue = false }: { value: string; label: string; ltrValue?: boolean }) {
  return (
    <span className={styles.tally} role="listitem">
      <b className={styles.tallyValue}>{ltrValue ? <bdi dir="ltr">{value}</bdi> : value}</b>
      <span className={styles.tallyLabel}>{label}</span>
    </span>
  );
}
