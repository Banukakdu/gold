import { Component, HostListener, OnInit, computed, inject, input, signal } from '@angular/core';
import { HttpClient, HttpErrorResponse } from '@angular/common/http';
import { FormsModule } from '@angular/forms';
import { Router, RouterLink } from '@angular/router';
import { firstValueFrom } from 'rxjs';
import { AuthService } from '../../core/auth.service';
import { AppDatePipe, AppDateTimePipe } from '../../core/app-date.pipe';
import { errorText } from '../../core/api.interceptor';
import { colomboToday } from '../../core/dates';
import { Account } from '../../core/models';
import { MoneyPipe, TYPE_LABELS } from '../../core/money.pipe';
import { ToastService } from '../../core/toast.service';
import { dayLabel } from '../../core/reconciliation';
import { statementFilesText } from '../../core/attachments';
import { StatementFilesComponent } from './statement-files.component';

export { dayLabel };

export interface Person { id: number; name: string; tag: string | null }
export interface ReconLine {
  entry_id: number; transaction_id: number; date: string; ref_no: string; type: string; status: string;
  reversal_of_ref: string | null; description: string; reference: string | null; in: string; out: string;
  mark: { cleared_date: string; marked_by: Person | null; marked_at: string } | null;
  frozen: { statement_id: number; statement_date: string } | null;
  self: boolean; cleared_before_booked: boolean;
}
export interface ReconStatement {
  id: number; statement_date: string; statement_balance: string; lines_count: number; self_count: number; status: 'completed' | 'undone';
  completed_by: Person | null; completed_at: string; undone_by: Person | null; undone_at: string | null; undo_reason: string | null;
  changed_since_completion: boolean; can_undo: boolean;
  /** AG-033b: active bank statement files, and removed ones. */
  attachments_count?: number; attachments_removed_count?: number;
}
export interface Reconciliation {
  account: { id: number; code: string | null; name: string; status: string; branch: { id: number; code: string; name: string } };
  last_statement: { id: number; statement_date: string; statement_balance: string } | null;
  book_balance_at: string | null; reconciled_at: string | null;
  /** Saved ticks cleared in (last statement, S] on lines not listed (dated after S, or past the line cap). */
  cleared_not_listed: string | null; lines_limited: boolean;
  lines: ReconLine[]; late_entries_count: number; statements: ReconStatement[];
}

export const BALANCE_PATTERN = /^-?\d{1,13}(\.\d{1,2})?$/;
/** Signed decimal string -> integer cents (no floats); null when not a money string. */
export function signedCents(v: unknown): number | null {
  const s = String(v ?? '').trim();
  const m = /^(-?)(\d{1,13})(?:\.(\d{1,2}))?$/.exec(s);
  if (!m) return null;
  const c = Number(m[2]) * 100 + Number((m[3] ?? '').padEnd(2, '0'));
  return m[1] ? -c : c;
}
export function centsText(c: number): string {
  const neg = c < 0;
  const a = Math.abs(c);
  return `${neg ? '-' : ''}${Math.floor(a / 100).toLocaleString('en-LK')}.${String(a % 100).padStart(2, '0')}`;
}
/** Last day of the previous month (Asia/Colombo today). */
function previousMonthEnd(today: string): string {
  const [y, m] = today.split('-').map(Number);
  const d = new Date(Date.UTC(y, m - 1, 0));
  return d.toISOString().slice(0, 10);
}

/**
 * AG-033 S1 Bank reconciliation (docs/specs/bank-reconciliation.md): /accounts/reconcile (picker) and
 * /accounts/:id/reconcile. Ticks are kept client side until Save; the summary is recomputed in cents on every tick.
 */
@Component({
  standalone: true,
  imports: [FormsModule, RouterLink, MoneyPipe, AppDatePipe, AppDateTimePipe, StatementFilesComponent],
  template: `
    <h1>Bank reconciliation @if (r(); as r) { <small class="muted">{{ r.account.branch.code }} · {{ r.account.name }}</small> }</h1>
    <div class="rc-pick">
      <label>Bank account
        <select name="account" [ngModel]="accountId()" (ngModelChange)="pick($event)">
          <option [ngValue]="null">Choose a bank account</option>
          @for (g of groups(); track g.label) {
            @if (g.label) {
              <optgroup [label]="g.label">
                @for (a of g.accounts; track a.id) { <option [ngValue]="a.id">{{ a.name }}{{ a.status === 'closed' ? ' (closed)' : '' }}</option> }
              </optgroup>
            } @else {
              @for (a of g.accounts; track a.id) { <option [ngValue]="a.id">{{ a.name }}{{ a.status === 'closed' ? ' (closed)' : '' }}</option> }
            }
          }
        </select>
      </label>
    </div>
    @if (loadedAccounts() && !banks().length) { <div class="card"><p class="muted">No bank accounts in this branch.</p></div> }
    @if (msg()) { <div class="alert">{{ msg() }}</div> }

    @if (r(); as r) {
      @if (!r.last_statement) {
        <!-- Review finding 7 -->
        <p class="alert info">First reconciliation: choose a statement date after all cheques and deposits outstanding at go-live have cleared, and tick the opening balance line.</p>
      }
      @if (r.late_entries_count > 0 && r.last_statement) {
        <p class="warn rc-late">@if (r.late_entries_count === 1) {1 entry dated on or before {{ label(r.last_statement.statement_date) }} was added after that statement was completed. It is listed and can be ticked with a later cleared date.} @else { {{ r.late_entries_count }} entries dated on or before {{ label(r.last_statement.statement_date) }} were added after that statement was completed. They are listed and can be ticked with a later cleared date.}</p>
      }
      <div class="rc-head">
        <label>Statement date
          <input type="date" name="statement_date" [max]="today" [ngModel]="statementDate()" (ngModelChange)="setDate($event)" />
          @if (dateError()) { <span class="field-error">{{ dateError() }}</span> }
        </label>
        <label>Statement balance
          <input name="statement_balance" inputmode="decimal" placeholder="0.00" [ngModel]="balance()" (ngModelChange)="balance.set($event)" />
          @if (overdrawn(); as o) { <span class="neg rc-od">Overdrawn {{ o }}</span> }
          @if (balance() && !balanceOk()) { <span class="field-error">Enter the statement balance (for example 125000.00 or -2500.00).</span> }
          <small class="muted">Closing balance on the bank statement. A balance in your favour (shown as CR by most banks) is positive; an overdrawn balance (DR / OD) is entered with a minus sign. On the bank statement, deposits are credits and cheques/charges are debits.</small>
        </label>
        <label class="rc-later"><input type="checkbox" name="show_later" [ngModel]="showLater()" (ngModelChange)="showLater.set($event); load()" /> Show later entries</label>
        <label class="rc-later"><input type="checkbox" name="show_reconciled" [ngModel]="showReconciled()" (ngModelChange)="showReconciled.set($event); load()" /> Show lines of the last statement</label>
      </div>

      <div class="rc-summary" role="status">
        <div class="rc-tile rc-diff" [class.ok]="difference() === 0" [class.bad]="difference() !== 0 && difference() !== null">
          <span>Difference</span><b>{{ difference() === null ? '—' : text(difference()!) }}</b></div>
        <div class="rc-tile"><span>Last statement</span><b>{{ r.last_statement ? (r.last_statement.statement_balance | money) : '0.00' }}</b>
          <small>{{ r.last_statement ? label(r.last_statement.statement_date) : 'None' }}</small></div>
        <div class="rc-tile"><span>Receipts ticked (+)</span><b>{{ text(sums().receipts) }}</b></div>
        <div class="rc-tile"><span>Payments ticked (-)</span><b>{{ text(sums().payments) }}</b></div>
        @if (notListed()) {
          <div class="rc-tile rc-notlisted" title="Ticks saved on lines not shown: dated after the statement date (tick Show later entries to see them).">
            <span>Ticked, not shown (+/-)</span><b>{{ text(notListed()) }}</b></div>
        }
        <div class="rc-tile"><span>Reconciled balance</span><b>{{ reconciled() === null ? '—' : text(reconciled()!) }}</b></div>
        <div class="rc-tile"><span>Statement balance</span><b>{{ balanceOk() ? text(balanceCents()!) : '—' }}</b></div>
        <div class="rc-tile"><span>Book balance at statement date</span><b>{{ r.book_balance_at === null ? '—' : (r.book_balance_at | money) }}</b></div>
      </div>

      <div class="rc-actions">
        <button type="button" class="ghost" (click)="tickAll()">Tick all shown</button>
        <button type="button" class="ghost" (click)="untickAll()">Untick all</button>
        <button type="button" class="ghost" (click)="load()">Refresh</button>
        @if (!auth.isAllBranches() && auth.can('expense.create')) {
          <a class="rc-link" [routerLink]="['/transactions/new/expense']" [queryParams]="{ account_id: r.account.id }" target="_blank">Record a bank entry</a>
        } @else if (!auth.isAllBranches() && auth.can('income.create')) {
          <a class="rc-link" [routerLink]="['/transactions/new/income']" [queryParams]="{ account_id: r.account.id }" target="_blank">Record a bank entry</a>
        }
      </div>

      @if (r.lines_limited) {
        <p class="warn rc-limited">Showing the oldest {{ r.lines.length }} lines only. Tick and complete these, or choose an earlier statement date, to see the rest.</p>
      }
      @if (!r.lines.length) {
        <div class="card"><p class="muted">Nothing to reconcile up to {{ label(statementDate()) }}.</p></div>
      } @else {
        <div class="card rc-card">
          <table class="list rc-table">
            <thead><tr><th></th><th>Date</th><th>Ref</th><th>Type</th><th>Description / payee</th><th>Reference</th><th class="num">In</th><th class="num">Out</th><th>Cleared on</th><th>Marked by</th></tr></thead>
            <tbody>
              @for (l of r.lines; track l.entry_id) {
                <tr [class.rc-frozen]="l.frozen">
                  <td><label class="rc-check" [title]="frozenTip(l)"><input type="checkbox" [checked]="isTicked(l)" [disabled]="!!l.frozen" (change)="toggle(l, $any($event.target).checked)" [attr.aria-label]="'Tick ' + l.ref_no" /></label></td>
                  <td>{{ l.date | appDate }}</td>
                  <td class="mono"><a [routerLink]="['/transactions', l.transaction_id]" target="_blank">{{ l.ref_no }}</a></td>
                  <td>{{ typeLabel(l.type) }}
                    @if (l.status === 'reversed') { <span class="tag" data-s="reversed">Reversed</span> }
                    @if (l.reversal_of_ref) { <span class="tag">Reversal of {{ l.reversal_of_ref }}</span> }
                    @if (beforeBooked(l)) { <span class="tag att-late">Cleared before booked</span> }</td>
                  <td>{{ l.description }}</td>
                  <td>{{ l.reference }}</td>
                  <td class="num pos">{{ +l.in ? (l.in | money) : '' }}</td>
                  <td class="num">{{ +l.out ? (l.out | money) : '' }}</td>
                  <td>@if (isTicked(l)) { <input type="date" class="rc-date" [max]="today" [disabled]="!!l.frozen" [value]="cleared(l)" (change)="setCleared(l, $any($event.target).value)" [attr.aria-label]="'Cleared on for ' + l.ref_no" /> }</td>
                  <td>{{ l.mark?.marked_by?.name }} @if (l.mark?.marked_by?.tag; as t) { <span class="tag" data-s="inactive">{{ t }}</span> }
                    @if (l.self) { <span class="tag">You recorded this</span> }</td>
                </tr>
              }
            </tbody>
          </table>
          <div class="rc-cards">
            @for (l of r.lines; track l.entry_id) {
              <div class="rc-line" [class.rc-frozen]="l.frozen">
                <div class="rc-l1">
                  <label class="rc-check" [title]="frozenTip(l)"><input type="checkbox" [checked]="isTicked(l)" [disabled]="!!l.frozen" (change)="toggle(l, $any($event.target).checked)" [attr.aria-label]="'Tick ' + l.ref_no" /></label>
                  <span>{{ l.date | appDate }}</span>
                  <b class="rc-amt" [class.pos]="+l.in">{{ +l.in ? (l.in | money) : '-' + (l.out | money) }}</b>
                </div>
                <div class="rc-l2"><a class="mono" [routerLink]="['/transactions', l.transaction_id]" target="_blank">{{ l.ref_no }}</a> · {{ typeLabel(l.type) }}
                  @if (l.status === 'reversed') { <span class="tag" data-s="reversed">Reversed</span> }
                  @if (l.reversal_of_ref) { <span class="tag">Reversal of {{ l.reversal_of_ref }}</span> }
                  @if (beforeBooked(l)) { <span class="tag att-late">Cleared before booked</span> }
                  @if (l.self) { <span class="tag">You recorded this</span> }</div>
                <div class="rc-l3">{{ l.description }}@if (l.reference) { · {{ l.reference }} }</div>
                @if (isTicked(l)) {
                  <label class="rc-l4">Cleared on <input type="date" class="rc-date" [max]="today" [disabled]="!!l.frozen" [value]="cleared(l)" (change)="setCleared(l, $any($event.target).value)" /></label>
                }
                @if (l.frozen) { <small class="muted">{{ frozenTip(l) }}</small> }
              </div>
            }
          </div>
        </div>
      }

      <div class="rc-bar">
        <button type="button" class="ghost" [disabled]="busy() || !changes().count" (click)="save()">Save{{ changes().count ? ' (' + changes().count + ')' : '' }}</button>
        <button type="button" [disabled]="busy() || !canComplete()" (click)="complete()">Complete reconciliation</button>
      </div>

      <details class="rc-history" open>
        <summary>History ({{ r.statements.length }})</summary>
        @if (!r.statements.length) { <p class="muted">No statement completed yet.</p> }
        @for (s of r.statements; track s.id) {
          <div class="rc-hrow">
            <div><b>{{ label(s.statement_date) }}</b> · {{ balanceText(s.statement_balance) }} · {{ s.lines_count }} {{ s.lines_count === 1 ? 'line' : 'lines' }}
              <span class="tag" [attr.data-s]="s.status === 'undone' ? 'void' : 'balanced'">{{ s.status === 'undone' ? 'Undone' : 'Completed' }}</span>
              @if (s.changed_since_completion) { <span class="tag att-late">Changed since completion</span> }</div>
            <div class="muted">Prepared by {{ s.completed_by?.name }}@if (s.completed_by?.tag; as t) { ({{ t }}) } on {{ s.completed_at | appDateTime }} · {{ s.self_count }} of {{ s.lines_count }} {{ s.lines_count === 1 ? 'line was' : 'lines were' }} recorded by the preparer</div>
            @if (s.status === 'undone') { <div class="muted">Undone by {{ s.undone_by?.name }} on {{ s.undone_at | appDateTime }}: {{ s.undo_reason }}</div> }
            <!-- AG-033b S1a: the Bank statement column; the cell opens the row's Files panel (one at a time). -->
            <div class="rc-bs">Bank statement:
              <button type="button" class="link rc-bs-cell" [attr.aria-expanded]="filesOf() === s.id" (click)="toggleFiles(s)">
                @if (noCopy(s)) { <span class="tag att-late">No copy</span>@if (s.attachments_removed_count) { ({{ s.attachments_removed_count }} removed) } } @else { {{ filesText(s) }} }
              </button>
            </div>
            <div class="rc-hact">
              <button type="button" class="ghost rc-files-btn" (click)="toggleFiles(s)">Files</button>
              @if (auth.can('report.view')) { <a class="rc-link" [routerLink]="['/reports/bank-reconciliation']" [queryParams]="{ statement_id: s.id }">View</a> }
              @if (auth.can('report.export')) { <button type="button" class="ghost" [disabled]="busy()" (click)="print(s)">Print</button> }
              @if (s.can_undo) { <button type="button" class="ghost" (click)="startUndo(s)">Undo</button> }
            </div>
            @if (filesOf() === s.id) {
              <ag-statement-files [accountId]="r.account.id" [accountName]="r.account.name" [statement]="s"
                (changed)="load()" (closed)="filesOf.set(null)" />
            }
          </div>
        }
      </details>
    }

    @if (undoing(); as s) {
      <div class="att-backdrop" (click)="undoing.set(null)"></div>
      <div class="card att-dialog ap-dialog rc-dialog" role="dialog" aria-modal="true" aria-labelledby="rc-undo-title">
        <h2 id="rc-undo-title">Undo the statement of {{ label(s.statement_date) }}?</h2>
        <p class="muted">Its ticks stay but become editable again. The statement stays in the history as Undone.</p>
        <label>Reason (10-500 characters) <textarea rows="3" name="reason" [ngModel]="reason()" (ngModelChange)="reason.set($event)" maxlength="500"></textarea></label>
        @if (undoError()) { <div class="alert">{{ undoError() }}</div> }
        <div class="att-dialog-actions">
          <button type="button" class="ghost" (click)="undoing.set(null)">Cancel</button>
          <button type="button" [disabled]="busy() || reason().trim().length < 10" (click)="undo(s)">Undo statement</button>
        </div>
      </div>
    }
  `,
})
export class ReconcileComponent implements OnInit {
  /** Route parameter (absent on /accounts/reconcile). */
  id = input<string>();
  auth = inject(AuthService);
  private http = inject(HttpClient);
  private router = inject(Router);
  private toast = inject(ToastService);
  today = colomboToday();

  accounts = signal<Account[]>([]);
  loadedAccounts = signal(false);
  banks = computed(() => this.accounts().filter(a => a.type === 'bank'));
  /** F5: All mode groups the picker by branch code. */
  groups = computed(() => {
    if (!this.auth.isAllBranches()) return [{ label: '', accounts: this.banks() }];
    const map = new Map<string, Account[]>();
    for (const a of this.banks()) map.set(a.branch?.code ?? '', [...(map.get(a.branch?.code ?? '') ?? []), a]);
    return [...map].sort(([a], [b]) => a.localeCompare(b)).map(([label, accounts]) => ({ label, accounts }));
  });
  accountId = signal<number | null>(null);

  r = signal<Reconciliation | null>(null);
  msg = signal('');
  busy = signal(false);
  statementDate = signal('');
  balance = signal('');
  showLater = signal(false);
  showReconciled = signal(false);
  /** Unsaved ticks: entry id -> cleared date (ticked) or null (unticked). */
  draft = signal<Record<number, string | null>>({});
  undoing = signal<ReconStatement | null>(null);
  reason = signal('');
  undoError = signal('');
  /** AG-033b: the statement whose Files panel is open (one at a time). */
  filesOf = signal<number | null>(null);
  private money = new MoneyPipe();

  lastDate = computed(() => this.r()?.last_statement?.statement_date ?? '');
  balanceOk = computed(() => BALANCE_PATTERN.test(this.balance().trim()));
  balanceCents = computed(() => (this.balanceOk() ? signedCents(this.balance()) : null));
  overdrawn = computed(() => { const c = this.balanceCents(); return c !== null && c < 0 ? centsText(-c) : ''; });
  dateError = computed(() => {
    const d = this.statementDate();
    if (!d) return '';
    if (d > this.today) return 'The statement date cannot be in the future.';
    if (this.lastDate() && d <= this.lastDate()) return `The statement date must be after ${dayLabel(this.lastDate())}.`;
    return '';
  });

  /** A tick counts towards the statement when its cleared date is after the last statement and on or before S. */
  private counts(date: string | null): boolean {
    const s = this.statementDate();
    return !!date && !!s && date <= s && (!this.lastDate() || date > this.lastDate());
  }
  private amount(l: ReconLine): number { return (signedCents(l.in) ?? 0) - (signedCents(l.out) ?? 0); }

  /** Receipts / payments ticked among the lines shown (not frozen), for the summary tiles. */
  sums = computed(() => {
    let receipts = 0, payments = 0;
    for (const l of this.r()?.lines ?? []) {
      if (l.frozen || !this.counts(this.clearedOf(l, this.draft()))) continue;
      receipts += signedCents(l.in) ?? 0;
      payments += signedCents(l.out) ?? 0;
    }
    return { receipts, payments };
  });
  /** reconciled(A, S) from the server plus the effect of the unsaved ticks (R2), in cents. */
  reconciled = computed(() => {
    const r = this.r();
    if (!r || r.reconciled_at === null) return null;
    let c = signedCents(r.reconciled_at) ?? 0;
    const d = this.draft();
    for (const l of r.lines) {
      if (l.frozen || !(l.entry_id in d)) continue;
      c += (this.counts(d[l.entry_id]) ? this.amount(l) : 0) - (this.counts(l.mark?.cleared_date ?? null) ? this.amount(l) : 0);
    }
    return c;
  });
  difference = computed(() => {
    const b = this.balanceCents(), rec = this.reconciled();
    return b === null || rec === null ? null : b - rec;
  });
  changes = computed(() => {
    const mark: { entry_id: number; cleared_date: string }[] = [];
    const unmark: number[] = [];
    const d = this.draft();
    for (const l of this.r()?.lines ?? []) {
      if (l.frozen || !(l.entry_id in d)) continue;
      const now = d[l.entry_id], was = l.mark?.cleared_date ?? null;
      if (now === was) continue;
      if (now) mark.push({ entry_id: l.entry_id, cleared_date: now }); else unmark.push(l.entry_id);
    }
    return { mark, unmark, count: mark.length + unmark.length };
  });
  /** Review finding 10: in cents; 0 when every saved tick of the period is on a listed line. */
  notListed = computed(() => signedCents(this.r()?.cleared_not_listed ?? '0') ?? 0);
  canComplete = computed(() => !!this.statementDate() && !this.dateError() && this.balanceOk() && this.difference() === 0);

  ngOnInit() {
    const id = Number(this.id() ?? 0);
    this.accountId.set(id > 0 ? id : null);
    // QA D5: the account must be a bank account of the current branch (a branch switch re-creates this page) before
    // the reconciliation is requested, so a switch never produces a 404.
    this.http.get<Account[]>('/api/v1/accounts').subscribe({
      next: a => {
        this.accounts.set(a);
        this.loadedAccounts.set(true);
        if (id <= 0) return;
        if (this.banks().some(b => b.id === id)) this.start();
        else { this.accountId.set(null); this.router.navigate(['/accounts/reconcile']); }
      },
      error: () => { this.accounts.set([]); this.loadedAccounts.set(true); },
    });
  }

  @HostListener('window:beforeunload', ['$event'])
  onBeforeUnload(e: BeforeUnloadEvent) { if (this.changes().count) e.preventDefault(); }

  /** Route guard: leaving with unsaved ticks asks first. */
  canLeave(): boolean { return !this.changes().count || confirm('Discard unsaved ticks?'); }

  pick(id: number | null) {
    if (id === this.accountId()) return;
    this.router.navigate(id ? ['/accounts', id, 'reconcile'] : ['/accounts/reconcile']);
  }

  /** First load: default statement date = last day of the previous month, or today when that is not after the last statement. */
  private async start() {
    const def = previousMonthEnd(this.today);
    this.statementDate.set(def);
    await this.load();
    const last = this.lastDate();
    if (last && def <= last && this.r()) {
      this.statementDate.set(this.today);
      await this.load();
    }
  }

  setDate(d: string) {
    this.statementDate.set(d);
    if (!d || d > this.today) return;
    this.load();
  }

  async load(keepDraft = true): Promise<void> {
    const id = this.accountId();
    if (!id) return;
    const params: Record<string, string> = { show_later: this.showLater() ? '1' : '0', show_reconciled: this.showReconciled() ? '1' : '0' };
    if (this.statementDate() && this.statementDate() <= this.today) params['statement_date'] = this.statementDate();
    try {
      const r = await firstValueFrom(this.http.get<Reconciliation>(`/api/v1/accounts/${id}/reconciliation`, { params }));
      // Keep the user's unsaved ticks on lines that are still listed and not frozen.
      const open = new Set(r.lines.filter(l => !l.frozen).map(l => l.entry_id));
      const d = keepDraft ? Object.fromEntries(Object.entries(this.draft()).filter(([k]) => open.has(Number(k)))) : {};
      this.r.set(r);
      this.draft.set(d);
      this.msg.set('');
    } catch (e) {
      const status = (e as HttpErrorResponse)?.status;
      // Branch switch: the account is not in the new branch, or the user has no key there.
      if (status === 404) { this.r.set(null); this.router.navigate(['/accounts/reconcile']); return; }
      this.r.set(null);
      this.msg.set(errorText(e));
    }
  }

  // ------------------------------------------------------------------ ticks

  private clearedOf(l: ReconLine, d: Record<number, string | null>): string | null {
    return l.entry_id in d ? d[l.entry_id] : (l.mark?.cleared_date ?? null);
  }
  isTicked(l: ReconLine): boolean { return this.clearedOf(l, this.draft()) !== null; }
  cleared(l: ReconLine): string { return this.clearedOf(l, this.draft()) ?? ''; }
  beforeBooked(l: ReconLine): boolean { const c = this.clearedOf(l, this.draft()); return !!c && c < l.date; }
  private defaultCleared(): string { return this.statementDate() && !this.dateError() ? this.statementDate() : this.today; }

  toggle(l: ReconLine, on: boolean) {
    if (l.frozen) return;
    this.draft.update(d => ({ ...d, [l.entry_id]: on ? (l.mark?.cleared_date ?? this.defaultCleared()) : null }));
  }
  setCleared(l: ReconLine, date: string) {
    if (l.frozen || !date) return;
    this.draft.update(d => ({ ...d, [l.entry_id]: date }));
  }
  tickAll() {
    const date = this.defaultCleared();
    this.draft.update(d => {
      const n = { ...d };
      for (const l of this.r()?.lines ?? []) if (!l.frozen && !this.clearedOf(l, d)) n[l.entry_id] = date;
      return n;
    });
  }
  untickAll() {
    this.draft.update(d => {
      const n = { ...d };
      for (const l of this.r()?.lines ?? []) if (!l.frozen) n[l.entry_id] = null;
      return n;
    });
  }
  frozenTip(l: ReconLine): string {
    return l.frozen ? `Reconciled in the statement of ${dayLabel(l.frozen.statement_date)}. Undo that statement first.` : '';
  }

  // ------------------------------------------------------------------ save / complete / undo

  /** Sends the changed ticks. Returns false (message shown, table reloaded, ticks kept where valid) on an error. */
  async save(quiet = false): Promise<boolean> {
    const c = this.changes();
    if (!c.count) return true;
    if (this.busy() && !quiet) return false;
    this.busy.set(true);
    try {
      await firstValueFrom(this.http.post(`/api/v1/accounts/${this.accountId()}/reconciliation/marks`, { mark: c.mark, unmark: c.unmark }));
      this.draft.set({});
      await this.load(false);
      if (!quiet) this.toast.show('Ticks saved.');
      return true;
    } catch (e) {
      this.msg.set(errorText(e));
      await this.load();
      this.msg.set(errorText(e));
      return false;
    } finally {
      if (!quiet) this.busy.set(false);
    }
  }

  async complete() {
    const r = this.r();
    if (!r || !this.canComplete() || this.busy()) return;
    if (!confirm(`Complete the reconciliation of ${r.account.name} to ${dayLabel(this.statementDate())}? Lines cleared up to that date will be frozen.`)) return;
    this.busy.set(true);
    try {
      if (!(await this.save(true))) return;
      const done = await firstValueFrom(this.http.post<{ id: number }>(`/api/v1/accounts/${this.accountId()}/reconciliation/complete`,
        { statement_date: this.statementDate(), statement_balance: this.balance().trim() }));
      // AG-033b F1: the toast opens the new statement's Files panel.
      this.toast.show(`${r.account.name} reconciled to ${dayLabel(this.statementDate())}.`, 10_000, 'info',
        { label: 'Attach bank statement', run: () => this.openFiles(done.id) });
      this.balance.set('');
      await this.load(false);
    } catch (e) {
      this.msg.set(errorText(e));
      await this.load();
      this.msg.set(errorText(e));
    } finally {
      this.busy.set(false);
    }
  }

  startUndo(s: ReconStatement) { this.reason.set(''); this.undoError.set(''); this.undoing.set(s); }

  async undo(s: ReconStatement) {
    if (this.busy()) return;
    this.busy.set(true);
    try {
      await firstValueFrom(this.http.post(`/api/v1/accounts/${this.accountId()}/reconciliation/statements/${s.id}/undo`, { reason: this.reason().trim() }));
      this.undoing.set(null);
      this.toast.show(`Statement of ${dayLabel(s.statement_date)} undone.`);
      await this.load();
    } catch (e) {
      this.undoError.set(errorText(e));
    } finally {
      this.busy.set(false);
    }
  }

  /** S3 PDF of the statement (report.export). */
  async print(s: ReconStatement) {
    this.busy.set(true);
    try {
      const blob = await firstValueFrom(this.http.post('/api/v1/reports/bank-reconciliation/export', { statement_id: s.id, format: 'pdf' }, { responseType: 'blob' }));
      const url = URL.createObjectURL(blob);
      const a = document.createElement('a');
      a.href = url;
      a.download = `bank-reconciliation_${s.statement_date}.pdf`;
      a.click();
      setTimeout(() => URL.revokeObjectURL(url), 1000);
    } catch (e) {
      const err = e as HttpErrorResponse;
      let m = errorText(e);
      if (err?.error instanceof Blob) { try { m = JSON.parse(await err.error.text())?.message ?? m; } catch { /* keep */ } }
      this.msg.set(m);
    } finally {
      this.busy.set(false);
    }
  }

  // ------------------------------------------------------------------ AG-033b bank statement copy

  toggleFiles(s: ReconStatement) { this.filesOf.set(this.filesOf() === s.id ? null : s.id); }

  /** Opens the Files panel of a statement and brings it into view. */
  openFiles(id: number) {
    this.filesOf.set(id);
    setTimeout(() => document.querySelector('ag-statement-files')?.scrollIntoView?.({ block: 'start' }));
  }

  noCopy(s: ReconStatement) { return s.status === 'completed' && !(s.attachments_count ?? 0); }
  filesText(s: ReconStatement) { return statementFilesText(s.attachments_count ?? 0, s.attachments_removed_count ?? 0); }

  // ------------------------------------------------------------------ display

  label(d: string | null | undefined) { return dayLabel(d); }
  text(c: number) { return centsText(c); }
  typeLabel(t: string) { return TYPE_LABELS[t] ?? t; }
  /** Review finding 3: a negative statement balance reads "Overdrawn 2,500.00". */
  balanceText(v: string) { const c = signedCents(v) ?? 0; return c < 0 ? `Overdrawn ${centsText(-c)}` : this.money.transform(v); }
}
