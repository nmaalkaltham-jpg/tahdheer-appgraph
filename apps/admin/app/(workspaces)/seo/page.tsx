'use client';
// UNIT: PAGE-SEO
// FILE: page.tsx
// ROUTE: /seo
// STYLE: none
// PARENT: none
// USES: ADMIN-SESSION, BROKEN-LINKS-PANEL, CRAWL-PANEL, PAGE-TABS, REDIRECTS-PANEL, RESOURCE +3
// API: none
// API-VIA: ADMIN-SESSION, BROKEN-LINKS-PANEL, CRAWL-PANEL, REDIRECTS-PANEL, URL-PLATFORM-PANEL
// MAP: docs/PROJECT_MAP.md

/**
 * الظهور والبحث — سطحٌ واحد بخمسة تبويبات.
 *
 * ─── العيب الذي يزيله الدمج ───
 *
 * كانت خمس وجهاتٍ في الشريط تجيب سؤالًا واحدًا: «هل يجدنا الناس، وهل يصلون؟».
 * وأسوأ من العدد أنّ بينها **ازدواجًا حقيقيًّا**: «منصّة SEO» تُعلن في نفسها
 * قسمَي «الفهرسة والزحف» و«المحتوى والروابط» — وهما بالضبط ما تفعله شاشتا
 * «الزحف» و«الروابط المكسورة». فيقرأ المشغّل الموضوع نفسه في سطحين يُحدَّثان
 * في لحظتين مختلفتين، ويصدّق أحدثهما ظهورًا لا أصدقهما.
 *
 * ─── وما لم يتغيّر ───
 *
 * كلّ لوحٍ هو ملفّه القديم حرفيًّا: المنطق والنداءات والصلاحيات كما كانت.
 * ما تغيّر أنّ التصدير صار مُسمًّى وأنّ رأس الصفحة انتقل إلى هنا — رأسان
 * يعلنان عنوانين يجعلان العنوان يقفز عند كلّ تبديل.
 *
 * والمسارات القديمة تبقى تعمل: كلٌّ منها يحوّل إلى تبويبه، فلا رابطٌ محفوظ
 * ينكسر ولا مفضّلة.
 */

import Link from 'next/link';
import { Suspense } from 'react';
import { useSession } from '../../../components/admin-session';
import { PageHeader } from '../../../components/resource';
import { PageTabs, type PageTab } from '../../../components/ui/page-tabs';
import { UrlPlatformPanel } from './url-platform-panel';
import { SeoOverviewPanel } from './overview-panel';
import { CrawlPanel } from '../crawl/crawl-panel';
import { BrokenLinksPanel } from '../seo-health/broken-links-panel';
import { RedirectsPanel } from '../redirects/redirects-panel';
import { SearchIntelPanel } from '../search/search-panel';

/*
 * `render` دالّة لا عقدة: اللوح غير النشط لا يُبنى أصلًا، فلا تنطلق
 * نداءاته. خمسة ألواحٍ تُركَّب معًا تعني خمسة طلباتٍ في كلّ فتحة، أربعةٌ
 * منها لا يراها أحد — وهو ما كان سيجعل السطح المدموج أبطأ ممّا حلّ محلّه.
 */
const TABS: readonly PageTab[] = [
  { id: 'overview', label: 'نظرة عامّة', permission: 'analytics.read', render: () => <SeoOverviewPanel /> },
  { id: 'crawl', label: 'الزحف والروابط', permission: 'analytics.read', render: () => <CrawlPanel /> },
  { id: 'broken', label: 'روابط مكسورة (٤٠٤)', permission: 'analytics.read', render: () => <BrokenLinksPanel /> },
  { id: 'redirects', label: 'قواعد التحويل', permission: 'analytics.read', render: () => <RedirectsPanel /> },
  { id: 'search', label: 'البحث الداخلي', permission: 'analytics.read', render: () => <SearchIntelPanel /> },
  { id: 'urls', label: 'منصّة العناوين', permission: 'url.read', render: () => <UrlPlatformPanel /> },
];

export default function VisibilityWorkspacePage() {
  return (
    /* `useSearchParams` في مكوّن عميل يوجب `Suspense` وقت البناء. */
    <Suspense fallback={null}>
      <VisibilityWorkspace />
    </Suspense>
  );
}

function VisibilityWorkspace() {
  const { can } = useSession();

  return (
    <section>
      <PageHeader
        trail="الظهور والبحث"
        title="الظهور والبحث"
        actions={
          <Link className="btn btn-ghost" href="/seo/international">
            SEO الدولي ›
          </Link>
        }
      />

      <PageTabs tabs={TABS} can={can} label="أقسام الظهور والبحث" scope="tabs:visibility" />
    </section>
  );
}
