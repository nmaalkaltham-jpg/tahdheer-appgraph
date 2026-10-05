'use client';
// UNIT: LIBRARY-PANEL
// FILE: library-panel.tsx
// STYLE: media-library.module.css
// PARENT: PAGE-MEDIA
// USES: ASSET-LIBRARY, LIB-FORMAT, MEDIA-DATA, MEDIA-ICONS, MEDIA-STATE, RESOURCE +3
// API: none
// API-VIA: ASSET-LIBRARY, _SCAN-PANELS
// MAP: docs/PROJECT_MAP.md

/**
 * مكتبة الوسائط — «Tahdheer - Media Library.dc.html».
 *
 * مصدران لا واحد، وكلٌّ لما يحسنه:
 *   · `GET /api/v1/admin/media` — جرد المكتبة كاملًا بحجمٍ وبصمةٍ وعدّ
 *     استعمال. منه «مكتبة الأصول» و«المكرّرة» و«اليتيمة».
 *   · `GET /api/v1/products` — أغلفة المنتجات المنشورة، وهي الوحيدة التي
 *     تحمل رابط الصورة وأبعادها. منها الشبكة والقائمة والجدول.
 * وما لا مسار له بعد — الرفع والحذف والتحسين — يُعلَن غيابه بالاسم (MIP §1.3).
 *
 * ثلاث قواعد تصميم تحملها هذه الشاشة:
 *   · التكرار يُكشف ببصمة المحتوى وحجم الملف، ولا يُكشف بالاسم أبدًا. وحيث
 *     لا بصمة — في صفوف الأغلفة — لا يُدَّعى كشف.
 *   · كشف اليتيم قابل للخطأ بطبيعته (قالب بريد أو ودجة أو CSS لا يفحصه
 *     المسح)، ولذلك لا وجود لزرّ حذف جماعي في هذا الملف — لا معطَّلًا ولا
 *     مخفيًا؛ غيابه هو القاعدة.
 *   · الحذف المفرد يمرّ بعرض مواضع الاستخدام ثمّ تأكيد مكتوب بالعدد.
 */

import { AssetLibraryPanel } from './asset-library';
import { useMemo, useState } from 'react';
import { ResourceView, useResource } from '../../../components/resource';
import { count } from '../../../lib/format';
import styles from './media-library.module.css';
import {
  PRODUCTS_PATH,
  SOURCE_SCOPE,
  filesFromProducts,
  type MediaFile,
  type ProductCardPage,
} from './media-data';
import { MediaIcon, MediaIconSprite, type MediaIconName } from './media-icons';
import { HonestPanel } from './media-state';
import { HatchDefs, MediaCard, MediaRows } from './_media-card';
import { DuplicateGroups, OrphanAssets } from './_scan-panels';
import { InspectDialog } from './_inspect-dialog';

type Mode = 'grid' | 'compact' | 'list' | 'table' | 'usage' | 'dups' | 'orphans';

const MODES: { id: Mode; label: string; icon: MediaIconName }[] = [
  { id: 'grid', label: 'شبكة', icon: 'grid' },
  { id: 'compact', label: 'مدمجة', icon: 'blocks' },
  { id: 'list', label: 'قائمة', icon: 'list' },
  { id: 'table', label: 'جدول', icon: 'table' },
  { id: 'usage', label: 'الاستخدام', icon: 'cart' },
  { id: 'dups', label: 'المكرّرة', icon: 'copy' },
  { id: 'orphans', label: 'اليتيمة', icon: 'info' },
];

/** ملاحظة الوضع. الجمل هنا مبدأ تشغيلي لا بيانات، فهي منقولة كما هي. */
const MODE_NOTES: Partial<Record<Mode, string>> = {
  usage:
    'كل ملف هنا مرتبط بمنتج منشور فعليًا — وهذا الجانب المُثبَت وحده. ' +
    'الجانب المقابل (ما لا يشير إليه شيء) لا يُعرض هنا لأنه يحتاج جرد المكتبة كاملة.',
  dups: 'التطابق بمقارنة بصمة المحتوى وحجم الملف بالبايت — لا بالاسم. الاسم المختلف لا ينفي التكرار.',
  orphans:
    'يتيم يعني «لا يشير إليه منتج ولا محتوى نعرفه». قد يكون مستخدمًا في قالب بريد أو ودجة أو CSS — ' +
    'لذلك لا حذف جماعي هنا.',
};

/** حرّاس ما قبل الحذف — أربعة، منقولة من لوحة التصميم بلا تعديل. */
const DELETION_RULES: { n: string; text: string }[] = [
  { n: '١', text: 'يُعرَض كل موضع استخدام معروف — منتجات وصفحات ومقالات — قبل عرض زر الحذف.' },
  {
    n: '٢',
    text: 'الملف «اليتيم» ليس دليل عدم استخدام: قد يكون في قالب بريد أو ودجة أو CSS لا يفحصه المسح. لذلك لا حذف جماعي.',
  },
  { n: '٣', text: 'الحذف يحتاج تأكيدًا مكتوبًا بعدد الملفات، ويُسجَّل في سجلّ التغييرات مع إمكانية الرجوع.' },
  { n: '٤', text: 'النصّ البديل المكتوب يدويًا لا يُستبدل ولا يُمسح في أي عملية جماعية.' },
];

export function MediaLibraryPanel() {
  const [mode, setMode] = useState<Mode>('grid');
  const products = useResource<ProductCardPage>(PRODUCTS_PATH);

  return (
    <section className={styles.page}>
      <MediaIconSprite />
      <p className={styles.tagline}>لا حذف تلقائي — كل إجراء هدّام يعرض مواضع الاستخدام أولًا</p>

      {/*
        المكتبة أوّلًا: «ما الذي نملكه وما الذي لا يخدم شيئًا» يسبق عرض الأغلفة.
        والقسمان يقرآن مصدرَين مختلفَين — المكتبة من سجلّ الأصول، والأغلفة من
        المنتجات المنشورة — فلا يُغني أحدهما عن الآخر.
      */}
      <AssetLibraryPanel />

      <ResourceView state={products.state} reload={products.reload}>
        {(page) => <LibraryBody mode={mode} onMode={setMode} products={page} />}
      </ResourceView>

      <section className={styles.rules} aria-labelledby="ml-rules-title">
        <div className={styles.rulesHead}>
          <MediaIcon name="shield" className={styles.rulesIcon} />
          <h2 className={styles.rulesTitle} id="ml-rules-title">
            قبل أي حذف
          </h2>
        </div>
        <ol className={styles.ruleList}>
          {DELETION_RULES.map((rule) => (
            <li className={styles.ruleItem} key={rule.n}>
              <span aria-hidden="true" className={styles.ruleNum}>
                {rule.n}
              </span>
              <span className={styles.ruleText}>{rule.text}</span>
            </li>
          ))}
        </ol>
      </section>
    </section>
  );
}

function LibraryBody({
  mode,
  onMode,
  products,
}: {
  mode: Mode;
  onMode: (mode: Mode) => void;
  products: ProductCardPage;
}) {
  const files = useMemo(() => filesFromProducts(products.data ?? []), [products]);
  const [inspected, setInspected] = useState<MediaFile | null>(null);

  const isGrid = mode === 'grid' || mode === 'compact';
  const isGroups = mode === 'dups';
  const isOrphans = mode === 'orphans';
  const note = MODE_NOTES[mode];

  /*
   * وضعا «المكرّرة» و«اليتيمة» يقرآن مكتبة الأصول لا الأغلفة، فعدّهما يصل مع
   * ردّهما لا قبله. عددٌ يُكتب هنا قبل وصول الردّ عددُ شيءٍ آخر.
   */
  const countLabel =
    isGroups || isOrphans ? (
      <>من مكتبة الأصول</>
    ) : (
      <>
        <bdi dir="ltr">{count(files.length)}</bdi> ملفًا محمَّلًا
      </>
    );

  return (
    <>
      <div className={styles.toolbar}>
        <span className={styles.toolbarLabel} id="ml-modes-label">
          العرض
        </span>
        <div className={styles.modes} role="group" aria-labelledby="ml-modes-label">
          {MODES.map((item) => (
            <button
              type="button"
              key={item.id}
              className={styles.modeBtn}
              aria-pressed={mode === item.id}
              onClick={() => onMode(item.id)}
            >
              <MediaIcon name={item.icon} className={styles.modeIcon} />
              {item.label}
            </button>
          ))}
        </div>
        <div className={styles.spacer} />
        <span className={styles.count}>{countLabel}</span>
      </div>

      {/* نطاق المصدر يخصّ الأوضاع المبنيّة على الأغلفة وحدها — والمكتبة لها نطاقها. */}
      {isGroups || isOrphans ? null : (
        <p className={styles.scope}>
          <MediaIcon name="info" className={styles.noteIcon} />
          <span className={styles.noteText}>{SOURCE_SCOPE}</span>
        </p>
      )}

      {note ? (
        <div
          className={`${styles.note} ${isOrphans ? styles.noteBad : ''}`}
          role="status"
          aria-live="polite"
        >
          <MediaIcon name="info" className={styles.noteIcon} />
          <span className={styles.noteText}>{note}</span>
        </div>
      ) : null}

      {files.length === 0 && !isOrphans && !isGroups ? (
        <HonestPanel state="NO_DATA" title="لا غلاف واحد في المصدر المتاح">
          <p>
            ردّت <bdi dir="ltr">GET /api/v1/products</bdi> بقائمة بلا أي حقل <bdi dir="ltr">image</bdi>: لا
            منتج منشور يحمل صف غلاف. هذا فراغ حقيقي في المصدر، لا فشل تحميل.
          </p>
        </HonestPanel>
      ) : null}

      {isOrphans ? <OrphanAssets /> : null}

      {isGroups ? <DuplicateGroups /> : null}

      {isGrid && files.length > 0 ? (
        <>
          <HatchDefs />
          <div className={`${styles.grid} ${mode === 'compact' ? styles.gridCompact : ''}`}>
            {files.map((file) => (
              <MediaCard key={file.url} file={file} compact={mode === 'compact'} onInspect={setInspected} />
            ))}
          </div>
        </>
      ) : null}

      {!isGrid && !isGroups && !isOrphans && files.length > 0 ? (
        <MediaRows files={files} table={mode === 'table'} onInspect={setInspected} />
      ) : null}

      {inspected ? <InspectDialog file={inspected} onClose={() => setInspected(null)} /> : null}
    </>
  );
}
