/**
 * بديلُ غلاف البطاقة حين لا صورة للمنتج: رسمُ مادّته من سجلّ `lib/art.ts`
 * (`subjectArt`) على لوحٍ مصبوغ — لا صورةٌ مدّعاة، بل أيقونة المادّة التي
 * يقولها مسار المنتج نفسه. لا مطابقة صادقة (مادّةٌ شرعيّة، أو مسارٌ بلا مادّة)
 * ⇒ `undefined`، فترسم البطاقة ورقتها الزخرفيّة كما كانت.
 *
 * زخرفةٌ خارج شجرة الوصول: العنوان يجاور البطاقة نصًّا، فالرسم بلا اسمٍ بديل.
 */
import type { ReactNode } from 'react';
import { subjectArt } from '../../lib/art';
import { ThemedArt } from '../home/ThemedArt';
import styles from './CardArt.module.css';

export function cardFallbackArt(categoryPath: readonly string[] | undefined, hasImage: boolean): ReactNode | undefined {
  if (hasImage || !categoryPath) return undefined;
  // أضيقُ المسار أوّلًا (المادّة بعد المرحلة والصفّ).
  for (let i = categoryPath.length - 1; i >= 0; i -= 1) {
    const art = subjectArt(categoryPath[i] ?? '');
    if (art) {
      return (
        <span className={styles.tile} aria-hidden="true">
          <ThemedArt art={art} alt="" sizes="96px" className={styles.art} />
        </span>
      );
    }
  }
  return undefined;
}
