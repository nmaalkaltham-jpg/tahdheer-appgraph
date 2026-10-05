'use client';
// UNIT: PAGE-SHIPPING
// FILE: page.tsx
// ROUTE: /shipping
// STYLE: shipping.module.css
// PARENT: none
// USES: ADMIN-SESSION, CONFIRM-DIALOG, LIB-ADMIN-API, LIB-CONTRACT, LIB-FORMAT, OPS +1
// API: /admin/shipping
// MAP: docs/PROJECT_MAP.md

/**
 * مناطق الشحن وقواعده، ومعاينة ما سيقرّره المحلّل.
 *
 * ─── لماذا المعاينة أهمّ من الجداول ───
 *
 * قواعد الشحن نظامُ أسبقيّاتٍ متشابك: الأخصّ يغلب الأعمّ، والأولويّة تفصل
 * بين المتساويين، والقاعدة ذات الصنف لا تصلح إلّا حين تكون السلّة كلّها من
 * صنفها. لا أحد يحسب ذلك في رأسه وهو ينظر إلى جدولين.
 *
 * والخطأ لا يظهر إلّا في طلبٍ حقيقيّ: إمّا سعرٌ يخسر في كلّ شحنة، وإمّا
 * رفضُ شحنٍ ممكن. فالمعاينة تسأل المحلّل نفسه قبل أن يسأله زبون.
 *
 * ─── لماذا تُعرض المناطق المطابِقة مرتّبة ───
 *
 * الجواب «٢٥ ريالًا» لا يكفي: المشغّل يحتاج أن يعرف **لماذا**. ترتيب
 * المناطق من الأخصّ إلى الأعمّ هو الشرح.
 *
 * ─── ولماذا صارت الكتابة هنا ───
 *
 * النقاط الأربع (`POST/PATCH` للمنطقة والقاعدة) كاملةٌ في الخادم منذ بنائه،
 * وكانت بلا سطحٍ يناديها. فتغييرُ سعر شحنٍ — وهو قرارٌ تجاريّ يوميّ — كان
 * يمرّ بيدٍ في قاعدة البيانات: خارج الصلاحيات وخارج سجلّ التدقيق، ولا أحد
 * يعرف من غيّره ولا متى. والمكان الصحيح للنموذج هو هذه الشاشة بعينها لأنّ
 * المعاينة تجاوره: يُكتب السعر، ثمّ يُسأل المحلّل عنه قبل أن يسأله زبون.
 *
 * ─── وحدة المال هنا هللة لا ريال ───
 *
 * الخادم يقرأ ويكتب الهللات أعدادًا صحيحة، فالحقول تُسمّى «هللة» صراحةً
 * وتُرسَل كما تُكتب. تحويلُ ريالٍ عشريّ في الواجهة يُدخل انحراف تقريبٍ في
 * مبلغٍ يُقارَن بحدٍّ («مجّانيّ فوق»)، وانحرافُ هللةٍ واحدة عند الحدّ يقلب
 * القرار.
 */

import { Fragment, useCallback, useState, type ReactNode } from 'react';
import { CountBar, OpsTable, ReasonLine, StatusPill } from '../operations/_shared/ops';
import { EmptyRows, PageHeader, ResourceView, useResource } from '../../../components/resource';
import { ConfirmDialog } from '../../../components/ui/confirm-dialog';
import { useSession } from '../../../components/admin-session';
import { proxyPath } from '../../../lib/contract';
import { api } from '../../../lib/admin-api';
import { money } from '../../../lib/format';
import styles from './shipping.module.css';

interface ZoneRow {
  id: number;
  code: string;
  name: string;
  countryCodes: string[];
  regions: string[];
  priority: number;
  active: boolean;
  rateCount: number;
}

interface RateRow {
  id: number;
  zoneId: number;
  carrier: string | null;
  shippingClass: string | null;
  serviceName: string;
  minWeightGrams: number | null;
  maxWeightGrams: number | null;
  minOrderMinor: number | null;
  maxOrderMinor: number | null;
  priceMinor: number;
  freeOverMinor: number | null;
  minDays: number | null;
  maxDays: number | null;
  priority: number;
  active: boolean;
}

interface NamedRow {
  id: number;
  code: string;
  name: string;
}

interface ShippingView {
  zones: ZoneRow[];
  rates: RateRow[];
  carriers: NamedRow[];
  classes: NamedRow[];
}

interface PreviewResult {
  zones: { id: number; code: string; name: string; regions: string[] }[];
  rate: {
    id: number;
    zoneId: number;
    carrier: string | null;
    serviceName: string;
    priceMinor: number;
    listPriceMinor: number;
    freeOverMinor: number | null;
    minDays: number | null;
    maxDays: number | null;
  } | null;
  shippable: boolean;
}

/** خريطة أخطاء التحقّق كما يبثّها الخادم: حقل ← رسائله بالعربية. */
type Errors = Record<string, string[]> | null;

function range(min: number | null, max: number | null, suffix: string): string {
  if (min === null && max === null) return 'الكلّ';
  if (min === null) return `حتّى ${max}${suffix}`;
  if (max === null) return `من ${min}${suffix}`;

  return `${min}–${max}${suffix}`;
}

/**
 * رسائل حقلٍ بعينه من خريطة الأخطاء.
 *
 * المطابقة بالبادئة لا بالتساوي: Laravel يفهرس أخطاء المصفوفات بمفتاحٍ
 * مركّب (`countryCodes.0`). ولو طابقنا الاسم وحده لسقطت «رمز الدولة حرفان»
 * من تحت حقلها وظهرت كتلةً عامّة لا تدلّ على أيّ خانةٍ أخطأت — وهي بالضبط
 * المعلومة التي يحتاجها من يصحّح.
 */
function messagesFor(errors: Errors, field: string): readonly string[] {
  if (errors === null) return [];

  return Object.entries(errors)
    .filter(([key]) => key === field || key.startsWith(`${field}.`))
    .flatMap(([, messages]) => messages);
}

/**
 * نصٌّ بفواصل ← قائمة. الفاصلة العربيّة والسطر الجديد مقبولان: من ينسخ
 * قائمة رموزٍ من مكانٍ آخر لا يعيد كتابة فواصلها.
 */
function listOf(input: string): string[] {
  return input
    .split(/[،,\n]/)
    .map((part) => part.trim())
    .filter((part) => part !== '');
}

/**
 * حقلٌ رقميّ اختياريّ ← عدد أو `null` صريح.
 *
 * الفراغ يُرسَل `null` لا يُحذف: في التحرير هو **محوٌ مقصود** لسقفٍ قائم،
 * وحذفُ المفتاح كان يُبقي السقف القديم فيظنّ المشغّل أنّه رفعه.
 *
 * والحقول من نوع `number` بخطوة صحيحة، فالمتصفّح يمنع إرسال النموذج بنصٍّ
 * غير رقميّ أصلًا — والخادم يتحقّق ثانيةً على كلّ حال.
 */
function optionalInt(input: string): number | null {
  const trimmed = input.trim();

  return trimmed === '' ? null : Number(trimmed);
}

/**
 * معرّف الصفّ من اسمه — أو لا شيء.
 *
 * صفوف القواعد تبثّ **اسم** الناقل والصنف لا معرّفهما، و`PATCH` يشترط
 * المعرّف. و`carriers.name` غير فريدٍ في المخطّط (الفريد هو `code`)، فالاسم
 * لا يُترجم إلّا حين يخصّ صفًّا واحدًا لا يشاركه فيه غيره.
 *
 * وعند التباسه يُترك الاختيار فارغًا ليقرّره المشغّل: تخمينُ الأوّل هنا
 * ينقل القاعدة إلى ناقلٍ آخر بلا أن يظهر ذلك في أيّ شاشة.
 */
function soleIdByName(options: readonly NamedRow[], name: string | null): string {
  if (name === null) return '';

  const matches = options.filter((option) => option.name === name);
  const only = matches.length === 1 ? matches[0] : undefined;

  return only === undefined ? '' : String(only.id);
}

/** حقلٌ بعنوانه وتلميحه ورسائل خطئه — الرسالة تحت حقلها لا في كتلةٍ واحدة. */
function Field({
  label,
  hint,
  errors,
  wide,
  children,
}: {
  label: string;
  hint?: string;
  errors: readonly string[];
  wide?: boolean;
  children: ReactNode;
}) {
  return (
    <label className={wide === true ? `${styles.field} ${styles.wide}` : styles.field}>
      <span>{label}</span>
      {children}
      {hint === undefined ? null : <small className={styles.hint}>{hint}</small>}
      {errors.map((message) => (
        <small key={message} className={styles.error} role="alert">
          {message}
        </small>
      ))}
    </label>
  );
}

/** خانة تفعيل — ما لا يكون فعّالًا لا يدخل الحلّ أصلًا. */
function ActiveCheck({ checked, onChange }: { checked: boolean; onChange: (value: boolean) => void }) {
  return (
    <label className={`${styles.check} ${styles.wide}`}>
      <input type="checkbox" checked={checked} onChange={(event) => onChange(event.target.checked)} />
      <span>فعّالة — المعطّلة لا يراها المحلّل</span>
    </label>
  );
}

function Preview({ classes }: { classes: readonly NamedRow[] }) {
  const [country, setCountry] = useState('SA');
  const [region, setRegion] = useState('');
  const [weight, setWeight] = useState('1000');
  const [order, setOrder] = useState('20000');
  const [classId, setClassId] = useState('');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [result, setResult] = useState<PreviewResult | null>(null);

  async function run(event: React.FormEvent) {
    event.preventDefault();
    setBusy(true);
    setError(null);

    const response = await api.post<PreviewResult>(proxyPath('adminShippingPreview'), {
      countryCode: country,
      region: region === '' ? null : region,
      weightGrams: Number(weight),
      orderMinor: Number(order),
      shippingClassIds: classId === '' ? [] : [Number(classId)],
    });

    setBusy(false);

    if (!response.ok) {
      setError(response.message);
      setResult(null);

      return;
    }

    setResult(response.data);
  }

  return (
    <form className={styles.preview} onSubmit={run}>
      <h2 className={styles.title}>معاينة القرار</h2>

      <div className={styles.grid}>
        <label className={styles.field}>
          <span>الدولة</span>
          <input value={country} onChange={(e) => setCountry(e.target.value.toUpperCase())} maxLength={2} required />
        </label>

        <label className={styles.field}>
          <span>المنطقة</span>
          <input value={region} onChange={(e) => setRegion(e.target.value)} placeholder="اختياريّة" />
        </label>

        <label className={styles.field}>
          <span>الوزن (غرام)</span>
          <input type="number" min={0} value={weight} onChange={(e) => setWeight(e.target.value)} required />
        </label>

        <label className={styles.field}>
          <span>قيمة الطلب (هللة)</span>
          <input type="number" min={0} value={order} onChange={(e) => setOrder(e.target.value)} required />
        </label>

        <label className={styles.field}>
          <span>صنف الشحن</span>
          <select value={classId} onChange={(e) => setClassId(e.target.value)}>
            <option value="">بلا صنف</option>
            {classes.map((c) => (
              <option key={c.id} value={c.id}>
                {c.name}
              </option>
            ))}
          </select>
        </label>
      </div>

      <button type="submit" className="btn btn-primary" disabled={busy}>
        {busy ? 'جارٍ…' : 'اسأل المحلّل'}
      </button>

      {error === null ? null : (
        <p className={styles.error} role="alert">
          {error}
        </p>
      )}

      {result === null ? null : (
        <div className={styles.result} role="status">
          {result.shippable && result.rate !== null ? (
            <p className={styles.verdict}>
              <strong>{money(result.rate.priceMinor)}</strong> — {result.rate.carrier ?? '—'} ·{' '}
              {result.rate.serviceName}
              {result.rate.priceMinor !== result.rate.listPriceMinor ? (
                <ReasonLine>مجّانيّ فوق الحدّ — السعر المعلن {money(result.rate.listPriceMinor)}</ReasonLine>
              ) : null}
            </p>
          ) : (
            /*
             * «لا نشحن إلى هنا» جوابٌ صريح لا خطأ. عرضُه صفرًا كان يعني
             * شحنةً مجّانيّة في كلّ طلبٍ لم يضع له أحدٌ قاعدة.
             */
            <p className={styles.verdict}>لا قاعدة تغطّي هذه الوجهة — لا شحن إليها.</p>
          )}

          <p className={styles.explain}>
            المناطق المطابِقة، من الأخصّ إلى الأعمّ:{' '}
            {result.zones.length === 0
              ? 'لا شيء'
              : result.zones.map((z) => `${z.name} (${z.code})`).join(' ← ')}
          </p>
        </div>
      )}
    </form>
  );
}

/**
 * منطقةٌ جديدة أو تعديل قائمة — النموذج نفسه.
 *
 * الرمز `code` يظهر في الإنشاء وحده: `PATCH` لا يقبله لأنّ المنطقة تُعرَف
 * به في البذور والتقارير، وتغييرُه يقطع ما يشير إليها. وعرضُ حقلٍ يتجاهله
 * الخادم أسوأ من غيابه — يُحرَّر فيُحفظ فلا يتغيّر شيء.
 */
function ZoneForm({ zone, onDone }: { zone?: ZoneRow; onDone: () => void }) {
  const [code, setCode] = useState('');
  const [name, setName] = useState(zone === undefined ? '' : zone.name);
  const [countries, setCountries] = useState(zone === undefined ? '' : zone.countryCodes.join('، '));
  const [regions, setRegions] = useState(zone === undefined ? '' : zone.regions.join('، '));
  const [priority, setPriority] = useState(zone === undefined ? '0' : String(zone.priority));
  const [active, setActive] = useState(zone === undefined ? true : zone.active);
  const [reason, setReason] = useState('');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [errors, setErrors] = useState<Errors>(null);
  const [notice, setNotice] = useState<string | null>(null);
  const [confirming, setConfirming] = useState(false);

  const closeConfirm = useCallback(() => setConfirming(false), []);

  async function send() {
    setBusy(true);
    setError(null);
    setErrors(null);
    setNotice(null);

    const payload: Record<string, unknown> = {
      name: name.trim(),
      countryCodes: listOf(countries).map((part) => part.toUpperCase()),
      regions: listOf(regions),
      priority: Number(priority),
      active,
    };

    /* السبب يُرسَل حين يُكتب وحده: الخادم يمرّره إلى سجلّ التدقيق، والفراغ يكتب سطرًا بلا تعليل. */
    if (reason.trim() !== '') payload.reason = reason.trim();

    const result =
      zone === undefined
        ? await api.post<{ id: number }>(proxyPath('adminShippingStoreZone'), { ...payload, code: code.trim() })
        : await api.patch<{ id: number }>(proxyPath('adminShippingUpdateZone', { id: zone.id }), payload);

    setBusy(false);

    if (!result.ok) {
      /* نصّ الخادم كما هو: «الرمز مستعمل» تُخبر، و«فشل الحفظ» تُرسل يخمّن. */
      setError(result.message);
      setErrors(result.errors);

      return;
    }

    onDone();

    if (zone !== undefined) return;

    /* بعد الإنشاء يبقى النموذج مفتوحًا فارغًا: المناطق تُضاف سربًا لا واحدة. */
    setCode('');
    setName('');
    setCountries('');
    setRegions('');
    setPriority('0');
    setActive(true);
    setReason('');
    setNotice('أُنشئت المنطقة.');
  }

  function submit(event: React.FormEvent) {
    event.preventDefault();

    /*
     * تعطيل منطقةٍ لها قواعد يغيّر ما يُسعَّر للزبون في اللحظة نفسها وبلا
     * أثرٍ مرئيّ: القواعد تبقى في الجدول «فعّالة» بينما خرجت من الحلّ.
     * فيُسأل عنه صراحةً بذكر عددها.
     */
    if (zone !== undefined && zone.active && !active && zone.rateCount > 0) {
      setConfirming(true);

      return;
    }

    void send();
  }

  return (
    <form className={zone === undefined ? styles.panel : `${styles.panel} ${styles.inline}`} onSubmit={submit}>
      <h2 className={styles.title}>{zone === undefined ? 'منطقة جديدة' : `تعديل المنطقة ${zone.code}`}</h2>

      <div className={styles.grid}>
        {zone === undefined ? (
          <Field label="الرمز" hint="فريدٌ لا يتغيّر بعد الإنشاء" errors={messagesFor(errors, 'code')}>
            <input value={code} onChange={(e) => setCode(e.target.value)} maxLength={32} required />
          </Field>
        ) : null}

        <Field label="الاسم" errors={messagesFor(errors, 'name')}>
          <input value={name} onChange={(e) => setName(e.target.value)} maxLength={120} required />
        </Field>

        <Field
          label="الدول"
          hint="رمزان لكلّ دولة، بفواصل: SA، AE"
          errors={messagesFor(errors, 'countryCodes')}
          wide
        >
          <input value={countries} onChange={(e) => setCountries(e.target.value)} required />
        </Field>

        <Field label="المناطق" hint="فارغ = كلّ مناطق الدولة" errors={messagesFor(errors, 'regions')} wide>
          <input value={regions} onChange={(e) => setRegions(e.target.value)} />
        </Field>

        <Field label="الأولويّة" hint="الأعلى يُفحص أوّلًا — ٠ إلى ١٠٠٠" errors={messagesFor(errors, 'priority')}>
          <input
            type="number"
            inputMode="numeric"
            step="1"
            min={0}
            max={1000}
            value={priority}
            onChange={(e) => setPriority(e.target.value)}
            required
          />
        </Field>

        <ActiveCheck checked={active} onChange={setActive} />

        <Field label="السبب" hint="يُكتب في سجلّ التدقيق بجوار التغيير" errors={messagesFor(errors, 'reason')} wide>
          <input value={reason} onChange={(e) => setReason(e.target.value)} maxLength={500} />
        </Field>
      </div>

      <div className={styles.actions}>
        <button type="submit" className="btn btn-primary" disabled={busy}>
          {busy ? 'جارٍ الحفظ…' : zone === undefined ? 'أنشئ المنطقة' : 'احفظ التعديل'}
        </button>
      </div>

      {error === null ? null : (
        <p className={styles.error} role="alert">
          {error}
        </p>
      )}

      {notice === null ? null : (
        <p className={styles.notice} role="status">
          {notice}
        </p>
      )}

      {zone === undefined ? null : (
        <ConfirmDialog
          open={confirming}
          title={`تعطيل المنطقة «${zone.name}»`}
          consequence={`للمنطقة ${zone.rateCount} قاعدة سعر تخرج من الحلّ فور التعطيل. كلّ وجهةٍ لا تغطّيها منطقةٌ أخرى تصير «لا نشحن إلى هنا» في المتجر بلا إشعار. التعطيل يُردّ بإعادة التفعيل، أمّا الطلبات التي رُفض شحنها أثناءه فلا تُردّ.`}
          confirmLabel="عطّلها"
          tone="danger"
          onConfirm={async () => {
            await send();
            setConfirming(false);
          }}
          onCancel={closeConfirm}
        />
      )}
    </form>
  );
}

/**
 * قاعدةٌ جديدة أو تعديل قائمة.
 *
 * المنطقة تُختار في الإنشاء وحده: `PATCH` لا يقبل `zoneId`، فنقلُ قاعدةٍ
 * بين منطقتين ليس فعلًا موجودًا في الخادم. تُعرض هنا نصًّا لا قائمةً حتّى
 * لا يُوعَد المشغّل بتغييرٍ يُبتلع صامتًا.
 *
 * والحدود الدنيا (الوزن وقيمة الطلب) مطلوبةٌ لا اختياريّة: عمودَاها لا
 * يقبلان فراغًا في المخطّط، وإرسال `null` إليهما يفشل عند الكتابة لا عند
 * التحقّق — أي بخمسمئة لا برسالةٍ تُقرأ.
 */
function RateForm({
  zones,
  carriers,
  classes,
  rate,
  onDone,
}: {
  zones: readonly ZoneRow[];
  carriers: readonly NamedRow[];
  classes: readonly NamedRow[];
  rate?: RateRow;
  onDone: () => void;
}) {
  /* الترجمة من الاسم إلى المعرّف تُشتقّ من البيانات ذاتها في كلّ تصيير — لا تُزامَن بأثرٍ جانبيّ. */
  const resolvedCarrier = rate === undefined ? '' : soleIdByName(carriers, rate.carrier);
  const resolvedClass = rate === undefined ? '' : soleIdByName(classes, rate.shippingClass);

  const [zoneId, setZoneId] = useState('');
  const [carrierId, setCarrierId] = useState(resolvedCarrier);
  const [classId, setClassId] = useState(resolvedClass);
  const [serviceName, setServiceName] = useState(rate === undefined ? '' : rate.serviceName);
  const [minWeight, setMinWeight] = useState(rate?.minWeightGrams === undefined || rate.minWeightGrams === null ? '0' : String(rate.minWeightGrams));
  const [maxWeight, setMaxWeight] = useState(rate?.maxWeightGrams === undefined || rate.maxWeightGrams === null ? '' : String(rate.maxWeightGrams));
  const [minOrder, setMinOrder] = useState(rate?.minOrderMinor === undefined || rate.minOrderMinor === null ? '0' : String(rate.minOrderMinor));
  const [maxOrder, setMaxOrder] = useState(rate?.maxOrderMinor === undefined || rate.maxOrderMinor === null ? '' : String(rate.maxOrderMinor));
  const [price, setPrice] = useState(rate === undefined ? '' : String(rate.priceMinor));
  const [freeOver, setFreeOver] = useState(rate?.freeOverMinor === undefined || rate.freeOverMinor === null ? '' : String(rate.freeOverMinor));
  const [minDays, setMinDays] = useState(rate?.minDays === undefined || rate.minDays === null ? '' : String(rate.minDays));
  const [maxDays, setMaxDays] = useState(rate?.maxDays === undefined || rate.maxDays === null ? '' : String(rate.maxDays));
  const [priority, setPriority] = useState(rate === undefined ? '0' : String(rate.priority));
  const [active, setActive] = useState(rate === undefined ? true : rate.active);
  const [reason, setReason] = useState('');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [errors, setErrors] = useState<Errors>(null);
  const [notice, setNotice] = useState<string | null>(null);

  async function submit(event: React.FormEvent) {
    event.preventDefault();
    setBusy(true);
    setError(null);
    setErrors(null);
    setNotice(null);

    const payload: Record<string, unknown> = {
      carrierId: Number(carrierId),
      shippingClassId: classId === '' ? null : Number(classId),
      serviceName: serviceName.trim(),
      minWeightGrams: Number(minWeight),
      maxWeightGrams: optionalInt(maxWeight),
      minOrderMinor: Number(minOrder),
      maxOrderMinor: optionalInt(maxOrder),
      priceMinor: Number(price),
      freeOverMinor: optionalInt(freeOver),
      minDays: optionalInt(minDays),
      maxDays: optionalInt(maxDays),
      priority: Number(priority),
      active,
    };

    if (reason.trim() !== '') payload.reason = reason.trim();

    const result =
      rate === undefined
        ? await api.post<{ id: number }>(proxyPath('adminShippingStoreRate'), { ...payload, zoneId: Number(zoneId) })
        : await api.patch<{ id: number }>(proxyPath('adminShippingUpdateRate', { id: rate.id }), payload);

    setBusy(false);

    if (!result.ok) {
      setError(result.message);
      setErrors(result.errors);

      return;
    }

    onDone();

    if (rate !== undefined) return;

    /* الخدمة والسعر وحدهما يُفرَغان: المنطقة والناقل يتكرّران في القاعدة التالية غالبًا. */
    setServiceName('');
    setPrice('');
    setReason('');
    setNotice('أُنشئت القاعدة.');
  }

  const zoneName = rate === undefined ? null : (zones.find((z) => z.id === rate.zoneId)?.name ?? '—');

  return (
    <form className={rate === undefined ? styles.panel : `${styles.panel} ${styles.inline}`} onSubmit={submit}>
      <h2 className={styles.title}>{rate === undefined ? 'قاعدة جديدة' : `تعديل القاعدة ${rate.serviceName}`}</h2>

      <div className={styles.grid}>
        {rate === undefined ? (
          <Field label="المنطقة" errors={messagesFor(errors, 'zoneId')}>
            <select value={zoneId} onChange={(e) => setZoneId(e.target.value)} required>
              <option value="">اختر منطقة</option>
              {zones.map((zone) => (
                <option key={zone.id} value={zone.id}>
                  {zone.name} ({zone.code})
                </option>
              ))}
            </select>
          </Field>
        ) : (
          <Field label="المنطقة" hint="لا تُنقل القاعدة بين المناطق — الخادم لا يقبل ذلك" errors={[]}>
            <span className={styles.readonly}>{zoneName}</span>
          </Field>
        )}

        <Field label="الناقل" errors={messagesFor(errors, 'carrierId')}>
          <select value={carrierId} onChange={(e) => setCarrierId(e.target.value)} required>
            <option value="">اختر ناقلًا</option>
            {carriers.map((carrier) => (
              <option key={carrier.id} value={carrier.id}>
                {carrier.name} ({carrier.code})
              </option>
            ))}
          </select>
        </Field>

        <Field label="صنف الشحن" hint="بلا صنف = القاعدة تصلح لكلّ سلّة" errors={messagesFor(errors, 'shippingClassId')}>
          <select value={classId} onChange={(e) => setClassId(e.target.value)}>
            <option value="">بلا صنف</option>
            {classes.map((row) => (
              <option key={row.id} value={row.id}>
                {row.name}
              </option>
            ))}
          </select>
        </Field>

        <Field label="اسم الخدمة" errors={messagesFor(errors, 'serviceName')}>
          <input value={serviceName} onChange={(e) => setServiceName(e.target.value)} maxLength={120} required />
        </Field>

        <Field label="أدنى وزن (غرام)" errors={messagesFor(errors, 'minWeightGrams')}>
          <input
            type="number"
            inputMode="numeric"
            step="1"
            min={0}
            value={minWeight}
            onChange={(e) => setMinWeight(e.target.value)}
            required
          />
        </Field>

        {/*
          * ─── الحدّ الأدنى للسقف هو القاع زائد واحد ───
          *
          * `rateRules()` لا ترتّب الحدّين، لكنّ القاعدة ترتّبهما بقيودٍ
          * صريحة (`shipping_rates_weight_range_ordered` وأختاها). فنطاقٌ
          * مقلوب أو متساوي الطرفين يمرّ التحقّق ويسقط على قيدٍ في المحرّك،
          * فيردّ ٥٠٠ ويصل المشغّل «فشل الطلب (500).» — وهي بالضبط الرسالة
          * العامّة التي يمنعها عقد الصدق. الحدّ هنا يجعل المتصفّح يقولها
          * بلغةٍ مفهومة قبل أن تُرسَل.
          */}
        <Field label="أقصى وزن (غرام)" hint="فارغ = بلا سقف، وإلّا فأكبر من الأدنى" errors={messagesFor(errors, 'maxWeightGrams')}>
          <input
            type="number"
            inputMode="numeric"
            step="1"
            min={minWeight === '' ? 0 : Number(minWeight) + 1}
            value={maxWeight}
            onChange={(e) => setMaxWeight(e.target.value)}
          />
        </Field>

        <Field label="أدنى قيمة طلب (هللة)" errors={messagesFor(errors, 'minOrderMinor')}>
          <input
            type="number"
            inputMode="numeric"
            step="1"
            min={0}
            value={minOrder}
            onChange={(e) => setMinOrder(e.target.value)}
            required
          />
        </Field>

        <Field label="أقصى قيمة طلب (هللة)" hint="فارغ = بلا سقف، وإلّا فأكبر من الأدنى" errors={messagesFor(errors, 'maxOrderMinor')}>
          <input
            type="number"
            inputMode="numeric"
            step="1"
            min={minOrder === '' ? 0 : Number(minOrder) + 1}
            value={maxOrder}
            onChange={(e) => setMaxOrder(e.target.value)}
          />
        </Field>

        <Field label="السعر (هللة)" hint="١٠٠ هللة = ريال واحد" errors={messagesFor(errors, 'priceMinor')}>
          <input
            type="number"
            inputMode="numeric"
            step="1"
            min={0}
            value={price}
            onChange={(e) => setPrice(e.target.value)}
            required
          />
        </Field>

        <Field
          label="مجّانيّ فوق (هللة)"
          hint="فارغ = لا مجّانيّة، لا «مجّانيّ دائمًا»"
          errors={messagesFor(errors, 'freeOverMinor')}
        >
          <input
            type="number"
            inputMode="numeric"
            step="1"
            min={0}
            value={freeOver}
            onChange={(e) => setFreeOver(e.target.value)}
          />
        </Field>

        <Field label="أقلّ مدّة (يوم)" errors={messagesFor(errors, 'minDays')}>
          <input
            type="number"
            inputMode="numeric"
            step="1"
            min={0}
            max={365}
            value={minDays}
            onChange={(e) => setMinDays(e.target.value)}
          />
        </Field>

        {/* المدّة قيدُها `>=` لا `>`: يومان إلى يومين نطاقٌ مشروع. */}
        <Field label="أكثر مدّة (يوم)" errors={messagesFor(errors, 'maxDays')}>
          <input
            type="number"
            inputMode="numeric"
            step="1"
            min={minDays === '' ? 0 : Number(minDays)}
            max={365}
            value={maxDays}
            onChange={(e) => setMaxDays(e.target.value)}
          />
        </Field>

        <Field label="الأولويّة" hint="تفصل بين قاعدتين متساويتي الخصوصيّة" errors={messagesFor(errors, 'priority')}>
          <input
            type="number"
            inputMode="numeric"
            step="1"
            min={0}
            max={1000}
            value={priority}
            onChange={(e) => setPriority(e.target.value)}
            required
          />
        </Field>

        <ActiveCheck checked={active} onChange={setActive} />

        <Field label="السبب" hint="يُكتب في سجلّ التدقيق بجوار التغيير" errors={messagesFor(errors, 'reason')} wide>
          <input value={reason} onChange={(e) => setReason(e.target.value)} maxLength={500} />
        </Field>
      </div>

      {/*
       * الاسم لم يُترجم إلى معرّف: يُقال صراحةً بدل أن يُملأ الاختيار تخمينًا.
       * الحفظ لا يمرّ قبل أن يختار المشغّل بنفسه لأنّ الحقل مطلوب.
       *
       * والشرط على **الاختيار الحاليّ** لا على نتيجة الترجمة وحدها:
       * `resolvedCarrier` تُحسب مرّةً من الصفّ ولا تتغيّر بعدها، فالتحذير
       * المعلَّق عليها يبقى معروضًا بعد أن يختار المشغّل — يطلب منه ما فعله
       * للتوّ، فيقرأ التحذيرات كلّها ضجيجًا.
       */}
      {rate !== undefined && rate.carrier !== null && resolvedCarrier === '' && carrierId === '' ? (
        <p className={styles.warn} role="alert">
          الناقل الحاليّ «{rate.carrier}» لا يقابله اسمٌ واحدٌ في قائمة الناقلين — اخترْه بنفسك قبل الحفظ.
        </p>
      ) : null}

      {rate !== undefined && rate.shippingClass !== null && resolvedClass === '' && classId === '' ? (
        <p className={styles.warn} role="alert">
          الصنف الحاليّ «{rate.shippingClass}» لا يقابله اسمٌ واحدٌ في قائمة الأصناف — اخترْه بنفسك، وتركُه فارغًا
          يمحوه.
        </p>
      ) : null}

      <div className={styles.actions}>
        <button type="submit" className="btn btn-primary" disabled={busy}>
          {busy ? 'جارٍ الحفظ…' : rate === undefined ? 'أنشئ القاعدة' : 'احفظ التعديل'}
        </button>
      </div>

      {error === null ? null : (
        <p className={styles.error} role="alert">
          {error}
        </p>
      )}

      {notice === null ? null : (
        <p className={styles.notice} role="status">
          {notice}
        </p>
      )}
    </form>
  );
}

const ZONE_COLUMNS = ['الرمز', 'الاسم', 'الدول', 'المناطق', 'الأولويّة', 'قواعد', 'الحالة'] as const;
const RATE_COLUMNS = [
  'المنطقة',
  'الناقل',
  'الخدمة',
  'الوزن',
  'قيمة الطلب',
  'السعر',
  'مجّانيّ فوق',
  'الحالة',
] as const;

/**
 * لوح الشحن — الجداول ونماذجها.
 *
 * فُصل عن الصفحة لأنّ حالة «أيّ صفٍّ مفتوحٌ للتحرير» لا تعيش في دالّة
 * التصيير التي يمرّرها `ResourceView`.
 */
function ShippingBoard({ view, reload }: { view: ShippingView; reload: () => void }) {
  const { can } = useSession();
  /*
   * القراءة بصلاحية الطلبات والكتابة بـ`shipping.manage`. وإظهارُ نموذجٍ
   * لمن لا يملكها ثمّ ردّ ٤٠٣ يعلّم المشغّل ألّا يثق بما تعرضه الشاشة.
   */
  const manage = can('shipping.manage');

  const [creatingZone, setCreatingZone] = useState(false);
  const [creatingRate, setCreatingRate] = useState(false);
  const [editingZone, setEditingZone] = useState<number | null>(null);
  const [editingRate, setEditingRate] = useState<number | null>(null);

  const zoneColumns = manage ? [...ZONE_COLUMNS, 'تحرير'] : [...ZONE_COLUMNS];
  const rateColumns = manage ? [...RATE_COLUMNS, 'تحرير'] : [...RATE_COLUMNS];

  /* تحريرٌ نجح ⇐ الصفّ يُغلق والجدول يُعاد جلبه: ما يُعرض بعده هو ما في الخادم لا ما في النموذج. */
  const closeZone = useCallback(() => {
    setEditingZone(null);
    reload();
  }, [reload]);

  const closeRate = useCallback(() => {
    setEditingRate(null);
    reload();
  }, [reload]);

  return (
    <>
      <CountBar
        items={[
          { label: 'مناطق فعّالة', value: view.zones.filter((z) => z.active).length },
          {
            label: 'مناطق بلا قواعد',
            value: view.zones.filter((z) => z.rateCount === 0).length,
            tone: view.zones.some((z) => z.active && z.rateCount === 0) ? 'danger' : 'done',
          },
          { label: 'قواعد فعّالة', value: view.rates.filter((r) => r.active).length },
          { label: 'ناقلون', value: view.carriers.length },
        ]}
      />

      {manage ? null : (
        <p className={styles.denied}>
          القراءة والمعاينة متاحتان لك، وإضافة المناطق والقواعد وتعديلها تتطلّب صلاحية «إدارة الشحن» — تغيير سعر شحنٍ
          قرارٌ تجاريّ لا تشغيليّ.
        </p>
      )}

      <Preview classes={view.classes} />

      <h2 className={styles.title}>المناطق</h2>

      {manage ? (
        <div className={styles.actions}>
          <button type="button" className="btn btn-ghost" onClick={() => setCreatingZone((open) => !open)}>
            {creatingZone ? 'أغلق نموذج المنطقة' : 'منطقة جديدة'}
          </button>
        </div>
      ) : null}

      {manage && creatingZone ? <ZoneForm onDone={reload} /> : null}

      <OpsTable columns={zoneColumns}>
        {view.zones.length === 0 ? (
          <EmptyRows colSpan={zoneColumns.length} message="لا مناطق شحن — لا وجهة مغطّاة بعد." />
        ) : (
          view.zones.map((zone) => (
            <Fragment key={zone.id}>
              <tr>
                <td>{zone.code}</td>
                <td>{zone.name}</td>
                <td>{zone.countryCodes.join('، ')}</td>
                <td>{zone.regions.length === 0 ? 'كلّ الدولة' : zone.regions.join('، ')}</td>
                <td>{zone.priority}</td>
                <td>
                  {zone.rateCount}
                  {/* منطقةٌ فعّالة بلا قاعدة ترفض الشحن صامتةً. */}
                  {zone.active && zone.rateCount === 0 ? <ReasonLine>فعّالة بلا قواعد — لا شحن إليها</ReasonLine> : null}
                </td>
                <td>
                  <StatusPill tone={zone.active ? 'done' : 'wait'} label={zone.active ? 'فعّالة' : 'معطّلة'} />
                </td>
                {manage ? (
                  <td>
                    <span className={styles.rowAction}>
                      <button
                        type="button"
                        className="btn btn-ghost"
                        onClick={() => setEditingZone((open) => (open === zone.id ? null : zone.id))}
                      >
                        {editingZone === zone.id ? 'إغلاق' : 'تحرير'}
                      </button>
                    </span>
                  </td>
                ) : null}
              </tr>

              {manage && editingZone === zone.id ? (
                <tr>
                  <td colSpan={zoneColumns.length}>
                    <ZoneForm zone={zone} onDone={closeZone} />
                  </td>
                </tr>
              ) : null}
            </Fragment>
          ))
        )}
      </OpsTable>

      <h2 className={styles.title}>القواعد</h2>

      {manage ? (
        <div className={styles.actions}>
          <button
            type="button"
            className="btn btn-ghost"
            disabled={view.zones.length === 0}
            onClick={() => setCreatingRate((open) => !open)}
          >
            {creatingRate ? 'أغلق نموذج القاعدة' : 'قاعدة جديدة'}
          </button>
          {/* القاعدة تتبع منطقة؛ بلا منطقةٍ واحدة لا شيء تُعلَّق عليه. */}
          {view.zones.length === 0 ? <ReasonLine>أنشئ منطقةً أوّلًا — القاعدة تُعلَّق على منطقة</ReasonLine> : null}
        </div>
      ) : null}

      {manage && creatingRate ? (
        <RateForm zones={view.zones} carriers={view.carriers} classes={view.classes} onDone={reload} />
      ) : null}

      <OpsTable columns={rateColumns}>
        {view.rates.length === 0 ? (
          <EmptyRows colSpan={rateColumns.length} message="لا قواعد شحن — كلّ وجهةٍ مرفوضة." />
        ) : (
          view.rates.map((rate) => (
            <Fragment key={rate.id}>
              <tr>
                <td>{view.zones.find((z) => z.id === rate.zoneId)?.name ?? '—'}</td>
                <td>{rate.carrier ?? '—'}</td>
                <td>
                  {rate.serviceName}
                  {rate.shippingClass === null ? null : <ReasonLine>صنف {rate.shippingClass}</ReasonLine>}
                </td>
                <td>{range(rate.minWeightGrams, rate.maxWeightGrams, 'غ')}</td>
                <td>{range(rate.minOrderMinor, rate.maxOrderMinor, '')}</td>
                <td>{money(rate.priceMinor)}</td>
                <td>{rate.freeOverMinor === null ? '—' : money(rate.freeOverMinor)}</td>
                <td>
                  <StatusPill tone={rate.active ? 'done' : 'wait'} label={rate.active ? 'فعّالة' : 'معطّلة'} />
                </td>
                {manage ? (
                  <td>
                    <span className={styles.rowAction}>
                      <button
                        type="button"
                        className="btn btn-ghost"
                        onClick={() => setEditingRate((open) => (open === rate.id ? null : rate.id))}
                      >
                        {editingRate === rate.id ? 'إغلاق' : 'تحرير'}
                      </button>
                    </span>
                  </td>
                ) : null}
              </tr>

              {manage && editingRate === rate.id ? (
                <tr>
                  <td colSpan={rateColumns.length}>
                    <RateForm
                      zones={view.zones}
                      carriers={view.carriers}
                      classes={view.classes}
                      rate={rate}
                      onDone={closeRate}
                    />
                  </td>
                </tr>
              ) : null}
            </Fragment>
          ))
        )}
      </OpsTable>
    </>
  );
}

export default function ShippingPage() {
  const { state, reload } = useResource<ShippingView>('/admin/shipping');

  return (
    <>
      <PageHeader trail="العمليّات" title="الشحن" />

      <ResourceView
        state={state}
        reload={reload}
        forbidden={<p>لا تملك صلاحية قراءة الطلبات، وقواعد الشحن تُقرأ بها.</p>}
      >
        {(view) => <ShippingBoard view={view} reload={reload} />}
      </ResourceView>
    </>
  );
}
