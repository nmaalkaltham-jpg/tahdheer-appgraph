export interface VariantDto {
  id: number;
  sku: string;
  slug: string;
  title?: string | null;
  format?: string | null;
  note?: string | null;
  purchasable: boolean;
  priceMinor?: number;
  regularMinor?: number;
  onSale?: boolean;
  discountPct?: number;
  saleTo?: string;
}

const FORMAT_LABEL: Record<string, string> = {
  DOCX: 'Word',
  PPTX: 'PowerPoint',
  PDF: 'PDF',
  XLSX: 'Excel',
};

export function labelFor(variant: VariantDto): string {
  if (variant.title != null && variant.title.trim() !== '') return variant.title;

  const format = variant.format?.toUpperCase();
  if (format != null && format !== '') return FORMAT_LABEL[format] ?? format;

  return variant.sku;
}
