import { Account } from './models';

/**
 * What the warning needs of an account (an Account, or the account of a transaction row). Transaction rows carry no
 * `type`; their `last_reconciled_date` is only ever set on bank accounts the user may see.
 */
export type ReconciledAccount = Pick<Account, 'name'> & {
  id?: number; type?: string; last_reconciled_date?: string | null; branch?: { code: string } | null;
};

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
export function lateEntryWarning(a: ReconciledAccount | null | undefined, date: string | null | undefined, label?: string): string {
  if (!a || (a.type && a.type !== 'bank') || !a.last_reconciled_date || !date || date > a.last_reconciled_date) return '';
  return `${label ?? a.name} is reconciled to ${dayLabel(a.last_reconciled_date)}. This entry will be a late entry for that statement.`;
}

/**
 * Every bank account a posting touches (both sides of a transfer, allocation or reversal): one warning per reconciled
 * account dated on or before its last statement. The same account (by id, else the same object) counts once; two
 * different accounts with the same name are told apart by their branch code when it is known. Accounts without
 * `last_reconciled_date` (not bank, never reconciled, or another branch's account the user may not see) give none.
 */
export function lateEntryWarnings(accounts: (ReconciledAccount | null | undefined)[], date: string | null | undefined): string[] {
  const seen = new Set<unknown>();
  const list = accounts.filter((a): a is ReconciledAccount => {
    if (!a || seen.has(a.id ?? a)) return false;
    seen.add(a.id ?? a);
    return true;
  });
  const shared = (a: ReconciledAccount) => list.some(b => b !== a && b.name === a.name);
  return list.map(a => lateEntryWarning(a, date, shared(a) && a.branch?.code ? `${a.branch.code} ${a.name}` : undefined)).filter(Boolean);
}
