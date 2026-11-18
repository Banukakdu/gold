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
}

export interface Page<T> {
  data: T[];
  current_page: number;
  last_page: number;
  total: number;
}
