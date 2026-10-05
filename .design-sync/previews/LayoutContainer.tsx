/**
 * LayoutContainer — الحاوية الواحدة خلف المحرّكات الأربعة.
 *
 * قانون الحزمة معروضًا لا مشروحًا: المحرّك (section · flex · stack · grid)
 * يصل خاصّيةً، والإعدادات جزئية تُدمج فوق CONTAINER_DEFAULTS. الأبناء هنا
 * صناديق محتوى بسيطة بألوان الرموز — الحاوية هي المعروض، لا محتواها.
 *
 * تجاوزات tab/mob (responsive) لا تُعرض في خلية: لقطة المعاينة سطح مكتب
 * واحد، وخليّة تدّعي إظهار تجاوز جوّال لا تثبته.
 */

import { LayoutContainer } from '@tahdheer/ui';
import type { ReactNode } from 'react';

/** صندوق محتوى للعرض — من رموز النظام، لا من مفردات خارجية. */
function Box({ children, tone = 'soft' }: { children: ReactNode; tone?: 'soft' | 'green' | 'gold' }) {
  const bg =
    tone === 'green' ? 'var(--bg-green-light)' : tone === 'gold' ? 'var(--tint-gold-16, #f7edd8)' : 'var(--bg-soft)';
  return (
    <div
      style={{
        background: bg,
        border: '1px solid var(--border)',
        borderRadius: 'var(--radius-md)',
        padding: 'var(--space-4)',
        fontSize: 13.5,
        fontWeight: 700,
        color: 'var(--text-secondary)',
        textAlign: 'center',
      }}
    >
      {children}
    </div>
  );
}

/** section — عرض content وخلفية soft وحشوة: حاوية صفحةٍ نموذجية. */
export function Section() {
  return (
    <div dir="rtl">
    <LayoutContainer engine="section" settings={{ width: 'content', pad: 24, bg: 'soft', radius: 'card' }}>
      <Box>محتوى القسم — يتوسّط بعرض «content» فوق خلفية soft</Box>
    </LayoutContainer>
    </div>
  );
}

/** flex — صفّ يوزّع بين الطرفين ويحاذي المنتصف: شريط أدوات نموذجي. */
export function FlexRow() {
  return (
    <div dir="rtl">
    <LayoutContainer
      engine="flex"
      settings={{ direction: 'row', justify: 'between', align: 'center', gap: 12, pad: 16, bg: 'surface', border: 'hairline', radius: 'md' }}
    >
      <Box tone="green">البداية</Box>
      <Box>الوسط</Box>
      <Box tone="gold">النهاية</Box>
    </LayoutContainer>
    </div>
  );
}

/** stack — عمود بفراغ منتظم: تكديس البطاقات الافتراضي. */
export function Stack() {
  return (
    <div dir="rtl">
    <LayoutContainer engine="stack" settings={{ gap: 10, pad: 16, bg: 'mist', radius: 'card' }}>
      <Box>العنصر الأوّل</Box>
      <Box>العنصر الثاني</Box>
      <Box>العنصر الثالث</Box>
    </LayoutContainer>
    </div>
  );
}

/** grid — ثلاثة أعمدة ثابتة بفراغين مختلفين للصفوف والأعمدة. */
export function Grid() {
  return (
    <div dir="rtl">
    <LayoutContainer
      engine="grid"
      settings={{ cols: 3, colMode: 'fixed', gap: 12, rowGap: 18, pad: 16, bg: 'page', radius: 'card', border: 'thin' }}
    >
      <Box tone="green">١</Box>
      <Box>٢</Box>
      <Box tone="gold">٣</Box>
      <Box>٤</Box>
      <Box tone="green">٥</Box>
      <Box>٦</Box>
    </LayoutContainer>
    </div>
  );
}

/** deep — الخلفية الداكنة الوحيدة في التعداد، بحدّ أدنى للارتفاع. */
export function DeepBand() {
  return (
    <div dir="rtl">
    <LayoutContainer engine="section" settings={{ width: 'wide', pad: 28, bg: 'deep', radius: 'lg', minH: 120 }}>
      <div style={{ color: 'var(--chrome-text, rgba(255,255,255,.82))', fontWeight: 800, textAlign: 'center' }}>
        شريط داكن — bg=&quot;deep&quot; من التعداد نفسه
      </div>
    </LayoutContainer>
    </div>
  );
}
