/**
 * حدُّ الانتظار لهذا المحور — بلاطاتُ المفردات لا شبكةَ البطاقات، فلا يُزيح
 * الهيكلُ التخطيطَ حين تصل المفردات (انظر `CatalogSkeleton`).
 */
import { CatalogSkeleton } from '../../(catalog)/_components/CatalogSkeleton';

export default function Loading() {
  return <CatalogSkeleton variant="tiles" />;
}
