import { Component, OnInit, computed, inject, signal } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { FormsModule } from '@angular/forms';
import { ActivatedRoute, Router } from '@angular/router';
import { AuthService } from '../../core/auth.service';
import { AppDatePipe } from '../../core/app-date.pipe';
import { MoneyPipe } from '../../core/money.pipe';
import { receiptLabel } from '../../core/receipts';
import {
  BranchPickerComponent, ExportButtonsComponent, ReportHeadComponent, ReportMeta, ReportParams, currentMonth, fieldErrors, toHttpParams,
  readQuery, reportError, writeQuery,
} from './report-common';

interface Movement {
  id: number; ref_no: string; type: string; movement_type: string; txn_date: string;
  from: { branch_code: string; account: string }; to: { branch_code: string; account: string };
  amount: string; status: 'posted' | 'reversed' | 'reversal'; reversal_of_ref: string | null; description: string | null; created_by: string;
  /** AG-052 S5. */
  receipt_status: string | null; received_on: string | null;
}
interface MatrixBranch { id: number; code: string; name: string; status: string; in_scope: boolean }
interface Transfers extends ReportMeta {
  movements: { data: Movement[]; current_page: number; last_page: number; total: number };
  net_moved: string;
  receipt?: string[];
  in_transit?: { as_at: string; count: number; amount: string } | null;
  matrix: { branches: MatrixBranch[]; cells: { from_id: number; to_id: number; amount: string }[];
    sent: Record<string, string>; received: Record<string, string>; net: Record<string, string> };
}

/** AG-052 S5: receipt states; "In transit at end date" (R14) stands alone. */
export const RECEIPT_OPTIONS: [string, string][] = [
  ['awaiting', 'Awaiting receipt'], ['received', 'Received'], ['disputed', 'Discrepancy reported'], ['cancelled', 'Cancelled (reversed)'],
];
export const IN_TRANSIT = 'in_transit';

export const MOVEMENT_TYPES: [string, string][] = [
  ['opening_allocation', 'Opening allocation'], ['fund_allocation', 'Top-up'], ['fund_return', 'Fund return'], ['branch_transfer', 'Branch transfer'],
];

/** AG-084 */
@Component({
  standalone: true,
  imports: [FormsModule, MoneyPipe, AppDatePipe, BranchPickerComponent, ExportButtonsComponent, ReportHeadComponent],
  template: `
    <h1>Transfers &amp; Allocations</h1>
    <form class="filters" (ngSubmit)="load(1)">
      <label>From <input type="date" name="from" [(ngModel)]="f.from" required /></label>
      <label>To <input type="date" name="to" [(ngModel)]="f.to" required /></label>
      <ag-branch-picker [(selected)]="branches" />
      <details class="picker">
        <summary>{{ types().length === all.length ? 'All movement types' : types().length + ' movement type(s)' }}</summary>
        @for (t of all; track t[0]) {
          <label><input type="checkbox" [checked]="types().includes(t[0])" (change)="toggleType(t[0])" /> {{ t[1] }}</label>
        }
      </details>
      <details class="picker">
        <summary>{{ receiptSummary() }}</summary>
        @for (o of receiptOpts; track o[0]) {
          <label><input type="checkbox" [checked]="receipt().includes(o[0])" (change)="toggleReceipt(o[0])" /> {{ o[1] }}</label>
        }
        <label title="Every transfer not yet received at the To date, whatever its date: the month-end reconciling items">
          <input type="checkbox" [checked]="receipt().includes(inTransit)" (change)="toggleReceipt(inTransit)" /> In transit at end date</label>
      </details>
      <button type="submit">Show</button>
      <ag-export-buttons name="transfers" [params]="applied()" />
    </form>
    @if (errLine(); as e) { <p class="field-error">{{ e }}</p> }
    @if (msg()) { <div class="alert">{{ msg() }}</div> }

    @if (loading()) { <p class="muted">Loading...</p> }
    @if (!loading() && r(); as r) {
      <ag-report-head [meta]="r" title="Transfers & Allocations" />
      @if (r.in_transit; as it) {
        <div class="att-note">{{ it.count }} {{ it.count === 1 ? 'transfer' : 'transfers' }} in transit at {{ it.as_at | appDate }}: {{ it.amount | money }}.
          From does not limit this list; Net moved and the matrix show the transfers in transit.</div>
      } @else if (r.receipt?.length) {
        <div class="att-note">Receipt filter: {{ receiptNames(r.receipt ?? []) }}. Net moved and the matrix show the listed transfers only.</div>
      }
      @if (r.movements.total === 0) { <div class="card"><p class="muted">No transfers or allocations in this period.</p></div> }
      @else {
        <div class="card section">
          <h2>Movements</h2>
          <table class="list">
            <thead><tr><th>Date</th><th>Ref no</th><th>Type</th><th>From</th><th>To</th><th class="num">Amount</th><th>Status</th><th>Receipt</th><th>Note</th><th>Created by</th></tr></thead>
            <tbody>
              @for (m of r.movements.data; track m.id) {
                <tr>
                  <td>{{ m.txn_date | appDate }}</td><td class="mono">{{ m.ref_no }}</td>
                  <td>{{ m.status === 'reversal' ? 'Reversal (' + typeLabel(m.movement_type) + ')' : typeLabel(m.movement_type) }}</td>
                  <td>{{ m.from.branch_code }} · {{ m.from.account }}</td><td>{{ m.to.branch_code }} · {{ m.to.account }}</td>
                  <td class="num" [class.neg]="+m.amount < 0">{{ m.amount | money }}</td>
                  <td><span class="tag" [attr.data-s]="m.status">{{ m.status === 'reversal' ? 'Reversal of ' + m.reversal_of_ref : m.status === 'reversed' ? 'Reversed' : 'Posted' }}</span></td>
                  <td>{{ m.receipt_status ? receiptText(m.receipt_status) : '—' }}@if (m.received_on) { <br /><small class="muted">{{ m.received_on | appDate }}</small> }</td>
                  <td>{{ m.description }}</td><td>{{ m.created_by }}</td>
                </tr>
              }
              <tr class="sub"><td colspan="5">Net moved for the whole period (all pages), reversals deducted</td><td class="num">{{ r.net_moved | money }}</td><td colspan="4"></td></tr>
            </tbody>
          </table>
          <div class="pager">
            <button type="button" class="ghost" [disabled]="r.movements.current_page <= 1" (click)="load(r.movements.current_page - 1)">Previous</button>
            <span>Page {{ r.movements.current_page }} of {{ r.movements.last_page }} · {{ r.movements.total }} movements</span>
            <button type="button" class="ghost" [disabled]="r.movements.current_page >= r.movements.last_page" (click)="load(r.movements.current_page + 1)">Next</button>
          </div>
        </div>

        <div class="card section">
          <h2>Net position matrix <small class="muted">rows send, columns receive</small></h2>
          <table class="list">
            <thead><tr><th>From \\ To</th>@for (b of r.matrix.branches; track b.id) { <th class="num">{{ b.code }}</th> }<th class="num">Total sent</th></tr></thead>
            <tbody>
              @for (a of r.matrix.branches; track a.id) {
                <tr><td>{{ a.code }} @if (a.status !== 'active') { <span class="tag" data-s="inactive">Inactive</span> }</td>
                  @for (b of r.matrix.branches; track b.id) {
                    <td class="num">{{ a.id === b.id ? '—' : (cell(a.id, b.id) === null ? '' : (cell(a.id, b.id) | money)) }}</td>
                  }
                  <td class="num">{{ a.in_scope ? (r.matrix.sent[a.id] | money) : '' }}</td></tr>
              }
              <tr class="sub"><td>Total received</td>@for (b of r.matrix.branches; track b.id) { <td class="num">{{ b.in_scope ? (r.matrix.received[b.id] | money) : '' }}</td> }<td></td></tr>
              <tr class="sub"><td>Net (received - sent)</td>@for (b of r.matrix.branches; track b.id) { <td class="num">{{ b.in_scope ? (r.matrix.net[b.id] | money) : '' }}</td> }<td></td></tr>
            </tbody>
          </table>
          @if (r.subset) { <p class="muted">Transfers with branches not selected are included; inter-branch amounts will not net to zero.</p> }
        </div>
      }
    }
  `,
})
export class TransfersReportComponent implements OnInit {
  private http = inject(HttpClient);
  private router = inject(Router);
  private route = inject(ActivatedRoute);
  private auth = inject(AuthService);
  all = MOVEMENT_TYPES;
  f = currentMonth();
  branches = signal<number[]>([]);
  types = signal<string[]>(MOVEMENT_TYPES.map(t => t[0]));
  receiptOpts = RECEIPT_OPTIONS;
  inTransit = IN_TRANSIT;
  /** Empty = all (no receipt filter). */
  receipt = signal<string[]>([]);
  r = signal<Transfers | null>(null);
  loading = signal(false);
  msg = signal('');
  errs = signal<Record<string, string>>({});
  applied = signal<ReportParams>({});
  private cells = computed(() => {
    const m = new Map<string, string>();
    for (const c of this.r()?.matrix.cells ?? []) m.set(`${c.from_id}:${c.to_id}`, c.amount);
    return m;
  });

  errLine() { return Object.values(this.errs()).join(' '); }

  ngOnInit() {
    const { q, branches } = readQuery(this.route, this.auth.isAllBranches());
    this.f = { from: q.get('from') ?? this.f.from, to: q.get('to') ?? this.f.to };
    const types = q.getAll('types').filter(t => MOVEMENT_TYPES.some(m => m[0] === t));
    if (types.length) this.types.set(types);
    const receipt = q.getAll('receipt').filter(v => v === IN_TRANSIT || RECEIPT_OPTIONS.some(o => o[0] === v));
    this.receipt.set(receipt.includes(IN_TRANSIT) ? [IN_TRANSIT] : receipt);
    this.branches.set(branches);
    this.load(Number(q.get('page')) || 1);
  }

  load(page: number) {
    const params: ReportParams = { from: this.f.from, to: this.f.to, branches: this.branches(), types: this.types(), receipt: this.receipt(), page };
    this.applied.set(params);
    writeQuery(this.router, this.route, params);
    this.loading.set(true);
    this.http.get<Transfers>('/api/v1/reports/transfers', { params: toHttpParams(params) }).subscribe({
      next: r => { this.r.set(r); this.msg.set(''); this.errs.set({}); this.loading.set(false); },
      error: e => { this.r.set(null); this.errs.set(fieldErrors(e)); this.msg.set(this.errLine() ? '' : reportError(e)); this.loading.set(false); },
    });
  }

  toggleType(t: string) {
    this.types.update(s => (s.includes(t) ? s.filter(x => x !== t) : [...s, t]));
  }

  /** "In transit at end date" cannot be combined with the receipt states. */
  toggleReceipt(v: string) {
    this.receipt.update(s => {
      if (s.includes(v)) return s.filter(x => x !== v);
      return v === IN_TRANSIT ? [IN_TRANSIT] : [...s.filter(x => x !== IN_TRANSIT), v];
    });
  }

  receiptSummary(): string {
    const r = this.receipt();
    if (!r.length) return 'All receipts';
    if (r.includes(IN_TRANSIT)) return 'In transit at end date';
    return `Receipt: ${r.length} selected`;
  }

  receiptNames(r: string[]) { return r.map(v => receiptLabel(v)).join(', '); }
  receiptText(s: string) { return receiptLabel(s); }

  cell(a: number, b: number): string | null { return this.cells().get(`${a}:${b}`) ?? null; }
  typeLabel(t: string) { return MOVEMENT_TYPES.find(x => x[0] === t)?.[1] ?? t; }
}
