'use client';
// UNIT: PAGE-REVENUE
// FILE: page-revenue.tsx
// STYLE: none
// PARENT: PAGE-ANALYTICS
// USES: INSIGHT-PANEL, RANGE
// API: none
// API-VIA: INSIGHT-PANEL
// MAP: docs/PROJECT_MAP.md

/**
 * إيراد الصفحات — سؤالٌ كان بلا جواب ممكن حتّى اليوم.
 *
 * جدول `orders` لا يحمل عمود إسنادٍ واحدًا: لا مُحيل ولا حملة ولا سلّة ولا
 * زائر. فـ«أيّ صفحةٍ أنتجت هذا الإيراد» لم يكن نقصًا في لوحة بل استحالةً
 * بنيويّة. وطبقة الأحداث ثمّ `order_attributions` جعلتاه مصدرًا حقيقيًّا،
 * و`PagesInsight` يجيبه عبر `GET /admin/insights/pages`.
 *
 * والتغطية أوّل ما يُعرض عمدًا: الإسناد يحتاج موافقة الزائر على القياس،
 * فمقارنة صفحتين بلا معرفة أنّ العيّنة تغطّي ثلث الطلبات مقارنةٌ بين مجهولين.
 */

import { InsightPanel } from '../../dashboard/insight-panel';
import { lastDays } from '../_shared/range';

export default function PageRevenueTab() {
  const { from, to } = lastDays(30);

  return (
    <InsightPanel
      domain="pages"
      title="إيراد الصفحات"
      description="أيّ صفحةٍ جلبت المشتري — بنموذج اللمسة الأولى، وبتغطيةٍ معلَنة قبل الأرقام."
      from={from}
      to={to}
      defaultOpen
    />
  );
}
