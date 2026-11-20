import { Component, OnInit, inject, signal } from '@angular/core';
import { HttpClient, HttpErrorResponse } from '@angular/common/http';
import { FormsModule } from '@angular/forms';
import { ActivatedRoute, Router } from '@angular/router';
import { AuthService } from '../../core/auth.service';
import { MoneyPipe } from '../../core/money.pipe';
import { SettingsService, colomboParts } from '../../core/settings.service';
import { CashFlowChartComponent, CashFlowMonth, monthLabel } from './cash-flow-chart.component';
import {
  BranchPickerComponent, ExportButtonsComponent, ReportHeadComponent, ReportMeta, ReportParams,
  fieldErrors, isoDate, readQuery, reportError, toHttpParams, writeQuery,
} from './report-common';

type Figures = Omit<CashFlowMonth, 'month'>;
interface CashFlow extends ReportMeta {
  consolidated: boolean;
  period_label: string;
  basis: string;
  notes: string[];
  empty: boolean;
  months: CashFlowMonth[];
  total: Figures;
}

/** R6 / review finding 3: the API's message for a report that does not tie to the ledger (shown as is). */
export const UNRECONCILED = 'This report could not be produced because its figures do not agree with the ledger. Nothing was changed. Please report this to your administrator.';

const MAX_MONTHS = 24;

/** "YYYY-MM" + n months */
function addMonths(ym: string, n: number): string {
  const [y, m] = ym.split('-').map(Number);
  const d = new Date(y, m - 1 + n, 1);
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}`;
}

function monthsBetween(from: string, to: string): number {
  const [fy, fm] = from.split('-').map(Number);
  const [ty, tm] = to.split('-').map(Number);
  return (ty - fy) * 12 + tm - fm + 1;
}

/** AG-086 Cash Flow Summary (docs/specs/cash-flow.md). */
@Component({
  standalone: true,
  imports: [FormsModule, MoneyPipe, BranchPickerComponent, ExportButtonsComponent, ReportHeadComponent, CashFlowChartComponent],
  template: `
    <h1>Cash Flow Summary</h1>
    <form class="filters" (ngSubmit)="load()">
      <span class="export">
        <button type="button" class="ghost" (click)="preset('this')">This fiscal year</button>
        <button type="button" class="ghost" (click)="preset('last')">Last fiscal year</button>
        <button type="button" class="ghost" (click)="preset('12')">Last 12 months</button>
      </span>
      <label>From <input type="month" name="from" [(ngModel)]="from" [max]="current" required /></label>
      <label>To <input type="month" name="to" [(ngModel)]="to" [max]="current" required /></label>
      <ag-branch-picker [(selected)]="branches" />
      <button type="submit">Show</button>
      <ag-export-buttons name="cash-flow" [params]="applied()" />
    </form>
    @if (errLine(); as e) { <p class="field-error">{{ e }}</p> }
    @if (msg()) { <div class="alert">{{ msg() }}</div> }

    @if (loading()) { <p class="muted">Loading...</p> }
    @if (!loading() && r(); as r) {
      <ag-report-head [meta]="r" title="Cash Flow Summary" [basis]="r.basis" [periodText]="r.period_label" />
      @if (r.consolidated) { <p class="muted cf-scope">Consolidated - all branches</p> }
      @if (r.empty) { <div class="ok">No cash movements in this period.</div> }
      <div class="card section cf-card">
        <ag-cash-flow-chart [months]="r.months" />
      </div>
      <div class="card">
        <table class="list cf-table">
          <thead><tr><th>Month</th>@for (c of cols; track c[0]) { <th class="num">{{ c[1] }}</th> }</tr></thead>
          <tbody>
            @for (m of r.months; track m.month) {
              <tr><td class="cf-month">{{ label(m.month) }}</td>
                @for (c of cols; track c[0]) { <td class="num" [class.neg]="+m[c[0]] < 0" [class.cf-key]="bold.includes(c[0])">{{ m[c[0]] | money }}</td> }</tr>
            }
            <tr class="sub"><td>Total</td>@for (c of cols; track c[0]) { <td class="num" [class.neg]="+r.total[c[0]] < 0">{{ r.total[c[0]] | money }}</td> }</tr>
          </tbody>
        </table>
        @for (n of r.notes; track n) { <p class="muted">{{ n }}</p> }
      </div>
    }
  `,
  styles: [`
    .cf-card { overflow: hidden; }
    .cf-table th { white-space: nowrap; }
    .cf-month { white-space: nowrap; }
    .cf-key { font-weight: 600; }
    .cf-scope { margin: -6px 0 10px; }
  `],
})
export class CashFlowComponent implements OnInit {
  private http = inject(HttpClient);
  private router = inject(Router);
  private route = inject(ActivatedRoute);
  private auth = inject(AuthService);
  private settings = inject(SettingsService);

  /** Section 4 column order (review finding 2); Eliminated is in the exports only. */
  cols: [keyof Figures, string][] = [
    ['opening', 'Opening'], ['income', 'Income received'], ['expenses', 'Expenses paid'], ['operating_net', 'Operating net'],
    ['capital', 'Capital introduced'], ['funds_received', 'Funds received'], ['transfers_in', 'Transfers in'], ['transfers_out', 'Transfers out'],
    ['funding_net', 'Funding net'], ['inflow', 'Total inflow'], ['outflow', 'Total outflow'], ['net', 'Net change'], ['closing', 'Closing'],
  ];
  bold: (keyof Figures)[] = ['operating_net', 'funding_net', 'inflow', 'outflow', 'net', 'closing'];

  /** Current month (business date, Asia/Colombo). */
  current = colomboParts(new Date().toISOString()).ymd.slice(0, 7);
  from = '';
  to = '';
  branches = signal<number[]>([]);
  r = signal<CashFlow | null>(null);
  loading = signal(false);
  msg = signal('');
  errs = signal<Record<string, string>>({});
  applied = signal<ReportParams>({});

  errLine() { return Object.values(this.errs()).join(' '); }
  label(ym: string) { return monthLabel(ym); }

  ngOnInit() {
    const { q, branches } = readQuery(this.route, this.auth.isAllBranches());
    const from = q.get('from') ?? '';
    const to = q.get('to') ?? '';
    if (/^\d{4}-\d{2}-01$/.test(from) && /^\d{4}-\d{2}-\d{2}$/.test(to)) {
      this.from = from.slice(0, 7);
      this.to = to.slice(0, 7);
    } else {
      this.setPreset('this');
    }
    this.branches.set(branches);
    this.load();
  }

  /** Spec section 4 presets (AC 11). */
  setPreset(p: 'this' | 'last' | '12') {
    const fy = this.settings.currentFiscalYear();
    if (p === 'this') {
      this.from = fy.start.slice(0, 7);
      this.to = this.current;
    } else if (p === 'last') {
      const last = this.settings.fiscalYear(fy.start_year - 1);
      this.from = last.start.slice(0, 7);
      this.to = last.end.slice(0, 7);
    } else {
      this.from = addMonths(this.current, -11);
      this.to = this.current;
    }
  }

  preset(p: 'this' | 'last' | '12') {
    this.setPreset(p);
    this.load();
  }

  load() {
    // Client checks mirror the API (R7); the API checks again.
    const errs: Record<string, string> = {};
    if (!/^\d{4}-\d{2}$/.test(this.from) || !/^\d{4}-\d{2}$/.test(this.to)) errs['from'] = 'Choose a From and a To month.';
    else if (this.to < this.from) errs['to'] = 'To must be the same as or after From.';
    else if (this.to > this.current) errs['to'] = 'The report cannot cover future months.';
    else if (monthsBetween(this.from, this.to) > MAX_MONTHS) errs['to'] = 'The range may not be longer than 24 months.';
    if (Object.keys(errs).length) {
      this.errs.set(errs);
      this.msg.set('');
      return;
    }
    const [ty, tm] = this.to.split('-').map(Number);
    const params: ReportParams = { from: `${this.from}-01`, to: isoDate(new Date(ty, tm, 0)), branches: this.branches() };
    this.applied.set(params);
    writeQuery(this.router, this.route, params);
    this.loading.set(true);
    this.http.get<CashFlow>('/api/v1/reports/cash-flow', { params: toHttpParams(params) }).subscribe({
      next: r => { this.r.set(r); this.msg.set(''); this.errs.set({}); this.loading.set(false); },
      error: (e: HttpErrorResponse) => {
        this.r.set(null);
        this.errs.set(fieldErrors(e));
        // Finding 3: the reconciliation stop has its own plain message; other server errors stay generic.
        this.msg.set(this.errLine() ? '' : e?.status === 500 && e.error?.message === UNRECONCILED ? UNRECONCILED : reportError(e));
        this.loading.set(false);
      },
    });
  }
}
