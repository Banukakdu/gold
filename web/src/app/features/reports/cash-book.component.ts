import { Component, OnInit, computed, inject, signal } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { FormsModule } from '@angular/forms';
import { ActivatedRoute, Router } from '@angular/router';
import { AuthService } from '../../core/auth.service';
import { AppDatePipe } from '../../core/app-date.pipe';
import { MoneyPipe, TYPE_LABELS } from '../../core/money.pipe';
import { Account } from '../../core/models';
import {
  BranchPickerComponent, ExportButtonsComponent, ReportHeadComponent, ReportMeta, ReportParams, SignaturesComponent,
  fieldErrors, isoDate, toHttpParams,
  readQuery, reportError, writeQuery,
} from './report-common';

interface Day {
  date: string; opening: string; receipts: string; payments: string; closing: string;
  lines: { ref_no: string; type: string; description: string; receipt: string; payment: string }[];
}
interface Book {
  account: { id: number; name: string; type: string; branch_code: string; branch_name: string; status: string };
  opening: string; receipts: string; payments: string; closing: string; days: Day[];
}
interface CashBook extends ReportMeta { accounts: Book[] }

/** AG-085 */
@Component({
  standalone: true,
  imports: [FormsModule, MoneyPipe, AppDatePipe, BranchPickerComponent, ExportButtonsComponent, ReportHeadComponent, SignaturesComponent],
  template: `
    <h1 class="no-print">Daily Cash Book</h1>
    <form class="filters" (ngSubmit)="load()">
      <label>From <input type="date" name="from" [(ngModel)]="f.from" required /></label>
      <label>To <input type="date" name="to" [(ngModel)]="f.to" required /></label>
      <ag-branch-picker [(selected)]="branches" />
      @if (accounts().length) {
        <details class="picker">
          <summary>{{ chosen().length ? chosen().length + ' account(s)' : 'All accounts' }}</summary>
          @for (a of visibleAccounts(); track a.id) {
            <label><input type="checkbox" [checked]="chosen().includes(a.id)" (change)="toggle(a.id)" />
              {{ a.branch?.code }} · {{ a.name }} ({{ a.type }}) @if (a.status && a.status !== 'active') { <span class="tag" data-s="inactive">Inactive</span> }</label>
          }
          @if (chosen().length) { <button type="button" class="link" (click)="chosen.set([])">All accounts</button> }
        </details>
      }
      <button type="submit">Show</button>
      <button type="button" class="ghost" (click)="print()" [disabled]="!r()">Print</button>
      <ag-export-buttons name="cash-book" [params]="applied()" />
    </form>
    @if (errLine(); as e) { <p class="field-error">{{ e }}</p> }
    @if (msg()) { <div class="alert">{{ msg() }}</div> }

    @if (loading()) { <p class="muted">Loading...</p> }
    @if (!loading() && r(); as r) {
      <div class="no-print"><ag-report-head [meta]="r" title="Daily Cash Book" /></div>
      @for (b of r.accounts; track b.account.id) {
        <section class="book">
          <!-- Print: company, branch, account, range, printed at/by on every account page -->
          <div class="print-only"><ag-report-head [meta]="r" title="Daily Cash Book" /></div>
          <h2>{{ b.account.branch_code }} · {{ b.account.name }} ({{ b.account.type }})
            @if (b.account.status !== 'active') { <span class="tag" data-s="inactive">Inactive</span> }</h2>
          <div class="card">
            <table class="list">
              <thead><tr><th>Date</th><th class="num">Opening</th><th class="num">Receipts</th><th class="num">Payments</th><th class="num">Closing</th></tr></thead>
              <tbody>
                @for (d of b.days; track d.date) {
                  <tr class="sub"><td>{{ d.date | appDate }}</td><td class="num">{{ d.opening | money }}</td><td class="num">{{ d.receipts | money }}</td>
                    <td class="num">{{ d.payments | money }}</td><td class="num">{{ d.closing | money }}</td></tr>
                  <tr><td colspan="5" class="lines">
                    <table>
                      <thead><tr><th>Ref no</th><th>Type</th><th>Description / payee</th><th class="num">Receipt</th><th class="num">Payment</th></tr></thead>
                      <tbody>
                        @for (l of d.lines; track $index) {
                          <tr><td class="mono">{{ l.ref_no }}</td><td>{{ label(l.type) }}</td><td>{{ l.description }}</td>
                            <td class="num">{{ +l.receipt ? (l.receipt | money) : '' }}</td><td class="num">{{ +l.payment ? (l.payment | money) : '' }}</td></tr>
                        }
                      </tbody>
                    </table>
                  </td></tr>
                } @empty { <tr><td colspan="5" class="muted">No movements in this period.</td></tr> }
                <tr class="sub"><td>Period</td><td class="num">{{ b.opening | money }}</td><td class="num">{{ b.receipts | money }}</td>
                  <td class="num">{{ b.payments | money }}</td><td class="num">{{ b.closing | money }}</td></tr>
              </tbody>
            </table>
          </div>
        </section>
      } @empty { <div class="card"><p class="muted">No accounts to show.</p></div> }
      <ag-signatures />
    }
  `,
})
export class CashBookComponent implements OnInit {
  auth = inject(AuthService);
  private http = inject(HttpClient);
  private router = inject(Router);
  private route = inject(ActivatedRoute);
  f = { from: isoDate(new Date()), to: isoDate(new Date()) };
  branches = signal<number[]>([]);
  accounts = signal<Account[]>([]);
  chosen = signal<number[]>([]);
  r = signal<CashBook | null>(null);
  loading = signal(false);
  msg = signal('');
  errs = signal<Record<string, string>>({});
  applied = signal<ReportParams>({});
  needsBranch = computed(() => this.auth.isAllBranches() && this.branches().length === 0);
  visibleAccounts = computed(() => {
    const b = this.branches();
    return this.auth.isAllBranches() && b.length ? this.accounts().filter(a => b.includes(a.branch_id)) : this.accounts();
  });

  errLine() { return Object.values(this.errs()).join(' '); }

  ngOnInit() {
    // The account picker needs account.view; without it the report covers all accounts in scope.
    const { q, branches } = readQuery(this.route, this.auth.isAllBranches());
    this.f = { from: q.get('from') ?? this.f.from, to: q.get('to') ?? this.f.to };
    this.branches.set(branches);
    this.chosen.set(q.getAll('accounts').map(Number).filter(n => n > 0));
    // Load only after the account list arrives, so account ids left in the URL from another branch are dropped.
    const start = () => {
      // All mode needs a branch first: say so once, as the field error the API would give (QA bug 5).
      if (this.needsBranch()) this.errs.set({ branches: 'Select at least one branch' });
      else this.load();
    };
    this.http.get<Account[]>('/api/v1/accounts').subscribe({
      next: a => { this.accounts.set(a); start(); },
      error: () => { this.accounts.set([]); this.chosen.set([]); start(); },
    });
  }

  load() {
    // Before the account list arrives (e.g. accounts from the URL), keep the choice as is; the API validates it.
    const visible = new Set(this.visibleAccounts().map(a => a.id));
    const accounts = this.accounts().length ? this.chosen().filter(id => visible.has(id)) : this.chosen();
    const params: ReportParams = { from: this.f.from, to: this.f.to, branches: this.branches(), accounts };
    this.applied.set(params);
    writeQuery(this.router, this.route, params);
    this.loading.set(true);
    this.http.get<CashBook>('/api/v1/reports/cash-book', { params: toHttpParams(params) }).subscribe({
      next: r => { this.r.set(r); this.msg.set(''); this.errs.set({}); this.loading.set(false); },
      error: e => { this.r.set(null); this.errs.set(fieldErrors(e)); this.msg.set(this.errLine() ? '' : reportError(e)); this.loading.set(false); },
    });
  }

  toggle(id: number) { this.chosen.update(s => (s.includes(id) ? s.filter(x => x !== id) : [...s, id])); }
  label(t: string) { return TYPE_LABELS[t] ?? t; }
  print() { window.print(); }
}
