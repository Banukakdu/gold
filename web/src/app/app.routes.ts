import { Routes } from '@angular/router';
import { authGuard, permissionGuard, superAdminGuard } from './core/guards';
import { ShellComponent } from './layout/shell.component';

export const routes: Routes = [
  { path: 'login', loadComponent: () => import('./features/auth/login.component').then(m => m.LoginComponent) },
  {
    path: '',
    component: ShellComponent,
    canActivate: [authGuard],
    children: [
      { path: '', pathMatch: 'full', redirectTo: 'dashboard' },
      { path: 'dashboard', loadComponent: () => import('./features/dashboard/dashboard.component').then(m => m.DashboardComponent) },
      { path: 'transactions', canActivate: [permissionGuard('transaction.view')],
        loadComponent: () => import('./features/transactions/transaction-list.component').then(m => m.TransactionListComponent) },
      { path: 'transactions/new/:kind', loadComponent: () => import('./features/transactions/entry-form.component').then(m => m.EntryFormComponent) },
      { path: 'transfers/new', canActivate: [permissionGuard('transfer.create')],
        loadComponent: () => import('./features/transfers/transfer-form.component').then(m => m.TransferFormComponent) },
      { path: 'allocations/new', canActivate: [superAdminGuard],
        loadComponent: () => import('./features/transfers/allocation-form.component').then(m => m.AllocationFormComponent) },
      { path: 'accounts', canActivate: [permissionGuard('account.view')],
        loadComponent: () => import('./features/accounts/account-list.component').then(m => m.AccountListComponent) },
      { path: 'accounts/:id/statement', canActivate: [permissionGuard('account.view')],
        loadComponent: () => import('./features/accounts/statement.component').then(m => m.StatementComponent) },
      { path: 'branches', canActivate: [superAdminGuard],
        loadComponent: () => import('./features/branches/branch-list.component').then(m => m.BranchListComponent) },
    ],
  },
  { path: '**', redirectTo: '' },
];
