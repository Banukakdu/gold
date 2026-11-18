import { Component, OnInit, inject, signal } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { FormsModule } from '@angular/forms';
import { ActivatedRoute, Router } from '@angular/router';
import { AuthService } from '../../core/auth.service';
import { MoneyPipe } from '../../core/money.pipe';
import { SettingsService } from '../../core/settings.service';
import {
  BranchPickerComponent, ExportButtonsComponent, FiscalPresetsComponent, ReportBranch, ReportHeadComponent, ReportMeta, ReportParams, SignaturesComponent,
  currentMonth, fieldErrors, readQuery, reportError, toHttpParams, writeQuery,
} from './report-common';

interface TbAccount { id: number; code: string | null; name: string; class: string; type: string; group: string; status: string }
interface Amounts { opening_dr: string; opening_cr: string; period_dr: string; period_cr: string; closing_dr: string; closing_cr: string }
interface Section { branch: ReportBranch; rows: (Amounts & { account: TbAccount })[]; totals: Amounts; balanced: boolean; difference: string }
interface ConsRow {
  key: string; label: string; group: string; by_branch: Record<string, string>; elimination: string | null; dr: string; cr: string;
}
/** Settings spec section 5: the fiscal-year meta of the trial balance. */
interface TbFiscalYear {
  start_year: number; label: string; start: string; end: string; closed: boolean; closed_at: string | null; closed_by: string | null;
  reopened: { at: string | null; by: string | null; reason: string | null } | null; reopen_count: number;
}
interface TrialBalance extends ReportMeta {
  view: 'branch' | 'consolidated';
  basis: string;
  fiscal_year: TbFiscalYear;
  earlier_unclosed: boolean;
  earlier_unclosed_label: string | null;
  closing_entries: boolean;
  balanced: boolean;
  sections?: Section[];
  rows?: ConsRow[];
  totals?: { by_branch: Record<string, string>; elimination: string; dr: string; cr: string };
  difference?: string;
}

export const TB_GROUPS: [string, string][] = [
  ['asset', 'Assets'], ['liability', 'Liabilities'], ['equity', 'Equity'], ['interbranch', 'Inter-branch (eliminated on consolidation)'],
  ['income', 'Income'], ['expense', 'Expenses'],
];
const CLASS_NAMES: Record<string, string> = { asset: 'Asset', liability: 'Liability', equity: 'Equity', income: 'Income', expense: 'Expense' };

/** Double-entry S2: trial balance by branch, or consolidated with inter-branch eliminations. */
@Component({
  standalone: true,
  imports: [FormsModule, MoneyPipe, BranchPickerComponent, ExportButtonsComponent, FiscalPresetsComponent, ReportHeadComponent, SignaturesComponent],
  template: `
    <h1>Trial Balance</h1>
    <form class="filters" (ngSubmit)="load()">
      <label>From <input type="date" name="from" [(ngModel)]="f.from" required /></label>
      <label>To <input type="date" name="to" [(ngModel)]="f.to" required /></label>
      <ag-fy-presets (pick)="preset($event)" />
      <ag-branch-picker [(selected)]="branches" />
      @if (auth.isAllBranches()) {
        <label>View
          <select name="view" [(ngModel)]="view">
            <option value="branch">By branch</option>
            <option value="consolidated">Consolidated</option>
          </select>
        </label>
      }
      @if (canPostClosing()) {
        <label><input type="checkbox" name="closing_entries" [(ngModel)]="closing" style="width:auto" /> Include closing entries</label>
      }
      <button type="submit">Show</button>
      <ag-export-buttons name="trial-balance" [params]="applied()" />
    </form>
    @if (errLine(); as e) { <p class="field-error">{{ e }}</p> }
    @if (msg()) { <div class="alert">{{ msg() }}</div> }

    @if (loading()) { <p class="muted">Loading...</p> }
    @if (!loading() && r(); as r) {
      <ag-report-head [meta]="r" [title]="r.closing_entries ? 'Trial balance — post-closing' : 'Trial balance — pre-closing'" [basis]="r.basis" />
      @if (r.fiscal_year; as fy) {
        @if (r.earlier_unclosed) { <div class="banner amber">{{ r.earlier_unclosed_label }} is not closed yet.</div> }
        @if (fy.closed) { <div class="banner grey">{{ fy.label }} closed on {{ settings.date(fy.closed_at) }} by {{ fy.closed_by }}.</div> }
        @if (fy.reopened; as ro) { <div class="banner red">{{ fy.label }} was reopened on {{ settings.date(ro.at) }} by {{ ro.by }}: {{ ro.reason }}</div> }
      }
      @if (!r.balanced) {
        <div class="alert">Out of balance by {{ outBy(r) | money }}. Run ledger:verify.</div>
      }

      @if (r.view === 'branch') {
        @for (s of r.sections ?? []; track s.branch.id) {
          <div class="card section">
            <h2>{{ s.branch.code }} · {{ s.branch.name }}
              @if (s.branch.status !== 'active') { <span class="tag" data-s="inactive">Inactive</span> }</h2>
            @if (!s.rows.length) { <p class="muted">No balances or movements.</p> }
            @else {
              <table class="list">
                <thead>
                  <tr><th>Code</th><th>Account</th><th>Class</th><th class="num">Opening Dr</th><th class="num">Opening Cr</th>
                    <th class="num">Period debits</th><th class="num">Period credits</th><th class="num">Closing Dr</th><th class="num">Closing Cr</th></tr>
                </thead>
                <tbody>
                  @for (g of groups; track g[0]) {
                    @if (rowsOf(s, g[0]); as rows) {
                      @if (rows.length) {
                        <tr class="sub"><td colspan="9"><b>{{ g[1] }}</b></td></tr>
                        @for (x of rows; track x.account.id ?? x.account.name) {
                          <tr>
                            <td class="mono">{{ x.account.code }}</td>
                            <td>{{ x.account.name }} @if (x.account.status !== 'active') { <span class="tag" data-s="inactive">{{ x.account.status }}</span> }</td>
                            <td>{{ className(x.account.class) }}</td>
                            <td class="num">{{ blank(x.opening_dr) }}</td><td class="num">{{ blank(x.opening_cr) }}</td>
                            <td class="num">{{ blank(x.period_dr) }}</td><td class="num">{{ blank(x.period_cr) }}</td>
                            <td class="num">{{ blank(x.closing_dr) }}</td><td class="num">{{ blank(x.closing_cr) }}</td>
                          </tr>
                        }
                      }
                    }
                  }
                  <tr class="sub">
                    <td colspan="3"><b>Total</b>
                      <span class="tag" [attr.data-s]="s.balanced ? 'balanced' : 'unbalanced'">{{ s.balanced ? 'Balanced' : 'Not balanced' }}</span></td>
                    <td class="num"><b>{{ s.totals.opening_dr | money }}</b></td><td class="num"><b>{{ s.totals.opening_cr | money }}</b></td>
                    <td class="num"><b>{{ s.totals.period_dr | money }}</b></td><td class="num"><b>{{ s.totals.period_cr | money }}</b></td>
                    <td class="num"><b>{{ s.totals.closing_dr | money }}</b></td><td class="num"><b>{{ s.totals.closing_cr | money }}</b></td>
                  </tr>
                </tbody>
              </table>
            }
          </div>
        }
      } @else {
        <div class="card section">
          <p class="muted">Branch columns show the closing balance at {{ r.to }}: debit positive, credit negative.</p>
          <table class="list">
            <thead>
              <tr><th>Account</th>
                @for (b of r.branches; track b.id) { <th class="num">{{ b.code }}</th> }
                <th class="num">Eliminations</th><th class="num">Consolidated Dr</th><th class="num">Consolidated Cr</th></tr>
            </thead>
            <tbody>
              @for (g of groups; track g[0]) {
                @if (consOf(r, g[0]); as rows) {
                  @if (rows.length) {
                    <tr class="sub"><td [attr.colspan]="r.branches.length + 4"><b>{{ g[1] }}</b></td></tr>
                    @for (x of rows; track x.key) {
                      <tr>
                        <td>{{ x.label }}</td>
                        @for (b of r.branches; track b.id) { <td class="num" [class.neg]="+x.by_branch[b.id] < 0">{{ blank(x.by_branch[b.id]) }}</td> }
                        <td class="num">{{ x.elimination === null ? '' : blank(x.elimination) }}</td>
                        <td class="num">{{ blank(x.dr) }}</td><td class="num">{{ blank(x.cr) }}</td>
                      </tr>
                    }
                  }
                }
              }
              @if (r.totals; as t) {
                <tr class="sub">
                  <td><b>Total</b> <span class="tag" [attr.data-s]="r.balanced ? 'balanced' : 'unbalanced'">{{ r.balanced ? 'Balanced' : 'Not balanced' }}</span></td>
                  @for (b of r.branches; track b.id) { <td class="num"><b>{{ t.by_branch[b.id] | money }}</b></td> }
                  <td class="num"><b>{{ t.elimination | money }}</b></td>
                  <td class="num"><b>{{ t.dr | money }}</b></td><td class="num"><b>{{ t.cr | money }}</b></td>
                </tr>
              }
            </tbody>
          </table>
          @if (r.subset) { <p class="muted">Inter-branch balances with branches not selected are not eliminated.</p> }
        </div>
      }
      <ag-signatures />
    }
  `,
})
export class TrialBalanceComponent implements OnInit {
  auth = inject(AuthService);
  private http = inject(HttpClient);
  private router = inject(Router);
  private route = inject(ActivatedRoute);
  private money = new MoneyPipe();
  settings = inject(SettingsService);
  /** "Include closing entries" (post-closing view, S6). */
  closing = false;
  groups = TB_GROUPS;
  f = currentMonth();
  view: 'branch' | 'consolidated' = 'branch';
  branches = signal<number[]>([]);
  r = signal<TrialBalance | null>(null);
  loading = signal(false);
  msg = signal('');
  errs = signal<Record<string, string>>({});
  applied = signal<ReportParams>({});

  errLine() { return Object.values(this.errs()).join(' '); }

  ngOnInit() {
    const { q, branches } = readQuery(this.route, this.auth.isAllBranches());
    this.f = { from: q.get('from') ?? this.f.from, to: q.get('to') ?? this.f.to };
    this.closing = q.get('closing_entries') === '1';
    if (q.get('view') === 'consolidated' && this.auth.isAllBranches()) this.view = 'consolidated';
    this.branches.set(branches);
    this.load();
  }

  load() {
    const params: ReportParams = { from: this.f.from, to: this.f.to, branches: this.branches(), view: this.view,
      closing_entries: this.closing && (!this.r() || this.canPostClosing()) ? 1 : '' };
    this.applied.set(params);
    writeQuery(this.router, this.route, params);
    this.loading.set(true);
    this.http.get<TrialBalance>('/api/v1/reports/trial-balance', { params: toHttpParams(params) }).subscribe({
      next: r => { this.r.set(r); this.msg.set(''); this.errs.set({}); this.loading.set(false); },
      error: e => { this.r.set(null); this.errs.set(fieldErrors(e)); this.msg.set(this.errLine() ? '' : reportError(e)); this.loading.set(false); },
    });
  }

  /** S6: the toggle shows only when To is the last day of a closed fiscal year. */
  canPostClosing(): boolean {
    const fy = this.r()?.fiscal_year;
    return !!fy?.closed && this.f.to === fy.end;
  }

  preset(r: { from: string; to: string }) {
    this.f = { ...r };
    this.load();
  }

  rowsOf(s: Section, group: string) { return s.rows.filter(x => x.account.group === group); }
  consOf(r: TrialBalance, group: string) { return (r.rows ?? []).filter(x => x.group === group); }
  className(c: string) { return CLASS_NAMES[c] ?? c; }
  /** Zero amounts are left blank, as on a printed trial balance. */
  blank(v: string | null | undefined): string { return !v || Number(v) === 0 ? '' : this.money.transform(v); }

  outBy(r: TrialBalance): string {
    if (r.view === 'consolidated') return r.difference ?? '0.00';
    const bad = (r.sections ?? []).find(s => !s.balanced);
    return bad?.difference ?? '0.00';
  }
}
