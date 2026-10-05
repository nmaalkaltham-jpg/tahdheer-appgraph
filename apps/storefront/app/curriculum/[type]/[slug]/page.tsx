/**
 * /curriculum/[type]/[slug] — الصفحة المحورية للمنهج.
 *
 * «المعلّم لا يبحث عن تحاضير، بل عن تحضير رياضيات سادس»: هذا التقاطع هو صياغة
 * الطلب نفسها، وهو موجود في بيانات المنتجات أصلًا. الصفحة تُولَّد منها بلا
 * كتابة سطر محتوى واحد — وهي أعلى ما يرشّحه التصميم عائدًا بحثيًّا.
 *
 * الفارق عن أرشيف بُعد واحد أن **بُعدَين مثبَّتان** بالمسار، فالرفّ يحمل
 * الثلاثة الباقية (المرحلة · الفصل · النوع).
 *
 * عنقود دون ثلاثة منتجات ليس صفحة رقيقة بل **غير موجود**: 404 حقيقية لا
 * صفحة بـnoindex. الخادم هو من يقرّر ذلك، والصفحة تنقل قراره.
 */
import type { Metadata } from 'next';
import { Suspense } from 'react';
import { CatalogSkeleton } from '../../../(catalog)/_components/CatalogSkeleton';
import { notFound } from 'next/navigation';
import { formatMoney, qty } from '@tahdheer/design-system/format';
import { apiGet } from '../../../../lib/api';
import { fetchHubs, siblingHubs } from '../../../../lib/curriculum-api';
import { routes } from '../../../../lib/routes';
import { fetchSearch, type SearchResponse } from '../../../../lib/search-api';
import { Archive, type ArchiveFacetGroup } from '../../../(catalog)/_components/Archive';
import { CatalogFailure } from '../../../(catalog)/_components/CatalogFailure';
import { fetchTerms, toFacetOptions } from '../../../(catalog)/_components/catalog-api';
import { termPath } from '../../../(catalog)/_components/dimensions';
import {
  catalogHref,
  collectionRobots,
  FILE_FORMS,
  parsePage,
  parseSort,
  type FacetState,
} from '../../../(catalog)/_components/query';

interface HubDto {
  type: string;
  slug: string;
  label: string;
  path: string;
  subject: string;
  grade: string;
  subjectSlug: string;
  gradeSlug: string;
  count: number;
}

type Params = Promise<{ type: string; slug: string }>;
type Search = Promise<{
  stage?: string;
  semester?: string;
  kind?: string;
  sort?: string;
  page?: string;
}>;

/** الخادم وحده يقرّر الأهلية — والصفحة لا تعيد حساب العتبة. */
function fetchHub(type: string, slug: string) {
  return apiGet<{ data: HubDto }>(`/api/v1/curriculum/${type}/${slug}`);
}

function isFiltered(sp: Awaited<Search>): boolean {
  return Boolean(sp.stage || sp.semester || sp.kind || sp.sort || sp.page);
}

/**
 * فقرة الإجابة — مكتفية بذاتها بقصد.
 *
 * محرّكات الإجابة تقتطع أوّل إجابة كاملة تجدها وتترك الغامضة. تُبنى من أرقام
 * حقيقية وحدها: عدد الملفات، ونطاق السعر المحسوب من المعروض، والصيغ الموجودة
 * فعلًا في العنقود — فلا تُذكر صيغة لا يحملها ملف منه.
 */
function answer(hub: HubDto, data: SearchResponse | null): string {
  const items = data?.results.items ?? [];
  const prices = items
    .map((item) => item.priceMinor)
    .filter((value): value is number => typeof value === 'number' && value > 0);

  const formats = [...new Set(items.map((item) => item.format).filter(Boolean))];

  const sentence = [
    `تضمّ هذه الصفحة ${qty(hub.count, FILE_FORMS)} لـ${hub.label} من التحاضير الحديثة، جاهزة للتنزيل الفوري`,
  ];

  if (formats.length > 0) sentence.push(`بصيغة ${formats.join(' و')}`);

  if (prices.length > 0) {
    const min = Math.min(...prices);
    const max = Math.max(...prices);
    const money = (minor: number) => `${formatMoney(minor).amount} ${formatMoney(minor).currency}`;
    sentence.push(min === max ? `بسعر ${money(min)}` : `بأسعار من ${money(min)} إلى ${money(max)}`);
  }

  return `${sentence.join(' ')}.`;
}

export async function generateMetadata({
  params,
  searchParams,
}: {
  params: Params;
  searchParams: Search;
}): Promise<Metadata> {
  const [{ type, slug }, sp] = await Promise.all([params, searchParams]);
  const { data, status } = await fetchHub(type, slug);
  const hub = data?.data;

  // 404 من الخادم يُقرَّر هنا قبل أوّل بايت (مع `loading.tsx` تُبثّ الصفحة بحالة 200 وإلّا).
  if (status === 404) notFound();
  if (!hub) return { title: 'صفحة غير موجودة', robots: { index: false, follow: true } };

  return {
    title: `تحاضير ${hub.label}`,
    description: `ملفات ${hub.subject} لـ${hub.grade} — تحاضير وعروض وأوراق عمل واختبارات جاهزة للتنزيل الفوري.`,
    alternates: { canonical: hub.path },
    robots: collectionRobots(hub.count, isFiltered(sp)),
  };
}

export default async function CurriculumHubPage({
  params,
  searchParams,
}: {
  params: Params;
  searchParams: Search;
}) {
  const [{ type, slug }, sp] = await Promise.all([params, searchParams]);
  const hubRes = await fetchHub(type, slug);

  // 404 من الخادم = العنقود غير مؤهَّل. أمّا انقطاع الاتصال (status 0) فليس
  // «غير موجود»، ويُعرَض بطاقةَ إعادة محاولة لا صفحةَ خطأ.
  // القرار هنا قبل أوّل بايت؛ وحدُّ الانتظار `Suspense` بعده لا `loading.tsx` قبله.
  if (hubRes.status === 404) notFound();

  return (
    <Suspense fallback={<CatalogSkeleton />}>
      <HubBody type={type} slug={slug} sp={sp} hub={hubRes.data?.data} />
    </Suspense>
  );
}

async function HubBody({
  type,
  slug,
  sp,
  hub,
}: {
  type: string;
  slug: string;
  sp: Awaited<Search>;
  hub: HubDto | undefined;
}) {
  const page = parsePage(sp.page);
  const sort = parseSort(sp.sort);
  const facets: FacetState = { stage: sp.stage, semester: sp.semester, kind: sp.kind };
  const basePath = hub?.path ?? `/curriculum/${type}/${slug}`;
  const crumbs = [
    { label: 'الرئيسية', href: routes.home },
    { label: 'المواد الدراسية', href: routes.subjects },
    { label: hub?.label ?? 'صفحة المنهج' },
  ];

  if (!hub) {
    return (
      <CatalogFailure crumbs={crumbs} title="صفحة المنهج" retryHref={basePath} />
    );
  }

  const [terms, hubsRes, pagesRes] = await Promise.all([
    Promise.all([fetchTerms('stages'), fetchTerms('semesters'), fetchTerms('kinds')]),
    fetchHubs(),
    Promise.all(
      Array.from({ length: page }, (_, index) =>
        fetchSearch({
          ...facets,
          // البُعدان المثبَّتان يُمرَّران بمفردات الفهرس لا بجزأي المسار.
          subject: hub.subjectSlug,
          grade: hub.gradeSlug,
          sort,
          page: index + 1,
        }),
      ),
    ),
  ]);

  const [stagesRes, semestersRes, kindsRes] = terms;
  /* السجلّ غير متاح ⇒ لا قسم أشقّاء. غيابُه لا يُسقط الصفحة ولا يُخترع رابط. */
  const siblings = siblingHubs(hub, hubsRes.data?.data ?? []);
  const pages = pagesRes.map((response) => response.data);
  const last = pages.at(-1);

  if (pages.some((data) => data === null) || !last) {
    return (
      <CatalogFailure
        crumbs={crumbs}
        title={`تحاضير ${hub.label}`}
        retryHref={catalogHref(basePath, { facets, sort, page })}
      />
    );
  }

  const loaded = pages.filter((data): data is SearchResponse => data !== null);
  const groups: ArchiveFacetGroup[] = [
    { key: 'stage', title: 'المرحلة', options: toFacetOptions(stagesRes.data, 'stage', last) },
    {
      key: 'semester',
      title: 'الفصل الدراسي',
      options: last.facets.semesters ?? toFacetOptions(semestersRes.data, 'semester', last),
    },
    { key: 'kind', title: 'نوع الملف', options: last.facets.kinds ?? toFacetOptions(kindsRes.data, 'kind', last) },
  ];

  return (
    <Archive
      basePath={basePath}
      crumbs={crumbs}
      title={`تحاضير ${hub.label}`}
      intro={answer(hub, last)}
      scopeLabel={`لـ${hub.label}`}
      facets={facets}
      sort={sort}
      page={page}
      groups={groups}
      items={loaded.flatMap((response) => response.results.items)}
      total={last.results.total}
      activeSort={last.sort}
      related={{ title: 'محاور قريبة', links: siblings }}
      emptyTitle={`لا توجد ملفات منشورة لـ${hub.label} بعد`}
      emptyText="نضيف الملفات أولًا بأول — تصفّح المادة أو الصف كاملًا في الأثناء."
      emptyActions={[
        // سلاسل المواد تحمل مسافات — المسار يُبنى من نفس المصدر لا بالوصل.
        {
          label: `كل ملفات ${hub.subject}`,
          href: termPath('subjects', hub.subjectSlug),
          primary: true,
        },
        { label: `كل ملفات ${hub.grade}`, href: termPath('grades', hub.gradeSlug) },
      ]}
    />
  );
}
