'use client';
/**
 * FocusScroll — يُدخل المُركَّز عليه في مسطحه.
 *
 * ─── العطل المقيس ───
 *
 * داخل مسطح تمريرٍ أفقيّ لا يُدخل كروم العنصرَ المُركَّز عليه في الشاشة إذا
 * كان **مرئيًّا جزئيًّا** أصلًا. فينتقل التركيز بـTab إلى رابطٍ عرضُه
 * ‎248px‎ ولا يظهر منه إلّا ‎37px‎، ولا يقع تمرير. وهو ‎WCAG 2.2 AA §2.4.7‎:
 * التركيز مرئيّ بالاسم، ومقطوعٌ بالفعل.
 *
 * وقيس على شريطَي الرئيسة عند ‎390px‎: أربعُ محطّاتٍ من تسعٍ مقصوصة.
 * وجُرِّبت أربعةُ علاجاتٍ في CSS وحدها فلم تُصلحها:
 *
 *  · `scroll-padding` و`scroll-margin` — لا أثر، لأنّ التمرير لا يقع أصلًا.
 *  · `scroll-snap-type: proximity` بدل `mandatory` — لا أثر.
 *  · `scroll-snap-type: none` — أصلح اثنتين من أربع، وكسر الالتقاط المقصود.
 *
 * و`scrollIntoView` صريحًا عند التركيز أصلح الأربع: ‎0/9‎.
 *
 * ثمّ ظهر لاحقًا أنّ `inline: 'nearest'` وحدها لا تكفي في كلّ مسطح —
 * التفصيل والقياس عند الاستدعاء أدناه.
 *
 * ─── ولماذا مستمعٌ واحد على المستند ───
 *
 * الشرط ليس خاصّيّة شريطٍ بعينه، بل ثابتٌ للواجهة كلّها: ما يُركَّز عليه
 * يُرى كاملًا. فمستمعٌ واحد يخدم كلّ مسطحٍ قائم وكلّ مسطحٍ يُضاف — بدل
 * قاعدةٍ في كلّ ملفّ تُنسى عند السادس، وهو الدرس نفسه الذي أخرجه حجزُ
 * الحافّة السفلى.
 *
 * ولا يعمل إلّا مع `:focus-visible`: نقرةُ فأرةٍ على بطاقةٍ نصفِ ظاهرة لا
 * يجوز أن تقفز بالصفحة تحت يد صاحبها.
 */
import { useEffect } from 'react';

/** أقرب سلفٍ يمرّر فعلًا — لا المستند. */
function scrollportOf(el: Element): Element | null {
  for (let e = el.parentElement; e !== null && e !== document.body; e = e.parentElement) {
    const cs = getComputedStyle(e);
    const scrolls = (axis: string) => axis === 'auto' || axis === 'scroll';
    if (
      (scrolls(cs.overflowX) && e.scrollWidth > e.clientWidth + 1) ||
      (scrolls(cs.overflowY) && e.scrollHeight > e.clientHeight + 1)
    ) {
      return e;
    }
  }
  return null;
}

export function FocusScroll() {
  useEffect(() => {
    const onFocusIn = (event: FocusEvent) => {
      const el = event.target;
      if (!(el instanceof Element)) return;
      /* لوحةُ المفاتيح وحدها. و`:focus-visible` قد ترميه بعضُ المتصفّحات
         القديمة، فيُلتقط الرمي ويُمضى بلا تدخّل. */
      try {
        if (!el.matches(':focus-visible')) return;
      } catch {
        return;
      }
      const port = scrollportOf(el);
      if (port === null) return;

      el.scrollIntoView({ inline: 'nearest', block: 'nearest' });

      /*
       * ─── و`nearest` وحدها لا تكفي في مسطحٍ عربيّ ───
       *
       * قيس على `/products/a` عند ‎390px‎: زرّ «أضف للسلة» في رفّ المنتجات
       * المقترحة يُركَّز عليه بـ`Tab` فيقع على ‎left: -22.5px‎ — طرفُه وحلقتُه
       * خارج صندوق الرفّ (‎33..357‎). واستدعاءُ
       * `scrollIntoView({inline:'nearest'})` عليه **لا يحرّكه بتاتًا**:
       * القيمة بعده ‎-22.5‎ نفسها، و`scrollLeft` يبقى صفرًا.
       *
       * والسبب أنّ مدى التمرير في حاويةٍ من اليمين إلى اليسار سالبٌ في كروم
       * (‎scrollLeft‎ من ‎-273‎ إلى ‎0‎)، و`nearest` تحسب أنّها «الأقرب» أصلًا
       * فلا تتحرّك. و`center` تتحرّك: القياس نفسه يعطي ‎left: 177.5‎
       * و`scrollLeft: -200` — داخل الصندوق كاملًا.
       *
       * فالعلاج محاولتان لا واحدة: `nearest` أوّلًا لأنّها الأهدأ (لا تُقفز
       * ما هو ظاهرٌ أصلًا)، وإن بقي العنصر خارج مسطحه بعدها فـ`center`.
       * والتحقّق بالقياس لا بالنيّة: يُقارَن الصندوقان بعد المحاولة الأولى.
       */
      const box = el.getBoundingClientRect();
      const portBox = port.getBoundingClientRect();
      const outside = box.left < portBox.left - 0.5 || box.right > portBox.right + 0.5;
      if (outside) el.scrollIntoView({ inline: 'center', block: 'nearest' });
    };

    document.addEventListener('focusin', onFocusIn);
    return () => document.removeEventListener('focusin', onFocusIn);
  }, []);

  return null;
}
