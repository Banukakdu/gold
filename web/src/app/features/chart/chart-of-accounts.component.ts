import { Component, OnInit, computed, inject, signal } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { FormsModule } from '@angular/forms';
import { RouterLink } from '@angular/router';
import { AuthService } from '../../core/auth.service';
import { errorText } from '../../core/api.interceptor';
import { ReportBranch } from '../reports/report-common';

interface ChartAccount {
  id: number; code: string | null; name: string; class: string; type: string; origin: 'accounts' | 'category' | 'system';
  category_id: number | null; parent_category_id: number | null; counterparty_branch: { id: number; code: string } | null; status: string;
}
interface ChartGroup { group: string; label: string; accounts: ChartAccount[] }
interface Chart { branch: { id: number; code: string; name: string; status: string; is_main: boolean }; groups: ChartGroup[] }

const TYPE_NAMES: Record<string, string> = {
  cash: 'Cash', bank: 'Bank', wallet: 'Wallet', head_office: 'Head office current account', branch_current: 'Branch current account',
  owner_capital: "Owner's capital", retained_earnings: 'Retained earnings', category: 'Category',
};
const ORIGIN_NAMES: Record<string, string> = { accounts: 'Accounts', category: 'Category', system: 'System' };
const MONEY = ['cash', 'bank', 'wallet'];

/** Double-entry S1: the chart of accounts of one branch. Structure only, no balances. */
@Component({
  standalone: true,
  imports: [FormsModule, RouterLink],
  template: `
    <h1>Chart of accounts @if (c(); as c) { <small class="muted">{{ c.branch.code }} · {{ c.branch.name }}</small> }</h1>
    @if (auth.isAllBranches()) {
      <form class="filters">
        <label>Branch
          <select name="branch" [ngModel]="branchId()" (ngModelChange)="pick($event)">
            @for (b of branches(); track b.id) {
              <option [ngValue]="b.id">{{ b.code }} · {{ b.name }}{{ b.status !== 'active' ? ' (inactive)' : '' }}</option>
            }
          </select>
        </label>
      </form>
    }
    <p class="muted">Cash, bank and wallet accounts are added under Accounts. Income and expense accounts follow Categories.
      Inter-branch and capital accounts are created by the system.
      At year end, each branch's result is moved to its Head office current account and to Retained earnings in MAIN.
      @if (auth.can('account.manage') && !auth.isAllBranches()) { <a routerLink="/accounts">Add account</a> }
      @if (auth.can('category.manage')) { <a routerLink="/categories">Manage categories</a> }</p>
    @if (msg()) { <div class="alert">{{ msg() }}</div> }
    @if (loading()) { <p class="muted">Loading...</p> }
    @if (!loading() && c(); as c) {
      @for (g of c.groups; track g.group) {
        <div class="card section">
          <h2>{{ g.label }}</h2>
          @if (!g.accounts.length) { <p class="muted">No accounts</p> }
          @else {
            <table class="list">
              <thead><tr><th>Code</th><th>Name</th><th>Type</th><th>Created from</th><th>Status</th><th></th></tr></thead>
              <tbody>
                @for (a of tree(g); track a.id) {
                  <tr>
                    <td class="mono">{{ a.code }}</td>
                    <td [style.padding-left.px]="isSub(a) ? 28 : null">{{ a.name }}</td>
                    <td>{{ typeName(a.type) }}</td>
                    <td>{{ originName(a.origin) }}</td>
                    <td>@if (a.status === 'active') { Active } @else { <span class="tag" data-s="inactive">{{ statusName(a.status) }}</span> }</td>
                    <td>@if (canLedger(a)) { <a [routerLink]="['/accounts', a.id, 'statement']">Ledger</a> }</td>
                  </tr>
                }
              </tbody>
            </table>
          }
        </div>
      }
    }
  `,
})
export class ChartOfAccountsComponent implements OnInit {
  auth = inject(AuthService);
  private http = inject(HttpClient);
  branches = signal<ReportBranch[]>([]);
  branchId = signal<number | null>(null);
  c = signal<Chart | null>(null);
  loading = signal(false);
  msg = signal('');
  private subParents = computed(() => new Set((this.c()?.groups ?? []).flatMap(g => g.accounts).filter(a => a.parent_category_id).map(a => a.id)));

  ngOnInit() {
    if (!this.auth.isAllBranches()) { this.load(); return; }
    this.http.get<(ReportBranch & { is_main?: boolean })[]>('/api/v1/branches').subscribe({
      next: b => {
        this.branches.set(b);
        this.branchId.set((b.find(x => x.is_main) ?? b[0])?.id ?? null);
        this.load();
      },
      error: e => this.msg.set(errorText(e)),
    });
  }

  pick(id: number) { this.branchId.set(Number(id)); this.load(); }

  load() {
    const params: Record<string, number> = {};
    if (this.auth.isAllBranches()) {
      if (!this.branchId()) return;
      params['branch_id'] = this.branchId()!;
    }
    this.loading.set(true);
    this.http.get<Chart>('/api/v1/chart-of-accounts', { params }).subscribe({
      next: c => { this.c.set(c); this.msg.set(''); this.loading.set(false); },
      error: e => { this.c.set(null); this.msg.set(errorText(e)); this.loading.set(false); },
    });
  }

  /** Category accounts as a tree: each parent category's account, then its sub-categories (by code). */
  tree(g: ChartGroup): ChartAccount[] {
    if (g.group !== 'income' && g.group !== 'expense') return g.accounts;
    const parents = g.accounts.filter(a => !a.parent_category_id);
    const out: ChartAccount[] = [];
    for (const p of parents) {
      out.push(p);
      out.push(...g.accounts.filter(a => a.parent_category_id && a.parent_category_id === p.category_id));
    }
    // Sub-categories whose parent has no account here (never expected) are still listed.
    out.push(...g.accounts.filter(a => !out.includes(a)));
    return out;
  }

  isSub(a: ChartAccount) { return this.subParents().has(a.id); }
  canLedger(a: ChartAccount) { return MONEY.includes(a.type) || this.auth.can('report.view'); }
  typeName(t: string) { return TYPE_NAMES[t] ?? t; }
  originName(o: string) { return ORIGIN_NAMES[o] ?? o; }
  statusName(s: string) { return s.charAt(0).toUpperCase() + s.slice(1); }
}
