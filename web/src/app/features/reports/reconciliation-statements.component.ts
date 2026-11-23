import { Component, OnInit, computed, inject, signal } from '@angular/core';
import { HttpClient, HttpErrorResponse } from '@angular/common/http';
import { FormsModule } from '@angular/forms';
import { ActivatedRoute, Router, RouterLink } from '@angular/router';
import { AuthService } from '../../core/auth.service';
import { SettingsService } from '../../core/settings.service';
import { AppDatePipe, AppDateTimePipe } from '../../core/app-date.pipe';
import { colomboToday } from '../../core/dates';
import { MoneyPipe } from '../../core/money.pipe';
import { dayLabel } from '../../core/reconciliation';
import {
  BranchPickerComponent, ExportButtonsComponent, ReportMeta, ReportParams, fieldErrors, readQuery, reportError, toHttpParams, writeQuery,
} from './report-common';

export interface ListPerson { id: number; name: string; tag: string | null }
export interface ListAccount { id: number; code: string | null; name: string; status: string }
export interface StatementRow {
  id: number; branch: { id: number; code: string }; account: ListAccount;
  statement_date: string; statement_balance: string; lines_count: number; self_count: number;
  status: 'completed' | 'undone'; completed_by: ListPerson | null; completed_at: string;
  undone_by: ListPerson | null; undone_at: string | null; undo_reason: string | null;
  changed_since_completion: boolean; late_entries_count: number | null; late_voided_count: number | null;
  attachments_count: number | null; attachments_removed_count: number | null;
}
export interface Coverage {
  reconciled_to: string;
  not_reconciled: { account: ListAccount; branch: { id: number; code: string }; last_statement_date: string | null }[];
}
export interface StatementList extends ReportMeta {
  basis: string; copies_supported: boolean;
  accounts: (ListAccount & { branch: { id: number; code: string }; last_statement_date: string | null })[];
  coverage: Coverage; total: number; page: number; per_page: number; data: StatementRow[];
}

interface Filters { from: string; to: string; status: string; changed: string; late: string; copy: string }

export const LATE_TOOLTIP = 'Entries dated on or before the statement date that were recorded or voided after completion (as listed on the statement)';

/** "today minus 12 months" as YYYY-MM-DD (the API's default; the 29 Feb of a leap year falls back to 28 Feb). */
export function yearBefore(ymd: string): string {
  const [y, m, d] = ymd.split('-').map(Number);
  const last = new Date(y - 1, m, 0).getDate();
  return `${y - 1}-${String(m).padStart(2, '0')}-${String(Math.min(d, last)).padStart(2, '0')}`;
}

/** R9 coverage line, as the API's BankReconciliation::coverageText. */
export function coverageText(c: Coverage): string {
  const to = dayLabel(c.reconciled_to);
  if (!c.not_reconciled.length) return `Every bank account in scope is reconciled to ${to}.`;
  return `Not reconciled to ${to}: ` + c.not_reconciled.map(n => `${n.account.name}${n.account.code ? ` (${n.account.code})` : ''}`
    + (n.last_statement_date ? `, last statement ${dayLabel(n.last_statement_date)}` : ', never reconciled')).join('; ') + '.';
}

/** "2 (1 voided)"; '' for an undone statement. */
export function lateText(r: StatementRow): string {
  if (r.late_entries_count === null) return '';
  return r.late_voided_count ? `${r.late_entries_count} (${r.late_voided_count} voided)` : String(r.late_entries_count);
}

/** "1 file (1 removed)", "No copy" for a completed statement without an active file, '' otherwise. */
export function copyText(r: StatementRow): string {
  const n = r.attachments_count ?? 0;
  const removed = r.attachments_removed_count ?? 0;
  const tail = removed ? ` (${removed} removed)` : '';
  if (n === 0 && r.status === 'completed') return 'No copy' + tail;
  if (n === 0 && removed === 0) return '';
  return `${n} ${n === 1 ? 'file' : 'files'}${tail}`;
}

/** The branch the list was last opened in: after a branch switch the Account filter of the URL no longer applies (F6). */
let lastBranch: string | null = null;

/** AG-033c S6 "Bank reconciliation statements" (docs/specs/reconciliation-statements-list.md). Read only. */
@Component({
  standalone: true,
  imports: [FormsModule, RouterLink, MoneyPipe, AppDatePipe, AppDateTimePipe, BranchPickerComponent, ExportButtonsComponent],
  template: `
    <h1>Bank reconciliation statements</h1>

    <button type="button" class="ghost rs-toggle" [attr.aria-expanded]="panelOpen()" aria-controls="rs-panel"
      (click)="panelOpen.set(!panelOpen())">{{ activeFilters() ? 'Filters (' + activeFilters() + ')' : 'Filters' }}</button>
    <form id="rs-panel" class="rs-panel" [class.open]="panelOpen()" (ngSubmit)="apply()" novalidate>
      @if (auth.isAllBranches()) {
        <div class="rs-field"><span>Branches</span><ag-branch-picker [(selected)]="branches" /></div>
      }
      <div class="rs-field"><span>Accounts</span>
        <details class="picker">
          <summary>{{ chosen().length ? chosen().length + ' account(s)' : 'All bank accounts' }}</summary>
          @for (a of visibleAccounts(); track a.id) {
            <label><input type="checkbox" [checked]="chosen().includes(a.id)" (change)="toggle(a.id)" /> {{ accountOption(a) }}</label>
          }
          @if (chosen().length) { <button type="button" class="link" (click)="chosen.set([])">All bank accounts</button> }
        </details>
        @if (errs()['account_ids']) { <small class="field-error">{{ errs()['account_ids'] }}</small> }
      </div>
      <label>Statement date from <input type="date" name="from" [(ngModel)]="f.from" [max]="f.to || today" />
        @if (errs()['from']) { <small class="field-error">{{ errs()['from'] }}</small> }</label>
      <label>Statement date to <input type="date" name="to" [(ngModel)]="f.to" [max]="today" />
        @if (errs()['to']) { <small class="field-error">{{ errs()['to'] }}</small> }</label>
      <label>Status
        <select name="status" [(ngModel)]="f.status">
          <option value="all">All</option><option value="completed">Completed</option><option value="undone">Undone</option>
        </select></label>
      <label>Changed since completion
        <select name="changed" [(ngModel)]="f.changed">
          <option value="any">Any</option><option value="yes">Yes</option><option value="no">No</option>
        </select></label>
      <label [title]="lateTooltip">Late entries
        <select name="late" [(ngModel)]="f.late">
          <option value="any">Any</option><option value="some">Some</option><option value="none">None</option>
        </select></label>
      @if (copiesSupported()) {
        <label>Bank statement copy
          <select name="copy" [(ngModel)]="f.copy">
            <option value="any">Any</option><option value="attached">Attached</option><option value="none">None</option>
          </select>
          @if (errs()['copy']) { <small class="field-error">{{ errs()['copy'] }}</small> }</label>
      }
      <div class="rs-actions">
        <button type="submit">Apply</button>
        <button type="button" class="ghost" (click)="clear()">Clear filters</button>
      </div>
    </form>

    @if (msg()) { <div class="alert">{{ msg() }}</div> }
    @if (netError()) {
      <div class="alert">Could not load the statements. Try again. <button type="button" class="ghost" (click)="retry()">Retry</button></div>
    }

    @if (r(); as r) {
      <div class="rs-head">
        <p><b>{{ r.total }} {{ r.total === 1 ? 'statement' : 'statements' }}</b></p>
        <p class="muted">{{ r.basis }}</p>
        <p class="rs-coverage" [class.warn]="r.coverage.not_reconciled.length" [class.ok]="!r.coverage.not_reconciled.length">{{ coverage() }}</p>
        <div class="rs-export"><ag-export-buttons name="reconciliation-statements" [params]="applied()" /></div>
      </div>
    }

    <div class="card rs-card">
      <table class="list rs-table">
        <thead><tr>
          @if (auth.isAllBranches()) { <th>Branch</th> }
          <th>Account</th><th>Statement date</th><th class="num">Statement balance</th><th class="num">Lines</th><th>Completed by / at</th>
          <th>Status</th><th>Changed</th><th class="num" [title]="lateTooltip">Late entries</th>
          @if (copiesSupported()) { <th>Bank statement copy</th> }
          <th></th>
        </tr></thead>
        <tbody>
          @if (loading() && !rows().length) {
            @for (i of skeleton; track i) { <tr class="rs-skel"><td [attr.colspan]="columns()">&nbsp;</td></tr> }
          }
          @for (s of rows(); track s.id) {
            <tr class="rs-row" (click)="open(s)">
              @if (auth.isAllBranches()) { <td>{{ s.branch.code }}</td> }
              <td>{{ accountText(s.account) }}</td>
              <td>{{ s.statement_date | appDate }}</td>
              <td class="num">{{ balance(s.statement_balance) }}</td>
              <td class="num" [title]="s.self_count + ' recorded by the preparer'">{{ s.lines_count }}</td>
              <td>{{ s.completed_by?.name ?? '-' }} @if (s.completed_by?.tag) { <span class="tag" data-s="inactive">{{ s.completed_by?.tag }}</span> }
                <div class="muted">{{ s.completed_at | appDateTime }}</div></td>
              <td>
                @if (s.status === 'completed') { <span class="tag" data-s="balanced">Completed</span> }
                @else {
                  <span class="tag" data-s="sr-grey">Undone</span>
                  <div class="muted rs-undo" [title]="s.undo_reason ?? ''">{{ undoneText(s) }}</div>
                }
              </td>
              <td>@if (s.changed_since_completion) { <span class="tag att-late">Changed since completion</span> }</td>
              <td class="num" [title]="lateTooltip">{{ late(s) }}</td>
              @if (copiesSupported()) {
                <td>@if (copy(s).startsWith('No copy')) { <span class="tag att-late">{{ copy(s) }}</span> } @else { {{ copy(s) }} }</td>
              }
              <td><a [routerLink]="['/reports/bank-reconciliation']" [queryParams]="{ statement_id: s.id }" (click)="$event.stopPropagation()">Open statement</a></td>
            </tr>
          } @empty {
            @if (!loading() && r()) { <tr><td [attr.colspan]="columns()" class="muted">{{ emptyText() }}
              @if (!isDefault()) { <button type="button" class="link" (click)="clear()">Clear filters</button> }</td></tr> }
          }
        </tbody>
      </table>

      <ul class="rs-cards">
        @if (loading() && !rows().length) { @for (i of skeleton; track i) { <li class="rs-skel">&nbsp;</li> } }
        @for (s of rows(); track s.id) {
          <li>
            <a class="rs-tap" [routerLink]="['/reports/bank-reconciliation']" [queryParams]="{ statement_id: s.id }" [attr.aria-label]="'Open statement ' + accountText(s.account) + ' ' + s.statement_date">
              <div class="rs-l1"><span>@if (auth.isAllBranches()) { {{ s.branch.code }} · }{{ accountText(s.account) }}</span><b class="num">{{ balance(s.statement_balance) }}</b></div>
              <div class="rs-l2"><span>{{ s.statement_date | appDate }}</span>
                @if (s.status === 'completed') { <span class="tag" data-s="balanced">Completed</span> } @else { <span class="tag" data-s="sr-grey">Undone</span> }</div>
              <div class="rs-l3">
                @if (s.changed_since_completion) { <span class="tag att-late">Changed since completion</span> }
                @if (s.late_entries_count !== null) { <span class="tag">Late entries {{ late(s) }}</span> }
                @if (copiesSupported() && copy(s)) { <span class="tag" [class.att-late]="copy(s).startsWith('No copy')">{{ copy(s) }}</span> }
              </div>
              <div class="rs-l4">Completed by {{ s.completed_by?.name ?? '-' }}@if (s.completed_by?.tag) { ({{ s.completed_by?.tag }}) }, {{ s.completed_at | appDateTime }}
                @if (s.status === 'undone') { <div>Undone {{ undoneText(s) }}</div> }</div>
            </a>
          </li>
        } @empty {
          @if (!loading() && r()) { <li class="muted">{{ emptyText() }} @if (!isDefault()) { <button type="button" class="link" (click)="clear()">Clear filters</button> }</li> }
        }
      </ul>

      @if (r(); as r) {
        @if (r.total > r.per_page) {
          <div class="pager rs-pager">
            <button type="button" class="ghost" [disabled]="page() <= 1 || loading()" (click)="go(page() - 1)">Previous</button>
            <span>Page {{ page() }} of {{ pages() }}</span>
            <button type="button" class="ghost" [disabled]="page() >= pages() || loading()" (click)="go(page() + 1)">Next</button>
          </div>
          @if (rows().length < r.total) {
            <button type="button" class="ghost rs-more" [disabled]="loading()" (click)="more()">Load more</button>
          }
        }
      }
    </div>
  `,
})
export class ReconciliationStatementsComponent implements OnInit {
  auth = inject(AuthService);
  private http = inject(HttpClient);
  private router = inject(Router);
  private route = inject(ActivatedRoute);
  private settings = inject(SettingsService);
  today = colomboToday();
  readonly lateTooltip = LATE_TOOLTIP;
  readonly skeleton = [1, 2, 3];
  readonly defaults: Filters = { from: yearBefore(this.today), to: this.today, status: 'all', changed: 'any', late: 'any', copy: 'any' };
  f: Filters = { ...this.defaults };
  branches = signal<number[]>([]);
  chosen = signal<number[]>([]);
  r = signal<StatementList | null>(null);
  rows = signal<StatementRow[]>([]);
  page = signal(1);
  loading = signal(false);
  msg = signal('');
  netError = signal(false);
  errs = signal<Record<string, string>>({});
  applied = signal<ReportParams>({});
  panelOpen = signal(false);
  /** Copies supported: from the last answer (R6); a bookmarked copy filter is kept until the API says otherwise. */
  copiesSupported = computed(() => this.r()?.copies_supported ?? false);
  accounts = computed(() => this.r()?.accounts ?? []);
  visibleAccounts = computed(() => {
    const b = this.branches();
    return b.length ? this.accounts().filter(a => b.includes(a.branch.id)) : this.accounts();
  });
  pages = computed(() => Math.max(1, Math.ceil((this.r()?.total ?? 0) / (this.r()?.per_page ?? 50))));
  coverage = computed(() => (this.r() ? coverageText(this.r()!.coverage) : ''));
  columns = computed(() => 9 + (this.auth.isAllBranches() ? 1 : 0) + (this.copiesSupported() ? 1 : 0));
  activeFilters = computed(() => {
    const a = this.applied();
    let n = 0;
    if (a['from'] !== this.defaults.from || a['to'] !== this.defaults.to) n++;
    for (const k of ['status', 'changed', 'late', 'copy'] as const) if (a[k] !== undefined && a[k] !== this.defaults[k]) n++;
    if ((a['account_ids'] as number[] | undefined)?.length) n++;
    if ((a['branches'] as number[] | undefined)?.length) n++;
    return n;
  });
  private money = new MoneyPipe();

  ngOnInit() {
    const { q, branches } = readQuery(this.route, this.auth.isAllBranches());
    const branch = this.auth.activeBranchId();
    const switched = lastBranch !== null && lastBranch !== branch;
    lastBranch = branch;
    this.f = {
      from: q.get('from') ?? this.defaults.from, to: q.get('to') ?? this.defaults.to,
      status: q.get('status') ?? 'all', changed: q.get('changed') ?? 'any', late: q.get('late') ?? 'any', copy: q.get('copy') ?? 'any',
    };
    this.branches.set(branches);
    // F6: a branch switch clears the Account filter (the ids belong to the old branch).
    this.chosen.set(switched ? [] : q.getAll('account_ids').map(Number).filter(n => n > 0));
    this.apply();
  }

  /** Apply: page 1, the filters into the URL (bookmarkable, and Back from the reprint restores them). */
  apply() {
    const params: ReportParams = {
      branches: this.branches(), account_ids: this.chosen(), from: this.f.from, to: this.f.to,
      status: this.f.status, changed: this.f.changed, late: this.f.late, copy: this.f.copy === 'any' ? undefined : this.f.copy,
    };
    this.applied.set(params);
    writeQuery(this.router, this.route, params);
    this.panelOpen.set(false);
    this.fetch(1, false);
  }

  clear() {
    this.f = { ...this.defaults };
    this.branches.set([]);
    this.chosen.set([]);
    this.apply();
  }

  retry() { this.fetch(this.page(), false); }
  go(p: number) { this.fetch(p, false); }
  /** 390px: "Load more" appends the next page. */
  more() { this.fetch(this.page() + 1, true); }

  private fetch(page: number, append: boolean) {
    this.loading.set(true);
    this.netError.set(false);
    this.http.get<StatementList>('/api/v1/reports/reconciliation-statements', { params: toHttpParams({ ...this.applied(), page }) }).subscribe({
      next: r => {
        this.r.set(r);
        this.rows.set(append ? [...this.rows(), ...r.data] : r.data);
        this.page.set(r.page);
        this.msg.set('');
        this.errs.set({});
        this.loading.set(false);
      },
      error: (e: HttpErrorResponse) => {
        this.loading.set(false);
        if (!append) { this.r.set(null); this.rows.set([]); }
        if (e.status === 0) { this.netError.set(true); return; }
        const fields = fieldErrors(e);
        this.errs.set(fields);
        this.msg.set(Object.keys(fields).length ? '' : reportError(e));
      },
    });
  }

  open(s: StatementRow) {
    this.router.navigate(['/reports/bank-reconciliation'], { queryParams: { statement_id: s.id } });
  }

  toggle(id: number) { this.chosen.update(s => (s.includes(id) ? s.filter(x => x !== id) : [...s, id])); }

  isDefault() {
    const a = this.applied();
    return this.activeFilters() === 0 && !(a['copy']);
  }

  emptyText() {
    return this.isDefault() ? 'No bank reconciliation statements in the last 12 months.' : 'No statements match these filters.';
  }

  accountText(a: ListAccount) { return `${a.name}${a.code ? ` (${a.code})` : ''}${a.status !== 'active' ? ' (inactive)' : ''}`; }
  accountOption(a: ListAccount & { branch: { code: string } }) { return `${a.branch.code} · ${this.accountText(a)}`; }
  /** AG-033 finding 3: a negative balance reads "Overdrawn 2,500.00". */
  balance(v: string) { return v.startsWith('-') ? `Overdrawn ${this.money.transform(v.slice(1))}` : this.money.transform(v); }
  late(s: StatementRow) { return lateText(s); }
  copy(s: StatementRow) { return copyText(s); }

  /** "by BA on 3 Dec 2026: <reason>" with the reason cut at 80 characters (full text in the tooltip). */
  undoneText(s: StatementRow) {
    const reason = s.undo_reason ?? '';
    const cut = reason.length > 80 ? reason.slice(0, 80) + '…' : reason;
    const on = s.undone_at ? this.settings.date(s.undone_at) : '-';
    return `by ${s.undone_by?.name ?? '-'}${s.undone_by?.tag ? ` (${s.undone_by.tag})` : ''} on ${on}: ${cut}`;
  }
}
