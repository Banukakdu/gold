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
  branch?: { id: number; code: string; name: string };
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
  category?: { name: string; parent?: { name: string } };
  account?: { name: string };
  from_account?: { name: string };
  to_account?: { name: string };
  creator?: { id: number; name: string };
  creator_tag?: string | null;
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
