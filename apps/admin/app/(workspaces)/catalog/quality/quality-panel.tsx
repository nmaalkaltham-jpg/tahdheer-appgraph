'use client';
// UNIT: QUALITY-PANEL
// FILE: quality-panel.tsx
// STYLE: none
// PARENT: PAGE-STUDIO-CATALOG
// USES: OPS, RESOURCE
// API: /admin/catalog/quality
// MAP: docs/PROJECT_MAP.md

/**
 * اكتمال بيانات المنتج (§١٢٠ §١٥٧).
 *
 * ─── لماذا لا درجة واحدة ───
 *
 * المتوسّط يكذب في الاتّجاهين: منتجٌ كامل المحتوى بلا استراتيجيّة تسليم
 * يبدو «٨٥٪ جاهز» وهو غير قابلٍ للبيع أصلًا، ومنتجٌ سليم التسليم ضعيف
 * المحتوى يبدو المشكلة نفسها. الأبعاد تبقى منفصلة.
 *
 * ─── لماذا الترتيب هو القيمة ───
 *
 * الشاشة تُفتح لتقرّر ما يُصلَح اليوم. الحاجب أوّلًا ثمّ الأكثر نقصًا:
 * منتجٌ لا يُنشر يسبق منتجًا ينقصه قسمُ أسئلةٍ شائعة. قائمةٌ أبجديّة تجعلها
 * تقريرًا يُقرأ مرّةً؛ والمرتّبة بالحجب تجعلها قائمةَ عملٍ تُفتح كلّ صباح.
 *
 * ─── لماذا النواقص بأسمائها ───
 *
 * «٣ حقول ناقصة» تُرسل المشغّل يبحث. «الصيغة V-12 بلا وزن — الشحن يرفضها»
 * تُرسله يُصلح، وهي الرسالة نفسها التي يقولها منفّذ الشحن وقت التنفيذ.
 */

import { CountBar, OpsTable, ReasonLine, StatusPill } from '../../operations/_shared/ops';
import { EmptyRows, ResourceView, useResource } from '../../../../components/resource';

interface QualityCard {
  id: number;
  title: string;
  slug: string;
  status: string;
  nature: string;
  legacyProductId: number | null;
  canPublish: boolean;
  blockers: string[];
  scores: Record<string, number>;
  missing: Record<string, string[]>;
}

interface QualityView {
  data: QualityCard[];
  dimensions: string[];
  blockedCount: number;
  total: number;
}

const DIMENSION_LABELS: Record<string, string> = {
  IDENTITY: 'الهويّة',
  CLASSIFICATION: 'التصنيف',
  CONTENT: 'المحتوى',
  SPECIFICATION: 'المواصفات',
  FULFILLMENT: 'التسليم',
  SEARCH: 'البحث',
  PROVENANCE: 'الأصل',
};

/** الحاجبان يُميَّزان بالنبرة: هما وحدهما ما يمنع النشر (§٦٢). */
const BLOCKING_DIMENSIONS = ['IDENTITY', 'FULFILLMENT'];

function scoreTone(dimension: string, score: number) {
  if (score === 100) return 'done' as const;

  return BLOCKING_DIMENSIONS.includes(dimension) ? ('danger' as const) : ('wait' as const);
}

export function CatalogQualityPanel() {
  const { state, reload } = useResource<QualityView>('/admin/catalog/quality');

  return (
    <>

      <ResourceView
        state={state}
        reload={reload}
        forbidden={<p>لا تملك صلاحية قراءة المنتجات، واكتمال البيانات يُقرأ بها.</p>}
      >
        {(view) => (
          <>
            <CountBar
              items={[
                { label: 'لا يُنشر', value: view.blockedCount, tone: view.blockedCount > 0 ? 'danger' : 'done' },
                { label: 'مجموعات المنتجات', value: view.total },
              ]}
            />

            <OpsTable columns={['المنتج', 'الحالة', ...view.dimensions.map((d) => DIMENSION_LABELS[d] ?? d)]}>
              {view.data.length === 0 ? (
                <EmptyRows colSpan={2 + view.dimensions.length} message="لا مجموعة منتجات بعد." />
              ) : (
                view.data.map((card) => (
                  <tr key={card.id}>
                    <td>
                      {card.legacyProductId === null ? (
                        card.title
                      ) : (
                        <a href={`/studio/catalog/${card.legacyProductId}`}>{card.title}</a>
                      )}
                      <ReasonLine>{card.slug}</ReasonLine>
                    </td>
                    <td>
                      <StatusPill
                        tone={card.canPublish ? 'done' : 'danger'}
                        label={card.canPublish ? 'قابل للنشر' : 'محجوب'}
                      />
                      {/* أسبابُ الحجب بأسمائها لا عددًا. */}
                      {card.blockers.map((b) => (
                        <ReasonLine key={b}>{b}</ReasonLine>
                      ))}
                    </td>
                    {view.dimensions.map((dimension) => (
                      <td key={dimension}>
                        <StatusPill
                          tone={scoreTone(dimension, card.scores[dimension] ?? 0)}
                          label={`${card.scores[dimension] ?? 0}٪`}
                        />
                        {(card.missing[dimension] ?? []).slice(0, 3).map((gap) => (
                          <ReasonLine key={gap}>{gap}</ReasonLine>
                        ))}
                      </td>
                    ))}
                  </tr>
                ))
              )}
            </OpsTable>
          </>
        )}
      </ResourceView>
    </>
  );
}
