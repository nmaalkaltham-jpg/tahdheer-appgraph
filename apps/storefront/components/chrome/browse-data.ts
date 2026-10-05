/**
 * جلب شجرة التصفّح في الخادم — من نقاط الكتالوج العامّة نفسها التي تقرؤها
 * صفحات المحاور (GET /api/v1/catalog/{stages,grades,subjects})، بنافذة
 * إعادة التحقّق العامّة ذاتها. فشلُ أيٍّ منها يُفرغ بُعده فقط.
 */
import { apiGetCollection } from '../../lib/api';
import { termPath } from '../../app/(catalog)/_components/dimensions';
import { buildBrowseTree, type BrowseTree, type TermInput } from './browse-tree';

export async function getBrowseTree(): Promise<BrowseTree | null> {
  const [stages, grades, subjects] = await Promise.all([
    apiGetCollection<TermInput>('/api/v1/catalog/stages'),
    apiGetCollection<TermInput>('/api/v1/catalog/grades'),
    apiGetCollection<TermInput>('/api/v1/catalog/subjects'),
  ]);
  return buildBrowseTree(stages.data, grades.data, subjects.data, termPath);
}
