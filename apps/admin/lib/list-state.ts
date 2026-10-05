'use client';
// UNIT: LIB-LIST-STATE
// FILE: list-state.ts
// STYLE: none
// PARENT: LIST-PANEL, PAGE-CUSTOMERS, PAGE-OPERATIONS-INVENTORY, PAGE-ORDERS, SHIPMENTS-PANEL
// USES: none
// API: none
// MAP: docs/PROJECT_MAP.md

/**
 * حالة القوائم في الرابط — بحثٌ وفرزٌ ومرشّحاتٌ وصفحة.
 *
 * ─── لماذا في الرابط ───
 *
 * المشغّل يرشّح إلى «الطلبات الفاشلة بعد أوّل مارس»، يجد عطلًا، وينسخ
 * الرابط إلى زميله. وحالةٌ محلّيّة تجعل الزميل يفتح قائمةً فارغة من
 * المرشّحات فلا يرى ما رآه الأوّل — ثمّ يتناقشان في شاشتين مختلفتين وهما
 * يظنّانهما واحدة. والرجوع بالمتصفّح يعيد المرشّح كما كان، وهو ما يتوقّعه
 * كلّ مستعمل.
 *
 * ─── ولماذا `replace` لا `push` ───
 *
 * كتابةُ حرفٍ في البحث ليست صفحةً جديدة. و`push` كان يجعل زرّ الرجوع يمرّ
 * على كلّ حرفٍ كُتب.
 */

import { useCallback, useMemo, useState } from 'react';
import { usePathname, useRouter, useSearchParams } from 'next/navigation';

interface ListState {
  /** نصّ البحث كما هو في الرابط — يتأخّر عن الكتابة بمقدار `DEBOUNCE_MS`. */
  q: string;
  page: number;
  perPage: number;
  sort: string | null;
  direction: 'asc' | 'desc';
  /** مرشّحاتٌ حرّة بمفاتيحها — كلٌّ منها معلَنٌ في الشاشة التي تستعملها. */
  filters: Readonly<Record<string, string>>;
}

/**
 * ٣٥٠ms: أقصر منها يُرسل طلبًا لكلّ حرف، وأطول يجعل الحقل يبدو معلّقًا.
 * وكلّ طلبٍ يُلغى عند الحرف التالي — راجع `useListQuery`.
 */
export const DEBOUNCE_MS = 350;

export function useListState(filterKeys: readonly string[] = []): {
  state: ListState;
  /** ما يُكتب في الحقل الآن — قبل أن يستقرّ في الرابط. */
  draftQuery: string;
  setQuery: (value: string) => void;
  setPage: (page: number) => void;
  setSort: (column: string) => void;
  setFilter: (key: string, value: string | null) => void;
  clearFilters: () => void;
  /** الرابط الحاليّ كسلسلة استعلام — يُمرَّر إلى الخادم كما هو. */
  queryString: string;
} {
  const router = useRouter();
  const pathname = usePathname();
  const params = useSearchParams();

  const state = useMemo<ListState>(() => {
    const filters: Record<string, string> = {};
    for (const key of filterKeys) {
      const value = params.get(key);
      if (value !== null && value !== '') filters[key] = value;
    }

    const rawPage = Number(params.get('page') ?? '1');
    const rawPerPage = Number(params.get('perPage') ?? '25');

    return {
      q: params.get('q') ?? '',
      // رقمٌ تالف في الرابط لا يُسقط الصفحة: يعود إلى الافتراض.
      page: Number.isFinite(rawPage) && rawPage >= 1 ? Math.floor(rawPage) : 1,
      perPage: Number.isFinite(rawPerPage) && rawPerPage >= 1 ? Math.min(100, Math.floor(rawPerPage)) : 25,
      sort: params.get('sort'),
      direction: params.get('dir') === 'asc' ? 'asc' : 'desc',
      filters,
    };
  }, [params, filterKeys]);

  const [draftQuery, setDraftQuery] = useState(state.q);
  const [syncedQuery, setSyncedQuery] = useState(state.q);

  /*
   * الرابط هو المصدر: رجوعُ المتصفّح يجب أن يُعيد الحقل إلى ما كان.
   *
   * والتعديل يقع **أثناء التصيير** لا في أثر. `useEffect` هنا كان يعني
   * تصييرًا أوّلَ بقيمةٍ بائتة ثمّ تصييرًا ثانيًا يصحّحها — أي وميضًا في
   * الحقل عند كلّ رجوع. وقاعدة المستودع تمنعه لهذا السبب:
   * الأثر لمزامنة نظامٍ خارجيّ، لا لاشتقاق حالةٍ من حالة.
   */
  if (state.q !== syncedQuery) {
    setSyncedQuery(state.q);
    setDraftQuery(state.q);
  }

  const write = useCallback(
    (mutate: (search: URLSearchParams) => void) => {
      const search = new URLSearchParams(params.toString());
      mutate(search);
      router.replace(search.size === 0 ? pathname : `${pathname}?${search.toString()}`, {
        scroll: false,
      });
    },
    [params, pathname, router],
  );

  const setQuery = useCallback(
    (value: string) => {
      setDraftQuery(value);
      window.clearTimeout(timers.get('q'));
      timers.set(
        'q',
        window.setTimeout(() => {
          write((search) => {
            if (value === '') search.delete('q');
            else search.set('q', value);
            // البحث الجديد يبدأ من الصفحة الأولى — وإلّا ظهرت «لا نتائج» كذبًا.
            search.delete('page');
          });
        }, DEBOUNCE_MS),
      );
    },
    [write],
  );

  const setPage = useCallback(
    (page: number) => {
      write((search) => {
        if (page <= 1) search.delete('page');
        else search.set('page', String(page));
      });
    },
    [write],
  );

  /**
   * نقرةٌ على العمود: تنازليّ ← تصاعديّ ← بلا فرز.
   *
   * الحالة الثالثة ليست زينة: بلا فرزٍ يعود الترتيب إلى ما يعرّفه الخادم
   * (الأحدث غالبًا)، ولا سبيل إلى العودة إليه لولاها إلّا بتحرير الرابط.
   */
  const setSort = useCallback(
    (column: string) => {
      write((search) => {
        const current = search.get('sort');
        const dir = search.get('dir');

        if (current !== column) {
          search.set('sort', column);
          search.set('dir', 'desc');
        } else if (dir !== 'asc') {
          search.set('dir', 'asc');
        } else {
          search.delete('sort');
          search.delete('dir');
        }

        search.delete('page');
      });
    },
    [write],
  );

  const setFilter = useCallback(
    (key: string, value: string | null) => {
      write((search) => {
        if (value === null || value === '') search.delete(key);
        else search.set(key, value);
        search.delete('page');
      });
    },
    [write],
  );

  const clearFilters = useCallback(() => {
    write((search) => {
      for (const key of filterKeys) search.delete(key);
      search.delete('q');
      search.delete('page');
    });
  }, [write, filterKeys]);

  const queryString = useMemo(() => {
    const search = new URLSearchParams();
    if (state.q !== '') search.set('q', state.q);
    if (state.page > 1) search.set('page', String(state.page));
    search.set('perPage', String(state.perPage));
    if (state.sort !== null) {
      search.set('sort', state.sort);
      search.set('dir', state.direction);
    }
    for (const [key, value] of Object.entries(state.filters)) search.set(key, value);
    return search.toString();
  }, [state]);

  return { state, draftQuery, setQuery, setPage, setSort, setFilter, clearFilters, queryString };
}

/*
 * مؤقّتات التأخير خارج المكوّن: `useRef` كان يُعيد ضبطها مع كلّ تركيبٍ
 * جديد للشجرة (تبديل تبويب مثلًا) فيمرّ طلبٌ لم يكن يجب أن يمرّ.
 */
const timers = new Map<string, number>();
