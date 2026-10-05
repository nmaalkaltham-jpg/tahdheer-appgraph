// UNIT: PAGE-HOME-CONTENT
// FILE: page.tsx
// ROUTE: /home-content
// STYLE: none
// PARENT: none
// USES: HOME-CONTENT-PANEL
// API: none
// MAP: docs/PROJECT_MAP.md

import { HomeContentPanel } from './home-content-panel';

export const metadata = { title: 'محتوى الرئيسية' };

export default function HomeContentPage() {
  return <HomeContentPanel />;
}
