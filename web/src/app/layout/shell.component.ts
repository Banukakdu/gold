import { Component, OnInit, inject } from '@angular/core';
import { Router, RouterLink, RouterLinkActive, RouterOutlet } from '@angular/router';
import { AuthService } from '../core/auth.service';
import { SettingsService } from '../core/settings.service';

@Component({
  selector: 'ag-shell',
  standalone: true,
  imports: [RouterOutlet, RouterLink, RouterLinkActive],
  template: `
    <div class="shell">
      <aside class="side">
        <!-- S2: the company's logo and name; the product name moves to the footer -->
        <div class="company">
          @if (settings.logoUrl(); as logo) { <img [src]="logo" [alt]="settings.companyName()" /> }
          <span>{{ settings.companyName() }}</span>
        </div>
        <nav>
          <a routerLink="/dashboard" routerLinkActive="on">Dashboard</a>
          @if (auth.can('transaction.view')) { <a routerLink="/transactions" routerLinkActive="on">Transactions</a> }
          @if (!auth.isAllBranches() && auth.can('income.create')) { <a routerLink="/transactions/new/income" routerLinkActive="on">Record income</a> }
          @if (!auth.isAllBranches() && auth.can('expense.create')) { <a routerLink="/transactions/new/expense" routerLinkActive="on">Record expense</a> }
          @if (auth.can('transfer.create')) { <a routerLink="/transfers/new" routerLinkActive="on">Transfer money</a> }
          @if (auth.isSuperAdmin()) { <a routerLink="/allocations/new" routerLinkActive="on">Fund a branch</a> }
          @if (auth.can('account.view')) { <a routerLink="/accounts" routerLinkActive="on">Accounts</a> }
          @if (auth.can('account.view')) { <a routerLink="/chart-of-accounts" routerLinkActive="on" class="subnav">Chart of accounts</a> }
          @if (auth.can('category.manage')) { <a routerLink="/categories" routerLinkActive="on" class="subnav">Categories</a> }
          @if (auth.can('report.view')) { <a routerLink="/reports" routerLinkActive="on">Reports</a> }
          @if (auth.can('audit.view')) { <a routerLink="/audit-log" routerLinkActive="on">Audit log</a> }
          @if (auth.isSuperAdmin()) { <a routerLink="/period-locks" routerLinkActive="on">Period locks</a> }
          @if (auth.isSuperAdmin()) { <a routerLink="/year-end" routerLinkActive="on">Year-end</a> }
          @if (auth.isSuperAdmin()) { <a routerLink="/settings" routerLinkActive="on">Settings</a> }
          @if (auth.isSuperAdmin()) { <a routerLink="/branches" routerLinkActive="on">Branches</a> }
          @if (auth.can('user.view')) { <a routerLink="/users" routerLinkActive="on">Users</a> }
          @if (auth.can('role.view')) { <a routerLink="/roles" routerLinkActive="on">Roles</a> }
        </nav>
        <div class="side-foot"><span class="brand">Account<span>gold</span></span></div>
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
export class ShellComponent implements OnInit {
  auth = inject(AuthService);
  settings = inject(SettingsService);
  private router = inject(Router);

  ngOnInit() { this.settings.load(); }

  switch(id: string) {
    // AG-065 S1: the list keeps its URL filters on a branch switch; branches[] applies in All branches mode only, so it
    // is dropped (with the page) before the switch, and the list reloads for the new branch.
    if (this.router.url.startsWith('/transactions?') && id !== 'all') {
      const tree = this.router.parseUrl(this.router.url);
      if (tree.queryParams['branches'] !== undefined) {
        const { branches: _b, page: _p, ...rest } = tree.queryParams;
        this.router.navigate(['/transactions'], { queryParams: rest }).then(() => this.auth.switchBranch(id));
        return;
      }
    }
    this.auth.switchBranch(id);
    // Entry forms and statements need a single branch; send the user back to the dashboard.
    if (this.router.url.includes('/new/') || this.router.url.includes('/statement')) {
      this.router.navigateByUrl('/dashboard');
    } else if (this.router.url.startsWith('/audit-log') && !this.auth.can('audit.view')) {
      // audit.view is per branch: leave if the new branch does not grant it.
      this.router.navigateByUrl('/dashboard');
    } else if (this.router.url.startsWith('/categories') && !this.auth.can('category.manage')) {
      // category.manage is per branch: leave if the new branch does not grant it.
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
