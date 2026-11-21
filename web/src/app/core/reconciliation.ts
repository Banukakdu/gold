import { Account } from './models';

const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

/** "2026-10-31" -> "31 Oct 2026" (the form the API's reconciliation messages use). */
export function dayLabel(ymd: string | null | undefined): string {
  if (!ymd) return '';
  const [y, m, d] = ymd.slice(0, 10).split('-').map(Number);
  return `${d} ${MONTHS[m - 1]} ${y}`;
}

/**
 * AG-033 review finding 10: a posting to a bank account dated on or before its last completed reconciliation becomes a
 * late entry for that statement. Non-blocking warning text, or '' when none applies.
 */
export function lateEntryWarning(a: Account | null | undefined, date: string | null | undefined): string {
  if (!a || a.type !== 'bank' || !a.last_reconciled_date || !date || date > a.last_reconciled_date) return '';
  return `${a.name} is reconciled to ${dayLabel(a.last_reconciled_date)}. This entry will be a late entry for that statement.`;
}
