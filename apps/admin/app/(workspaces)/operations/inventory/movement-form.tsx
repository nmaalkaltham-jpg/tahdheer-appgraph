'use client';
// UNIT: MOVEMENT-FORM
// FILE: movement-form.tsx
// STYLE: movement-form.module.css
// PARENT: PAGE-OPERATIONS-INVENTORY
// USES: ADMIN-SESSION, LIB-ADMIN-API, LIB-CONTRACT
// API: none
// API-VIA: ADMIN-SESSION
// MAP: docs/PROJECT_MAP.md

/**
 * تسجيل حركة مخزون — استلامٌ أو تسوية.
 *
 * ─── لماذا مفتاح الخمول يُولَّد هنا لا في الخادم ───
 *
 * الخادم لا يستطيع التفريق بين «إرسالٍ ثانٍ لنفس النيّة» و«نيّةٍ ثانية
 * مطابقة»: كلاهما نفس الحقول من نفس المشغّل بعد ثوانٍ. الفرق يعرفه العميل
 * وحده، فهو من يُعلنه.
 *
 * والمفتاح يُولَّد **عند فتح النموذج** ويبقى ثابتًا خلال كلّ إعادة إرسال:
 * انقطاعُ الشبكة بعد وصول الطلب هو الحالة الخطرة — المشغّل لا يرى ردًّا
 * فيعيد النقر، والجرد يتضاعف. المفتاح الثابت يجعل الإرسال الثاني يُعيد
 * نتيجة الأوّل. ولا يتغيّر إلّا بعد نجاحٍ مؤكَّد، فالحركة التالية حركةٌ
 * جديدة فعلًا.
 *
 * ─── لماذا لا يُعرض الفعل لمن لا يملكه ───
 *
 * `inventory.manage` صلاحيةُ مستودعٍ لا دعم. وعرضُ النموذج لمن لا يملكها
 * ثمّ ردّ ٤٠٣ يجعل المشغّل يظنّ العطل في النظام لا في دوره.
 */

import { useCallback, useState } from 'react';
import { proxyPath } from '../../../../lib/contract';
import type { OperationBody } from '../../../../lib/contract';
import { api } from '../../../../lib/admin-api';
import { useSession } from '../../../../components/admin-session';
import styles from './movement-form.module.css';

type MovementBody = OperationBody<'adminOperationsActionStockMovement'>;

export interface MovementTarget {
  variantId: number;
  locationId: number;
  sku: string | null;
  product: string | null;
  location: string | null;
  available: number;
}

/**
 * مفتاحٌ عشوائيّ من مولّدٍ تعمويّ.
 *
 * `Math.random` مرفوضة هنا لا أسلوبًا بل صحّةً: مفتاح الخمول هو ما يمنع
 * مضاعفة الجرد، وتصادمُ مفتاحين يعني حركةً ثانية تُبتلع بوصفها مكرّرة —
 * أي بضاعةٌ دخلت ولم تُسجَّل. والمولّد التعمويّ متاحٌ في كلّ سياقٍ آمن،
 * واللوحة لا تعمل خارج سياقٍ آمن أصلًا.
 */
function freshKey(): string {
  if (typeof crypto === 'undefined') {
    throw new Error('لا مولّد أرقامٍ تعمويّ — لا يمكن ضمان عدم تكرار الحركة.');
  }

  if (typeof crypto.randomUUID === 'function') {
    return crypto.randomUUID();
  }

  const bytes = crypto.getRandomValues(new Uint8Array(16));

  return `mv-${Array.from(bytes, (b) => b.toString(16).padStart(2, '0')).join('')}`;
}

export function MovementForm({ targets, onDone }: { targets: readonly MovementTarget[]; onDone: () => void }) {
  const { can } = useSession();
  const allowed = can('inventory.manage');

  const [target, setTarget] = useState<string>('');
  const [kind, setKind] = useState<'RECEIPT' | 'ADJUSTMENT'>('RECEIPT');
  const [quantity, setQuantity] = useState<string>('');
  const [reason, setReason] = useState<string>('');
  const [idempotencyKey, setIdempotencyKey] = useState<string>(freshKey);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [fieldErrors, setFieldErrors] = useState<Record<string, string[]> | null>(null);
  const [notice, setNotice] = useState<string | null>(null);

  /*
   * الاختيار الافتراضيّ **يُشتقّ** ولا يُزامَن بأثرٍ جانبيّ: مزامنتُه في
   * `useEffect` تُصيّر الصفحة مرّتين عند كلّ وصول بيانات، وتترك نافذةً
   * يكون فيها النموذج معروضًا بلا صفٍّ مختار.
   */
  const first = targets[0];
  const selected = target !== '' ? target : first === undefined ? '' : `${first.variantId}:${first.locationId}`;

  const submit = useCallback(
    async (event: React.FormEvent) => {
      event.preventDefault();

      const [rawVariant, rawLocation] = selected.split(':');
      const variantId = Number(rawVariant);
      const locationId = Number(rawLocation);
      const amount = Number(quantity);

      if (!Number.isInteger(variantId) || !Number.isInteger(locationId)) {
        setError('اختر صفّ مخزونٍ أوّلًا.');

        return;
      }

      /*
         الصفر يُرفض هنا وفي الخادم معًا: حركةٌ بصفرٍ تكتب سطر تدقيقٍ لا
         يعني شيئًا وتُخفي خطأ إدخالٍ حقيقيًّا.
      */
      if (!Number.isInteger(amount) || amount === 0) {
        setError('الكمّيّة عددٌ صحيح لا يساوي صفرًا. السالب يُنقص، والموجب يزيد.');

        return;
      }

      const body: MovementBody = {
        variantId,
        locationId,
        kind,
        quantity: amount,
        reason,
        idempotencyKey,
      };

      setBusy(true);
      setError(null);
      setFieldErrors(null);
      setNotice(null);

      const result = await api.post<{ duplicate?: boolean; available?: number }>(
        proxyPath('adminOperationsActionStockMovement'),
        // النوع مولَّد من قواعد الخادم؛ الناقل يقبل سجلًّا عامًّا، فيُوسَّع بلا فقد.
        { ...body },
      );

      setBusy(false);

      if (!result.ok) {
        // نصّ الخادم كما هو: «الموقع غير معروضٍ للبيع» تُخبر، و«فشل» تُرسل يخمّن.
        setError(result.message);
        setFieldErrors(result.errors);

        return;
      }

      setNotice(
        result.data.duplicate === true
          ? 'هذه الحركة مسجّلة سلفًا بالمفتاح نفسه — لم تتحرّك كمّيّة.'
          : 'سُجّلت الحركة.',
      );

      // نجاحٌ مؤكَّد: مفتاحٌ جديد لأنّ ما بعده نيّةٌ جديدة لا إعادة إرسال.
      setIdempotencyKey(freshKey());
      setQuantity('');
      setReason('');
      onDone();
    },
    [selected, kind, quantity, reason, idempotencyKey, onDone],
  );

  if (!allowed) {
    return (
      <p className={styles.denied}>
        تسجيل حركات المخزون يتطلّب صلاحية «إدارة المخزون وحركاته» — وهي صلاحية مستودع لا تُمنح لدورك.
      </p>
    );
  }

  return (
    <form className={styles.form} onSubmit={submit}>
      <h2 className={styles.title}>تسجيل حركة مخزون</h2>

      <div className={styles.grid}>
        <label className={styles.field}>
          <span>صفّ المخزون</span>
          <select value={selected} onChange={(e) => setTarget(e.target.value)} disabled={targets.length === 0}>
            {targets.length === 0 ? <option value="">لا صفوف</option> : null}
            {targets.map((row) => (
              <option key={`${row.variantId}:${row.locationId}`} value={`${row.variantId}:${row.locationId}`}>
                {row.sku ?? '—'} · {row.product ?? '—'} · {row.location ?? '—'} (متاح {row.available})
              </option>
            ))}
          </select>
        </label>

        <label className={styles.field}>
          <span>النوع</span>
          <select value={kind} onChange={(e) => setKind(e.target.value as 'RECEIPT' | 'ADJUSTMENT')}>
            <option value="RECEIPT">استلام</option>
            <option value="ADJUSTMENT">تسوية</option>
          </select>
        </label>

        <label className={styles.field}>
          <span>الكمّيّة</span>
          <input
            type="number"
            inputMode="numeric"
            step="1"
            value={quantity}
            onChange={(e) => setQuantity(e.target.value)}
            placeholder="موجب يزيد · سالب ينقص"
            required
          />
        </label>

        <label className={`${styles.field} ${styles.wide}`}>
          <span>السبب</span>
          <input
            type="text"
            value={reason}
            onChange={(e) => setReason(e.target.value)}
            minLength={3}
            maxLength={500}
            placeholder="جردٌ دوريّ، تلف، استلام أمر شراء…"
            required
          />
        </label>
      </div>

      <p className={styles.key}>
        مفتاح الخمول <code>{idempotencyKey}</code> — إعادة الإرسال بالمفتاح نفسه لا تحرّك كمّيّة مرّتين.
      </p>

      <button type="submit" className="btn btn-primary" disabled={busy || targets.length === 0}>
        {busy ? 'جارٍ التسجيل…' : 'تسجيل الحركة'}
      </button>

      {error === null ? null : (
        <p className={styles.error} role="alert">
          {error}
        </p>
      )}

      {fieldErrors === null
        ? null
        : Object.entries(fieldErrors).map(([field, messages]) => (
            <p key={field} className={styles.error} role="alert">
              {field}: {messages.join(' · ')}
            </p>
          ))}

      {notice === null ? null : (
        <p className={styles.notice} role="status">
          {notice}
        </p>
      )}
    </form>
  );
}
