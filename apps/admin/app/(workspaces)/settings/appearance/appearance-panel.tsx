'use client';
// UNIT: APPEARANCE-PANEL
// FILE: appearance-panel.tsx
// STYLE: appearance.module.css
// PARENT: PAGE-SETTINGS
// USES: APPEARANCE-SYNC, CONFIRM-DIALOG, LIB-DASHBOARD-LAYOUT, LIB-LAYOUT-PREFS, STATUS-BADGE
// API: none
// API-VIA: APPEARANCE-SYNC
// MAP: docs/PROJECT_MAP.md

/**
 * الإعدادات › المظهر — تفضيلات العرض لهذا المشرف.
 *
 * ─── لا «حفظ» ولا مسودّة ───
 *
 * كلّ اختيارٍ يُطبَّق في اللحظة على اللوحة كلّها، والمعاينة إلى الجانب
 * مكوّناتٌ حقيقيّة (البطاقة واللوح والجدول والحقل والأزرار والتبويبات
 * والقائمة) لا صورٌ عنها — فما يُرى هنا هو ما سيُرى في كلّ شاشة. والتراجع
 * نقرةٌ واحدة أو «إعادة المظهر» لكلّ شيء.
 *
 * ─── تفضيلٌ شخصيّ لا إعداد متجر ───
 *
 * يُحفظ لهذا المشرف وحده: في حسابه متى وُجدت نقطة التفضيلات، وإلّا على هذا
 * الجهاز — والسطر أسفل اللوح يقول أيّهما، فلا يظنّ أحدٌ أنّه غيّر ما يراه
 * زملاؤه.
 */

import { useId, useState, type ReactNode } from 'react';
import {
  ADMIN_ACCENTS,
  ADMIN_DENSITIES,
  ADMIN_LAYOUTS,
  ADMIN_RADII,
  ADMIN_THEMES,
  EFFECT_LEVELS,
  MOTION_PREFS,
  ON_OFF,
  useAdminAppearance,
  useAdminDensity,
  useAdminLayout,
  useAdminTheme,
} from '../../../../lib/layout-prefs';
import { useSyncState } from '../../../../components/settings/appearance-sync';
import {
  useDashboardLayout,
  useDashboardPreset,
  type DashboardMode,
} from '../../../../lib/dashboard-layout';
import { ConfirmDialog } from '../../../../components/ui/confirm-dialog';
import { StatusBadge } from '../../../../components/ui/status-badge';
import styles from './appearance.module.css';

interface Option<T extends string> {
  id: T;
  label: string;
  hint?: string;
}

/**
 * مجموعة اختيارٍ واحد — أزرارٌ بـ`aria-pressed` داخل `group` مسمّاة.
 *
 * لا `radiogroup`: ذاك يَعِد بالتنقّل بالأسهم وتركيزٍ متجوّل، وصفٌّ من
 * ثلاثة أزرار يتصفّحه ‎Tab‎ كما يتوقّع كلّ مستعمل — وهو النمط نفسه في لوح
 * الحساب (`page-head.tsx`)، فلا يتعلّم المشغّل عنصرين لفكرةٍ واحدة.
 */
function Choice<T extends string>({
  group,
  label,
  hint,
  options,
  value,
  onChange,
  swatch,
}: {
  group: string;
  label: string;
  hint?: string;
  options: readonly Option<T>[];
  value: T | null;
  onChange: (next: T) => void;
  swatch?: boolean;
}) {
  const id = useId();
  const current = options.find((option) => option.id === value);

  return (
    <div className={styles.row}>
      <div className={styles.rowText}>
        <span id={`${id}-label`} className={styles.rowLabel}>
          {label}
        </span>
        {hint || current?.hint ? (
          <span id={`${id}-hint`} className={styles.rowHint}>
            {current?.hint ?? hint}
          </span>
        ) : null}
      </div>
      <div
        role="group"
        aria-labelledby={`${id}-label`}
        aria-describedby={hint || current?.hint ? `${id}-hint` : undefined}
        className={styles.seg}
        data-pref={group}
      >
        {options.map((option) => (
          <button
            key={option.id}
            type="button"
            className={styles.segItem}
            aria-pressed={value === option.id}
            data-value={option.id}
            onClick={() => onChange(option.id)}
          >
            {swatch ? <span className={styles.swatch} data-swatch={option.id} aria-hidden="true" /> : null}
            {option.label}
          </button>
        ))}
      </div>
    </div>
  );
}

function Section({ title, note, children }: { title: string; note?: string; children: ReactNode }) {
  const id = useId();
  return (
    <section className={styles.section} aria-labelledby={id}>
      <h2 id={id} className={styles.sectionTitle}>
        {title}
      </h2>
      {note ? <p className={styles.sectionNote}>{note}</p> : null}
      <div className={styles.rows}>{children}</div>
    </section>
  );
}

const DASHBOARD_MODES: readonly Option<DashboardMode>[] = [
  { id: 'grid', label: 'شبكة', hint: 'البطاقات تصطفّ في أعمدة ثابتة.' },
  { id: 'canvas', label: 'لوحة حرّة', hint: 'تُسحب البطاقات وتُحجَّم بحرّيّة.' },
];

const SYNC_LINE = {
  checking: 'جارٍ التحقّق من مكان الحفظ…',
  account: 'يُحفظ في حسابك — يتبعك إلى أيّ متصفّح.',
  device: 'يُحفظ على هذا الجهاز وحده — الحفظ في الحساب يُفعَّل حين تتوفّر نقطة التفضيلات في الخادم.',
} as const;

export function AppearancePanel() {
  const { theme, setTheme } = useAdminTheme();
  const { density, setDensity } = useAdminDensity();
  const { layout, setLayout } = useAdminLayout();
  const { appearance, systemReducedMotion, setAccent, setRadius, setEffect, reset } = useAdminAppearance();
  /* القالب والوضع من مخزن لوحة القيادة نفسه — خطوةٌ في تاريخها يُتراجع عنها هناك. */
  const { preset, presets, setPreset } = useDashboardPreset();
  const { doc: dashboardDoc, update: updateDashboard } = useDashboardLayout();
  const sync = useSyncState();
  const [confirming, setConfirming] = useState(false);
  const fx = appearance.effects;
  const dimmed = fx.intensity === 'off';

  return (
    <div className={styles.layout}>
      <div className={styles.controls}>
        <Section title="السمة واللون">
          <Choice group="theme" label="السمة" options={ADMIN_THEMES} value={theme} onChange={setTheme} />
          <Choice
            group="accent"
            label="اللون الأساسيّ"
            hint="من ألوان الهويّة — كلّها تبلغ تباين 4.5:1 للنصّ في السمتين."
            options={ADMIN_ACCENTS}
            value={appearance.accent}
            onChange={setAccent}
            swatch
          />
        </Section>

        <Section title="الكثافة والشكل">
          <Choice group="density" label="الكثافة" options={ADMIN_DENSITIES} value={density} onChange={setDensity} />
          <Choice group="radius" label="الزوايا" options={ADMIN_RADII} value={appearance.radius} onChange={setRadius} />
          <Choice group="layout" label="تخطيط الملاحة" options={ADMIN_LAYOUTS} value={layout} onChange={setLayout} />
        </Section>

        <Section
          title="المؤثّرات"
          note="«معطّل» يُزيل المؤثّر من جذره في كلّ الشاشات — لا يخفّفه. والتوهّج لا يظهر إلّا على ما يدلّ على حالة: خللٌ، أو حجب، أو موضعك الحاليّ."
        >
          <Choice group="shadows" label="الظلال" options={EFFECT_LEVELS} value={fx.shadows} onChange={(v) => setEffect('shadows', v)} />
          <Choice group="glow" label="التوهّج" options={ON_OFF} value={fx.glow} onChange={(v) => setEffect('glow', v)} />
          <Choice group="shine" label="اللمعة" hint="شعرة الضوء أعلى البطاقات." options={ON_OFF} value={fx.shine} onChange={(v) => setEffect('shine', v)} />
          <Choice group="glass" label="الزجاج" hint="شفافيّةٌ وضبابيّة خلف الأسطح." options={ON_OFF} value={fx.glass} onChange={(v) => setEffect('glass', v)} />
          <Choice group="ambient" label="الإضاءة المحيطة" hint="وهجٌ هادئ في خلفيّة اللوحة." options={ON_OFF} value={fx.ambient} onChange={(v) => setEffect('ambient', v)} />
          <Choice group="motion" label="الحركة" options={MOTION_PREFS} value={fx.motion} onChange={(v) => setEffect('motion', v)} />
          <Choice
            group="intensity"
            label="الشدّة"
            hint="قوّة الضبابيّة والتوهّج والإضاءة معًا — «بلا» تُطفئ الأربعة."
            options={EFFECT_LEVELS}
            value={fx.intensity}
            onChange={(v) => setEffect('intensity', v)}
          />
          {systemReducedMotion && fx.motion === 'full' ? (
            <p className={styles.notice} role="status">
              نظامك يطلب تقليل الحركة، فالحركة الآن «مخفّفة» مهما اخترت هنا.
            </p>
          ) : null}
          {dimmed ? (
            <p className={styles.notice} role="status">
              الشدّة «بلا»: التوهّج واللمعة والزجاج والإضاءة مطفأةٌ الآن، وتعود باختياراتها حين ترفع الشدّة.
            </p>
          ) : null}
        </Section>

        <Section title="لوحة القيادة">
          <Choice group="preset" label="نمط اللوحة" options={presets} value={preset} onChange={setPreset} />
          <Choice
            group="mode"
            label="وضع التخطيط"
            options={DASHBOARD_MODES}
            value={dashboardDoc.mode}
            onChange={(mode) => updateDashboard((d) => ({ ...d, mode }))}
          />
        </Section>

        <footer className={styles.foot}>
          <p className={styles.sync} role="status" aria-live="polite">
            {SYNC_LINE[sync]}
          </p>
          <button type="button" className="btn btn-ghost" data-reset-appearance onClick={() => setConfirming(true)}>
            إعادة المظهر إلى الافتراضيّ
          </button>
        </footer>
      </div>

      {/*
        * المعاينة مكوّناتُ اللوحة نفسها بأصنافها العامّة — لا نسخةٌ مرسومة.
        * ولا أرقام فيها: لا بيانات تجريبيّة في الواجهة (قاعدة الصدق).
        */}
      <aside className={styles.preview} aria-label="معاينة حيّة" data-preview>
        <p className={styles.previewTitle}>معاينة حيّة</p>
        {/*
          * `inert`: عيّنةٌ بصريّة لا أدوات — لا تدخل ترتيب التبويب ولا تُعلَن
          * أزرارًا تعمل. ما يُختار يُختار من العمود المجاور.
          */}
        <div className={styles.previewBody} inert>
        <div className={styles.previewGrid}>
          <div className="card">
            <span className="label">بطاقة ساكنة</span>
            <div className="value">—</div>
          </div>
          <div className="card card-alert" data-glow-probe>
            <span className="label">بطاقة حالة</span>
            <div className={styles.previewText}>تتوهّج لأنّها تُنبّه</div>
          </div>
        </div>

        <div className="tabs" role="tablist" aria-label="تبويبات المعاينة">
          <button type="button" role="tab" aria-selected="true" className="tab tab-active">
            الحاليّ
          </button>
          <button type="button" role="tab" aria-selected="false" className="tab">
            آخر
          </button>
        </div>

        <label className="field">
          حقل نصّيّ
          <input type="text" placeholder="اكتب هنا" />
        </label>

        <div className={styles.previewActions}>
          <button type="button" className="btn btn-primary">
            حفظ
          </button>
          <button type="button" className="btn btn-ghost">
            إلغاء
          </button>
        </div>

        <div className="table-scroll">
          <table className="data-table">
            <caption className={styles.srOnly}>جدول المعاينة</caption>
            <thead>
              <tr>
                <th scope="col">الطلب</th>
                <th scope="col">الحالة</th>
              </tr>
            </thead>
            <tbody>
              <tr>
                <td>طلب تجريبيّ</td>
                <td>
                  <StatusBadge label="مكتمل" tone="success" />
                </td>
              </tr>
              <tr>
                <td>طلب آخر</td>
                <td>
                  <StatusBadge label="بانتظار الدفع" tone="warning" />
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <div className={styles.menu} data-shadow-probe role="presentation">
          <span className={styles.menuItem}>قائمة منبثقة</span>
          <span className={styles.menuItem}>بظلّ المستوى الثالث</span>
        </div>

        <p className="state-ok">إشعار نجاح — يبقى مقروءًا في السمتين.</p>
        </div>
      </aside>

      <ConfirmDialog
        open={confirming}
        title="إعادة المظهر إلى الافتراضيّ؟"
        consequence="تعود السمة والكثافة والتخطيط واللون والزوايا وكلّ المؤثّرات إلى قيمها الأولى. قالب لوحة القيادة وبطاقاتها لا تتغيّر."
        confirmLabel="إعادة المظهر"
        tone="default"
        onConfirm={() => {
          reset();
          setConfirming(false);
        }}
        onCancel={() => setConfirming(false)}
      />
    </div>
  );
}
