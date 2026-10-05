'use client';
/**
 * BottomChrome — مالكُ الحافّة السفلى الواحد.
 *
 * ─── العطل الذي وُلد هذا من أجله ───
 *
 * في المتجر أربع طبقاتٍ تسكن الحافّة السفلى نفسها: شريط الموافقة (ثابت)،
 * وشريط مجموع السلّة في الجوّال (ثابت)، وشريط الصفحة الرئيسة (ملتصق)،
 * وشريط الشراء في صفحة المنتج (ملتصق). ولم يكن بينها عقد.
 *
 * وحجزُ المساحة كان مكتوبًا في ملفّ الموافقة وحده، معلّقًا على صنفٍ موضعيّ
 * (`:root:has(.bar)`). فكان الأثر المقيس على البناء الإنتاجيّ:
 *
 *  · عند ‎390px‎ وزائرٍ لم يقرّر: شريط الموافقة (‎232px‎، z=99) يغطّي شريط
 *    الشراء (‎88px‎، z=30) تغطيةً تامّة — «أضف إلى السلّة» غير مرئيّ ولا
 *    منقور لكلّ زائرٍ أوّل على الجوّال. ومثله شريط الرئيسة (‎69px‎).
 *  · وحين يقرّر الزائر يختفي شريط الموافقة فيسقط الحجز كلّه، فيحجب الشريط
 *    الملتصق ما يُركَّز عليه — ‎WCAG 2.2 AA §2.4.11‎.
 *  · والقيم ثوابتُ مكتوبة بنقطة انكسارٍ واحدة (‎720px‎)، وارتفاع الشريط
 *    الحقيقيّ يتدرّج ‎256→86px‎ على سبع درجات. فكان الحجز ناقصًا ‎27px‎ بين
 *    ‎728‎ و‎839‎، وزائدًا ‎83px‎ عند ‎608–720‎، و‎44px‎ فوق ‎2192‎.
 *
 * ─── ولماذا قياسٌ لا ثوابت ───
 *
 * ارتفاع شريط الموافقة دالّةُ التفافِ نصٍّ يأتي من الخادم. لا ثابت يلاحقه،
 * ولا نقطة انكسارٍ تصفه: القياس وحده يصدق عند كلّ عرض وكلّ صياغة.
 *
 * والشريط لا يُصيَّر في الخادم أصلًا (انظر `consent.tsx`)، فلا ومضة: هذا
 * المراقب ينشر قيمته في اللحظة نفسها التي يظهر فيها.
 *
 * ─── العقد ───
 *
 * كلّ طبقةٍ سفليّة تعلن نفسها بسمة `data-bottom-chrome`:
 *
 *  · `base`   — الشريط العامّ على مستوى التطبيق. في القاع دائمًا.
 *  · `fixed`  — شريطٌ ثابت للصفحة. يرتفع فوق `base`.
 *  · `sticky` — شريطٌ ملتصق في التدفّق. يرتفع فوق الثابتَين.
 *
 * وينشر هذا المكوّن ثلاثة رموز على `:root`، و`globals.css` وحدَه يشتقّ
 * منها الحجز. ولا يكتب هذا المكوّن نمطًا على أيّ عنصر: العناصر تقرأ الرمز،
 * فلا تتسابق قاعدتان على التخصيص نفسه — وهو ما أفسد المحاولة السابقة.
 */
import { useEffect } from 'react';

const ATTR = 'data-bottom-chrome';

/** يكتب على الجذر فقط حين تتغيّر القيمة — وإلّا دار المراقب على نفسه. */
function setVar(root: HTMLElement, name: string, px: number): boolean {
  const next = `${Math.round(px)}px`;
  if (root.style.getPropertyValue(name) === next) return false;
  root.style.setProperty(name, next);
  return true;
}

export function BottomChrome() {
  useEffect(() => {
    const root = document.documentElement;
    let frame = 0;

    const publish = () => {
      frame = 0;

      const layers = Array.from(document.querySelectorAll<HTMLElement>(`[${ATTR}]`)).filter((el) =>
        el.checkVisibility(),
      );

      const sum = (role: string) =>
        layers
          .filter((el) => el.getAttribute(ATTR) === role)
          .reduce((total, el) => total + el.getBoundingClientRect().height, 0);

      const tallest = (role: string) =>
        layers
          .filter((el) => el.getAttribute(ATTR) === role)
          .reduce((max, el) => Math.max(max, el.getBoundingClientRect().height), 0);

      /* الثابتة تتراكم لأنّها تقتطع من الشاشة معًا؛ والملتصقة لا تتراكم:
         صفحةٌ واحدة لا تحمل إلّا شريطًا ملتصقًا واحدًا، وأطولُه يكفي. */
      setVar(root, '--chrome-bottom-base', sum('base'));
      setVar(root, '--chrome-bottom-fixed', sum('fixed'));
      setVar(root, '--chrome-bottom-sticky', tallest('sticky'));
    };

    const schedule = () => {
      if (frame === 0) frame = requestAnimationFrame(publish);
    };

    /* حجمُ الشريط يتغيّر بالعرض وبطول النصّ؛ ووجودُه يتغيّر بقرار الزائر
       وبالانتقال بين المسارات. فمراقبان: واحدٌ للحجم وواحدٌ للوجود. */
    const sizes = new ResizeObserver(schedule);
    const attach = () => {
      sizes.disconnect();
      for (const el of document.querySelectorAll(`[${ATTR}]`)) sizes.observe(el);
      schedule();
    };

    const tree = new MutationObserver(attach);
    tree.observe(document.body, { childList: true, subtree: true, attributeFilter: [ATTR, 'class'] });

    attach();
    window.addEventListener('resize', schedule);

    return () => {
      if (frame !== 0) cancelAnimationFrame(frame);
      window.removeEventListener('resize', schedule);
      sizes.disconnect();
      tree.disconnect();
      root.style.removeProperty('--chrome-bottom-base');
      root.style.removeProperty('--chrome-bottom-fixed');
      root.style.removeProperty('--chrome-bottom-sticky');
    };
  }, []);

  return null;
}
