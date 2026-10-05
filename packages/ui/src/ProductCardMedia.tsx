'use client';
// UNIT: PRODUCTCARD-MEDIA
// FILE: ProductCardMedia.tsx
// STYLE: ProductCard.module.css
// PARENT: PRODUCTCARD
// USES: none
// API: none
// MAP: docs/PROJECT_MAP.md

/**
 * غلافُ بطاقة المنتج — الجزيرة الوحيدة في البطاقة التي تحتاج JavaScript.
 *
 * ثلاث حالاتٍ للصورة: `loading` (هيكلٌ خلفها)، و`loaded`، و`error` (تُزال
 * الصورة المكسورة ويحلّ البديل الذي يملكه المستدعي — رسمُ المادّة أو ورقة
 * القالب). الصورة تُرسم فوق الهيكل لا تُخفى خلفه: صورةٌ بشفافيّةٍ صفر ليست
 * مرشّحًا لأكبر عنصرٍ مرئيّ (LCP)، فكانت ستُؤخّر القياس بلا سبب.
 *
 * الصورة وأبعادها تصل من الخادم، فأوّل تصييرٍ في المتصفّح يطابق ترميز الخادم
 * (الحالة الأولى `loading` في الجهتين)؛ وبعد الترطيب يُقرأ `complete` لصورةٍ
 * سبقت التحميل، فلا يبقى الهيكل يومض خلف صورةٍ اكتملت قبل أن يصل الأثر.
 */
import { useEffect, useRef, useState, type ReactNode } from 'react';

export interface ProductCardMediaProps {
  src: string;
  alt: string;
  width: number;
  height: number;
  /** فوق الطيّة: تحميلٌ فوريّ وأولويّة جلبٍ عالية (بطاقات الصفّ الأوّل). */
  priority?: boolean;
  frameClass: string;
  imgClass: string;
  /** يحلّ محلّ الصورة حين تنكسر. */
  fallback: ReactNode;
}

type MediaState = 'loading' | 'loaded' | 'error';

export function ProductCardMedia({ src, alt, width, height, priority, frameClass, imgClass, fallback }: ProductCardMediaProps) {
  const [state, setState] = useState<MediaState>('loading');
  const ref = useRef<HTMLImageElement>(null);

  useEffect(() => {
    const img = ref.current;
    if (!img || !img.complete) return;
    setState(img.naturalWidth > 0 ? 'loaded' : 'error');
  }, []);

  return (
    <span className={frameClass} data-state={state}>
      {state === 'error' ? (
        fallback
      ) : (
        <img
          ref={ref}
          src={src}
          alt={alt}
          width={width}
          height={height}
          loading={priority ? 'eager' : 'lazy'}
          fetchPriority={priority ? 'high' : undefined}
          decoding="async"
          className={imgClass}
          onLoad={() => setState('loaded')}
          onError={() => setState('error')}
        />
      )}
    </span>
  );
}
