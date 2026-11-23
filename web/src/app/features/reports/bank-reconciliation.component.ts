import { Component, OnInit, computed, inject, signal } from '@angular/core';
import { HttpClient, HttpErrorResponse } from '@angular/common/http';
import { FormsModule } from '@angular/forms';
import { ActivatedRoute, Router, RouterLink } from '@angular/router';
import { AuthService } from '../../core/auth.service';
import { AppDatePipe, AppDateTimePipe } from '../../core/app-date.pipe';
import { colomboToday } from '../../core/dates';
import { Account } from '../../core/models';
import { MoneyPipe, TYPE_LABELS } from '../../core/money.pipe';
import { errorText } from '../../core/api.interceptor';
import { ToastService } from '../../core/toast.service';
import {
  COMPARED_LABELS, CheckActions, CheckStatus, StatementCheck, checkHistoryText, checkTags, colomboMinute, dayLabel, personName, recheckText,
} from '../../core/reconciliation';
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
    id: number; status: 'completed' | 'undone'; statement_date: string; statement_balance: string; overdrawn: boolean;
    expected_bank_balance: string; difference: string; changed_since_completion: boolean;
    late_entries: { entry_id: number; ref_no: string; date: string; amount: string; kind: 'recorded' | 'voided'; by: string | null; at: string; after_check?: boolean }[];
    lines_count: number; self_count: number; completed_by: Person | null; completed_at: string;
    undone: { by: Person | null; at: string; reason: string } | null;
    /** AG-033d: the check block (section 5). */
    check_status?: CheckStatus; checks?: StatementCheck[]; late_since_check?: number | null; check_actions?: CheckActions;
    /** AG-033d: statement files (AG-033b) uploaded by the active checker; null when copies are not supported. */
    checker_attachment_ids?: number[] | null;
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
        @if (s.changed_since_completion) { <p class="warn">Changed since completion</p> }
        <!-- AG-033d S3: the Check block, after the balance / difference (and the AG-033b copy), before the late entries. -->
        <section class="card ck-block" aria-labelledby="ck-title">
          <h2 id="ck-title">Check</h2>
          @if (active(); as c) {
            <div class="ck-checked">
              <span class="ck-l1"><b>Checked by {{ name(c.checked_by) }}</b></span>
              <span class="ck-l2"> on {{ minute(c.checked_at) }} (Asia/Colombo) against {{ compared(c) }}.</span>
            </div>
            @if (c.note) { <div class="ck-note">Note: {{ c.note }}</div> }
            @if (tags(c).length) {
              <div class="ck-tags">@for (t of tags(c); track t) { <span class="tag att-late">{{ t }}</span> }</div>
            }
            @if (s.late_since_check) { <p class="warn ck-recheck">{{ recheck(s.late_since_check) }}</p> }
          } @else {
            <p class="muted ck-none">Not checked in the system.</p>
          }
          @if (ended().length) {
            <ul class="ck-history">@for (c of ended(); track c.id) { <li>{{ history(c) }}</li> }</ul>
          }
          @if (s.check_actions; as a) {
            @if (a.can_check || a.blocked_reason || a.can_withdraw) {
              <div class="ck-actions no-print">
                @if (a.can_check || a.blocked_reason) {
                  <div class="ck-act">
                    <button type="button" [disabled]="!a.can_check || saving()" (click)="openCheck()">{{ s.check_status === 'recheck' ? 'Re-check' : 'Record check' }}</button>
                    @if (a.blocked_reason) { <small class="muted ck-why">{{ a.blocked_reason }}</small> }
                  </div>
                }
                @if (a.can_withdraw) { <button type="button" class="ghost" [disabled]="saving()" (click)="openWithdraw()">Withdraw check</button> }
              </div>
            }
          }
        </section>
        @if (s.late_entries.length) {
          <h2>Late entries (added or voided after the statement was completed)</h2>
          <div class="card">
            @for (l of s.late_entries; track $index) {
              <div>{{ l.ref_no }} · {{ l.date | appDate }} · {{ l.amount | money }} · {{ l.kind === 'voided' ? 'Voided' : 'Recorded' }} by {{ l.by ?? '-' }} at {{ l.at | appDateTime }}
                @if (l.after_check) { <span class="tag att-late">after check</span> }</div>
            }
          </div>
        }
        <p>Prepared by {{ s.completed_by?.name }} on {{ s.completed_at | appDateTime }}</p>
        <p>{{ s.self_count }} of {{ s.lines_count }} {{ s.lines_count === 1 ? 'line was' : 'lines were' }} recorded by the preparer</p>
        <!-- AG-033d D1: the recorded check replaces the blank Checked by line; no Approved by line. -->
        <div class="signatures print-only">
          @if (active(); as c) {
            <p><b>Checked by {{ name(c.checked_by) }} on {{ minute(c.checked_at) }} (recorded in Account-gold, check #{{ c.id }})@if (s.late_since_check) { - Re-check needed }</b></p>
          } @else {
            <div><b>Checked by</b><span>Name</span><span>Signature</span><span>Date</span></div>
            <p class="muted">Not checked in the system</p>
          }
        </div>
      }
    }
    @if (loading() && statementId()) { <p class="ck-skel" aria-hidden="true">&nbsp;</p> }

    @if (dialog() === 'check' && r()?.statement; as s) {
      <div class="att-backdrop" (click)="close()"></div>
      <div class="card att-dialog ap-dialog ck-sheet" role="dialog" aria-modal="true" aria-labelledby="ck-dlg-title">
        <div class="ck-sheet-head">
          <h2 id="ck-dlg-title">{{ s.check_status === 'recheck' ? 'Re-check' : 'Record check' }}</h2>
          <button type="button" class="ghost ck-close" aria-label="Close" (click)="close()">×</button>
        </div>
        <div class="ck-sheet-body">
          <p class="ck-facts">{{ accountName() }} · statement of {{ label(s.statement_date) }} · balance {{ balanceText(s.statement_balance) }} · late entries {{ s.late_entries.length }}</p>
          <fieldset class="ck-radios">
            <legend>Compared with</legend>
            <label class="ck-opt"><input type="radio" name="compared_with" value="bank_statement" [ngModel]="comparedWith()" (ngModelChange)="comparedWith.set($event)" />
              <span>The bank's statement (paper or PDF received from the bank)</span></label>
            <label class="ck-opt"><input type="radio" name="compared_with" value="internet_banking" [ngModel]="comparedWith()" (ngModelChange)="comparedWith.set($event)" />
              <span>Internet banking</span></label>
            <small class="muted ck-hint">Not the uploaded copy alone. Use the bank's balance at the end of {{ s.statement_date }}, not today's balance.</small>
          </fieldset>
          <label class="ck-opt ck-confirm"><input type="checkbox" name="confirmed" [ngModel]="confirmed()" (ngModelChange)="confirmed.set($event)" />
            <span>I agreed the statement balance ({{ balanceText(s.statement_balance) }}) to the bank's own record of the balance at the end of {{ s.statement_date }}, and I checked that the uncleared items on this statement are genuine (they appear on the bank's record after {{ s.statement_date }}, or I saw the cheque book or deposit record).</span></label>
          <label class="ck-note-field">Note {{ noteRequired() ? '(10-500 characters)' : '(optional, up to 500 characters)' }}
            <textarea rows="3" name="note" maxlength="500" [ngModel]="note()" (ngModelChange)="note.set($event)"></textarea>
            @if (noteRequired()) { <small class="muted">Explain the late entries you checked.</small> }
          </label>
          @if (dlgError()) { <div class="alert">{{ dlgError() }}</div> }
        </div>
        <div class="att-dialog-actions ck-sheet-bar">
          <button type="button" class="ghost" (click)="close()">Cancel</button>
          <button type="button" [disabled]="saving() || !checkValid()" (click)="saveCheck()">Save</button>
        </div>
      </div>
    }

    @if (dialog() === 'withdraw' && active(); as c) {
      <div class="att-backdrop" (click)="close()"></div>
      <div class="card att-dialog ap-dialog ck-sheet" role="dialog" aria-modal="true" aria-labelledby="ck-wd-title">
        <div class="ck-sheet-head">
          <h2 id="ck-wd-title">Withdraw check</h2>
          <button type="button" class="ghost ck-close" aria-label="Close" (click)="close()">×</button>
        </div>
        <div class="ck-sheet-body">
          <p>Withdraw {{ c.checked_by?.id === auth.me()?.id ? 'your check' : 'the check by ' + name(c.checked_by) }} of {{ accountName() }}, {{ label(r()!.statement!.statement_date) }}? The check stays in the history.</p>
          <label class="ck-note-field">Reason (10-500 characters)
            <textarea rows="3" name="reason" maxlength="500" [ngModel]="reason()" (ngModelChange)="reason.set($event)"></textarea></label>
          @if (dlgError()) { <div class="alert">{{ dlgError() }}</div> }
        </div>
        <div class="att-dialog-actions ck-sheet-bar">
          <button type="button" class="ghost" (click)="close()">Cancel</button>
          <button type="button" [disabled]="saving() || reason().trim().length < 10" (click)="saveWithdraw(c)">Withdraw check</button>
        </div>
      </div>
    }
  `,
})
export class BankReconciliationReportComponent implements OnInit {
  auth = inject(AuthService);
  private http = inject(HttpClient);
  private toast = inject(ToastService);
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
  /** AG-033d dialogs. */
  dialog = signal<'check' | 'withdraw' | null>(null);
  saving = signal(false);
  dlgError = signal('');
  comparedWith = signal<string>('');
  confirmed = signal(false);
  note = signal('');
  reason = signal('');
  active = computed(() => this.r()?.statement?.checks?.find(c => c.status === 'active') ?? null);
  ended = computed(() => (this.r()?.statement?.checks ?? []).filter(c => c.status !== 'active'));
  accountName = computed(() => this.r()?.accounts[0]?.account.name ?? '');
  /** R3 rule 5 (the API decides): changed since completion, late entries, or a re-check. */
  noteRequired = computed(() => {
    const s = this.r()?.statement;
    return !!s && (s.changed_since_completion || s.late_entries.length > 0 || !!this.active());
  });
  checkValid = computed(() => {
    const n = this.note().trim();
    return !!this.comparedWith() && this.confirmed() && n.length <= 500 && (!this.noteRequired() || n.length >= 10);
  });

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

  // ------------------------------------------------------------------ AG-033d check

  name(p: Person | null | undefined) { return personName(p); }
  minute(iso: string) { return colomboMinute(iso); }
  compared(c: StatementCheck) { return COMPARED_LABELS[c.compared_with] ?? c.compared_with; }
  tags(c: StatementCheck) { return checkTags(c); }
  history(c: StatementCheck) { return checkHistoryText(c); }
  recheck(n: number) { return recheckText(n); }

  openCheck() {
    this.comparedWith.set('');
    this.confirmed.set(false);
    this.note.set('');
    this.dlgError.set('');
    this.dialog.set('check');
  }

  openWithdraw() {
    this.reason.set('');
    this.dlgError.set('');
    this.dialog.set('withdraw');
  }

  close() { if (!this.saving()) this.dialog.set(null); }

  private base(): string | null {
    const s = this.r()?.statement, a = this.r()?.accounts[0]?.account;
    return s && a ? `/api/v1/accounts/${a.id}/reconciliation/statements/${s.id}/checks` : null;
  }

  /** 403 / 422: the API message in the dialog; a network error keeps the values. */
  private fail(e: unknown) {
    this.saving.set(false);
    this.dlgError.set((e as HttpErrorResponse)?.status === 0 ? 'Could not save the check. Try again.' : errorText(e));
  }

  saveCheck() {
    const url = this.base();
    if (!url || this.saving() || !this.checkValid()) return;
    this.saving.set(true);
    this.dlgError.set('');
    const note = this.note().trim();
    this.http.post(url, { compared_with: this.comparedWith(), confirmed: true, note: note || null }).subscribe({
      next: () => { this.saving.set(false); this.dialog.set(null); this.toast.show('Check recorded.'); this.load(); },
      error: e => this.fail(e),
    });
  }

  saveWithdraw(c: StatementCheck) {
    const url = this.base();
    if (!url || this.saving() || this.reason().trim().length < 10) return;
    this.saving.set(true);
    this.dlgError.set('');
    this.http.post(`${url}/${c.id}/withdraw`, { reason: this.reason().trim() }).subscribe({
      next: () => { this.saving.set(false); this.dialog.set(null); this.toast.show('Check withdrawn.'); this.load(); },
      error: e => this.fail(e),
    });
  }
}
