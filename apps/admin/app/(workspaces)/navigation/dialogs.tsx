'use client';
// UNIT: DIALOGS
// FILE: dialogs.tsx
// STYLE: dialogs.module.css, page.module.css
// PARENT: PAGE-NAVIGATION
// USES: LIB-ADMIN-API, LIB-FORMAT, MODEL, NAVIGATION-ICONS, _MODEL-TRANSFER
// API: /admin/navigation
// MAP: docs/PROJECT_MAP.md

/**
 * الحوارات: عقد النشر · اختصارات لوحة المفاتيح.
 *
 * حوار النشر ينشر فعلًا: يستدعي `POST /admin/navigation/{location}/publish`
 * بمفتاح تكرار، ويعرض قبل ذلك ما سيُرسَل وما ستفعله كل خطوة — وأيّها منفَّذ
 * اليوم وأيّها لا. الحالة تُقرأ من الخادم لا من الرغبة: كانت كل خطوة معلَّمة
 * «غير موصولة» بينما المنسّق ينفّذ تسعًا منها، وذلك إعلان غياب كاذب يمنع
 * المشغّل من استعمال قدرة يملكها.
 */

import { useEffect, useRef, useState, type ReactNode } from 'react';
import * as DialogPrimitive from '@radix-ui/react-dialog';
import { api } from '../../../lib/admin-api';
import { dateTime } from '../../../lib/format';
import { Icon } from './icons';
import {
  CACHE_TIERS,
  type PublishContract,
  type Issue,
  isTempId,
  type MenuNode,
} from './model';
import { checkImport, type ImportCheck } from './_model-transfer';
import pageStyles from './page.module.css';
import styles from './dialogs.module.css';

/**
 * حوار نمطي على Radix Dialog — حصر التركيز، والإغلاق بـ Escape وبالنقر خارج
 * اللوح، وإعادة التركيز لمصدره كلّها من الأساسيّة لا من مصيدة تركيز مكتوبة
 * باليد: النسخة اليدويّة كانت تعدّ العناصر القابلة للتركيز بنفسها وتتجاهل
 * ‎inert‎ والعناصر المخفيّة، وتوجد في المستودع نسخة Radix أصلًا لمكوّنات
 * الأطلس. الأصناف والبنية (‎scrim‎ · ‎panel‎ · ‎foot‎) كما كانت.
 */
function Dialog({
  title,
  onClose,
  children,
  footer,
}: {
  title: string;
  onClose(): void;
  children: ReactNode;
  footer?: ReactNode;
}) {
  /*
   * إعادة التركيز لمصدره تبقى هنا لا في Radix: الصفحة تُزيل الحوار من الشجرة
   * عند الإغلاق (‎open‎ ثابتة و‎onClose‎ يفكّكه)، وفي التفكيك المفاجئ لا يصل
   * ‎onCloseAutoFocus‎ إلى الزرّ الذي فتحه — مقيس: التركيز يسقط إلى ‎body‎.
   */
  const restoreRef = useRef<HTMLElement | null>(null);
  useEffect(() => {
    restoreRef.current = document.activeElement as HTMLElement | null;
    return () => restoreRef.current?.focus?.();
  }, []);
  return (
    <DialogPrimitive.Root open onOpenChange={(open) => !open && onClose()}>
      <DialogPrimitive.Portal>
        <DialogPrimitive.Overlay className={styles.scrim} />
        <DialogPrimitive.Content className={`${styles.panel} ${pageStyles.tokens}`} aria-label={title}>
          <DialogPrimitive.Title className="sr-only">{title}</DialogPrimitive.Title>
          <DialogPrimitive.Description className="sr-only">{title}</DialogPrimitive.Description>
          {children}
          {footer ? <footer className={styles.foot}>{footer}</footer> : null}
        </DialogPrimitive.Content>
      </DialogPrimitive.Portal>
    </DialogPrimitive.Root>
  );
}

/* ═══════════════ عقد النشر ═══════════════ */

/** خطوة نُفّذت فعلًا في عمليّة بعينها — من دفتر الخادم. */
interface ExecutedStep {
  id: string;
  status: 'SUCCESS' | 'FAILED' | 'SKIPPED' | 'NOT_APPLICABLE' | string;
  at: string;
  reason?: string;
}

export function PublishContractDialog({
  nodes,
  issues,
  onClose,
  onPublish,
  publishing,
  blockedReason,
  locationName,
  operationId,
  location,
  contract,
}: {
  nodes: MenuNode[];
  issues: Issue[];
  onClose(): void;
  onPublish(): void;
  publishing: boolean;
  /** سبب منع النشر، أو null حين يكون ممكنًا. يُعلَن قبل الضغط لا بعده. */
  blockedReason: string | null;
  /** اسم موقع القالب الذي سيُنشر — النشر لموقع واحد لا لكل المواقع. */
  locationName: string;
  /** معرّف آخر عملية نشر في هذه الجلسة، أو null. */
  operationId: string | null;
  /** مفتاح الموقع — لازمٌ لاستجواب دفتر العمليّات. */
  location: string;
  /** عقد النشر من الخادم؛ null قبل وصول الردّ — لا قائمة مكتوبة هنا. */
  contract: PublishContract | null;
}) {
  const blocking = issues.filter((issue) => issue.severity === 'error');

  /*
   * أثر آخر عمليّة — من الدفتر على الخادم.
   *
   * `GET …/operations/{id}` كان مبنيًّا ومختبَرًا ولا يستدعيه أيّ ملفّ في
   * الواجهة: يرى المشغّل معرّف العمليّة ولا يملك طريقًا لاستجوابه، والدفتر
   * وُجد ليُقرأ بعد انقطاع بدل إعادة النشر. هنا يُقرأ.
   */
  const [journal, setJournal] = useState<{ id: string; steps: ExecutedStep[] } | null>(null);

  useEffect(() => {
    if (operationId === null) {
      return;
    }

    let live = true;
    void api
      .get<{ data: { steps?: ExecutedStep[] } }>(
        `/admin/navigation/${encodeURIComponent(location)}/operations/${encodeURIComponent(operationId)}`,
      )
      .then((result) => {
        if (live && result.ok) {
          setJournal({ id: operationId, steps: result.data.data.steps ?? [] });
        }
      });

    return () => {
      live = false;
    };
  }, [location, operationId]);

  /*
   * الأثر يُشتقّ لا يُمسح داخل الأثر: دفترٌ يخصّ عمليّةً أخرى ليس أثرًا
   * لهذه، فيُقرأ null بدل أن يُعرض تنفيذ عمليّةٍ سابقة على خطوات هذه.
   */
  const executed = journal !== null && journal.id === operationId ? journal.steps : null;
  const ranById = new Map((executed ?? []).map((step) => [step.id, step]));
  const live = nodes.filter((node) => !node.deleted);
  const pendingDeletes = nodes.filter((node) => node.deleted);
  const tempIds = live.filter((node) => isTempId(node.id));

  return (
    <Dialog
      title="عقد النشر الذرّي"
      onClose={onClose}
      footer={
        <>
          <span className={styles.footNote}>
            النشر ينسخ المسوّدة المحفوظة على الخادم إلى المنشور؛ المسوّدة غير المحفوظة لا تُنشر.
            إعادة المحاولة بعد انقطاع تستعمل مفتاح التكرار نفسه فتقرأ العملية القائمة ولا تُنشئ
            نشرًا ثانيًا.
            {operationId === null ? null : (
              <>
                {' '}
                آخر عملية:{' '}
                <bdi dir="ltr">
                  <code>{operationId}</code>
                </bdi>
              </>
            )}
          </span>
          <button
            type="button"
            className={blockedReason === null ? styles.primaryBtn : styles.disabledAction}
            disabled={blockedReason !== null || publishing}
            aria-describedby={blockedReason === null ? undefined : 'publish-blocked-reason'}
            onClick={onPublish}
          >
            {publishing ? 'جارٍ النشر…' : 'نفّذ النشر'}
          </button>
          <button type="button" className={styles.primaryBtn} onClick={onClose}>
            إغلاق
          </button>
        </>
      }
    >
      <header className={styles.head}>
        <span className={styles.headIcon}>
          <Icon name="publish" size={17} />
        </span>
        <h2>عقد النشر الذرّي — «{locationName}»</h2>
      </header>

      <div className={styles.body}>
        {blockedReason === null ? null : (
          <p id="publish-blocked-reason" className={styles.blockedBanner} role="note">
            <Icon name="lock" size={14} />
            <span>{blockedReason}</span>
          </p>
        )}

        <section className={styles.section}>
          <h3>الفحص المسبق — يعمل الآن</h3>
          {blocking.length === 0 ? (
            <p className={styles.ok}>
              <Icon name="check" size={14} />
              لا فحص حاجب في الفحوص القابلة للحسم في المتصفح: لا نص فارغ، ولا رابط فارغ، ولا
              javascript:، ولا دائرية، ولا مرجع معرّف مؤقّت معلَّق.
            </p>
          ) : (
            <ul className={styles.blockList}>
              {blocking.map((issue, index) => (
                <li key={`${issue.code}-${index}`}>
                  <strong>{issue.title}</strong>
                  <span>{issue.detail}</span>
                </li>
              ))}
            </ul>
          )}
          <p className={styles.sub}>
            الخادم يعيد الفحص المسبق بنفسه قبل أي كتابة: النسخة الأساسية، ومسوّدة غير فارغة، واجتياز
            التطبيع، وخلوّها من المعرّفات المؤقّتة. أمّا فحوص الوجهة (الحالة، سلسلة التحويل، الرابط
            الدائم، الفهرسة) فتُشغَّل من قسم «التشخيص» — لا تُقاس هنا تلقائيًّا حتى لا يصير فتحُ
            الحوار طلبًا خارجيًّا لكل رابط.
          </p>
        </section>

        <section className={styles.section}>
          <h3>ما سيُرسَل</h3>
          <ul className={styles.factGrid}>
            <li>
              <span className={styles.factValue}>
                <bdi dir="ltr">{live.length}</bdi>
              </span>
              <span>عنصرًا نشطًا</span>
            </li>
            <li>
              <span className={styles.factValue}>
                <bdi dir="ltr">{pendingDeletes.length}</bdi>
              </span>
              <span>حذفًا مؤجلًا لا يُرسَل إلى الخادم</span>
            </li>
            <li>
              <span className={styles.factValue}>
                <bdi dir="ltr">{tempIds.length}</bdi>
              </span>
              <span>معرّفًا مؤقّتًا قديمًا يرفضه الفحص المسبق</span>
            </li>
          </ul>
        </section>

        <section className={styles.section}>
          <h3>خطوات النشر بالترتيب — كما يعرّفها الخادم</h3>
          {contract === null ? (
            <p>لم يصل عقد النشر من الخادم بعد.</p>
          ) : (
            <ol className={styles.steps}>
              {contract.publish.map((step, index) => (
                <li key={step.id}>
                  <span className={styles.stepIndex} aria-hidden="true">
                    {index + 1}
                  </span>
                  <span className={styles.stepBody}>
                    <strong>{step.label}</strong>
                    <span>{step.detail}</span>
                    <bdi dir="ltr" className={styles.needs}>
                      <code>{step.executor}</code>
                    </bdi>
                  </span>
                  {/*
                    «مبنيّة» قدرةٌ في الخادم لا تنفيذٌ وقع، و«آخر تنفيذ» يُقرأ
                    من دفتر العمليّة. دمجهما هو ما كان يجعل الشارة تقول
                    «منفَّذة» عمّا لم يُنفَّذ بعد.
                  */}
                  <span className={styles.stepBody}>
                    <span className={step.implemented ? styles.wired : styles.notWired}>
                      {step.implemented ? (step.required ? 'مبنيّة · واجبة' : 'مبنيّة') : 'غير مبنيّة'}
                    </span>
                    {ranById.has(step.id) ? (
                      <bdi dir="ltr" className={styles.needs}>
                        {ranById.get(step.id)!.status}
                        {ranById.get(step.id)!.reason ? ` · ${ranById.get(step.id)!.reason}` : ''} ·{' '}
                        {dateTime(ranById.get(step.id)!.at)}
                      </bdi>
                    ) : executed === null ? null : (
                      <span className={styles.needs}>لم تُنفَّذ في آخر عمليّة</span>
                    )}
                  </span>
                </li>
              ))}
            </ol>
          )}
        </section>

        <section className={styles.section}>
          <h3>التراجع التعويضي عند فشل أي خطوة بعد الكتابة</h3>
          <ol className={styles.steps}>
            {(contract?.rollback ?? []).map((step) => (
              <li key={step.id}>
                <span className={styles.stepIndex} aria-hidden="true">
                  <Icon name="restore" size={12} />
                </span>
                <span className={styles.stepBody}>
                  <strong>{step.label}</strong>
                  <span>{step.detail}</span>
                  <bdi dir="ltr" className={styles.needs}>
                    <code>{step.executor}</code>
                  </bdi>
                </span>
                <span className={step.implemented ? styles.wired : styles.notWired}>
                  {step.implemented ? 'مبنيّة' : 'غير مبنيّة'}
                </span>
              </li>
            ))}
          </ol>
          <p className={styles.sub}>
            ترتيب الخطوات مقصود: التحقّق يأتي بعد الإبطال، فإن اختلفت بصمة ما يُخدَم عن المستهدفة
            استُعيدت اللقطة تلقائيًّا ولم تبقَ قائمة نصف منشورة أمام الزائر.
          </p>
        </section>

        <section className={styles.section}>
          <h3>إبطال الكاش المتدرّج — من الأضيق إلى الأوسع</h3>
          <p className={styles.sub}>
            كاش القائمة المنشورة يُبطَل فورًا على الخادم لهذا الموقع وللمواقع التي ترثه، والنشر يكتب
            حدثًا في صندوق الصادر. أمّا طبقات المتجر والحافة فتُحدَّث ضمن نافذة إعادة التحقّق
            العامّة: لا إبطال فوري موجَّه بالوسوم بعد.
          </p>
          <ul className={styles.tiers}>
            {CACHE_TIERS.map((tier, index) => (
              <li key={tier.key}>
                <span className={styles.tierIndex} aria-hidden="true">
                  {index + 1}
                </span>
                <span className={styles.stepBody}>
                  <strong>{tier.name}</strong>
                  <span>النطاق: {tier.scope}</span>
                  <bdi dir="ltr" className={styles.needs}>
                    <code>{tier.needs}</code>
                  </bdi>
                </span>
                <span className={tier.wired ? styles.wired : styles.notWired}>
                  {tier.wired ? 'منفَّذ' : 'NOT_CONFIGURED'}
                </span>
              </li>
            ))}
          </ul>
        </section>
      </div>
    </Dialog>
  );
}

/* ═══════════════ حذف أبٍ له أبناء ═══════════════ */

/**
 * يسأل قبل قتل فرع كامل.
 *
 * `setDeleted` يشطب العقدة وكل نسلها معًا، فكانت ضغطة Delete على أبٍ تُخفي
 * فرعه كلّه بلا سؤال ولا بديل. الخياران هنا صريحان، وكلاهما مؤجَّل وقابل
 * للتراجع، والقائمة المنشورة لا تتأثر قبل النشر.
 */
export function BranchDeleteDialog({
  name,
  count,
  onClose,
  onDeleteBranch,
  onPromote,
}: {
  name: string;
  count: number;
  onClose(): void;
  onDeleteBranch(): void;
  onPromote(): void;
}) {
  return (
    <Dialog
      title="حذف عنصر له أبناء"
      onClose={onClose}
      footer={
        <>
          <span className={styles.footNote}>
            كلا الخيارين حذف مؤجَّل داخل المسوّدة وقابل للتراجع، والقائمة المنشورة لا تتأثر قبل
            النشر.
          </span>
          <button type="button" className={styles.primaryBtn} onClick={onClose}>
            إلغاء
          </button>
        </>
      }
    >
      <header className={styles.head}>
        <span className={styles.headIcon}>
          <Icon name="lock" size={17} />
        </span>
        <h2>
          «{name}» له <bdi dir="ltr">{count}</bdi> عنصرًا داخله
        </h2>
      </header>
      <div className={styles.body}>
        <section className={styles.section}>
          <button type="button" className={styles.choiceBtn} onClick={onDeleteBranch}>
            <strong>
              حذف الفرع كاملًا (<bdi dir="ltr">{count}</bdi> + الأب)
            </strong>
            <span>كل ما تحت «{name}» يُشطب معه، ويعود بالتراجع أو بزر الاسترجاع.</span>
          </button>
          <button type="button" className={styles.choiceBtn} onClick={onPromote}>
            <strong>ترقية الأبناء لمستوى الأب</strong>
            <span>
              الأبناء ينتقلون إلى مستوى «{name}» بترتيبهم نفسه، ويُشطب هو وحده.
            </span>
          </button>
        </section>
      </div>
    </Dialog>
  );
}

/* ═══════════════ التصدير ═══════════════ */

/**
 * تصدير المسوّدة ملفَّ JSON: عرض للقراءة + تنزيل.
 *
 * عمل محلّي بالكامل — لا نقطة نهاية له ولا يحتاجها. الصيغة هي نفسها التي
 * يقبلها الاستيراد، وحقولها حقول عقد الخادم كما تُرسَل عند الحفظ بالضبط.
 */
export function ExportDialog({
  name,
  json,
  count,
  onClose,
  onDownload,
}: {
  name: string;
  json: string;
  count: number;
  onClose(): void;
  onDownload(): void;
}) {
  return (
    <Dialog
      title={`تصدير — ${name}`}
      onClose={onClose}
      footer={
        <>
          <span className={styles.footNote}>
            الملف يُبنى في هذا المتصفح من مسوّدة المحرّر الحالية — المحذوف المؤجل لا يدخل فيه، كما
            لا يُرسَل إلى الخادم.
          </span>
          <button type="button" className={styles.primaryBtn} onClick={onDownload}>
            <Icon name="download" size={14} /> تنزيل الملف
          </button>
          <button type="button" className={styles.primaryBtn} onClick={onClose}>
            إغلاق
          </button>
        </>
      }
    >
      <header className={styles.head}>
        <span className={styles.headIcon}>
          <Icon name="download" size={17} />
        </span>
        <h2>تصدير — {name}</h2>
      </header>
      <div className={styles.body}>
        <p className={styles.sub}>
          صيغة موثَّقة قابلة للاستيراد: القائمة كاملة · <bdi dir="ltr">{count}</bdi> عنصرًا — حقول
          عقد الخادم كما تُحفظ بالضبط.
        </p>
        <textarea
          readOnly
          value={json}
          rows={12}
          dir="ltr"
          className={styles.jsonArea}
          aria-label="محتوى التصدير بصيغة JSON"
        />
      </div>
    </Dialog>
  );
}

/* ═══════════════ الاستيراد ═══════════════ */

/**
 * استيراد ملف قائمة: لصق ثم تحقّق ثم تطبيق.
 *
 * الحقيقة أولًا: لا مكتبة قوائم هنا (معدَّدة في «ما تحتاجه بقية دورة
 * الحياة»)، فالاستيراد يستبدل مسوّدة المحرّر لهذا الموقع — استبدال محلّي
 * قابل للتراجع، ولا يمسّ الخادم حتى يحفظ المشغّل. التطبيق لا يُتاح قبل
 * فحصٍ ناجح، ولا يُنفَّذ أي كود من الملف.
 */
export function ImportDialog({
  locationName,
  onClose,
  onApply,
}: {
  locationName: string;
  onClose(): void;
  onApply(nodes: MenuNode[]): void;
}) {
  const [text, setText] = useState('');
  const [check, setCheck] = useState<ImportCheck | null>(null);

  return (
    <Dialog
      title="استيراد قائمة"
      onClose={onClose}
      footer={
        <>
          <span className={styles.footNote}>
            الاستبدال داخل المحرّر فقط وقابل للتراجع (Ctrl/⌘+Z) — لا يُكتب على الخادم حتى تضغط
            «حفظ على الخادم».
          </span>
          <button
            type="button"
            className={check === null ? styles.primaryBtn : styles.disabledAction}
            disabled={check !== null}
            onClick={() => setCheck(checkImport(text))}
          >
            تحقّق من الصيغة
          </button>
          <button
            type="button"
            className={check?.ok ? styles.primaryBtn : styles.disabledAction}
            disabled={!check?.ok}
            onClick={() => {
              if (check?.ok) onApply(check.nodes);
            }}
          >
            تحميل الملف كمسوّدة
          </button>
        </>
      }
    >
      <header className={styles.head}>
        <span className={styles.headIcon}>
          <Icon name="upload" size={17} />
        </span>
        <h2>استيراد قائمة</h2>
      </header>
      <div className={styles.body}>
        <p className={styles.sub}>
          يستبدل مسوّدة المحرّر لموقع «{locationName}» بمحتوى الملف. يُتحقق من الصيغة والمراجع قبل
          التطبيق، ولا يُنفَّذ أي كود من الملف.
        </p>
        <label className={styles.importLabel}>
          <span>الصق محتوى ملف التصدير (JSON)</span>
          <textarea
            value={text}
            rows={12}
            dir="ltr"
            className={styles.jsonArea}
            onChange={(event) => {
              setText(event.target.value);
              setCheck(null);
            }}
          />
        </label>
        {check === null ? null : check.ok ? (
          <ul className={styles.factGrid}>
            <li>
              <span className={styles.factValue}>
                <bdi dir="ltr">{check.count}</bdi>
              </span>
              <span>عنصرًا في «{check.name}»</span>
            </li>
            <li>
              <span className={styles.factValue}>
                <bdi dir="ltr">{check.missing}</bdi>
              </span>
              <span>عنصرًا بلا نص أو رابط — يُستورد ويكشفه التحقق المحلي</span>
            </li>
            <li>
              <span className={styles.factValue}>
                <bdi dir="ltr">{check.unknownKeys}</bdi>
              </span>
              <span>حقلًا غريبًا سيسقط — عقد الخادم قائمة بيضاء لا تحفظه</span>
            </li>
          </ul>
        ) : (
          <p className={styles.blockedBanner} role="alert">
            <Icon name="warn" size={14} />
            <span>{check.error}</span>
          </p>
        )}
      </div>
    </Dialog>
  );
}

/* ═══════════════ الاختصارات ═══════════════ */

const SHORTCUTS: [string, string][] = [
  ['⌘Z / Ctrl+Z', 'تراجع'],
  ['⇧⌘Z / Ctrl+Y', 'إعادة'],
  ['⌘S / Ctrl+S', 'حفظ نسخة استرجاع في هذا المتصفح'],
  ['Enter', 'تحرير نص العنصر المحدد'],
  ['Escape', 'إغلاق الحوار أو إلغاء التحرير'],
  ['Alt + ↑', 'تحريك العنصر للأعلى بين أشقائه'],
  ['Alt + ↓', 'تحريك العنصر للأسفل بين أشقائه'],
  ['Alt + ←', 'تعشيش تحت العنصر السابق'],
  ['Alt + →', 'إخراج من الأب'],
  ['Ctrl/⌘ + نقر', 'إضافة عنصر إلى التحديد أو إزالته'],
  ['Delete', 'حذف مؤجل للعنصر المحدد'],
];

export function ShortcutsDialog({ onClose }: { onClose(): void }) {
  return (
    <Dialog
      title="اختصارات لوحة المفاتيح"
      onClose={onClose}
      footer={
        <button type="button" className={styles.primaryBtn} onClick={onClose}>
          تمام
        </button>
      }
    >
      <header className={styles.head}>
        <span className={styles.headIcon}>
          <Icon name="key" size={17} />
        </span>
        <h2>اختصارات لوحة المفاتيح</h2>
      </header>
      <div className={styles.body}>
        <p className={styles.sub}>
          كل إجراء سحب في الشجرة والخريطة له زر ظاهر ومفتاح مكافئ — لا وظيفة تعتمد على الفأرة وحدها.
        </p>
        <ul className={styles.keyGrid}>
          {SHORTCUTS.map(([keys, label]) => (
            <li key={keys}>
              <bdi dir="ltr">
                <code>{keys}</code>
              </bdi>
              <span>{label}</span>
            </li>
          ))}
        </ul>
      </div>
    </Dialog>
  );
}
