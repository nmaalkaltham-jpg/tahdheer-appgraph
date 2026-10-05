/**
 * رسمٌ من سجلّ `lib/art.ts` بنسختيه — الفاتحة، والداكنة (`darkSrc`) إن وُجدت.
 *
 * التبديل بالـCSS وحده (`[data-theme='dark']` على `<html>`، أو `surface="dark"`
 * لسطحٍ داكنٍ دائمًا كشريحة الهيرو الكحليّة): لا حالةَ عميل ولا وميضَ ترطيب.
 * الصورة المخفيّة `display:none` مع `loading="lazy"` لا تُجلب، فلا تُدفع النسختان
 * معًا — إلّا صورة `priority` (الهيرو) فتُحمَّل نسختها الفاتحة مسبقًا وحدها.
 *
 * الأبعاد صريحة دائمًا (أو `fill` داخل صندوقٍ ثابت النسبة) ⇒ لا إزاحة تخطيط.
 */
import Image from 'next/image';
import type { Art } from '../../lib/art';
import styles from './ThemedArt.module.css';

export function ThemedArt({
  art,
  sizes,
  className,
  alt,
  priority = false,
  surface = 'page',
  fill = false,
}: {
  art: Art;
  /** مقاس العرض الفعليّ — `next/image` يختار الدقّة منه. */
  sizes: string;
  className?: string;
  /** يتجاوز `art.alt` — `""` حين يجاور الرسمَ اسمُه نصًّا. */
  alt?: string;
  priority?: boolean;
  /** `dark`: السطح داكنٌ في الوضعين ⇒ النسخة الداكنة دائمًا. */
  surface?: 'page' | 'dark';
  fill?: boolean;
}) {
  const text = alt ?? art.alt;
  const box = fill ? { fill: true as const } : { width: art.width, height: art.height };

  if (!art.darkSrc) {
    return <Image {...box} src={art.src} alt={text} sizes={sizes} priority={priority} className={className} />;
  }
  if (surface === 'dark') {
    return <Image {...box} src={art.darkSrc} alt={text} sizes={sizes} priority={priority} className={className} />;
  }
  return (
    <>
      <Image
        {...box}
        src={art.src}
        alt={text}
        sizes={sizes}
        priority={priority}
        className={`${styles.light} ${className ?? ''}`}
      />
      {/* التوأم الداكن خارج شجرة الوصول: الاسم البديل واحدٌ لا اثنان. */}
      <Image
        {...box}
        src={art.darkSrc}
        alt=""
        aria-hidden="true"
        sizes={sizes}
        loading="lazy"
        className={`${styles.dark} ${className ?? ''}`}
      />
    </>
  );
}
