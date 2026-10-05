'use client';
// UNIT: _I18N-KIT
// FILE: _i18n-kit.tsx
// STYLE: international.module.css
// PARENT: HREFLANG-PANEL, OPPORTUNITIES-PANEL, PREVIEW-PANEL, REGISTRY-PANEL
// USES: RESOURCE
// API: none
// MAP: docs/PROJECT_MAP.md

/**
 * عُدّة استوديو SEO الدولي — الأنواع الحرفية لنقاط النهاية القائمة فعلًا،
 * ورأس الاستوديو المشترك، وصندوق الغياب الصادق.
 *
 * الموصول حقًّا (routes/api-admin.php):
 *   · GET   /admin/i18n/overview        — اللغات والأعداد المحسوبة من الجداول.
 *   · GET   /admin/i18n/hreflang-audit  — إثبات الثوابت منتجًا منتجًا عند الطلب.
 *   · PATCH /admin/i18n/locales/{code}  — isActive والترتيب فقط؛
 *     isRoutable وisDefault حقيقتا خادم يرفضهما 422 ولا تُعدَّلان من هنا.
 *
 * عقيدة الصدق (MIP §1.3): الغائب يُسمّى غائبًا بسببه — لا عيّنة تسدّ فراغًا.
 */

import type { ReactNode } from 'react';
import { ResourceView, type ResourceState } from '../../../../components/resource';
import styles from './international.module.css';

/* ───────────── أنواع GET /admin/i18n/overview ───────────── */

export interface LocaleRow {
  code: string;
  nameAr: string;
  nameEn: string;
  hreflang: string;
  direction: 'rtl' | 'ltr' | string;
  urlPrefix: string | null;
  isDefault: boolean;
  isActive: boolean;
  /** حقيقة خادم للقراءة فقط — الواجهة تخدم مسار اللغة أو لا تخدمه. */
  isRoutable: boolean;
  position: number;
  /** null للّغة الافتراضية: نصّها أعمدة المنتج نفسها، لا صفّ ترجمة. */
  translations: { draft: number; published: number } | null;
  /** expected: منتجات تجتاز بوّابة اللغة الآن · potentialMax: المنشور كله. */
  alternateUrls: { expected: number; potentialMax: number };
}

export interface I18nOverview {
  publishedProducts: number;
  locales: LocaleRow[];
}

export interface I18nOverviewPayload {
  data: I18nOverview;
}

/* ───────────── أنواع GET /admin/i18n/hreflang-audit ───────────── */

export interface AuditOffender {
  productId: number;
  slug: string | null;
  title: string;
  detail: string;
}

export interface AuditCheck {
  key: string;
  /** تسمية عربية من الخادم — تُعرض حرفيًا ولا تُعاد صياغتها هنا. */
  label: string;
  pass: boolean;
  offenders: AuditOffender[];
  checkedAt: string;
}

export interface HreflangAudit {
  scannedProducts: number;
  /** طابع الجولة نفسها — «آخر تشغيل» الحقيقي؛ لا مهمة ليلية في النظام. */
  checkedAt: string;
  checks: AuditCheck[];
}

export interface HreflangAuditPayload {
  data: HreflangAudit;
}

/** سقف الخادم للمخالفين المُعادين لكل فحص (HreflangAuditor::OFFENDER_CAP). */
export const OFFENDER_CAP = 50;

/* ───────────── رأس الاستوديو المشترك ───────────── */

export function StudioHeader() {
  return (
    <nav className={styles.band} aria-label="شاشات استوديو SEO الدولي">
      <b className={styles.bandTitle}>استوديو SEO الدولي</b>
      <span className={styles.gatePill}>لا hreflang لعنوان غير موجود — بوابة أهلية صارمة</span>
      {/*
        * لا تبويباتٍ هنا: شريط تبويبات الصفحة فوق هذا الشريط يملك الشاشات
        * الأربع نفسها، وصفّان من التبويبات لوجهةٍ واحدة يوهمان بمستويين.
        */}
    </nav>
  );
}

/* ───────────── صندوق الغياب الصادق ───────────── */

/**
 * الغائب يُعلن غيابه بالاسم والسبب — لا صفر يُقرأ نتيجةً ولا عيّنة تُقرأ قياسًا.
 */
export function AbsentNote({ title, children }: { title: string; children: ReactNode }) {
  return (
    <div className={styles.absentNote} role="note">
      <b className={styles.absentTitle}>{title}</b>
      <p className={styles.absentBody}>{children}</p>
    </div>
  );
}

/**
 * لوحٌ محروسٌ بصلاحية — والغياب هنا من نوعٍ ثالث لا يشبه النوعين الآخرين.
 *
 * «غير مُهيأ» تكاملٌ ناقص عن النظام كلّه، و«لا بيانات» سجلٌّ لم يمتلئ بعد،
 * أمّا ٤٠٣ فمصدرٌ قائم يقرأه زميلك ولا تقرأه أنت. خلطُه ببطاقة خطأ عامّة
 * يُوهم بعطلٍ عابر، وزرّ «إعادة المحاولة» فيها يَعِد بما لا يفي: الردّ
 * الثاني هو الأوّل. فتُسمّى الصلاحية، ويُسحب الزرّ.
 */
export function GatedView<T>({
  state,
  reload,
  permission,
  reads,
  children,
}: {
  state: ResourceState<T>;
  reload: () => void;
  permission: string;
  reads: string;
  children: (data: T) => ReactNode;
}) {
  if (state.status === 'error' && state.code === 403) {
    return (
      <AbsentNote title="هذا اللوح مقفل على دورك">
        دورك لا يملك <bdi dir="ltr">{permission}</bdi>، فردّ الخادم ٤٠٣. {reads} — وما لا يُقرأ لا يُعرض
        صفرًا ولا جدولًا فارغًا. اطلب الصلاحية من مدير المتجر.
      </AbsentNote>
    );
  }

  return (
    <ResourceView state={state} reload={reload}>
      {children}
    </ResourceView>
  );
}
