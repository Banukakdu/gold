import { Account } from './models';

/** What the warning needs of an account (an Account, or the account of a transaction row). */
export type ReconciledAccount = Pick<Account, 'name'> & { type?: string; last_reconciled_date?: string | null };

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
export function lateEntryWarning(a: ReconciledAccount | null | undefined, date: string | null | undefined): string {
  if (!a || a.type !== 'bank' || !a.last_reconciled_date || !date || date > a.last_reconciled_date) return '';
  return `${a.name} is reconciled to ${dayLabel(a.last_reconciled_date)}. This entry will be a late entry for that statement.`;
}

/**
 * Every bank account a posting touches (both sides of a transfer, allocation or reversal): one warning per reconciled
 * account dated on or before its last statement, the same account once. Accounts without `last_reconciled_date` (not
 * bank, never reconciled, or another branch's account the user may not see) give none.
 */
export function lateEntryWarnings(accounts: (ReconciledAccount | null | undefined)[], date: string | null | undefined): string[] {
  return [...new Set(accounts.map(a => lateEntryWarning(a, date)).filter(Boolean))];
}
