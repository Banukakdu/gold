import { Component, OnInit, computed, inject, signal } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { FormsModule } from '@angular/forms';
import { ActivatedRoute, Router, RouterLink } from '@angular/router';
import { AuthService } from '../../core/auth.service';
import { AppDatePipe, AppDateTimePipe } from '../../core/app-date.pipe';
import { colomboToday } from '../../core/dates';
import { Account } from '../../core/models';
import { MoneyPipe, TYPE_LABELS } from '../../core/money.pipe';
import { dayLabel } from '../../core/reconciliation';
import { StatementFile, blobErrorText, fetchStoredFile, fileSize, saveFile, statementFilesUrl } from '../../core/attachments';
import { colomboDateTime } from '../../core/dates';
import {
  BranchPickerComponent, ExportButtonsComponent, ReportHeadComponent, ReportMeta, ReportParams,
  fieldErrors, readQuery, reportError, toHttpParams, writeQuery,
} from './report-common';

interface Person { id: number; name: string; tag: string | null }
export interface BankRow {
  entry_id: number; transaction_id: number; date: string; ref_no: string; type: string; reversal_of_ref: string | null;
  description: string; reference: string | null; in: string; out: string; age_days?: number; stale?: boolean; cleared_date?: string;
}
export interface BankSection {
  account: { id: number; code: string | null; name: string; status: string; branch: { id: number; code: string; name: string } };
  last_statement: { id: number; statement_date: string; statement_balance: string } | null;
  book_balance: string; uncleared_receipts: string; uncleared_payments: string; cleared_booked_later: string;
  cleared_booked_later_rows: BankRow[]; expected_bank_balance: string; rows: BankRow[];
  /** Review finding 2: rows hidden by Older than; finding 9: rows past the screen cap (exports list them). */
  rows_filtered_out: number; rows_not_shown: number; empty_text: string | null;
}
export interface BankReport extends ReportMeta {
  as_at: string; older_than: number | null; older_than_ignored: boolean; filter_note: string | null; basis: string; accounts: BankSection[];
  statement: {
    id: number; account_id: number; status: 'completed' | 'undone'; statement_date: string; statement_balance: string; overdrawn: boolean;
    expected_bank_balance: string; difference: string; changed_since_completion: boolean;
    late_entries: { ref_no: string; date: string; amount: string; kind: 'recorded' | 'voided'; by: string | null; at: string }[];
    lines_count: number; self_count: number; completed_by: Person | null; completed_at: string;
    undone: { by: Person | null; at: string; reason: string } | null;
    /** AG-033b S3: the bank statement copy, removed and replaced files included (metadata only). */
    attachments?: StatementFile[];
  } | null;
}

/** AG-033 S3 "Unreconciled bank entries" (docs/specs/bank-reconciliation.md); with ?statement_id= the statement reprint. */
@Component({
  standalone: true,
  imports: [FormsModule, RouterLink, MoneyPipe, AppDatePipe, AppDateTimePipe, BranchPickerComponent, ExportButtonsComponent, ReportHeadComponent],
  template: `
    <h1 class="no-print">{{ statementId() ? 'Bank reconciliation statement' : 'Unreconciled bank entries' }}</h1>
    @if (!statementId()) {
      <form class="filters" (ngSubmit)="load()">
        <label>As at <input type="date" name="as_at" [(ngModel)]="f.as_at" [max]="today" required /></label>
        <ag-branch-picker [(selected)]="branches" />
        @if (banks().length) {
          <details class="picker">
            <summary>{{ chosen().length ? chosen().length + ' account(s)' : 'All bank accounts' }}</summary>
            @for (a of visibleBanks(); track a.id) {
              <label><input type="checkbox" [checked]="chosen().includes(a.id)" (change)="toggle(a.id)" /> {{ a.branch?.code }} · {{ a.name }}</label>
            }
            @if (chosen().length) { <button type="button" class="link" (click)="chosen.set([])">All bank accounts</button> }
          </details>
        }
        <label>Older than (days) <input type="number" name="older_than" min="0" max="3650" step="1" [(ngModel)]="f.older_than" style="width:90px" /></label>
        <button type="submit">Show</button>
        <button type="button" class="ghost" (click)="print()" [disabled]="!r()">Print</button>
        <ag-export-buttons name="bank-reconciliation" [params]="applied()" />
      </form>
    } @else {
      <div class="filters">
        <a routerLink="/reports/bank-reconciliation">All unreconciled entries</a>
        <button type="button" class="ghost" (click)="print()" [disabled]="!r()">Print</button>
        <ag-export-buttons name="bank-reconciliation" [params]="applied()" />
      </div>
    }
    @if (errLine(); as e) { <p class="field-error">{{ e }}</p> }
    @if (msg()) { <div class="alert">{{ msg() }}</div> }
    @if (loading()) { <p class="muted">Loading...</p> }

    @if (!loading() && r(); as r) {
      @if (r.statement?.undone; as u) {
        <p class="alert br-undone">UNDONE on {{ u.at | appDate }} by {{ u.by?.name }}: {{ u.reason }}. This statement is superseded.</p>
      }
      <ag-report-head [meta]="r" [title]="r.statement ? 'Bank reconciliation statement' : 'Unreconciled bank entries'" [basis]="r.basis" [periodText]="'As at ' + (r.as_at | appDate)" />
      @if (r.filter_note) { <p class="warn br-filter">{{ r.filter_note }}</p> }
      @for (a of r.accounts; track a.account.id) {
        <section class="book">
          <h2>{{ a.account.branch.code }} · {{ a.account.name }}@if (a.account.code) { ({{ a.account.code }}) } —
            @if (a.last_statement; as l) { last reconciled to {{ label(l.statement_date) }} (statement balance {{ balanceText(l.statement_balance) }}) }
            @else { never reconciled }</h2>
          <div class="card br-card-wrap">
            @if (!a.rows.length) {
              <p class="muted br-empty">{{ a.empty_text }}</p>
            } @else {
              <table class="list br-table">
                <thead><tr><th>Date</th><th>Ref</th><th>Type</th><th>Description / payee</th><th>Reference</th><th class="num">In</th><th class="num">Out</th><th class="num">Age</th></tr></thead>
                <tbody>
                  @for (l of a.rows; track l.entry_id) {
                    <tr>
                      <td>{{ l.date | appDate }}</td><td class="mono"><a [routerLink]="['/transactions', l.transaction_id]">{{ l.ref_no }}</a></td>
                      <td>{{ typeLabel(l.type) }} @if (l.reversal_of_ref) { <span class="tag">Reversal of {{ l.reversal_of_ref }}</span> }</td>
                      <td>{{ l.description }} @if (l.stale) { <span class="tag" data-s="void">Stale cheque (over 6 months)</span> }</td>
                      <td>{{ l.reference }}</td>
                      <td class="num pos">{{ +l.in ? (l.in | money) : '' }}</td><td class="num">{{ +l.out ? (l.out | money) : '' }}</td>
                      <td class="num">{{ l.age_days }}</td>
                    </tr>
                  }
                </tbody>
              </table>
              <div class="br-cards">
                @for (l of a.rows; track l.entry_id) {
                  <div class="br-card">
                    <div><span>{{ l.date | appDate }}</span><b [class.pos]="+l.in">{{ +l.in ? (l.in | money) : '-' + (l.out | money) }}</b></div>
                    <div><span class="mono">{{ l.ref_no }} · {{ typeLabel(l.type) }}</span></div>
                    <div><span style="overflow-wrap:anywhere">{{ l.description }}@if (l.reference) { · {{ l.reference }} }</span></div>
                    <div><span class="muted">Age {{ l.age_days }} days</span>@if (l.stale) { <span class="tag" data-s="void">Stale cheque (over 6 months)</span> }</div>
                  </div>
                }
              </div>
              @if (a.rows_not_shown) { <p class="warn br-more">{{ a.rows_not_shown }} more {{ a.rows_not_shown === 1 ? 'line is' : 'lines are' }} not shown here; they are in the totals and in the Excel export.</p> }
            }
            <table class="br-totals">
              <tr><td>Book balance at {{ label(r.as_at) }}</td><td class="num">{{ a.book_balance | money }}</td></tr>
              <tr><td>less uncleared receipts</td><td class="num">{{ a.uncleared_receipts | money }}</td></tr>
              <tr><td>add uncleared payments</td><td class="num">{{ a.uncleared_payments | money }}</td></tr>
              @if (a.cleared_booked_later_rows.length) {
                <tr><td>add/less entries cleared by the bank on or before {{ label(r.as_at) }}, booked after</td><td class="num">{{ a.cleared_booked_later | money }}</td></tr>
                @for (l of a.cleared_booked_later_rows; track l.entry_id) {
                  <tr class="muted"><td>&nbsp;&nbsp;{{ l.ref_no }} dated {{ l.date | appDate }}, cleared {{ l.cleared_date | appDate }} <span class="tag att-late">Cleared before booked</span></td>
                    <td class="num">{{ +l.in ? (l.in | money) : '-' + (l.out | money) }}</td></tr>
                }
              }
              <tr class="b"><td>= Expected bank balance</td><td class="num">{{ a.expected_bank_balance | money }}</td></tr>
              @if (r.statement; as s) {
                <tr class="b"><td>Balance per bank statement</td><td class="num">{{ balanceText(s.statement_balance) }}</td></tr>
                <tr class="b"><td>Difference</td><td class="num" [class.neg]="+s.difference !== 0">{{ s.difference | money }}</td></tr>
              }
            </table>
          </div>
        </section>
      } @empty { <div class="card"><p class="muted">No bank accounts in scope.</p></div> }

      @if (r.statement; as s) {
        <!-- AG-033b S3: after the statement balance / difference, before the late entries. -->
        <h2>Bank statement copy</h2>
        <div class="card bsc">
          @for (a of active(s.attachments); track a.id) {
            <div class="bsc-card">Bank statement attached:
              <button type="button" class="link bsc-name" [disabled]="busy()" (click)="download(s.account_id, a)">{{ a.original_name }}</button>
              ({{ a.extension.toUpperCase() }}, {{ size(a.size_bytes) }}), uploaded by {{ a.uploaded_by?.name }} on {{ when(a.created_at) }},
              sha256 <span class="mono" [title]="a.sha256">{{ a.sha256.slice(0, 12) }}…</span>{{ tags(a) }}
            </div>
          } @empty {
            <div class="bsc-card muted">No bank statement copy attached. File the paper bank statement with this printout.</div>
          }
          @for (a of removed(s.attachments); track a.id) {
            <div class="bsc-card att-removed"><span class="att-file" [title]="a.sha256">{{ removedText(a) }}</span>
              @if (auth.can('audit.view')) { <button type="button" class="link" [disabled]="busy()" (click)="download(s.account_id, a)">Download</button> }
            </div>
          }
          @if (fileMsg()) { <div class="alert">{{ fileMsg() }}</div> }
        </div>
        @if (s.changed_since_completion) { <p class="warn">Changed since completion</p> }
        @if (s.late_entries.length) {
          <h2>Late entries (added or voided after the statement was completed)</h2>
          <div class="card">
            @for (l of s.late_entries; track $index) {
              <div>{{ l.ref_no }} · {{ l.date | appDate }} · {{ l.amount | money }} · {{ l.kind === 'voided' ? 'Voided' : 'Recorded' }} by {{ l.by ?? '-' }} at {{ l.at | appDateTime }}</div>
            }
          </div>
        }
        <p>Prepared by {{ s.completed_by?.name }} on {{ s.completed_at | appDateTime }}</p>
        <p>{{ s.self_count }} of {{ s.lines_count }} {{ s.lines_count === 1 ? 'line was' : 'lines were' }} recorded by the preparer</p>
        <div class="signatures print-only">
          @for (who of ['Checked by', 'Approved by']; track who) { <div><b>{{ who }}</b><span>Name</span><span>Signature</span><span>Date</span></div> }
        </div>
      }
    }
  `,
})
export class BankReconciliationReportComponent implements OnInit {
  auth = inject(AuthService);
  private http = inject(HttpClient);
  private router = inject(Router);
  private route = inject(ActivatedRoute);
  today = colomboToday();
  f: { as_at: string; older_than: number | null } = { as_at: this.today, older_than: null };
  branches = signal<number[]>([]);
  accounts = signal<Account[]>([]);
  chosen = signal<number[]>([]);
  statementId = signal<number | null>(null);
  r = signal<BankReport | null>(null);
  loading = signal(false);
  msg = signal('');
  errs = signal<Record<string, string>>({});
  applied = signal<ReportParams>({});
  banks = computed(() => this.accounts().filter(a => a.type === 'bank'));
  visibleBanks = computed(() => {
    const b = this.branches();
    return this.auth.isAllBranches() && b.length ? this.banks().filter(a => b.includes(a.branch_id)) : this.banks();
  });
  private money = new MoneyPipe();

  errLine() { return Object.values(this.errs()).join(' '); }

  ngOnInit() {
    const { q, branches } = readQuery(this.route, this.auth.isAllBranches());
    const sid = Number(q.get('statement_id') ?? 0);
    this.statementId.set(sid > 0 ? sid : null);
    this.f = { as_at: q.get('as_at') ?? this.today, older_than: q.get('older_than') ? Number(q.get('older_than')) : null };
    this.branches.set(branches);
    this.chosen.set(q.getAll('account_ids').map(Number).filter(n => n > 0));
    // The account picker needs account.view; without it the report covers every bank account in scope.
    if (this.auth.can('account.view')) {
      this.http.get<Account[]>('/api/v1/accounts').subscribe({ next: a => this.accounts.set(a), error: () => this.accounts.set([]) });
    }
    this.load();
  }

  load() {
    const visible = new Set(this.visibleBanks().map(a => a.id));
    const params: ReportParams = this.statementId()
      ? { statement_id: this.statementId() }
      : { as_at: this.f.as_at, branches: this.branches(), account_ids: this.banks().length ? this.chosen().filter(id => visible.has(id)) : this.chosen(),
        older_than: this.f.older_than ?? undefined };
    this.applied.set(params);
    writeQuery(this.router, this.route, params);
    this.loading.set(true);
    this.http.get<BankReport>('/api/v1/reports/bank-reconciliation', { params: toHttpParams(params) }).subscribe({
      next: r => { this.r.set(r); this.msg.set(''); this.errs.set({}); this.loading.set(false); },
      error: e => { this.r.set(null); this.errs.set(fieldErrors(e)); this.msg.set(this.errLine() ? '' : reportError(e)); this.loading.set(false); },
    });
  }

  toggle(id: number) { this.chosen.update(s => (s.includes(id) ? s.filter(x => x !== id) : [...s, id])); }
  typeLabel(t: string) { return TYPE_LABELS[t] ?? t; }
  label(d: string) { return dayLabel(d); }
  /** Review finding 3: "Overdrawn 2,500.00" for a negative statement balance. */
  balanceText(v: string) { return v.startsWith('-') ? `Overdrawn ${this.money.transform(v.slice(1))}` : this.money.transform(v); }
  print() { window.print(); }

  // ------------------------------------------------------------------ AG-033b bank statement copy (S3)

  busy = signal(false);
  fileMsg = signal('');
  active(rows: StatementFile[] | undefined) { return (rows ?? []).filter(a => !a.removed_at); }
  removed(rows: StatementFile[] | undefined) { return (rows ?? []).filter(a => !!a.removed_at); }
  size(n: number) { return fileSize(n); }
  when(iso: string | null) { return colomboDateTime(iso); }
  /** The reprint tags (" - added after close", " - uploaded by the preparer", " - also on the <date> statement (<status>)"). */
  tags(a: StatementFile) {
    return (a.added_after === 'close' ? ' - added after close' : '') + (a.by_preparer ? ' - uploaded by the preparer' : '')
      + a.also_on.map(o => ` - also on the ${o.statement_date} statement (${o.status})`).join('');
  }
  removedText(a: StatementFile) {
    const who = `, by ${a.removed_by?.name ?? '-'} on ${colomboDateTime(a.removed_at).slice(0, 10)}: ${a.removal_reason ?? ''}`;
    return (a.replaced_by_id ? `Replaced by ${a.replaced_by_name ?? 'a new file'}: ` : 'Removed: ') + `${a.original_name}, sha256 ${a.sha256.slice(0, 12)}…${who}`;
  }

  async download(accountId: number, a: StatementFile) {
    if (this.busy()) return;
    this.busy.set(true);
    this.fileMsg.set('');
    try {
      saveFile(await fetchStoredFile(this.http, `${statementFilesUrl(accountId, a.bank_statement_id)}/${a.id}/download`, a.original_name, a.mime));
    } catch (e) {
      this.fileMsg.set(await blobErrorText(e));
    } finally {
      this.busy.set(false);
    }
  }
}
