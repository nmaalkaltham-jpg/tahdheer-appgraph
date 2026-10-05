'use client';
/**
 * ظهوراتُ نتائج البحث — ما رآه الزائر فعلًا، لا ما أعاده الخادم.
 *
 * يراقب بطاقات النتائج (`[data-search-product]` داخل الحاوية) ويعدّ البطاقة
 * ظاهرةً حين يبلغ نصفُها مجالَ الرؤية. يُرسل دفعةً بعد هدوء قصير، وما بقي حين
 * تُخفى الصفحة (تنقّل، إغلاق، تبديل تبويب). والمنتج مرّةً لكلّ بحث
 * (`ImpressionBuffer`)، والخادم يحرس التكرار أيضًا.
 */
import { useEffect, useRef, type ReactNode } from 'react';
import { ImpressionBuffer } from '../../lib/impression-buffer';
import { sendSearchImpressions } from '../../lib/search-api';

const IDLE_MS = 800;

export function SearchImpressions({ queryId, token, children }: { queryId: number; token: string; children: ReactNode }) {
  const root = useRef<HTMLDivElement>(null);

  useEffect(() => {
    const el = root.current;
    if (!el || typeof IntersectionObserver === 'undefined') return;

    const buffer = new ImpressionBuffer();
    let timer: ReturnType<typeof setTimeout> | null = null;
    const flush = () => {
      if (timer) clearTimeout(timer);
      timer = null;
      while (buffer.size > 0) sendSearchImpressions(queryId, token, buffer.drain());
    };

    const observer = new IntersectionObserver(
      (entries) => {
        for (const entry of entries) {
          if (!entry.isIntersecting) continue;
          const id = Number((entry.target as HTMLElement).dataset.searchProduct);
          if (buffer.add(id)) observer.unobserve(entry.target);
        }
        if (buffer.size > 0) {
          if (timer) clearTimeout(timer);
          timer = setTimeout(flush, IDLE_MS);
        }
      },
      { threshold: 0.5 },
    );
    el.querySelectorAll<HTMLElement>('[data-search-product]').forEach((card) => observer.observe(card));

    const onHide = () => {
      if (document.visibilityState === 'hidden') flush();
    };
    document.addEventListener('visibilitychange', onHide);
    window.addEventListener('pagehide', flush);

    return () => {
      observer.disconnect();
      document.removeEventListener('visibilitychange', onHide);
      window.removeEventListener('pagehide', flush);
      flush();
    };
  }, [queryId, token]);

  return <div ref={root}>{children}</div>;
}
