/**
 * حدُّ الانتظار لهذه المجموعة — شبكةُ البطاقات بلا رفّ ترشيح، فلا يظهر رفٌّ
 * ثمّ يختفي (انظر `CatalogSkeleton`).
 */
import { CatalogSkeleton } from '../(catalog)/_components/CatalogSkeleton';

export default function Loading() {
  return <CatalogSkeleton variant="grid" />;
}
