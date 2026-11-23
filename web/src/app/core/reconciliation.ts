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

// ------------------------------------------------------------------ AG-033d "Checked by" (docs/specs/reconciliation-checked-by.md)

export interface CheckPerson { id: number; name: string; tag: string | null }
export type CheckStatus = 'checked' | 'recheck' | 'not_checked' | 'cancelled' | null;
/** The active check as S6 rows and S1 History items carry it. */
export interface CheckSummary {
  id: number; checked_by: CheckPerson | null; checked_at: string; compared_with: 'bank_statement' | 'internet_banking';
  after_close: string | null; late_since_check: number | null;
}
/** Section 5 `Check` (S3 reprint, POST answers). */
export interface StatementCheck {
  id: number; bank_statement_id: number; account_id: number; branch: { id: number; code: string };
  checked_by: CheckPerson | null; checked_at: string; compared_with: 'bank_statement' | 'internet_banking'; note: string | null; confirmed: true;
  changed_at_check: boolean; late_count_at_check: number; checker_recorded_count: number; checker_uncleared_count: number; entries_count: number;
  after_close: 'close' | null; status: 'active' | 'superseded' | 'withdrawn' | 'cancelled';
  ended_by: CheckPerson | null; ended_at: string | null; end_reason: string | null; superseded_by_id: number | null;
}
export interface CheckActions { can_check: boolean; can_withdraw: boolean; blocked_reason: string | null }

export const COMPARED_LABELS: Record<string, string> = { bank_statement: "the bank's statement", internet_banking: 'internet banking' };

/** "2026-12-10 14:05" in Asia/Colombo (the API's ReconciliationChecks::at). */
export function colomboMinute(iso: string): string {
  const parts = new Intl.DateTimeFormat('en-CA', { timeZone: 'Asia/Colombo', year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit', hourCycle: 'h23' })
    .formatToParts(new Date(iso));
  const p = (t: string) => parts.find(x => x.type === t)?.value ?? '';
  return `${p('year')}-${p('month')}-${p('day')} ${p('hour')}:${p('minute')}`;
}

export function personName(p: CheckPerson | null | undefined): string {
  return p ? `${p.name}${p.tag ? ` (${p.tag})` : ''}` : '-';
}

/** S6 / S1 "Checked" text; '' for an undone statement that never had a check. */
export function checkStatusText(status: CheckStatus | undefined, c: CheckSummary | null | undefined): string {
  const date = c ? colomboMinute(c.checked_at).slice(0, 10) : '';
  switch (status) {
    case 'checked': return `Checked by ${personName(c?.checked_by)}, ${date}`;
    case 'recheck': return `Re-check needed (${c?.late_since_check ?? 0} since check)`;
    case 'not_checked': return 'Not checked';
    case 'cancelled': return 'Check cancelled';
    default: return '';
  }
}

/** S3 tags of the active check (without the leading " - "). */
export function checkTags(c: StatementCheck): string[] {
  const tags: string[] = [];
  if (c.after_close) tags.push('checked after close');
  if (c.checker_recorded_count > 0) {
    tags.push(`the checker recorded ${c.checker_recorded_count} of the ${c.entries_count} entries on this statement`
      + (c.checker_uncleared_count > 0 ? `, ${c.checker_uncleared_count} of them uncleared` : ''));
  }
  if (c.changed_at_check) tags.push('changed since completion when checked');
  return tags;
}

/** S3 history line of an ended check. */
export function checkHistoryText(c: StatementCheck): string {
  const checked = `checked by ${personName(c.checked_by)} on ${colomboMinute(c.checked_at)}`;
  const ended = `${personName(c.ended_by)} on ${c.ended_at ? colomboMinute(c.ended_at) : '-'}`;
  switch (c.status) {
    case 'superseded': return `Superseded: ${checked}`;
    case 'withdrawn': return `Withdrawn: ${checked}; withdrawn by ${ended}: ${c.end_reason ?? ''}`;
    case 'cancelled': return `Cancelled: ${checked}; statement undone by ${ended}`;
    default: return checked;
  }
}

/** "Re-check needed: 1 entry was recorded or voided after the check." */
export function recheckText(n: number): string {
  return `Re-check needed: ${n === 1 ? '1 entry was' : `${n} entries were`} recorded or voided after the check.`;
}
