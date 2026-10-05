/**
 * بلاطة الرسم الموحّدة للمراحل والموادّ — نسبةٌ واحدة (مربّع) ومقاسٌ من المستهلك
 * (`--tile`)، فلا تختلف البلاطات بين الرئيسة والمحاور.
 *
 * - المرحلة: رسمها من `STAGE_ART` على لهجتها الناعمة `--stage-*-soft`.
 * - المادّة: رسمها من `subjectArt()`؛ و`null` (لا رسم صادق — التربية الإسلاميّة مثلًا)
 *   ⇒ حرفٌ طباعيّ على سطحٍ هادئ، لا أيقونةٌ مستعارة.
 * الرسم زخرفيّ دائمًا هنا (`alt=""`): الاسم مكتوبٌ بجانبه نصًّا.
 */
import type { Art, StageKey } from '../../lib/art';
import { monogram } from '../../lib/homepage';
import { ThemedArt } from './ThemedArt';
import styles from './ArtTile.module.css';

const TONE: Record<StageKey, string | undefined> = {
  kindergarten: styles.kg,
  primary: styles.primary,
  middle: styles.middle,
  secondary: styles.secondary,
};

/** صنف لهجة المرحلة (`--sa`, `--sa-soft`, `--sa-ink`) — يُوضع على البطاقة كلّها. */
export function stageTone(key: StageKey | null): string {
  return key ? (TONE[key] ?? '') : '';
}

export function ArtTile({
  art,
  name,
  sizes,
  className,
  glyph,
}: {
  art: Art | null;
  /** اسم المفردة — مصدر الحرف البديل حين لا رسم. */
  name: string;
  /** بديلٌ طباعيّ صريح بدل الحرف الأوّل (رقم الصفّ مثلًا). */
  glyph?: string;
  sizes: string;
  className?: string;
}) {
  return (
    <span className={`${styles.tile} ${art ? '' : styles.mono} ${className ?? ''}`} aria-hidden="true">
      {art ? <ThemedArt art={art} alt="" sizes={sizes} className={styles.img} /> : (glyph ?? monogram(name))}
    </span>
  );
}
