'use client';

/**
 * مساعد المتجر — المستهلك الذي لم يكن لـ`POST /api/v1/assistant/ask`.
 *
 * ─── ما كان ───
 *
 * النقطة مبنيّة ومُختبَرة وفي الحزمة المولَّدة (`assistantAsk`)، ووثائق
 * الذكاء تعدّها مبنيّة ولا تذكرها في «ما ليس مبنيًّا بعد»، وتعليق وحدة
 * التحكّم يصف واجهةً تعرض جوابها. ولا سطر في المتجر ولا في اللوحة يناديها.
 * قدرةٌ تامّةٌ خلف بابٍ لا مقبض له.
 *
 * ─── ولماذا بطلبٍ لا تلقائيًّا ───
 *
 * كلّ نداءٍ ثانيتان من معالج، والنقطة تحت `throttle:expensive` (عشرون في
 * الدقيقة لكلّ الزوّار). ونداءٌ تلقائيّ مع كلّ بحثٍ يُنفِد الحصّة على من لم
 * يطلب المساعدة، ويؤخّر نتائج البحث نفسها. فالفعل صريح.
 *
 * ─── وما لا يُصيَّر أبدًا ───
 *
 * لا سعرٌ ولا عنوانٌ من نصّ النموذج: الحقول من `products[]` التي يبنيها
 * الخادم من الجداول، وهي منشورةٌ حصرًا. والسعر بالهللة و`null` يعني «لا
 * سعر» لا «مجّانيّ».
 *
 * وحين يردّ الخادم أنّ المساعد غير متاح (٥٠٣) تختفي اللوحة كلّها لهذه
 * الجلسة: زرٌّ يفشل في كلّ ضغطة وعدٌ كاذب، والبحث المعتاد يعمل بدونه.
 */

import { useCallback, useId, useState } from 'react';
import { formatMoney } from '@tahdheer/design-system/format';
import { askAssistant, type AssistantAnswer } from '../../lib/assistant-api';
import { routes } from '../../lib/routes';
import styles from './AssistantPanel.module.css';

type State =
  | { kind: 'idle' }
  | { kind: 'busy' }
  | { kind: 'answered'; answer: AssistantAnswer }
  /** غير متاح: اللوحة تنصرف ولا تترك زرًّا يفشل. */
  | { kind: 'gone'; reason: string };

export function AssistantPanel({ question }: { question: string }) {
  const [state, setState] = useState<State>({ kind: 'idle' });
  const panelId = useId();

  const ask = useCallback(() => {
    setState({ kind: 'busy' });

    void askAssistant(question).then((res) => {
      /*
       * ٥٠٣ = الميزة مطفأة أو المحرّك غير متاح، وهو قرار الخادم لا تخمين
       * هنا. ونصّ الانصراف من جسم الردّ نفسه — لا جملةٌ تُكتب في الواجهة
       * ثمّ تتباعد عن سبب الخادم.
       */
      if (res.status === 503 || res.data === null) {
        setState({
          kind: 'gone',
          reason: res.data?.answer ?? 'المساعد غير متاح الآن. البحث المعتاد يعمل كالعادة.',
        });

        return;
      }

      setState({ kind: 'answered', answer: res.data });
    });
  }, [question]);

  if (state.kind === 'gone') {
    return (
      <p className={styles.gone} role="status">
        {state.reason}
      </p>
    );
  }

  return (
    <section className={styles.panel} aria-labelledby={`${panelId}-title`}>
      <h2 className={styles.title} id={`${panelId}-title`}>
        اسأل مساعد المتجر
      </h2>
      <p className={styles.lede}>
        يجيب من ملفّات المتجر المنشورة وحدها، ويسمّي ما استند إليه. وإن لم يجد، قال ذلك.
      </p>

      {state.kind === 'answered' ? (
        <>
          <p className={styles.answer}>{state.answer.answer}</p>

          {state.answer.products.length > 0 ? (
            <ul className={styles.sources} role="list">
              {state.answer.products.map((product) => (
                <li key={product.id}>
                  <a className={styles.source} href={routes.product(product.slug)}>
                    <span className={styles.sourceTitle}>{product.title}</span>
                    {/* كلّ وصفٍ اختياريّ: الغائب يُسقَط ولا يُعوَّض بنصّ. */}
                    <span className={styles.sourceMeta}>
                      {[product.subject, product.grade, product.stage].filter(Boolean).join(' · ')}
                    </span>
                    {product.priceMinor !== null && product.priceMinor !== undefined ? (
                      <span className={styles.sourcePrice}>
                        <bdi dir="ltr">{formatMoney(product.priceMinor).amount}</bdi>{' '}
                        {formatMoney(product.priceMinor).currency}
                      </span>
                    ) : null}
                  </a>
                </li>
              ))}
            </ul>
          ) : null}
        </>
      ) : (
        <button
          className={styles.ask}
          type="button"
          onClick={ask}
          disabled={state.kind === 'busy'}
          aria-busy={state.kind === 'busy'}
        >
          <span className={styles.askLabels}>
            <span data-on={state.kind !== 'busy' ? '' : undefined}>اسأل عن «{question}»</span>
            <span data-on={state.kind === 'busy' ? '' : undefined}>يقرأ ملفّات المتجر…</span>
          </span>
        </button>
      )}
    </section>
  );
}
