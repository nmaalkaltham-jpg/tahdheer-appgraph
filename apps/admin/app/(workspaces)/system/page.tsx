'use client';
// UNIT: PAGE-SYSTEM
// FILE: page.tsx
// ROUTE: /system
// STYLE: none
// PARENT: none
// USES: ADMIN-SESSION, AUDIT-PANEL, DIAGNOSTICS-PANEL, PAGE-TABS, QUEUES-PANEL, RESOURCE
// API: none
// API-VIA: ADMIN-SESSION, AUDIT-PANEL, QUEUES-PANEL
// MAP: docs/PROJECT_MAP.md

/**
 * النظام — سطحٌ واحد بثلاثة تبويبات.
 *
 * كانت «التشغيل والطوابير» و«صحة الخدمات» و«سجلّ التدقيق» ثلاث وجهات، وهي
 * تُفتح في اللحظة نفسها وللسؤال نفسه: «ما الذي انكسر، ومن غيّر ماذا؟».
 * ومن يحقّق في حادثةٍ يحتاج الثلاثة معًا لا واحدًا منها.
 *
 * والصلاحيات كما كانت — تُطبَّق على التبويب: `audit.read` أضيق من البقيّة،
 * فمن لا يملكها لا يرى تبويب السجلّ أصلًا ولا يُفتح له ثمّ يُردّ ٤٠٣.
 */

import { Suspense } from 'react';
import { useSession } from '../../../components/admin-session';
import { PageHeader } from '../../../components/resource';
import { PageTabs, type PageTab } from '../../../components/ui/page-tabs';
import { QueuesPanel } from './queues-panel';
import { DiagnosticsPanel } from '../diagnostics/diagnostics-panel';
import { AuditPanel } from '../audit/audit-panel';

const TABS: readonly PageTab[] = [
  { id: 'queues', label: 'التشغيل والطوابير', permission: 'audit.read', render: () => <QueuesPanel /> },
  { id: 'health', label: 'صحّة الخدمات', render: () => <DiagnosticsPanel /> },
  { id: 'audit', label: 'سجلّ التدقيق', permission: 'audit.read', render: () => <AuditPanel /> },
];

export default function SystemWorkspacePage() {
  return (
    <Suspense fallback={null}>
      <SystemWorkspace />
    </Suspense>
  );
}

function SystemWorkspace() {
  const { can } = useSession();

  return (
    <section>
      <PageHeader trail="النظام" title="النظام" />
      <PageTabs tabs={TABS} can={can} label="أقسام النظام" scope="tabs:system" />
    </section>
  );
}
