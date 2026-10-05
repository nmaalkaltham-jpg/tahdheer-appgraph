'use client';
// UNIT: REORDERABLE
// FILE: reorderable.ts
// STYLE: none
// PARENT: PAGE-TABS, SIDEBAR
// USES: none
// API: none
// MAP: docs/PROJECT_MAP.md

/**
 * إعادة الترتيب — بالسحب وبلوحة المفاتيح معًا.
 *
 * ─── لماذا الاثنان ───
 *
 * السحب بالمؤشّر وحده يُقصي من لا يستعمل فأرة: لا بديل له إلّا تحرير
 * التخزين المحلّيّ يدويًّا. و«حرّيّة التحريك» التي طُلبت يجب أن تكون حرّيّةً
 * للجميع لا لأصحاب المؤشّر وحدهم. فالسحب للسرعة، و`Ctrl+سهم` للوصول —
 * وكلاهما يكتب في المخزن نفسه.
 *
 * ─── ولماذا `pointerdown` لا `dragstart` ───
 *
 * سحب HTML5 يحمل حمولةً نصّيّة ويُظهر صورة شبحٍ لا تُضبط، ويتصرّف تصرّفًا
 * مختلفًا داخل حاويةٍ تُمرَّر أفقيًّا — وشريط التبويبات كذلك. أحداث المؤشّر
 * موحّدة عبر الفأرة واللمس والقلم، و`setPointerCapture` يُبقي السحب متّصلًا
 * حتّى لو خرج الإصبع عن العنصر.
 */

import { useCallback, useRef, useState, type PointerEvent as ReactPointerEvent } from 'react';

interface Reorder {
  /** المفتاح المسحوب الآن — لتلوين العنصر ولإعلان الحالة. */
  dragging: string | null;
  /** المفتاح الذي سيقع عنده الإفلات. */
  over: string | null;
  handleProps: (key: string) => {
    onPointerDown: (event: ReactPointerEvent<HTMLElement>) => void;
    onPointerMove: (event: ReactPointerEvent<HTMLElement>) => void;
    onPointerUp: (event: ReactPointerEvent<HTMLElement>) => void;
    onPointerCancel: (event: ReactPointerEvent<HTMLElement>) => void;
  };
}

export function useReorder({
  keys,
  onReorder,
  /** سمةُ البيانات التي تحمل مفتاح كلّ عنصر في الـDOM. */
  attribute = 'data-reorder-key',
}: {
  keys: readonly string[];
  onReorder: (next: string[]) => void;
  attribute?: string;
}): Reorder {
  const [dragging, setDragging] = useState<string | null>(null);
  const [over, setOver] = useState<string | null>(null);
  const moved = useRef(false);

  const finish = useCallback(() => {
    if (dragging !== null && over !== null && dragging !== over) {
      const order = [...keys];
      const from = order.indexOf(dragging);
      const to = order.indexOf(over);

      if (from !== -1 && to !== -1) {
        const [item] = order.splice(from, 1);
        if (item !== undefined) {
          order.splice(to, 0, item);
          onReorder(order);
        }
      }
    }

    setDragging(null);
    setOver(null);
    moved.current = false;
  }, [dragging, over, keys, onReorder]);

  const handleProps = useCallback(
    (key: string) => ({
      onPointerDown: (event: ReactPointerEvent<HTMLElement>) => {
        /* الزرّ الأيسر وحده: الأيمن يفتح قائمة السياق ولا يسحب. */
        if (event.button !== 0) return;

        event.currentTarget.setPointerCapture(event.pointerId);
        setDragging(key);
        setOver(key);
        moved.current = false;
      },

      onPointerMove: (event: ReactPointerEvent<HTMLElement>) => {
        if (dragging === null) return;

        moved.current = true;

        /*
         * العنصر تحت الإصبع يُسأل عنه المستند لا يُحسب هندسيًّا.
         *
         * الحساب بالمستطيلات يفشل حين تكون الحاوية مُمرَّرة أو الاتّجاه RTL،
         * وكلاهما يقع هنا. و`elementFromPoint` يقرأ ما يراه المستعمل فعلًا.
         */
        const under = document.elementFromPoint(event.clientX, event.clientY);
        const host = under?.closest(`[${attribute}]`);
        const target = host?.getAttribute(attribute) ?? null;

        if (target !== null && target !== over) setOver(target);
      },

      onPointerUp: (event: ReactPointerEvent<HTMLElement>) => {
        if (event.currentTarget.hasPointerCapture(event.pointerId)) {
          event.currentTarget.releasePointerCapture(event.pointerId);
        }

        finish();
      },

      onPointerCancel: () => {
        /* إلغاءٌ من النظام (مكالمة، إيماءة): يُترك الترتيب كما كان. */
        setDragging(null);
        setOver(null);
        moved.current = false;
      },
    }),
    [dragging, over, finish, attribute],
  );

  return { dragging, over, handleProps };
}
