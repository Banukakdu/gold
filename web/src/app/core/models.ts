export interface BranchAccess {
  id: number;
  code: string;
  name: string;
  is_main: boolean;
  roles: string[];
  permissions: string[]; // ['*'] for Super Admin
}

export interface Me {
  id: number;
  name: string;
  email: string;
  is_super_admin: boolean;
  branches: BranchAccess[];
}

export interface Account {
  id: number;
  branch_id: number;
  name: string;
  type: 'cash' | 'bank' | 'wallet';
  current_balance: string;
  is_main_account?: boolean;
  status?: string;
  branch?: { id: number; code: string; name: string; is_main?: boolean };
  code?: string | null;
  /** AG-033 SHOULD 10: bank accounts only, the last completed reconciliation (YYYY-MM-DD) or null. */
  last_reconciled_date?: string | null;
}

export interface Category {
  id: number;
  name: string;
  type: 'income' | 'expense';
  children?: Category[];
}

export interface Transaction {
  id: number;
  ref_no: string;
  type: string;
  txn_date: string;
  amount: string;
  status: string;
  description?: string;
  payee_payer?: string;
  branch?: { code: string; name: string };
  category?: { name: string; is_active?: boolean; parent?: { name: string; is_active?: boolean } | null };
  account?: { name: string };
  from_account?: { name: string; branch?: { code: string } };
  to_account?: { name: string; branch?: { code: string } };
  creator?: { id: number; name: string };
  creator_tag?: string | null;
  entries_count?: number;
  /** Controls S4: a void from before contra entries existed (no ledger lines). */
  legacy_void_note?: string;
  /** AG-062: active attachments, and removed ones (accounting review finding 6). */
  attachments_count?: number;
  attachments_removed_count?: number;
  /** AG-065 R10: false on an incoming row in branch mode (read-only: actions follow the sending branch). */
  in_active_branch?: boolean;
  /** 'in' when the row was received from a branch outside the scope set. */
  direction?: 'in' | null;
  reversal_of_id?: number | null;
  reversal_of_ref?: string | null;
  reversed_by_ref?: string | null;
  reversed_by?: { id: number; ref_no: string } | null;
  /** AG-052: null = no receipt needed. */
  receipt_status?: string | null;
  received_on?: string | null;
  receipt_note?: string | null;
  receipt_by?: { id: number; name: string } | null;
  receipt_at?: string | null;
  /** AG-051: null = no approval needed. */
  approval_status?: string | null;
  requested_date?: string | null;
  approval_note?: string | null;
  decided_by?: { id: number; name: string } | null;
  decided_at?: string | null;
}

export interface Page<T> {
  data: T[];
  current_page: number;
  last_page: number;
  total: number;
}

export interface Membership {
  branch_id: number;
  branch_code: string;
  branch_name: string;
  role_id: number | null;
  role_name: string | null;
  role_status: 'active' | 'inactive' | null;
}

export interface UserRow {
  id: number;
  name: string;
  email: string;
  phone?: string | null;
  status: 'active' | 'inactive';
  is_super_admin: boolean;
  last_login_at: string | null;
  memberships: Membership[];
  roles_in_branch?: string[];
  /** Detail only: why the actor cannot change profile/status/invitation (B5, B6, B2), or null. */
  profile_locked_reason?: string | null;
}

export interface RoleRow {
  id: number;
  name: string;
  branch_id: number | null;
  is_template: boolean;
  status: 'active' | 'inactive';
  users_count: number;
  permissions: string[];
}

/** Module order of the permission catalogue (prefix before the dot). */
export const PERMISSION_MODULES = ['branch', 'user', 'role', 'account', 'transfer', 'transaction', 'income', 'expense', 'category', 'report', 'audit'];

/** Field errors of a Laravel 422 response, keyed by field. */
export function fieldErrors(err: unknown): Record<string, string> {
  const errors = (err as { error?: { errors?: Record<string, string[]> } })?.error?.errors ?? {};
  return Object.fromEntries(Object.entries(errors).map(([k, v]) => [k, v.join(' ')]));
}
