'use client';
// UNIT: MAPS
// FILE: maps.tsx
// STYLE: diagnostics.module.css, shared.module.css
// PARENT: DIAGNOSTICS-PANEL
// USES: LIB-FORMAT, RESOURCE, UI
// API: none
// MAP: docs/PROJECT_MAP.md

/**
 * الخرائط التشخيصية.
 *
 * خريطة واحدة حقيقية: شجرة التصنيف من GET /api/v1/catalog — المرحلة عقدة،
 * والصفّ عقدة تحتها، وعدد المنتجات المنشورة يأتي من الخادم لا من الواجهة.
 * الصفّ بلا منتج منشور يُرسَم متقطّعًا لأن صفحة تصنيفه ستكون فارغة لمن يصل
 * إليها — وهو أوضح عطب يمكن كشفه من البيانات المتاحة اليوم.
 *
 * أمّا خريطة الزحف وخريطة قوة الروابط فلا مصدر لهما: لا مسار يُرجع رسم
 * الروابط الداخلية. تُعرَضان بحالة «غير مُقاس» صريحة، ولا تُرسَم عقدة واحدة
 * مخترعة فيهما.
 */

import Link from 'next/link';
import { useState } from 'react';
import { ResourceView, useResource } from '../../../../components/resource';
import { count } from '../../../../lib/format';
import { NotMeasured, Segmented, type SegmentOption } from '../../analytics/_shared/ui';
import s from '../../analytics/_shared/shared.module.css';
import d from '../diagnostics.module.css';

interface CatalogNode {
  id: number;
  slug: string;
  name: string;
  position: number;
  productsCount?: number;
  stage?: { id: number; slug: string; name: string };
}

interface CatalogResponse {
  data: CatalogNode[];
}

type MapId = 'taxonomy' | 'crawl' | 'links';

const MAPS: SegmentOption[] = [
  { id: 'taxonomy', label: 'شجرة التصنيف' },
  { id: 'crawl', label: 'الزحف' },
  { id: 'links', label: 'قوة الروابط' },
];

const STAGE_Y = 110;
const GRADE_Y = [268, 348];
/** عرض الخانة الأفقية لكل عقدة ورقية — أوسع من أطول اسم صفّ حتى لا تُقصّ. */
const LEAF_WIDTH = 190;

interface PlacedNode {
  key: string;
  kind: 'stage' | 'grade';
  label: string;
  slug: string;
  published: number | null;
  x: number;
  y: number;
  tone: 'ok' | 'warn' | 'bad';
}

interface PlacedEdge {
  key: string;
  x1: number;
  y1: number;
  x2: number;
  y2: number;
  weak: boolean;
}

function place(stages: CatalogNode[], grades: CatalogNode[]) {
  const groups = stages.map((stage) => ({
    stage,
    grades: grades.filter((grade) => grade.stage?.id === stage.id),
  }));

  const leaves = groups.reduce((total, group) => total + Math.max(1, group.grades.length), 0);
  const nodes: PlacedNode[] = [];
  const edges: PlacedEdge[] = [];
  let slot = 0;
  let gradeIndex = 0;

  for (const group of groups) {
    const span = Math.max(1, group.grades.length);
    const centre = ((slot + span / 2) / leaves) * 100;
    const stagePublished = group.stage.productsCount ?? null;

    nodes.push({
      key: `stage-${group.stage.id}`,
      kind: 'stage',
      label: group.stage.name,
      slug: group.stage.slug,
      published: stagePublished,
      x: centre,
      y: STAGE_Y,
      tone: stagePublished !== null && stagePublished === 0 ? 'warn' : 'ok',
    });

    group.grades.forEach((grade, index) => {
      const x = ((slot + index + 0.5) / leaves) * 100;
      const y = GRADE_Y[gradeIndex % GRADE_Y.length] ?? GRADE_Y[0] ?? 300;
      const published = grade.productsCount ?? null;
      gradeIndex += 1;
      nodes.push({
        key: `grade-${grade.id}`,
        kind: 'grade',
        label: grade.name,
        slug: grade.slug,
        published,
        x,
        y,
        tone: published === 0 ? 'bad' : 'ok',
      });
      edges.push({
        key: `edge-${group.stage.id}-${grade.id}`,
        x1: centre,
        y1: STAGE_Y,
        x2: x,
        y2: y,
        weak: published === 0,
      });
    });

    slot += span;
  }

  return { nodes, edges, leaves };
}

function toneClass(tone: PlacedNode['tone']): string {
  if (tone === 'ok') return d.nodeOk ?? '';
  if (tone === 'warn') return d.nodeWarn ?? '';
  return d.nodeBad ?? '';
}

function describe(node: PlacedNode): string {
  if (node.published === null) {
    return 'لا يُرجع الخادم عدد منتجات لهذا البُعد، فلا يُعرض له رقم — والصفر هنا سيكون اختلاقًا.';
  }
  if (node.kind === 'grade' && node.published === 0) {
    return 'لا منتج منشور تحت هذا الصفّ: صفحة تصنيفه ستصل إلى الزائر فارغة. هذا أوضح فجوة يكشفها الكتالوج نفسه.';
  }
  if (node.kind === 'stage' && node.published === 0) {
    return 'لا منتج منشور مرتبط بالمرحلة مباشرة. راجع صفوفها أدناه قبل أن تفترض خللًا.';
  }
  return `${count(node.published)} منتج منشور مرتبط بهذه العقدة — عدٌّ حقيقي من الكتالوج لا تقدير.`;
}

function TaxonomyMap() {
  /*
   * لا فرع 403 هنا، وغيابه قياسٌ لا سهو: `/catalog/{dimension}` قراءةٌ عامّة
   * خارج أيّ حارس تخويل (`backend/laravel/routes/api.php:35-36`، داخل
   * `throttle:read` وحدها). فأيّ دورٍ يفتح اللوحة يقرأ هذه الخريطة، واختراع
   * «لا تملك صلاحية» لمسارٍ لا يطلب صلاحية كذبةٌ في الاتجاه المعاكس.
   */
  const stagesResource = useResource<CatalogResponse>('/catalog/stages');
  const gradesResource = useResource<CatalogResponse>('/catalog/grades');
  const [selected, setSelected] = useState<string | null>(null);

  return (
    <ResourceView state={stagesResource.state} reload={stagesResource.reload}>
      {(stagesBody) => (
        <ResourceView state={gradesResource.state} reload={gradesResource.reload}>
          {(gradesBody) => {
            const stages = stagesBody.data;
            const grades = gradesBody.data;

            if (stages.length === 0) {
              return (
                <NotMeasured
                  icon="map"
                  title="لا مراحل في الكتالوج"
                  body="المسار /api/v1/catalog/stages ردّ بقائمة فارغة. هذا فراغ مرصود لا عطب في الخريطة: لا شيء يُرسَم لأنه لا شيء موجود."
                />
              );
            }

            const { nodes, edges, leaves } = place(stages, grades);
            const gaps = nodes.filter((node) => node.kind === 'grade' && node.published === 0);
            const fallback = gaps[0] ?? nodes[0];
            const active = nodes.find((node) => node.key === selected) ?? fallback;

            return (
              <div className={d.mapCard}>
                <div className={d.canvasScroll}>
                  <div
                    className={d.canvas}
                    dir="ltr"
                    style={{ minInlineSize: `${Math.max(640, leaves * LEAF_WIDTH)}px` }}
                  >
                    <svg className={d.edges} preserveAspectRatio="none" aria-hidden="true">
                      {edges.map((edge) => (
                        <line
                          key={edge.key}
                          x1={`${edge.x1}%`}
                          y1={edge.y1}
                          x2={`${edge.x2}%`}
                          y2={edge.y2}
                          strokeWidth={edge.weak ? 1.5 : 2}
                          strokeDasharray={edge.weak ? '5 5' : undefined}
                          strokeLinecap="round"
                          style={{ stroke: edge.weak ? 'var(--an-bad-fg)' : 'var(--an-muted)' }}
                        />
                      ))}
                    </svg>

                    {nodes.map((node) => (
                      /*
                       * لا dir على العقدة: اتجاهها يجب أن يبقى ltr موروثًا من
                       * اللوحة، لأن inset-inline-start يُحلّ باتجاه العنصر
                       * نفسه — ولو صار rtl لانعكس موضع العقدة بينما تبقى
                       * إحداثيات الحواف في SVG فيزيائية، فتنفصل العقدة عن
                       * حافتها. النصّ العربي معزول داخل bdi فيُقرأ سليمًا.
                       */
                      <button
                        key={node.key}
                        type="button"
                        className={[
                          d.node,
                          toneClass(node.tone),
                          active !== undefined && active.key === node.key ? d.nodeOn : '',
                        ]
                          .filter(Boolean)
                          .join(' ')}
                        style={{ insetInlineStart: `${node.x}%`, insetBlockStart: `${node.y}px` }}
                        aria-pressed={active !== undefined && active.key === node.key}
                        title={node.label}
                        onClick={() => setSelected(node.key)}
                      >
                        <bdi>{node.label}</bdi>
                        {node.published !== null ? (
                          <bdi dir="ltr" className={d.nodeCount}>
                            {count(node.published)}
                          </bdi>
                        ) : null}
                      </button>
                    ))}

                    <div className={d.legend} dir="rtl">
                      <span className={d.legendChip}>
                        <span aria-hidden="true" className={[s.dot, s.dotOk].filter(Boolean).join(' ')} />
                        مرحلة أو صفّ بمنتجات منشورة
                      </span>
                      <span className={d.legendChip}>
                        <span aria-hidden="true" className={[s.dot, s.dotBad].filter(Boolean).join(' ')} />
                        صفّ بلا منتج منشور
                      </span>
                      <span className={d.legendChip}>
                        الرقم على العقدة: عدد المنتجات المنشورة
                      </span>
                    </div>
                  </div>
                </div>

                <div className={d.mapFoot} role="status" aria-live="polite">
                  <b className={d.mapFootName}>{active === undefined ? '—' : active.label}</b>
                  <span className={d.mapFootBody}>
                    {active === undefined ? (
                      'لا عقدة محدَّدة.'
                    ) : (
                      <>
                        <bdi dir="ltr">{active.slug}</bdi> — {describe(active)}
                      </>
                    )}
                  </span>
                  <Link className={[s.btn, s.btnCompact].filter(Boolean).join(' ')} href="/studio/catalog">
                    افتح مساحة المنتجات
                  </Link>
                </div>

                <p className={s.panelNote} style={{ paddingInline: '16px', paddingBlockEnd: '12px' }}>
                  العقد والأعداد كلها من <bdi dir="ltr">/api/v1/catalog</bdi>. عدد المنتجات للمنشور وحده،
                  فالصفّ الذي عليه صفر قد يحوي مسودّات — وهذا بالضبط ما تكشفه الخريطة:{' '}
                  <bdi dir="ltr">{count(gaps.length)}</bdi> صفًّا بلا منتج منشور من أصل{' '}
                  <bdi dir="ltr">{count(grades.length)}</bdi>.
                </p>
              </div>
            );
          }}
        </ResourceView>
      )}
    </ResourceView>
  );
}

export default function MapsScreen() {
  const [map, setMap] = useState<MapId>('taxonomy');

  return (
    <>
      <div className={d.mapHead}>
        <h2 className={d.mapTitle}>
          {map === 'taxonomy'
            ? 'شجرة التصنيف — المرحلة والصفّ والمنشور تحتهما'
            : map === 'crawl'
              ? 'خريطة الزحف — العمق والمسارات'
              : 'تدفّق قوة الروابط الداخلية'}
        </h2>
        <span className={d.mapSub}>
          {map === 'taxonomy'
            ? 'العقدة المتقطّعة صفّ بلا منتج منشور'
            : 'لا مصدر لرسم الروابط اليوم'}
        </span>
        <span className={d.spacer} />
        <Segmented label="الخريطة" options={MAPS} value={map} onChange={(id) => setMap(id as MapId)} />
      </div>

      {map === 'taxonomy' ? <TaxonomyMap /> : null}

      {map === 'crawl' ? (
        <NotMeasured
          icon="map"
          title="لا خريطة زحف"
          body="عمق الزحف والصفحات اليتيمة يحتاجان رسم الروابط الداخلية بين صفحات المتجر: أي صفحة تشير إلى أي صفحة. لا يوجد مسار يُرجع هذا الرسم، ولن تُرسَم عقدة واحدة مخترعة مكانه."
          requirements={[
            'زحف داخلي يستخرج الروابط من صفحات المتجر ويحفظ حوافها.',
            'مقارنة الناتج بخريطة الموقع لكشف ما لا يصله رابط داخلي.',
            'حتى ذلك الحين، أقرب كشف حقيقي متاح هو «شجرة التصنيف»: صفّ بلا منتج منشور صفحته فارغة فعلًا.',
          ]}
        />
      ) : null}

      {map === 'links' ? (
        <NotMeasured
          icon="layers"
          title="لا قياس لقوة الروابط"
          body="سماكة الحافة في هذه الخريطة تعني عدد الروابط الواردة ونصوص مراسيها. لا مصدر لأيٍّ منهما، وخريطة بسماكات مخترعة تقود إلى نقل روابط بلا سبب."
          requirements={[
            'حصر الروابط الداخلية الواردة لكل صفحة مع نصّ المرساة.',
            'قاعدة ترجيح معلنة تُشتقّ منها السماكة، وإلا فالشكل زينة.',
          ]}
        />
      ) : null}
    </>
  );
}
