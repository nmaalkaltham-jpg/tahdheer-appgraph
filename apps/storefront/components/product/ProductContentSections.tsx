export interface ContentSectionDto {
  kind: string;
  heading?: string;
  body?: string;
  items?: unknown;
}

interface ContentSectionScope {
  include?: readonly string[];
  exclude?: readonly string[];
}

function toLines(items: unknown): string[] {
  if (!Array.isArray(items)) return [];

  return items
    .map((item) => (typeof item === 'string' ? item.trim() : ''))
    .filter((item) => item !== '');
}

function inScope(kind: string, scope?: ContentSectionScope): boolean {
  if (!scope) return true;
  if (scope.include && !scope.include.includes(kind)) return false;
  if (scope.exclude && scope.exclude.includes(kind)) return false;
  return true;
}

export function usableContentSections(
  sections: ContentSectionDto[],
  scope?: ContentSectionScope,
) {
  return sections
    .filter((section) => inScope(section.kind, scope))
    .map((section) => ({
      ...section,
      lines: toLines(section.items),
      text: typeof section.body === 'string' ? section.body.trim() : '',
    }))
    .filter((section) => section.lines.length > 0 || section.text !== '');
}
