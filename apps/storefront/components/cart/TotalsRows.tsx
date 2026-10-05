/**
 * Totals chain rows (MIP §5.3 derived pricing chain) — pure/presentational,
 * usable from both the client cart view and the server order page.
 *
 * HONESTY DOCTRINE: every figure comes from the server-authoritative
 * `CartTotals` — no client math beyond choosing which rows to show.
 * Discount rows render only when non-zero; the coupon row only when applied.
 */
import { formatMoney } from '@tahdheer/design-system/format';
import type { CartCoupon, CartTotals } from '../../lib/commerce-api';
import styles from './TotalsRows.module.css';

/** Minor-unit amount as «amount ر.س» with the LTR digits isolated in <bdi>. */
export function Money({ minor }: { minor: number }) {
  const parts = formatMoney(minor);
  return (
    <span className={styles.money}>
      <bdi dir="ltr">{parts.amount}</bdi> <span className={styles.currency}>{parts.currency}</span>
    </span>
  );
}

export interface TotalsRowsProps {
  totals: CartTotals;
  coupon?: CartCoupon | null;
  /**
   * «hero» = تشكيل السلة (Tahdheer - Cart.dc.html): صفوف 12.5px ثمّ إجمالي
   * نهائي بحجم 26px في كتلة مفصولة. الافتراضي يبقى كما هو للطلبات والدفع،
   * فلكلٍّ منها تصميمه الخاص.
   */
  variant?: 'default' | 'hero';
}

export function TotalsRows({ totals, coupon, variant = 'default' }: TotalsRowsProps) {
  return (
    <dl className={`${styles.rows} ${variant === 'hero' ? styles.hero : ''}`}>
      <div className={styles.row}>
        <dt>المجموع الفرعي قبل الخصم</dt>
        <dd>
          <Money minor={totals.grossMinor} />
        </dd>
      </div>

      {totals.productDiscountMinor > 0 ? (
        <div className={`${styles.row} ${styles.discount}`}>
          <dt>خصومات المنتجات</dt>
          <dd>
            <bdi dir="ltr">−</bdi> <Money minor={totals.productDiscountMinor} />
          </dd>
        </div>
      ) : null}

      {coupon || totals.couponDiscountMinor > 0 ? (
        <div className={`${styles.row} ${styles.discount}`}>
          <dt>
            رمز الخصم{coupon ? <> (<bdi dir="ltr">{coupon.code}</bdi>)</> : null}
          </dt>
          <dd>
            <bdi dir="ltr">−</bdi> <Money minor={totals.couponDiscountMinor} />
          </dd>
        </div>
      ) : null}

      <div className={`${styles.row} ${styles.vat}`}>
        <dt>ضريبة القيمة المضافة (مشمولة)</dt>
        <dd>
          <Money minor={totals.vatMinor} />
        </dd>
      </div>

      {totals.savingMinor > 0 ? (
        <div className={`${styles.row} ${styles.saving}`}>
          <dt>إجمالي التوفير</dt>
          <dd>
            <Money minor={totals.savingMinor} />
          </dd>
        </div>
      ) : null}

      <div className={`${styles.row} ${styles.grand}`}>
        <dt>الإجمالي النهائي</dt>
        <dd>
          <Money minor={totals.totalMinor} />
        </dd>
      </div>
    </dl>
  );
}
