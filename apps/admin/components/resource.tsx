'use client';
// UNIT: RESOURCE
// FILE: resource.tsx
// STYLE: none
// PARENT: AI-REQUEST-LOG, AI-USAGE-PANEL, ALERTS, ASSET-LIBRARY, AUDIT-PANEL, BROKEN-LINKS-PANEL +72
// USES: LIB-ADMIN-API, PAGE-HEAD
// API: none
// API-VIA: PAGE-HEAD
// MAP: docs/PROJECT_MAP.md

/**
 * قراءة مورد من الواجهة الخلفية بحالات صريحة: تحميل / خطأ / بيانات.
 * لا توجد حالة رابعة صامتة — الجدول الفارغ يعلن فراغه، والخطأ يعلن سببه
 * ويعرض إعادة المحاولة (MIP §1.3).
 */

import { PageHeadPublisher } from './page-head';
import { useCallback, type ReactNode } from 'react';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { api } from '../lib/admin-api';

export type ResourceState<T> =
  | { status: 'loading' }
  | { status: 'error'; message: string; code: number }
  | { status: 'ready'; data: T };

/**
 * خطأ يحمل رمز الحالة — ‎api.get‎ لا يرمي بل يُعيد نتيجةً، وReact Query تميّز
 * النجاح من الفشل بالرمي. فالجسر هنا: نتيجة فاشلة ← استثناء يحمل الرمز، ثمّ
 * يُفكّ إلى ‎ResourceState‎ نفسها التي يقرأها مئةُ موضعٍ ونيّف.
 */
class ResourceError extends Error {
  readonly code: number;

  constructor(message: string, code: number) {
    super(message);
    this.name = 'ResourceError';
    this.code = code;
  }
}

/**
 * قراءة مورد — الواجهة نفسها، والمحرّك تحتها صار ذاكرة الخادم.
 *
 * لم تتغيّر بصمة الدالة ولا شكل ‎ResourceState‎: مئةٌ وخمسة مواضع نداء تعمل
 * بلا تعديل حرف. ما تغيّر أنّ المسار الواحد صار مفتاحًا واحدًا في الذاكرة،
 * فسبعةُ ملفّات تقرأ ‎/admin/dashboard‎ صارت طلبًا واحدًا، وعشرة موارد متوازية
 * في شاشةٍ واحدة لم تعد تُعاد عند كلّ عودة إليها.
 *
 * ‎placeholderData‎ غائبة عمدًا: البيانات القديمة تحت مسارٍ جديد تعني رقمًا
 * يخصّ شاشةً أخرى معروضًا بثقة. الانتقال يُظهر «جارٍ التحميل» كما كان.
 */
export function useResource<T>(path: string): { state: ResourceState<T>; reload: () => void } {
  const client = useQueryClient();

  const query = useQuery<T, ResourceError>({
    queryKey: ['admin-resource', path],
    queryFn: async ({ signal }) => {
      const result = await api.get<T>(path, signal);
      if (!result.ok) throw new ResourceError(result.message, result.status);
      return result.data;
    },
  });

  /*
   * ‎invalidateQueries‎ لا ‎refetch‎: «أعد المحاولة» يجب أن يُبطل الذاكرة أيضًا،
   * وإلّا بقي كلّ مكوّن آخر يقرأ المسار نفسه على النسخة القديمة بعد نجاح
   * الإعادة — وهو بالضبط التشوّه الذي جاءت الذاكرة لتمنعه.
   */
  const reload = useCallback(() => {
    void client.invalidateQueries({ queryKey: ['admin-resource', path] });
  }, [client, path]);

  const state: ResourceState<T> = query.isPending
    ? { status: 'loading' }
    : query.isError
      ? { status: 'error', message: query.error.message, code: query.error.code }
      : { status: 'ready', data: query.data };

  return { state, reload };
}

/**
 * عرض المورد — والفرعان اللذان كانا يُكتبان بخطّ اليد في كلّ صفحة.
 *
 * ‎403‎ و‎404‎ ليسا «خطأً» كبقيّة الأخطاء: الأوّل يقول «هذا ليس لك» والثاني
 * «هذا لم يعد موجودًا»، وكلاهما **لا يُعاد المحاولة عليه** — زرّ «إعادة
 * المحاولة» على مورد محذوف يَعِد بما لن يقع. ولذلك كانت اثنتا عشرة صفحة تلفّ
 * ‎ResourceView‎ بغلافٍ محلّي يقرأ ‎state.code‎ ويرسم ‎div.state-error‎ بنفسه
 * (النموذج الأوّل: ‎app/(workspaces)/quotes/[id]/page.tsx‎) — منطقٌ مشترك في
 * اثنتَي عشرة نسخة، وكلّ نسخةٍ تُخطئ في تفصيلٍ مختلف (‎role="alert"‎ ناقص هنا،
 * زرّ إعادةٍ باقٍ هناك).
 *
 * الحقلان اختياريّان بحتًا: بصمة الاستدعاء القديمة تعمل بلا حرف — مئةٌ وخمسة
 * مواضع نداء لم تُلمس ولا واحد منها. ومن لا يمرّرهما يبقى على السلوك نفسه
 * تمامًا: ‎403‎ و‎404‎ يسقطان في فرع الخطأ العامّ كما كانا.
 *
 * ولا نصّ افتراضيّ يُخترع هنا: رسالة «لا طلب عرضٍ بهذا المعرّف» تعرف اسم
 * المورد ووجهة العودة، والمكوّن المشترك لا يعرفهما. المشترك هو **الغلاف
 * والإعلان** (`role="alert"`) وقرارُ حجب زرّ الإعادة — لا الكلمات.
 */
export function ResourceView<T>({
  state,
  reload,
  children,
  forbidden,
  notFound,
}: {
  state: ResourceState<T>;
  reload?: () => void;
  children: (data: T) => ReactNode;
  /** بديل ‎403‎ — يُلفّ في ‎.state-error‎ بـ‎role="alert"‎ بلا زرّ إعادة. */
  forbidden?: ReactNode;
  /** بديل ‎404‎ — كسابقه. */
  notFound?: ReactNode;
}) {
  if (state.status === 'loading') {
    return (
      <p className="state-line" role="status" aria-live="polite">
        جارٍ التحميل…
      </p>
    );
  }

  /*
   * قبل الفرع العامّ لا بعده، وبشرط `!== undefined` لا بالصدق: `null` قرارٌ
   * صريح من المستدعي («لا تعرض شيئًا لهذه الحالة») ويجب ألّا يسقط إلى الخطأ
   * العامّ الذي يعرض رسالة الخادم وزرّ إعادة.
   */
  if (state.status === 'error' && state.code === 403 && forbidden !== undefined) {
    return (
      <div className="state-error" role="alert">
        {forbidden}
      </div>
    );
  }

  if (state.status === 'error' && state.code === 404 && notFound !== undefined) {
    return (
      <div className="state-error" role="alert">
        {notFound}
      </div>
    );
  }

  if (state.status === 'error') {
    return (
      <div className="state-error" role="alert">
        <p>{state.message}</p>
        {reload ? (
          <button type="button" className="btn btn-ghost" onClick={reload}>
            إعادة المحاولة
          </button>
        ) : null}
      </div>
    );
  }

  return <>{children(state.data)}</>;
}

/** حالة فراغ صريحة لجدول: تقول ما الذي لا يوجد، ولا تعرض صفوفًا وهمية. */
export function EmptyRows({ colSpan, message }: { colSpan: number; message: string }) {
  return (
    <tr>
      <td className="empty-cell" colSpan={colSpan}>
        {message}
      </td>
    </tr>
  );
}

/**
 * رأس الصفحة — التوقيع نفسه، والتصيير انتقل إلى الرأس الموحّد.
 *
 * إحدى وأربعون صفحة تستدعيه ولم تُلمس واحدة: الناشر ينقل المسار والعنوان
 * إلى `AdminTopbar` والإجراءات ببوّابة إلى فتحتها فيه. وخارج القشرة يصيّر
 * رأسًا محلّيًّا كما كان — الصفحة تبقى مفهومة بلا قشرة.
 */
export function PageHeader({ trail, title, actions }: { trail: string; title: string; actions?: ReactNode }) {
  return <PageHeadPublisher trail={trail} title={title} actions={actions} />;
}
