import { Component, OnInit, computed, inject, signal } from '@angular/core';
import { HttpClient, HttpErrorResponse } from '@angular/common/http';
import { FormsModule } from '@angular/forms';
import { ActivatedRoute, Router, RouterLink } from '@angular/router';
import { AuthService } from '../../core/auth.service';
import { AppDatePipe } from '../../core/app-date.pipe';
import { CheckPerson, CheckStatus, checkStatusText, dayLabel, personName } from '../../core/reconciliation';
import {
  BranchPickerComponent, ExportButtonsComponent, ReportMeta, ReportParams, fieldErrors, readQuery, reportError, toHttpParams, writeQuery,
} from './report-common';

export type ReconStatus = 'overdue' | 'due' | 'up_to_date' | 'not_required';
export interface StatusRow {
  account: { id: number; code: string | null; name: string; status: string };
  branch: { id: number; code: string; is_main?: boolean };
  status: ReconStatus;
  not_required_reason: 'no_entries' | 'closed_nil' | null;
  never_reconciled: boolean;
  months_overdue: number | null;
  final_month_end: string | null;
  due_by: string | null;
  last_statement: { id: number; statement_date: string; completed_by: CheckPerson | null; completed_at: string } | null;
  check_status: CheckStatus;
  check: { id: number; checked_by: CheckPerson | null; checked_at: string; late_since_check: number | null } | null;
  recheck_needed: boolean;
}
export interface StatusSummary { overdue: number; due: number; up_to_date: number; not_required: number; not_checked: number; recheck: number }
export interface StatusReport extends ReportMeta {
  as_at: string; grace_days: number; due_month_end: string; overdue_through: string; basis: string;
  filters: { status: string }; summary: StatusSummary; data: StatusRow[];
}

export const STATUS_OPTIONS: { value: string; label: string }[] = [
  { value: 'all', label: 'All' }, { value: 'overdue', label: 'Overdue' }, { value: 'due', label: 'Due' },
  { value: 'up_to_date', label: 'Up to date' }, { value: 'not_required', label: 'Not required' },
];

function months(n: number): string { return `${n} ${n === 1 ? 'month' : 'months'}`; }

/** The S7 Status tag, as the API's BankReconciliation::statusTagText. */
export function statusTag(r: StatusRow, dueMonthEnd: string): string {
  switch (r.status) {
    case 'overdue': return (r.never_reconciled ? 'Never reconciled · overdue ' : 'Overdue ') + months(r.months_overdue ?? 0);
    case 'due': return (r.never_reconciled ? 'Never reconciled · due by ' : 'Due by ') + dayLabel(r.due_by);
    case 'up_to_date': return 'Up to date';
    default: return r.not_required_reason === 'closed_nil'
      ? `Not required: inactive, nil balance, reconciled to its last month (${dayLabel(r.final_month_end)})`
      : `Not required: no entries up to ${dayLabel(dueMonthEnd)}`;
  }
}

/** Tag colour (existing sr-* tags): red overdue, amber due, green up to date, grey not required. */
export function statusColour(s: ReconStatus): string {
  return { overdue: 'sr-red', due: 'sr-amber', up_to_date: 'sr-green', not_required: 'sr-grey' }[s];
}

export function summaryText(s: StatusSummary): string {
  return `Overdue ${s.overdue} · Due ${s.due} · Up to date ${s.up_to_date} · Not required ${s.not_required}`;
}

export function checkLine(s: StatusSummary): string {
  return `Latest statement not checked ${s.not_checked} · Re-check needed ${s.recheck}`;
}

/** AG-033e S7 "Bank reconciliation status" (docs/specs/reconciliation-overdue.md). Read only. */
@Component({
  standalone: true,
  imports: [FormsModule, RouterLink, AppDatePipe, BranchPickerComponent, ExportButtonsComponent],
  template: `
    <h1>Bank reconciliation status</h1>

    <button type="button" class="ghost rs-toggle" [attr.aria-expanded]="panelOpen()" aria-controls="rst-panel"
      (click)="panelOpen.set(!panelOpen())">{{ activeFilters() ? 'Filters (' + activeFilters() + ')' : 'Filters' }}</button>
    <form id="rst-panel" class="rs-panel" [class.open]="panelOpen()" (ngSubmit)="apply()" novalidate>
      @if (auth.isAllBranches()) {
        <div class="rs-field"><span>Branches</span><ag-branch-picker [selected]="branches()" (selectedChange)="branches.set($event)" /></div>
      }
      <label>Status
        <select name="status" [(ngModel)]="status">
          @for (o of options; track o.value) { <option [value]="o.value">{{ o.label }}</option> }
        </select>
        @if (errs()['status']) { <small class="field-error">{{ errs()['status'] }}</small> }</label>
      <div class="rs-actions">
        <button type="submit">Apply</button>
        <button type="button" class="ghost" (click)="clear()">Clear filters</button>
      </div>
    </form>

    @if (msg()) { <div class="alert">{{ msg() }}</div> }
    @if (netError()) {
      <div class="alert">Could not load the reconciliation status. Try again. <button type="button" class="ghost" (click)="load()">Retry</button></div>
    }

    @if (r(); as r) {
      <div class="rs-head">
        <p class="muted rst-basis">{{ r.basis }}</p>
        <p class="rst-summary"><b>{{ summary() }}</b></p>
        <p class="rst-check" [class.warn]="r.summary.not_checked || r.summary.recheck" [class.muted]="!r.summary.not_checked && !r.summary.recheck">{{ checks() }}</p>
        <div class="rs-export rst-export"><ag-export-buttons name="reconciliation-status" [params]="applied()" /></div>
      </div>
    }

    <div class="card rs-card">
      <table class="list rs-table rst-table">
        <thead><tr>
          @if (auth.isAllBranches()) { <th>Branch</th> }
          <th>Account</th><th>Status</th><th class="num">Months overdue</th><th>Last reconciled</th><th>Last check</th><th></th>
        </tr></thead>
        <tbody>
          @if (loading() && !rows().length) {
            @for (i of skeleton; track i) { <tr class="rs-skel"><td [attr.colspan]="columns()">&nbsp;</td></tr> }
          }
          @for (s of rows(); track s.account.id) {
            <tr class="rst-row">
              @if (auth.isAllBranches()) { <td>{{ s.branch.code }}</td> }
              <td>{{ accountText(s) }}</td>
              <td><span class="tag rst-tag" [attr.data-s]="colour(s)">{{ tag(s) }}</span></td>
              <td class="num">{{ s.months_overdue ?? '' }}</td>
              <td>
                @if (s.last_statement; as l) {
                  {{ l.statement_date | appDate }}
                  <div class="muted">by {{ l.completed_by?.name ?? '-' }} @if (l.completed_by?.tag) { <span class="tag" data-s="inactive">{{ l.completed_by?.tag }}</span> }</div>
                } @else { Never }
              </td>
              <td>@if (checkText(s); as t) { <span class="tag rst-tag" [attr.data-s]="checkColour(s)">{{ t }}</span> }</td>
              <td class="rst-links">
                <a [routerLink]="['/reports/reconciliation-statements']" [queryParams]="statementsQuery(s)">Statements</a>
                @if (canReconcile(s)) { <a [routerLink]="['/accounts', s.account.id, 'reconcile']">Reconcile</a> }
              </td>
            </tr>
          } @empty {
            @if (!loading() && r()) { <tr><td [attr.colspan]="columns()" class="muted">{{ emptyText() }}
              @if (applied()['status'] !== 'all') { <button type="button" class="link" (click)="clear()">Clear filters</button> }</td></tr> }
          }
        </tbody>
      </table>

      <ul class="rs-cards rst-cards">
        @if (loading() && !rows().length) { @for (i of skeleton; track i) { <li class="rs-skel">&nbsp;</li> } }
        @for (s of rows(); track s.account.id) {
          <li class="rst-card">
            <div class="rs-l1"><span>@if (auth.isAllBranches()) { {{ s.branch.code }} · }{{ accountText(s) }}</span>
              <span class="tag rst-tag" [attr.data-s]="colour(s)">{{ tag(s) }}</span></div>
            <div class="rs-l4">@if (s.last_statement; as l) { Last reconciled {{ l.statement_date | appDate }} by {{ person(l.completed_by) }} } @else { Never reconciled }</div>
            @if (checkText(s); as t) { <div class="rs-l3"><span class="tag rst-tag" [attr.data-s]="checkColour(s)">{{ t }}</span></div> }
            <div class="rst-buttons">
              <a class="rst-button" [routerLink]="['/reports/reconciliation-statements']" [queryParams]="statementsQuery(s)">Statements</a>
              @if (canReconcile(s)) { <a class="rst-button" [routerLink]="['/accounts', s.account.id, 'reconcile']">Reconcile</a> }
            </div>
          </li>
        } @empty {
          @if (!loading() && r()) { <li class="muted">{{ emptyText() }}
            @if (applied()['status'] !== 'all') { <button type="button" class="link" (click)="clear()">Clear filters</button> }</li> }
        }
      </ul>
    </div>
  `,
})
export class ReconciliationStatusComponent implements OnInit {
  auth = inject(AuthService);
  private http = inject(HttpClient);
  private router = inject(Router);
  private route = inject(ActivatedRoute);
  readonly options = STATUS_OPTIONS;
  readonly skeleton = [1, 2, 3];
  status = 'all';
  branches = signal<number[]>([]);
  r = signal<StatusReport | null>(null);
  rows = computed(() => this.r()?.data ?? []);
  loading = signal(false);
  msg = signal('');
  netError = signal(false);
  errs = signal<Record<string, string>>({});
  applied = signal<ReportParams>({ status: 'all' });
  panelOpen = signal(false);
  summary = computed(() => (this.r() ? summaryText(this.r()!.summary) : ''));
  checks = computed(() => (this.r() ? checkLine(this.r()!.summary) : ''));
  columns = computed(() => 6 + (this.auth.isAllBranches() ? 1 : 0));
  activeFilters = computed(() => {
    const a = this.applied();
    return (a['status'] && a['status'] !== 'all' ? 1 : 0) + ((a['branches'] as number[] | undefined)?.length ? 1 : 0);
  });

  ngOnInit() {
    const { q, branches } = readQuery(this.route, this.auth.isAllBranches());
    // Review SHOULD 1: an unknown status from a bookmark falls back to All (a phone never shows a blank page).
    const status = q.get('status') ?? 'all';
    this.status = STATUS_OPTIONS.some(o => o.value === status) ? status : 'all';
    this.branches.set(branches);
    this.apply();
  }

  /** Apply: the filters into the URL (bookmarkable; Back from S6 or S1 restores them). */
  apply() {
    if (this.loading()) return; // QA D1: a second click while the request is in flight is ignored
    const params: ReportParams = { branches: this.branches(), status: this.status };
    this.applied.set(params);
    writeQuery(this.router, this.route, params);
    this.panelOpen.set(false);
    this.load();
  }

  clear() {
    this.status = 'all';
    this.branches.set([]);
    this.apply();
  }

  load() {
    this.loading.set(true);
    this.netError.set(false);
    this.http.get<StatusReport>('/api/v1/reports/reconciliation-status', { params: toHttpParams(this.applied()) }).subscribe({
      next: r => {
        this.r.set(r);
        this.msg.set('');
        this.errs.set({});
        this.loading.set(false);
      },
      error: (e: HttpErrorResponse) => {
        this.loading.set(false);
        this.r.set(null);
        if (e.status === 0) { this.netError.set(true); return; }
        const fields = fieldErrors(e);
        // A 422 on Status goes under the field; any other field (branches) to the alert, so a bad bookmark never leaves a blank page.
        const { status, ...rest } = fields;
        this.errs.set(status ? { status } : {});
        // The Status error sits in the filter sheet: open it, so the message is visible at 390px too.
        if (status) this.panelOpen.set(true);
        this.msg.set(Object.keys(fields).length ? Object.values(rest).join(' ') : reportError(e));
      },
    });
  }

  emptyText() {
    return this.applied()['status'] === 'all' ? 'No bank accounts in this branch.' : 'No bank accounts with this status.';
  }

  accountText(s: StatusRow) {
    const a = s.account;
    return `${a.name}${a.code ? ` (${a.code})` : ''}${a.status !== 'active' ? ' (inactive)' : ''}`;
  }

  tag(s: StatusRow) { return statusTag(s, this.r()?.due_month_end ?? ''); }
  colour(s: StatusRow) { return statusColour(s.status); }
  checkText(s: StatusRow) { return checkStatusText(s.check_status, s.check ? { ...s.check, compared_with: 'bank_statement', after_close: null } : null); }
  checkColour(s: StatusRow) { return s.check_status === 'checked' ? 'sr-green' : 'sr-amber'; }
  person(p: CheckPerson | null) { return personName(p); }

  /** S6 filtered to the account (and, in All mode, to its branch). */
  statementsQuery(s: StatusRow) {
    return this.auth.isAllBranches() ? { account_ids: s.account.id, branches: s.branch.id } : { account_ids: s.account.id };
  }

  /** "Reconcile" needs account.reconcile; in All mode only MAIN accounts (S1 is a branch-mode screen, Q8). */
  canReconcile(s: StatusRow) {
    return this.auth.can('account.reconcile') && (!this.auth.isAllBranches() || !!s.branch.is_main);
  }
}
