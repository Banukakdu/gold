import { Component, inject } from '@angular/core';
import { Router, RouterLink, RouterLinkActive, RouterOutlet } from '@angular/router';
import { AuthService } from '../core/auth.service';

@Component({
  selector: 'ag-shell',
  standalone: true,
  imports: [RouterOutlet, RouterLink, RouterLinkActive],
  template: `
    <div class="shell">
      <aside class="side">
        <div class="brand">Account<span>gold</span></div>
        <nav>
          <a routerLink="/dashboard" routerLinkActive="on">Dashboard</a>
          @if (auth.can('transaction.view')) { <a routerLink="/transactions" routerLinkActive="on">Transactions</a> }
          @if (!auth.isAllBranches() && auth.can('income.create')) { <a routerLink="/transactions/new/income" routerLinkActive="on">Record income</a> }
          @if (!auth.isAllBranches() && auth.can('expense.create')) { <a routerLink="/transactions/new/expense" routerLinkActive="on">Record expense</a> }
          @if (auth.can('transfer.create')) { <a routerLink="/transfers/new" routerLinkActive="on">Transfer money</a> }
          @if (auth.isSuperAdmin()) { <a routerLink="/allocations/new" routerLinkActive="on">Fund a branch</a> }
          @if (auth.can('account.view')) { <a routerLink="/accounts" routerLinkActive="on">Accounts</a> }
          @if (auth.can('report.view')) { <a routerLink="/reports" routerLinkActive="on">Reports</a> }
          @if (auth.isSuperAdmin()) { <a routerLink="/branches" routerLinkActive="on">Branches</a> }
          @if (auth.can('user.view')) { <a routerLink="/users" routerLinkActive="on">Users</a> }
          @if (auth.can('role.view')) { <a routerLink="/roles" routerLinkActive="on">Roles</a> }
        </nav>
      </aside>
      <div class="main">
        <header class="top">
          <label class="switcher">
            <span>Branch</span>
            <!-- [selected] per option: the options render after /me loads, so [value] on the select alone is lost on reload -->
            <select (change)="switch($any($event.target).value)">
              @if (auth.isSuperAdmin()) { <option value="all" [selected]="auth.activeBranchId() === 'all'">All branches</option> }
              @for (b of auth.me()?.branches; track b.id) {
                <option [value]="b.id" [selected]="auth.activeBranchId() === '' + b.id">{{ b.code }} · {{ b.name }}</option>
              }
            </select>
          </label>
          <div class="who">
            <span>{{ auth.me()?.name }} @if (auth.isSuperAdmin()) { <span class="tag">Super Admin</span> }</span>
            <small>{{ auth.isSuperAdmin() ? '' : auth.activeBranch()?.roles?.join(', ') }}</small>
            <button class="ghost" (click)="auth.logout()">Log out</button>
          </div>
        </header>
        <main class="content">
          <!-- Re-create the page whenever the active branch changes so it refetches its data -->
          @for (k of [auth.activeBranchId()]; track k) { <router-outlet /> }
        </main>
      </div>
    </div>
  `,
})
export class ShellComponent {
  auth = inject(AuthService);
  private router = inject(Router);

  switch(id: string) {
    this.auth.switchBranch(id);
    // Entry forms and statements need a single branch; send the user back to the dashboard.
    if (this.router.url.includes('/new/') || this.router.url.includes('/statement')) {
      this.router.navigateByUrl('/dashboard');
    } else if (this.router.url.startsWith('/reports') && !this.auth.can('report.view')) {
      // Reports are per branch permission (report.view); leave if the new branch does not grant it.
      this.router.navigateByUrl('/dashboard');
    } else if (/^\/(users|roles)\/./.test(this.router.url)) {
      // User/role pages belong to one branch; go back to the list for the new branch.
      this.router.navigateByUrl(this.router.url.startsWith('/users') ? '/users' : '/roles');
    }
  }
}
