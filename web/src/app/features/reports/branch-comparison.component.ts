import { Component, OnInit, inject, signal } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { FormsModule } from '@angular/forms';
import { ActivatedRoute, Router } from '@angular/router';
import { AuthService } from '../../core/auth.service';
import { MoneyPipe } from '../../core/money.pipe';
import {
  BranchPickerComponent, ExportButtonsComponent, ReportBranch, ReportHeadComponent, ReportMeta, ReportParams, SignaturesComponent,
  fieldErrors, isoDate, periodRange, toHttpParams,
  readQuery, reportError, writeQuery,
} from './report-common';

type Figures = Record<'opening' | 'capital' | 'funds_received' | 'income' | 'expenses' | 'transfers_in' | 'transfers_out' | 'closing', string>;
interface Comparison extends ReportMeta { rows: (Figures & { branch: ReportBranch })[]; total: Figures }

/** AG-083 */
@Component({
  standalone: true,
  imports: [FormsModule, MoneyPipe, BranchPickerComponent, ExportButtonsComponent, ReportHeadComponent, SignaturesComponent],
  template: `
    <h1>Branch Comparison</h1>
    <form class="filters" (ngSubmit)="load()">
      <select name="ptype" [(ngModel)]="ptype">
        <option value="month">Month</option><option value="quarter">Quarter</option><option value="year">Year (calendar)</option><option value="custom">Custom</option>
      </select>
      @switch (ptype) {
        @case ('month') { <input type="month" name="month" [(ngModel)]="p.month" required /> }
        @case ('quarter') {
          <select name="quarter" [(ngModel)]="p.quarter">@for (q of [1, 2, 3, 4]; track q) { <option [ngValue]="q">Q{{ q }}</option> }</select>
          <input type="number" name="qyear" [(ngModel)]="p.year" min="2000" max="2100" />
        }
        @case ('year') { <input type="number" name="year" [(ngModel)]="p.year" min="2000" max="2100" /> }
        @case ('custom') {
          <label>From <input type="date" name="from" [(ngModel)]="p.from" /></label>
          <label>To <input type="date" name="to" [(ngModel)]="p.to" /></label>
        }
      }
      <ag-branch-picker [(selected)]="branches" />
      <button type="submit">Show</button>
      <ag-export-buttons name="branch-comparison" [params]="applied()" />
    </form>
    @if (errLine(); as e) { <p class="field-error">{{ e }}</p> }
    @if (msg()) { <div class="alert">{{ msg() }}</div> }

    @if (loading()) { <p class="muted">Loading...</p> }
    @if (!loading() && r(); as r) {
      <ag-report-head [meta]="r" title="Branch Comparison" />
      <div class="card">
        <table class="list">
          <thead><tr><th>Branch</th>@for (c of cols; track c[0]) { <th class="num">{{ c[1] }}</th> }</tr></thead>
          <tbody>
            @for (row of r.rows; track row.branch.id) {
              <tr><td>{{ row.branch.code }} · {{ row.branch.name }} @if (row.branch.status !== 'active') { <span class="tag" data-s="inactive">Inactive</span> }</td>
                @for (c of cols; track c[0]) { <td class="num" [class.neg]="+row[c[0]] < 0">{{ row[c[0]] | money }}</td> }</tr>
            } @empty { <tr><td [attr.colspan]="cols.length + 1" class="muted">No branches to show.</td></tr> }
            <tr class="sub"><td>Total</td>@for (c of cols; track c[0]) { <td class="num">{{ r.total[c[0]] | money }}</td> }</tr>
          </tbody>
        </table>
        <p class="muted">Closing = Opening + Capital introduced + Funds received + Income - Expenses + Transfers in - Transfers out.
          Internal transfers between accounts of the same branch are not shown.</p>
        @if (r.subset) { <p class="muted">Transfers with branches not selected are included; inter-branch amounts will not net to zero.</p> }
      </div>
      <ag-signatures />
    }
  `,
})
export class BranchComparisonComponent implements OnInit {
  private http = inject(HttpClient);
  private router = inject(Router);
  private route = inject(ActivatedRoute);
  private auth = inject(AuthService);
  cols: [keyof Figures, string][] = [
    ['opening', 'Opening balance'], ['capital', 'Capital introduced'], ['funds_received', 'Funds received'], ['income', 'Income'],
    ['expenses', 'Expenses'], ['transfers_in', 'Transfers in'], ['transfers_out', 'Transfers out'], ['closing', 'Closing balance'],
  ];
  ptype: 'month' | 'quarter' | 'year' | 'custom' = 'month';
  p = (() => {
    const now = new Date();
    const today = isoDate(now);
    return { month: today.slice(0, 7), quarter: Math.floor(now.getMonth() / 3) + 1, year: now.getFullYear(), from: today.slice(0, 8) + '01', to: today };
  })();
  branches = signal<number[]>([]);
  r = signal<Comparison | null>(null);
  loading = signal(false);
  msg = signal('');
  errs = signal<Record<string, string>>({});
  applied = signal<ReportParams>({});

  errLine() { return Object.values(this.errs()).join(' '); }

  ngOnInit() {
    const { q, branches } = readQuery(this.route, this.auth.isAllBranches());
    const period = q.get('period');
    const from = q.get('from');
    const to = q.get('to');
    if (period && ['month', 'quarter', 'year', 'custom'].includes(period) && from && to) {
      this.ptype = period as typeof this.ptype;
      const m = Number(from.slice(5, 7));
      this.p = { month: from.slice(0, 7), quarter: Math.floor((m - 1) / 3) + 1, year: Number(from.slice(0, 4)), from, to };
    }
    this.branches.set(branches);
    this.load();
  }

  load() {
    const range = periodRange(this.ptype, { ...this.p, year: Number(this.p.year), quarter: Number(this.p.quarter) });
    const params: ReportParams = { ...range, branches: this.branches() };
    this.applied.set(params);
    writeQuery(this.router, this.route, { period: this.ptype, ...params });
    this.loading.set(true);
    this.http.get<Comparison>('/api/v1/reports/branch-comparison', { params: toHttpParams(params) }).subscribe({
      next: r => { this.r.set(r); this.msg.set(''); this.errs.set({}); this.loading.set(false); },
      error: e => { this.r.set(null); this.errs.set(fieldErrors(e)); this.msg.set(this.errLine() ? '' : reportError(e)); this.loading.set(false); },
    });
  }
}
