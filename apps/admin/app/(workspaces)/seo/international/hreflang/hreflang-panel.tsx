'use client';
// UNIT: HREFLANG-PANEL
// FILE: hreflang-panel.tsx
// STYLE: international.module.css
// PARENT: PAGE-SEO-INTERNATIONAL
// USES: LIB-FORMAT, RESOURCE, _I18N-KIT
// API: /admin/i18n/hreflang-audit
// MAP: docs/PROJECT_MAP.md

/**
 * مدقّق hreflang — فوق GET /admin/i18n/hreflang-audit حرفيًا.
 *
 * التدقيق يُحسب لحظة الطلب: «آخر تشغيل» هو طابع الاستجابة نفسها، وزرّ
 * التشغيل يعيد النداء. لا مهمة ليلية في النظام — «آخر تشغيل ليلي» في
 * اللوح عيّنة، وسجل المهام الخلفية يُعلن غيابه بالاسم لا بنتائج مُختلقة.
 *
 * تسميات الفحوص الخمسة تأتي من الخادم وتُعرض حرفيًا؛ وفحص عضوية Sitemap
 * للبدائل لا نقطة نهاية له (استُبعد بناؤها عمدًا) فيُعرض غائبًا لا ناجحًا.
 */

import Link from 'next/link';
import { useResource } from '../../../../../components/resource';
import { count, dateTime } from '../../../../../lib/format';
import {
  AbsentNote,
  GatedView,
  OFFENDER_CAP,
  StudioHeader,
  type HreflangAudit,
  type HreflangAuditPayload,
} from '../_i18n-kit';
import styles from '../international.module.css';

export function HreflangPanel() {
  const { state, reload } = useResource<HreflangAuditPayload>('/admin/i18n/hreflang-audit');

  return (
    <div className={styles.wrap}>
      {/*
        * زرّ التدقيق داخل اللوح لا في رأس الصفحة: الرأس صار للغلاف وهو
        * يحمل أربعة ألواح، وزرٌّ يخصّ واحدًا يبدو فعلًا على الأربعة.
        */}
      <p className="list-toolbar">
        <button
          type="button"
          className="btn btn-primary"
          onClick={reload}
          disabled={state.status === 'loading'}
        >
          {state.status === 'loading' ? 'جارٍ التدقيق…' : 'تشغيل التدقيق الآن'}
        </button>
      </p>
      <StudioHeader />
      <GatedView
        state={state}
        reload={reload}
        permission="analytics.read"
        reads="التدقيق كلّه يُحسب في ‎GET /admin/i18n/hreflang-audit‎ لحظة الطلب (routes/api-admin.php:228-229)"
      >
        {(payload) => <Audit audit={payload.data} />}
      </GatedView>
    </div>
  );
}

function Audit({ audit }: { audit: HreflangAudit }) {
  return (
    <div className={styles.grid}>
      <section className={styles.card} aria-label="نتائج تدقيق hreflang">
        <h2 className={styles.cardSub}>مدقّق hreflang — تشغيل عند الطلب</h2>
        <p className={styles.metaLine}>
          آخر تشغيل: {dateTime(audit.checkedAt)} · فُحص {count(audit.scannedProducts)} منتجًا منشورًا
          — النتيجة تُحسب لحظة الطلب، لا مهمة ليلية.
        </p>

        {audit.scannedProducts === 0 ? (
          <AbsentNote title="لا منتجات منشورة">
            التدقيق جرى على صفر صفحات — نجاح الفحوص أدناه فراغ لا إثبات.
          </AbsentNote>
        ) : null}

        <ul className={styles.checks}>
          {audit.checks.map((check) => (
            <li key={check.key} className={styles.checkRow}>
              <span className={check.pass ? styles.mark : styles.markFail} aria-hidden="true">
                {check.pass ? '✓' : '✕'}
              </span>
              <div className={styles.checkBody}>
                <span className={styles.checkLabel}>{check.label}</span>
                {check.pass ? (
                  <span className={styles.checkOk}>سليم — لا مخالفين</span>
                ) : (
                  <details className={styles.offenders}>
                    <summary>{count(check.offenders.length)} مخالفة — اعرض المخالفين</summary>
                    <ul>
                      {check.offenders.map((offender) => (
                        <li key={`${check.key}-${offender.productId}`}>
                          <Link href={`/studio/catalog/${offender.productId}`}>{offender.title}</Link>
                          {offender.slug ? (
                            <>
                              {' '}
                              <bdi dir="ltr">/{offender.slug}</bdi>
                            </>
                          ) : null}{' '}
                          — {offender.detail}
                        </li>
                      ))}
                    </ul>
                    {check.offenders.length >= OFFENDER_CAP ? (
                      <p className={styles.capNote}>
                        تُعرض أول {count(OFFENDER_CAP)} مخالفة فقط (سقف الخادم) — أصلِح ثم أعد
                        التشغيل.
                      </p>
                    ) : null}
                  </details>
                )}
              </div>
            </li>
          ))}
        </ul>
      </section>

      <div>
        <section className={styles.card} aria-label="فحوص غير متاحة">
          <h2 className={styles.cardSub}>فحوص غير متاحة</h2>
          <AbsentNote title="عضوية Sitemap للبدائل">
            غير مدقَّقة — لا نقطة نهاية تقرأ عضوية البدائل في Sitemap، واستُبعد بناؤها عمدًا؛ فلا
            حكم يُعرض بدل الحكم الغائب.
          </AbsentNote>
        </section>

        <section className={styles.card} aria-label="سجل المهام الخلفية">
          <h2 className={styles.cardSub}>سجل المهام الخلفية</h2>
          <AbsentNote title="لا مهام مجدولة">
            لا شيء مجدول في الخادم (<bdi dir="ltr">routes/console.php</bdi> بلا مهام i18n) —
            التدقيق يعمل عند الطلب فقط، ولا نتائج ليلية تُختلق.
          </AbsentNote>
        </section>
      </div>
    </div>
  );
}
