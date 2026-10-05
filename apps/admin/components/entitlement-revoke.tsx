'use client';
// UNIT: ENTITLEMENT-REVOKE
// FILE: entitlement-revoke.tsx
// STYLE: none
// PARENT: PAGE-CUSTOMERS-ID
// USES: ADMIN-SESSION, LIB-ADMIN-API
// API: POST /admin/entitlements
// MAP: docs/PROJECT_MAP.md

/**
 * سحب صلاحية وصول — الضابط الذي كان غائبًا.
 *
 * ─── الفجوة ───
 *
 * `POST /admin/entitlements/{id}/revoke` مبنيّ ومُدقَّق ومسجَّل في سجلّ
 * التدقيق منذ زمن، وصفحة العميل **تعرض** «سُحبت» ولا تملك سحبًا. أي أنّ
 * المشرف يرى أثر الفعل ولا يستطيع فعله. كشفه محرّك الاكتشاف بوصفه مسارًا
 * بلا مستهلك.
 *
 * ─── لماذا سببٌ إلزاميّ وسياسةٌ صريحة ───
 *
 * السحب يقطع وصولًا اشتراه إنسان. الخادم يفرض السبب (`required`) لأنّه
 * يدخل سجلّ التدقيق، ويفرض السياسة لأنّ «اسحب» وحدها غامضة: فورًا؟ بعد
 * مهلة؟ أم يبقى الملفّ ويُمنع التحديث؟ الواجهة لا تختار عن المشرف ولا
 * تُخفي الفرق — تعرضه ليقرّر.
 *
 * والزرّ لا يظهر لمن لا يملك `entitlements.revoke`: زرٌّ يقود إلى ٤٠٣ ليس
 * حراسةً بل إحباط.
 */

import { useEffect, useRef, useState } from 'react';
import { useSession } from './admin-session';
import { api, firstError, type ApiErr } from '../lib/admin-api';

/** السياسات كما يقبلها `RevocationPolicy` في الخادم حرفيًّا. */
const POLICIES = [
  { value: 'grace_3d', label: 'مهلة ٣ أيّام', note: 'الوصول يبقى ثلاثة أيّام ثمّ ينقطع — الافتراضيّ' },
  { value: 'immediate', label: 'فورًا', note: 'ينقطع الوصول الآن' },
  { value: 'updates_only', label: 'التحديثات فقط', note: 'الملفّ الحاليّ يبقى، ولا تصله نسخ جديدة' },
  { value: 'license_only', label: 'الترخيص فقط', note: 'الترخيص يسقط والتنزيل يبقى' },
  { value: 'keep', label: 'إبقاء الوصول', note: 'يُسجَّل السحب ولا يُقطع شيء' },
];

export function EntitlementRevoke({
  entitlementId,
  productLabel,
  onDone,
}: {
  entitlementId: number;
  productLabel: string;
  onDone: () => void;
}) {
  const { can } = useSession();
  const [open, setOpen] = useState(false);
  const [reason, setReason] = useState('');
  const [policy, setPolicy] = useState('grace_3d');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  /*
   * التركيز لا يسقط إلى الجسم.
   *
   * الزرّ **يُستبدل** بالنموذج لا يُضاف إليه، فالعنصر الذي كان مركَّزًا لحظة
   * الضغط يُفكّ من الشجرة و`document.activeElement` يصير `<body>`: من ضغط
   * «سحب» بلوحة المفاتيح كان يجد التبويب التالي يبدأ من رأس المستند، وحقل
   * السبب — وهو الحقل الوحيد المطلوب في فعلٍ يقطع وصولًا مشترى — بعيدٌ عنه
   * بعشرات النقرات. والدورة تُغلق في الاتجاهين: يدخل إلى الحقل، ويعود إلى
   * الزرّ عند الإلغاء.
   *
   * الخطّافان قبل حارس الصلاحية عمدًا: `return null` تحته، ولا خطّاف بعد
   * عودةٍ مشروطة.
   */
  const reasonRef = useRef<HTMLInputElement>(null);
  const triggerRef = useRef<HTMLButtonElement>(null);
  const wasOpen = useRef(false);
  useEffect(() => {
    if (open) {
      wasOpen.current = true;
      reasonRef.current?.focus();
    } else if (wasOpen.current) {
      wasOpen.current = false;
      // بعد سحبٍ ناجح قد يختفي الصفّ كلّه — والمرجع الفارغ لا يفعل شيئًا.
      triggerRef.current?.focus();
    }
  }, [open]);

  if (!can('entitlements.revoke')) return null;

  const submit = async () => {
    setBusy(true);
    setError(null);
    try {
      await api.post(`/admin/entitlements/${entitlementId}/revoke`, { reason: reason.trim(), policy });
      setOpen(false);
      setReason('');
      onDone();
    } catch (e) {
      // رسالة الخادم حرفيًّا: «مسحوبة مسبقًا» جوابٌ صحيح لا عطل عامّ.
      setError(firstError(e as ApiErr));
    } finally {
      setBusy(false);
    }
  };

  if (!open) {
    return (
      <button ref={triggerRef} type="button" className="btn btn-ghost" onClick={() => setOpen(true)}>
        سحب
      </button>
    );
  }

  return (
    <form
      className="filters"
      onSubmit={(event) => {
        event.preventDefault();
        void submit();
      }}
    >
      <label className="field field-inline" htmlFor={`revoke-reason-${entitlementId}`}>
        <span>السبب</span>
        <input
          ref={reasonRef}
          id={`revoke-reason-${entitlementId}`}
          value={reason}
          required
          maxLength={255}
          placeholder={`لماذا يُسحب وصول «${productLabel}»؟`}
          onChange={(event) => setReason(event.target.value)}
        />
      </label>

      <label className="field field-inline" htmlFor={`revoke-policy-${entitlementId}`}>
        <span>السياسة</span>
        <select
          id={`revoke-policy-${entitlementId}`}
          value={policy}
          onChange={(event) => setPolicy(event.target.value)}
        >
          {POLICIES.map((option) => (
            <option key={option.value} value={option.value}>
              {option.label}
            </option>
          ))}
        </select>
      </label>

      <button type="submit" className="btn btn-primary" disabled={busy || reason.trim() === ''}>
        {busy ? 'يُسحب…' : 'تأكيد السحب'}
      </button>
      <button type="button" className="btn btn-ghost" disabled={busy} onClick={() => setOpen(false)}>
        إلغاء
      </button>

      <span className="list-toolbar-end">{POLICIES.find((p) => p.value === policy)?.note}</span>

      {error ? (
        <p className="form-error" role="alert">
          {error}
        </p>
      ) : null}
    </form>
  );
}
