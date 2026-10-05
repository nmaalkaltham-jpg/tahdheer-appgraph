const LONG_DATE = new Intl.DateTimeFormat('ar-SA', { dateStyle: 'long' });

export function longDateOf(date: Date): string {
  return LONG_DATE.format(date);
}
