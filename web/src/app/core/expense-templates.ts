import { MoneyPipe } from './money.pipe';

/** AG-066 (docs/specs/recurring-expenses.md): one used expense in a template's period (R9 / R6). */
export interface TemplateUse { id: number; ref_no: string | null; date: string; status: string; status_label?: string }

/** GET /expense-templates row (section 5). */
export interface ExpenseTemplate {
  id: number; branch: { id: number; code: string | null }; name: string; version: number;
  account: { id: number; name: string | null; status: string | null };
  category: { id: number; parent_id: number | null; path: string; active: boolean };
  amount: string | null; payee_payer: string | null; payment_method: string | null; description: string | null;
  frequency: 'monthly' | 'quarterly' | 'yearly' | 'none';
  archived_at: string | null; archived_by: { id: number; name: string } | null; archive_reason: string | null;
  created_by: { id: number; name: string; inactive?: boolean } | null;
  this_period: { label: string; from?: string; to?: string; transactions: TemplateUse[] } | null;
  last_used_on: string | null;
  changed_since_last_use?: boolean;
  last_changed?: { on: string; by: { id: number; name: string } | null } | null;
  can_use: boolean; can_edit: boolean;
}

export interface ExpenseTemplateList { threshold: string | null; archived_count?: number; data: ExpenseTemplate[] }

export const FREQUENCY_LABELS: Record<string, string> = {
  monthly: 'Monthly', quarterly: 'Quarterly', yearly: 'Yearly', none: 'No fixed period',
};

export const PAYMENT_METHOD_LABELS: Record<string, string> = {
  cash: 'Cash', bank_transfer: 'Bank transfer', cheque: 'Cheque', card: 'Card', online: 'Online',
};

/** Review SHOULD 7: the payment method a new template starts with, by the type of its account. */
export function defaultPaymentMethod(accountType: string | null | undefined): string {
  return accountType === 'bank' ? 'bank_transfer' : accountType === 'wallet' ? 'online' : 'cash';
}

/** "KDY-EXP-2026-000510 (Pending approval, 2026-11-20)" */
export function useText(u: TemplateUse): string {
  return `${u.ref_no ?? '#' + u.id} (${u.status_label ?? (u.status === 'pending' ? 'Pending approval' : u.status === 'posted' ? 'Posted' : u.status)}, ${u.date})`;
}

/** R7a / R11: what the use's audit row recorded, as the API returns it on the approval rows and the detail. */
export interface TemplateFacts {
  template?: { id: number; name: string | null; version: number | null } | null;
  expense_template?: { id: number; name: string; archived: boolean } | null;
  template_changes?: string[];
  template_values?: Record<string, string | number | null>;
  template_labels?: Record<string, string | null>;
  template_changed_since_last_use?: boolean;
  template_previous_version?: number | null;
  template_changed_on?: string | null;
  template_changed_by?: string | null;
  duplicate_confirmed?: boolean;
  duplicate_of?: string[];
  duplicate_period?: string | null;
  duplicate_entries?: TemplateUse[];
}

export interface TemplateLine { text: string; warn?: boolean }

const FIELD_LABELS: Record<string, string> = {
  account_id: 'Paid from', category_id: 'Category', amount: 'Amount', payee_payer: 'Paid to', payment_method: 'Payment method',
  description: 'Description',
};

const money = new MoneyPipe();

function fieldValue(field: string, f: TemplateFacts): string {
  const label = f.template_labels?.[field];
  if (label) return label;
  const v = f.template_values?.[field];
  if (v === null || v === undefined || v === '') return 'empty';
  if (field === 'amount') return money.transform(String(v));
  if (field === 'payment_method') return PAYMENT_METHOD_LABELS[String(v)] ?? String(v);
  return String(v);
}

/** "Amount (template 150,000.00), Paid to (template S. Fernando (landlord))" */
export function changesText(f: TemplateFacts): string {
  return (f.template_changes ?? []).map(c => `${FIELD_LABELS[c] ?? c} (template ${fieldValue(c, f)})`).join(', ');
}

/**
 * AG-066 R7a: the lines the approver sees for a held expense saved from a template (the dashboard row, the approve
 * dialog), from the request's audit row. Empty when the expense has no template.
 */
export function approvalTemplateLines(f: TemplateFacts | null | undefined): TemplateLine[] {
  const name = f?.template?.name ?? f?.expense_template?.name;
  if (!f || !name) return [];
  const lines: TemplateLine[] = [{ text: `From template ${name}` }];
  if (f.template_changes?.length) lines.push({ text: `Changed from template: ${changesText(f)}` });
  if (f.template_changed_since_last_use) {
    const version = f.template?.version;
    const from = f.template_previous_version ?? (version ? version - 1 : null);
    const detail = [from !== null && version ? `version ${from} -> ${version}` : '', f.template_changed_on ?? '', f.template_changed_by ? `by ${f.template_changed_by}` : '']
      .filter(Boolean).join(', ').replace(/, by /, ' by ');
    lines.push({ text: `Template changed since its last use${detail ? ` (${detail})` : ''}` });
  }
  if (f.duplicate_confirmed) {
    const refs = f.duplicate_entries?.length ? f.duplicate_entries.map(useText).join(', ') : (f.duplicate_of ?? []).join(', ');
    lines.push({ text: `Second use for ${f.duplicate_period ?? 'this period'}, confirmed by the requester: ${refs}`, warn: true });
  }
  return lines;
}
