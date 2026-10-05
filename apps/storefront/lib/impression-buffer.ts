/**
 * ظهوراتُ نتائج البحث — ما يُرسل ومتى، بلا React ولا متصفّح (يُختبر بـnode --test).
 *
 * السياسة (الخادم يطابقها في `SearchTracking`):
 *   · الظهور بطاقةٌ بلغ نصفُها مجالَ الرؤية — يقرّره المراقب، وهذا يستقبل النتيجة.
 *   · المنتجُ مرّةً واحدة لكلّ بحث في هذه الصفحة؛ الرجوع والتمرير لا يُعيدان إرساله.
 *   · الدفعةُ لا تتجاوز حدّ الخادم؛ وما زاد يبقى للدفعة التالية.
 */
export const IMPRESSION_BATCH_MAX = 48;

export class ImpressionBuffer {
  private readonly seen = new Set<number>();
  private pending: number[] = [];

  /** @returns هل أُضيف (أوّل مرّة يُرى في هذا البحث) */
  add(productId: number): boolean {
    if (!Number.isInteger(productId) || productId <= 0 || this.seen.has(productId)) return false;
    this.seen.add(productId);
    this.pending.push(productId);
    return true;
  }

  get size(): number {
    return this.pending.length;
  }

  /** يُخرج دفعةً واحدة بحدّ الخادم، ويُبقي الباقي. */
  drain(): number[] {
    const batch = this.pending.slice(0, IMPRESSION_BATCH_MAX);
    this.pending = this.pending.slice(IMPRESSION_BATCH_MAX);
    return batch;
  }
}
