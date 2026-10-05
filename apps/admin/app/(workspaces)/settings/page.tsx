'use client';
// UNIT: PAGE-SETTINGS
// FILE: page.tsx
// ROUTE: /settings
// STYLE: none
// PARENT: none
// USES: ADMIN-SESSION, APPEARANCE-PANEL, INTEGRATIONS-PANEL, PAGE-TABS, RESOURCE, SETTINGS-OVERVIEW-PANEL, USERS-PANEL
// API: none
// API-VIA: ADMIN-SESSION, INTEGRATIONS-PANEL, USERS-PANEL
// MAP: docs/PROJECT_MAP.md

/**
 * الإعدادات — سطحٌ واحد بأربعة تبويبات.
 *
 * «الإعدادات» وحدها كانت مئةً وسبعين سطرًا لا تعرض إلّا هويّة المشغّل
 * وصلاحياته — لوحٌ لا مساحة عمل. وبجوارها وجهتان تخصّان الحساب نفسه.
 *
 * والصلاحيات تُطبَّق على التبويب: «المستخدمون» و«مفاتيح الربط» للمالك وحده،
 * فمن لا يملكهما لا يراهما.
 */

import { Suspense } from 'react';
import { useSession } from '../../../components/admin-session';
import { PageHeader } from '../../../components/resource';
import { PageTabs, type PageTab } from '../../../components/ui/page-tabs';
import { SettingsOverviewPanel } from './overview-panel';
import { AdminUsersPanel } from './users/users-panel';
import { IntegrationsPanel } from './integrations/integrations-panel';
import { AppearancePanel } from './appearance/appearance-panel';

const TABS: readonly PageTab[] = [
  { id: 'overview', label: 'نظرة عامّة', render: () => <SettingsOverviewPanel /> },
  /* بلا صلاحية: تفضيلٌ شخصيّ لكلّ مشرف، لا إعدادٌ يمسّ غيره. */
  { id: 'appearance', label: 'المظهر', render: () => <AppearancePanel /> },
  { id: 'users', label: 'المستخدمون والأدوار', permission: 'users.manage', render: () => <AdminUsersPanel /> },
  { id: 'keys', label: 'مفاتيح الربط', permission: 'integrations.manage', render: () => <IntegrationsPanel /> },
];

export default function SettingsWorkspacePage() {
  return (
    <Suspense fallback={null}>
      <SettingsWorkspace />
    </Suspense>
  );
}

function SettingsWorkspace() {
  const { can } = useSession();

  return (
    <section>
      <PageHeader trail="النظام › الإعدادات" title="الإعدادات" />
      <PageTabs tabs={TABS} can={can} label="أقسام الإعدادات" scope="tabs:settings" />
    </section>
  );
}
