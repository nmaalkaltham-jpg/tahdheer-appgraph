'use client';
// UNIT: PRODUCT-COPILOT
// FILE: product-copilot.tsx
// STYLE: product-copilot.module.css
// PARENT: AI-INDEX
// USES: COMPONENTS-ICONS, LIB-ADMIN-API, LIB-FORMAT, STATES
// API: /admin/ai/suggestions, /admin/products
// MAP: docs/PROJECT_MAP.md

/**
 * مساعد المنتج — تحليلٌ يُجدول، ومقترحاتٌ تُعرض «قبل/بعد»، وقرارٌ بشريّ.
 *
 * ─── لماذا لا يحجب الشاشة ───
 *
 * التحليل نداءان للنموذج، والثانية على معالجٍ بلا مسرّع. فالزرّ يجدول مهمّةً
 * ويعود فورًا (٢٠٢)، والمقترحات تظهر حين تصل. «لا تحجب طلب المشرف بعملٍ طويل»
 * قاعدةٌ صريحة.
 *
 * ─── و«قبل» تأتي من الخادم ───
 *
 * القيمة القائمة تُحسب حيث يُحسب التطبيق نفسه، فما يراه المشرف هو ما سيقع:
 * حقلٌ ممتلئ يُعرض «سيبقى»، وفارغٌ يُعرض «سيُكتب». وبناؤها في الواجهة كان
 * سيجعلها تخمينًا يخالف ما يفعله الخادم.
 */

import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api } from '../../lib/admin-api';
import { count } from '../../lib/format';
import { EmptyState, ErrorState, Skeleton } from '../ui/states';
import { Icon } from '../icons';
import styles from './product-copilot.module.css';

interface Suggestion {
  id: number;
  field: string;
  value: Record<string, unknown>;
  current: Record<string, string | null>;
  confidencePct: number | null;
  promptId: string;
  promptVersion: number;
  status: string;
}

const FIELD_LABELS: Record<string, string> = {
  classification: 'التصنيف التعليميّ',
  keywords: 'عبارات البحث',
  seo: 'عنوان الظهور ووصفه',
};

const KEY_LABELS: Record<string, string> = {
  stage: 'المرحلة',
  grade: 'الصفّ',
  subject: 'المادّة',
  semester: 'الفصل',
  resource_type: 'نوع المصدر',
  topic: 'الموضوع',
  seo_title: 'عنوان الظهور',
  seo_desc: 'وصف الظهور',
  keywords: 'كلمات',
  synonyms: 'مرادفات',
  english: 'إنجليزيّة',
};

function asText(value: unknown): string {
  return Array.isArray(value) ? value.join('، ') : String(value ?? '');
}

export function ProductCopilot({ productId, canEdit }: { productId: number; canEdit: boolean }) {
  const client = useQueryClient();
  const [note, setNote] = useState<string | null>(null);
  const path = `/admin/ai/suggestions?entityType=product&entityId=${productId}&status=PENDING`;

  const suggestions = useQuery<{ data: Suggestion[] }>({
    queryKey: ['admin-resource', path],
    /*
     * استطلاعٌ كلّ خمس ثوانٍ: المهمّة في الطابور وقد تستغرق عشر ثوانٍ، ودفعُ
     * المشرف إلى تحديث الصفحة بيده يجعل الميزة تبدو معطّلة.
     */
    refetchInterval: 5_000,
    queryFn: async () => {
      const result = await api.get<{ data: Suggestion[] }>(path);
      if (!result.ok) throw new Error(result.message);
      return result.data;
    },
  });

  const act = useMutation({
    mutationFn: async ({ url, body }: { url: string; body?: Record<string, unknown> }) => {
      const result = await api.post(url, body ?? {});
      if (!result.ok) throw new Error(result.message);
      return result.data;
    },
    onSuccess: () => {
      setNote(null);
      void client.invalidateQueries({ queryKey: ['admin-resource', path] });
    },
    onError: (error: Error) => setNote(error.message),
  });

  const rows = suggestions.data?.data ?? [];

  return (
    <div className={styles.panel}>
      <header className={styles.head}>
        <div>
          <h3 className={styles.title}>مساعد المنتج</h3>
          <p className={styles.sub}>
            يقترح تصنيفًا وعبارات بحثٍ ونصَّ ظهور. لا يكتب شيئًا إلّا باعتمادك، ولا يستبدل ما كتبتَه.
          </p>
        </div>
        {!canEdit ? null : (
          <button
            type="button"
            className="btn btn-primary"
            disabled={act.isPending}
            onClick={() => act.mutate({ url: `/admin/products/${productId}/ai/enrich` })}
          >
            حلّل المنتج
          </button>
        )}
      </header>

      {note === null ? null : (
        <p className={styles.note} role="status">
          <Icon name="alert" size={14} />
          {note}
        </p>
      )}

      {suggestions.isPending ? (
        <Skeleton rows={2} columns={2} />
      ) : suggestions.isError ? (
        <ErrorState message={suggestions.error.message} onRetry={() => void suggestions.refetch()} />
      ) : rows.length === 0 ? (
        <EmptyState
          icon="cpu"
          title="لا مقترح معلّق"
          body="اضغط «حلّل المنتج» ليقرأ النموذج عنوانه ووصفه ويقترح. التحليل يجري في الخلفيّة."
        />
      ) : (
        <ul className={styles.list}>
          {rows.map((row) => (
            <li key={row.id} className={styles.item}>
              <div className={styles.itemHead}>
                <strong>{FIELD_LABELS[row.field] ?? row.field}</strong>
                <span className={styles.conf}>
                  {row.confidencePct === null ? 'بلا ثقة معلنة' : `ثقة ${row.confidencePct}%`}
                </span>
                <bdi dir="ltr" className={styles.prompt}>
                  {row.promptId}@{row.promptVersion}
                </bdi>
              </div>

              <table className={styles.diff}>
                <thead>
                  <tr>
                    <th scope="col">الحقل</th>
                    <th scope="col">الحاليّ</th>
                    <th scope="col">المقترح</th>
                    <th scope="col">عند الاعتماد</th>
                  </tr>
                </thead>
                <tbody>
                  {Object.entries(row.value).map(([key, proposed]) => {
                    const before = row.current[key] ?? null;
                    const filled = typeof before === 'string' && before.trim() !== '';

                    return (
                      <tr key={key}>
                        <td>{KEY_LABELS[key] ?? key}</td>
                        <td className={styles.before}>{filled ? before : '—'}</td>
                        <td className={styles.after}>{asText(proposed)}</td>
                        {/*
                          * الأثر يُقال قبل الضغط لا بعده: حقلٌ ممتلئ لن
                          * يتغيّر، وعرضُه «سيُكتب» كان وعدًا كاذبًا.
                          */}
                        <td className={styles.effect}>
                          {row.field === 'keywords' ? 'يُضاف للمراجعة' : filled ? 'يبقى كما هو' : 'يُكتب'}
                        </td>
                      </tr>
                    );
                  })}
                </tbody>
              </table>

              {!canEdit ? (
                <p className={styles.sub}>الاعتماد يحتاج صلاحية تعديل المنتجات.</p>
              ) : (
                <div className={styles.actions}>
                  <button
                    type="button"
                    className="btn btn-primary"
                    disabled={act.isPending}
                    onClick={() => act.mutate({ url: `/admin/ai/suggestions/${row.id}/approve` })}
                  >
                    اعتماد
                  </button>
                  <button
                    type="button"
                    className="btn btn-ghost"
                    disabled={act.isPending}
                    onClick={() => {
                      /* السبب إلزاميّ في الخادم — يُطلب هنا بدل طلبٍ يُرَدّ ٤٢٢. */
                      const reason = window.prompt('لماذا تُرفض؟ السبب يُحسّن الموجّه.');
                      if (reason !== null && reason.trim().length >= 3) {
                        act.mutate({ url: `/admin/ai/suggestions/${row.id}/reject`, body: { reason: reason.trim() } });
                      }
                    }}
                  >
                    رفض
                  </button>
                </div>
              )}
            </li>
          ))}
        </ul>
      )}

      <p className={styles.footnote}>
        المقترحات تُحفظ مرشَّحاتٍ بمصدرٍ غير قانونيّ. عددها الآن:{' '}
        <bdi dir="ltr">{count(rows.length)}</bdi>.
      </p>
    </div>
  );
}
