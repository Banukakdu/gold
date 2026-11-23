import { Component, OnInit, computed, inject, signal } from '@angular/core';
import { toSignal } from '@angular/core/rxjs-interop';
import { filter, map } from 'rxjs';
import { NavigationEnd, Router, RouterLink, RouterLinkActive, RouterOutlet } from '@angular/router';
import { AuthService } from '../core/auth.service';
import { SettingsService } from '../core/settings.service';
import { ToastService } from '../core/toast.service';
import { NotificationBellComponent } from './notification-bell.component';

@Component({
  selector: 'ag-shell',
  standalone: true,
  imports: [RouterOutlet, RouterLink, RouterLinkActive, NotificationBellComponent],
  template: `
    <div class="shell">
      <aside class="side">
        <!-- S2: the company's logo and name; the product name moves to the footer -->
        <div class="company">
          @if (settings.logoUrl(); as logo) { <img [src]="logo" [alt]="settings.companyName()" /> }
          <span>{{ settings.companyName() }}</span>
        </div>
        <!-- AG-005 F4: while two-factor set-up is required, the menu is hidden (only "Log out" stays) -->
        @if (!required()) {
        <nav>
          <a routerLink="/dashboard" routerLinkActive="on">Dashboard</a>
          @if (auth.can('transaction.view')) { <a routerLink="/transactions" routerLinkActive="on">Transactions</a> }
          @if (!auth.isAllBranches() && auth.can('income.create')) { <a routerLink="/transactions/new/income" routerLinkActive="on">Record income</a> }
          @if (!auth.isAllBranches() && auth.can('expense.create')) { <a routerLink="/transactions/new/expense" routerLinkActive="on">Record expense</a> }
          @if (auth.can('expense.create') || auth.can('expense.edit')) { <a routerLink="/expense-templates" routerLinkActive="on" class="subnav">Expense templates</a> }
          @if (auth.can('transfer.create')) { <a routerLink="/transfers/new" routerLinkActive="on">Transfer money</a> }
          @if (auth.isSuperAdmin()) { <a routerLink="/allocations/new" routerLinkActive="on">Fund a branch</a> }
          @if (auth.can('account.view')) { <a routerLink="/accounts" routerLinkActive="on">Accounts</a> }
          @if (auth.can('account.reconcile')) { <a routerLink="/accounts/reconcile" routerLinkActive="on" class="subnav">Bank reconciliation</a> }
          @if (auth.can('account.view')) { <a routerLink="/chart-of-accounts" routerLinkActive="on" class="subnav">Chart of accounts</a> }
          @if (auth.can('category.manage')) { <a routerLink="/categories" routerLinkActive="on" class="subnav">Categories</a> }
          @if (auth.can('report.view')) { <a routerLink="/reports" routerLinkActive="on">Reports</a> }
          @if (auth.isSuperAdmin() || auth.can('report.export')) { <a routerLink="/exports" routerLinkActive="on" class="subnav">Exports</a> }
          @if (auth.isSuperAdmin()) { <a routerLink="/scheduled-reports" routerLinkActive="on" class="subnav">Scheduled reports</a> }
          @if (auth.can('audit.view')) { <a routerLink="/audit-log" routerLinkActive="on">Audit log</a> }
          @if (auth.isSuperAdmin()) { <a routerLink="/period-locks" routerLinkActive="on">Period locks</a> }
          @if (auth.isSuperAdmin()) { <a routerLink="/year-end" routerLinkActive="on">Year-end</a> }
          @if (auth.isSuperAdmin()) { <a routerLink="/settings" routerLinkActive="on">Settings</a> }
          @if (auth.isSuperAdmin()) { <a routerLink="/backups" routerLinkActive="on">Backups</a> }
          @if (auth.isSuperAdmin()) { <a routerLink="/branches" routerLinkActive="on">Branches</a> }
          @if (auth.can('user.view')) { <a routerLink="/users" routerLinkActive="on">Users</a> }
          @if (auth.can('role.view')) { <a routerLink="/roles" routerLinkActive="on">Roles</a> }
        </nav>
        }
        <div class="side-foot"><span class="brand">Account<span>gold</span></span></div>
      </aside>
      <div class="main">
        <header class="top">
          @if (!required()) {
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
          <!-- AG-094 S1: before .who; at phone width it stays next to the branch switcher -->
          <ag-notification-bell />
          }
          <div class="who">
            @if (required()) {
              <span>{{ auth.me()?.name }}</span>
              <button class="ghost" (click)="auth.logout()">Log out</button>
            } @else {
              <!-- AG-005 S5: the user name is a menu with "Security" and "Log out" -->
              <div class="tf-menu">
                <button type="button" class="link tf-menu-btn" aria-haspopup="menu" [attr.aria-expanded]="menuOpen()" (click)="menuOpen.set(!menuOpen())">
                  {{ auth.me()?.name }} ▾</button>
                @if (menuOpen()) {
                  <div class="tf-menu-list" role="menu">
                    <a role="menuitem" routerLink="/account/security" (click)="menuOpen.set(false)">Security</a>
                    <button type="button" role="menuitem" class="link" (click)="menuOpen.set(false); auth.logout()">Log out</button>
                  </div>
                }
              </div>
              @if (auth.isSuperAdmin()) { <span class="tag">Super Admin</span> }
              <small>{{ auth.isSuperAdmin() ? '' : auth.activeBranch()?.roles?.join(', ') }}</small>
            }
          </div>
        </header>
        <main class="content">
          <!-- Re-create the page whenever the active branch changes so it refetches its data (AG-088: except the
               scheduled reports pages, which carry their own branches and keep their state) -->
          @for (k of [outletKey()]; track k) { <router-outlet /> }
        </main>
        <div class="toast" role="status" aria-live="polite">@if (toast.text()) { <span [class.toast-warn]="toast.kind() === 'warning'">{{ toast.text() }}@if (toast.action(); as a) { <button type="button" class="toast-action" (click)="toast.runAction()">{{ a.label }}</button> }</span> }</div>
      </div>
    </div>
  `,
})
export class ShellComponent implements OnInit {
  auth = inject(AuthService);
  settings = inject(SettingsService);
  toast = inject(ToastService);
  private router = inject(Router);
  private url = toSignal(this.router.events.pipe(filter((e): e is NavigationEnd => e instanceof NavigationEnd), map(e => e.urlAfterRedirects)),
    { initialValue: this.router.url });
  /** The outlet is keyed on the active branch; AG-088: the scheduled reports pages are not reloaded by a branch switch. */
  /** AG-005 F4 / S5: /account/security?required=1 shows no menu, no branch switcher and no bell. */
  required = computed(() => /^\/account\/security\?(.*&)?required=1(&|$)/.test(this.url()));
  menuOpen = signal(false);
  outletKey = computed(() => (/^\/scheduled-reports(\/|\?|$)/.test(this.url()) ? 'scheduled-reports' : this.auth.activeBranchId()));

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
    } else if (/^\/accounts\/(\d+\/)?reconcile/.test(this.router.url) && !this.auth.can('account.reconcile')) {
      // AG-033: account.reconcile is per branch; leave if the new branch does not grant it.
      this.router.navigateByUrl('/dashboard');
    } else if (this.router.url.startsWith('/audit-log') && !this.auth.can('audit.view')) {
      // audit.view is per branch: leave if the new branch does not grant it.
      this.router.navigateByUrl('/dashboard');
    } else if (this.router.url.startsWith('/expense-templates') && !this.auth.can('expense.create') && !this.auth.can('expense.edit')) {
      // AG-066: the template keys are per branch; leave if the new branch grants neither.
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
