/** Business dates are Asia/Colombo days, like the API's BusinessDate (reviewer R10). */
export const BUSINESS_TZ = 'Asia/Colombo';

function parts(d: Date, withTime: boolean): Record<string, string> {
  return Object.fromEntries(new Intl.DateTimeFormat('en-GB', {
    timeZone: BUSINESS_TZ, year: 'numeric', month: '2-digit', day: '2-digit',
    ...(withTime ? { hour: '2-digit', minute: '2-digit', hourCycle: 'h23' as const } : {}),
  }).formatToParts(d).map(p => [p.type, p.value]));
}

/** Today in Asia/Colombo as YYYY-MM-DD. */
export function colomboToday(): string {
  const p = parts(new Date(), false);
  return `${p['year']}-${p['month']}-${p['day']}`;
}

/** An ISO timestamp (UTC) as "YYYY-MM-DD HH:mm" in Asia/Colombo. */
export function colomboDateTime(iso: string | null | undefined): string {
  if (!iso) return '';
  const d = new Date(iso);
  if (isNaN(d.getTime())) return '';
  const p = parts(d, true);
  return `${p['year']}-${p['month']}-${p['day']} ${p['hour']}:${p['minute']}`;
}
