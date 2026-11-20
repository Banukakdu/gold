import { Component, OnInit, inject, signal } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { RouterLink } from '@angular/router';
import { AppDatePipe } from '../../core/app-date.pipe';
import { AuthService } from '../../core/auth.service';
import { MoneyPipe } from '../../core/money.pipe';
import { errorText } from '../../core/api.interceptor';
import { ApprovalDialogComponent, ApprovalTarget, toCents } from '../../core/approvals';
import { TemplateFacts, TemplateLine, approvalTemplateLines } from '../../core/expense-templates';

export interface ExpenseRow {
  id: number; ref_no: string; branch: { id: number; code: string | null }; requested_date: string | null; requested_on: string | null;
  age_days: number; expires_on: string | null; account: { id: number; name: string | null; available: string }; category_path: string;
  payee_payer: string | null; amount: string; other_pending_from_account: string; files_count: number;
  below_threshold_7d: { count: number; amount: string }; requested_by: { id: number; name: string } | null; requested_by_you: boolean;
  requester_inactive: boolean; requester_left_branch: boolean; super_admin_only: boolean; approver_onboarded_requester: boolean;
  requested_period_locked: boolean; can_approve: boolean; can_cancel: boolean;
}
/** AG-066 R7a: the row carries the template facts of the request's audit row. */
export type ExpenseRowWithTemplate = ExpenseRow & TemplateFacts;
export interface ExpenseApprovals {
  threshold: string | null;
  negative_balance?: 'block' | 'warn';
  to_approve: { data: ExpenseRowWithTemplate[]; total: number; amount: string };
  my_requests: { data: ExpenseRow[]; total: number };
}

/** Signed cents of a decimal string (negative balances count as below). No floats. */
export function signedCents(v: string | null | undefined): number {
  const s = String(v ?? '0').trim();
  const neg = s.startsWith('-');
  const c = toCents(neg ? s.slice(1) : s) ?? 0;
  return neg ? -c : c;
}

/**
 * AG-064 S3 "Expenses to approve" (Super Admin, or `expense.approve` in the active branch) and S4 "My expense requests"
 * (the requester, branch mode). A table on wide screens, stacked cards at phone width (390px layout).
 */
@Component({
  selector: 'ag-expense-approvals',
  standalone: true,
  imports: [RouterLink, MoneyPipe, AppDatePipe, ApprovalDialogComponent],
  template: `
    @if (r(); as r) {
      @if (showApprovals()) {
        <section class="card section tr-widget ea-widget">
          <h2>Expenses to approve @if (r.to_approve.total) { <small class="muted">{{ r.to_approve.total }} · {{ r.to_approve.amount | money }}</small> }</h2>
          @if (ok()) { <div class="ok">{{ ok() }}</div> }
          @if (r.to_approve.data.length) {
            <div class="tr-scroll ea-table">
              <table class="list ta-table">
                <thead><tr>
                  @if (auth.isAllBranches()) { <th>Branch</th> }
                  <th>Requested on</th><th>Ref</th><th>Account / category</th><th>Payee</th><th class="num">Amount</th>
                  <th class="num">Available now</th><th>Files</th><th>Requested by</th><th class="tr-actions"></th>
                </tr></thead>
                <tbody>
                  @for (t of r.to_approve.data; track t.id) {
                    <tr>
                      @if (auth.isAllBranches()) { <td class="mono">{{ t.branch.code }}</td> }
                      <td>{{ t.requested_on | appDate }}<br /><small class="muted">{{ t.age_days }} day{{ t.age_days === 1 ? '' : 's' }}</small></td>
                      <td><a class="mono" [routerLink]="['/transactions', t.id]">{{ t.ref_no }}</a>
                        @if (t.super_admin_only) { <br /><span class="tag" data-s="pending">Super Admin approval</span> }
                        @if (t.approver_onboarded_requester) { <br /><span class="tag" data-s="pending" title="Super Admins will be told of your approval">You added this requester</span> }</td>
                      <td>{{ t.account.name }}<br /><small class="ea-path" [title]="t.category_path">{{ t.category_path }}</small>
                        @for (l of lines(t); track $index) { <br /><small class="tpl-fact" [class.tpl-warn]="l.warn">{{ l.text }}</small> }</td>
                      <td class="ta-note">{{ t.payee_payer }}</td>
                      <td class="num">{{ t.amount | money }}</td>
                      <td class="num">{{ t.account.available | money }} @if (short(t)) { <span class="tag" data-s="reversed">Short</span> }
                        @if (signed(t.other_pending_from_account) > 0) { <br /><small class="muted">Other pending {{ t.other_pending_from_account | money }}</small> }
                        @if (t.below_threshold_7d.count) {
                          <br /><small class="muted" title="Posted below the limit in this category in the last 7 days">Same category below the limit, last 7 days: {{ t.below_threshold_7d.count }}, {{ t.below_threshold_7d.amount | money }}</small>
                        }</td>
                      <td>{{ t.files_count }} @if (!t.files_count) { <span class="tag" data-s="pending">No receipt</span> }</td>
                      <td>{{ t.requested_by?.name }}
                        @if (t.requester_inactive) { <span class="tag">Inactive</span> }
                        @if (t.requester_left_branch) { <span class="tag">No longer in this branch</span> }</td>
                      <td class="tr-actions">
                        @if (t.requested_by_you) { <span class="muted">Requested by you</span> }
                        @else if (t.can_approve) {
                          <button type="button" class="link" (click)="open(t, 'approve')">Approve</button>
                          <button type="button" class="link" (click)="open(t, 'reject')">Reject</button>
                        }
                      </td>
                    </tr>
                  }
                </tbody>
              </table>
            </div>
            <div class="ea-cards">
              @for (t of r.to_approve.data; track t.id) {
                <div class="ea-card">
                  <div class="ea-line"><a class="mono" [routerLink]="['/transactions', t.id]">{{ t.ref_no }}</a><b>{{ t.amount | money }}</b></div>
                  <div class="ea-line ea-sub"><span>{{ t.account.name }}</span><span class="ea-path">{{ t.category_path }}</span></div>
                  @for (l of lines(t); track $index) { <div class="ea-line ea-sub tpl-fact" [class.tpl-warn]="l.warn">{{ l.text }}</div> }
                  <div class="ea-line ea-sub">
                    <span>{{ t.requested_on | appDate }}</span><span>{{ t.age_days }} day{{ t.age_days === 1 ? '' : 's' }}</span>
                    @if (auth.isAllBranches()) { <span>{{ t.branch.code }}</span> }
                    <span>{{ t.requested_by_you ? 'Requested by you' : t.requested_by?.name }}</span>
                  </div>
                  <div class="ea-line ea-sub">
                    @if (short(t)) { <span class="tag" data-s="reversed">Short</span> }
                    @if (!t.files_count) { <span class="tag" data-s="pending">No receipt</span> }
                    @if (t.super_admin_only) { <span class="tag" data-s="pending">Super Admin approval</span> }
                    @if (t.approver_onboarded_requester) { <span class="tag" data-s="pending">You added this requester</span> }
                    @if (t.requester_inactive) { <span class="tag">Inactive</span> }
                    @if (t.requester_left_branch) { <span class="tag">No longer in this branch</span> }
                    @if (t.below_threshold_7d.count) { <span>Below the limit, 7 days: {{ t.below_threshold_7d.count }}, {{ t.below_threshold_7d.amount | money }}</span> }
                  </div>
                  @if (t.can_approve && !t.requested_by_you) {
                    <div class="ea-buttons">
                      <button type="button" (click)="open(t, 'approve')">Approve</button>
                      <button type="button" class="ghost" (click)="open(t, 'reject')">Reject</button>
                    </div>
                  }
                </div>
              }
            </div>
            @if (r.to_approve.total > r.to_approve.data.length) { <p class="muted">and {{ r.to_approve.total - r.to_approve.data.length }} more</p> }
          } @else { <p class="muted">No expenses waiting for approval.</p> }
        </section>
      }

      @if (!auth.isAllBranches() && auth.can('expense.create') && r.my_requests.data.length) {
        <section class="card section tr-widget ea-mine">
          <h2>My expense requests</h2>
          @if (mineOk()) { <div class="ok">{{ mineOk() }}</div> }
          @if (mineErr()) { <div class="alert">{{ mineErr() }}</div> }
          <div class="tr-scroll ea-table">
            <table class="list">
              <thead><tr><th>Requested on</th><th>Ref</th><th>Account / category</th><th class="num">Amount</th><th>Expires on</th><th></th></tr></thead>
              <tbody>
                @for (t of r.my_requests.data; track t.id) {
                  <tr>
                    <td>{{ t.requested_on | appDate }}</td>
                    <td class="mono"><a [routerLink]="['/transactions', t.id]">{{ t.ref_no }}</a></td>
                    <td>{{ t.account.name }}<br /><small class="ea-path">{{ t.category_path }}</small></td>
                    <td class="num">{{ t.amount | money }}</td>
                    <td>{{ t.expires_on | appDate }}</td>
                    <td>@if (t.can_cancel) { <button type="button" class="link" [disabled]="busy()" (click)="cancel(t)">Cancel request</button> }</td>
                  </tr>
                }
              </tbody>
            </table>
          </div>
          <div class="ea-cards">
            @for (t of r.my_requests.data; track t.id) {
              <div class="ea-card">
                <div class="ea-line"><a class="mono" [routerLink]="['/transactions', t.id]">{{ t.ref_no }}</a><b>{{ t.amount | money }}</b></div>
                <div class="ea-line ea-sub"><span>{{ t.account.name }}</span><span class="ea-path">{{ t.category_path }}</span></div>
                <div class="ea-line ea-sub"><span>{{ t.requested_on | appDate }}</span><span>Expires {{ t.expires_on | appDate }}</span></div>
                @if (t.can_cancel) {
                  <div class="ea-buttons"><button type="button" class="ghost" [disabled]="busy()" (click)="cancel(t)">Cancel request</button></div>
                }
              </div>
            }
          </div>
          @if (r.my_requests.total > r.my_requests.data.length) { <p class="muted">and {{ r.my_requests.total - r.my_requests.data.length }} more</p> }
        </section>
      }
    } @else if (error()) { <div class="alert">{{ error() }}</div> }

    @if (dialog(); as d) {
      <ag-approval-dialog [target]="d.target" [mode]="d.mode" (done)="done($event)" (cancelled)="dialog.set(null)" (refused)="load()" />
    }
  `,
})
export class ExpenseApprovalsComponent implements OnInit {
  auth = inject(AuthService);
  private http = inject(HttpClient);
  r = signal<ExpenseApprovals | null>(null);
  error = signal('');
  ok = signal('');
  mineOk = signal('');
  mineErr = signal('');
  busy = signal(false);
  dialog = signal<{ target: ApprovalTarget; mode: 'approve' | 'reject' } | null>(null);

  ngOnInit() { this.load(); }

  /** S3: Super Admin, or expense.approve in the active branch. */
  showApprovals(): boolean {
    return this.auth.isSuperAdmin() || this.auth.can('expense.approve');
  }

  load() {
    this.http.get<ExpenseApprovals>('/api/v1/expense-approvals').subscribe({
      next: r => { this.r.set(r); this.error.set(''); },
      error: e => this.error.set(errorText(e)),
    });
  }

  short(t: ExpenseRow): boolean { return signedCents(t.account.available) < signedCents(t.amount); }

  signed(v: string | null | undefined): number { return signedCents(v); }

  /** AG-066 R7a: "From template ...", the changes with the template's values, changed since last use, confirmed duplicate. */
  lines(t: ExpenseRowWithTemplate): TemplateLine[] { return approvalTemplateLines(t); }

  open(t: ExpenseRowWithTemplate, mode: 'approve' | 'reject') {
    this.ok.set('');
    // S3: in warn mode a short account may still be approved; block mode refuses at approval with the funds message.
    const warn = this.r()?.negative_balance === 'warn';
    this.dialog.set({ mode, target: {
      kind: 'expense', id: t.id, ref_no: t.ref_no, amount: t.amount, requested_date: t.requested_date, requester_inactive: t.requester_inactive,
      from: `${t.branch.code} ${t.account.name}`, to: t.category_path, files_count: t.files_count,
      below_zero_line: warn && this.short(t) ? `This will take ${t.branch.code} ${t.account.name} below zero.` : null,
      approver_onboarded_requester: t.approver_onboarded_requester, requested_period_locked: t.requested_period_locked,
      template_lines: this.lines(t),
    } });
  }

  done(res: { ref_no: string }) {
    const mode = this.dialog()?.mode;
    this.dialog.set(null);
    this.ok.set(mode === 'approve' ? `${res.ref_no} approved and posted.` : `${res.ref_no} rejected.`);
    this.load();
  }

  cancel(t: ExpenseRow) {
    if (this.busy()) return;
    const reason = prompt(`Cancel request ${t.ref_no}? Reason (optional):`);
    if (reason === null) return;
    this.busy.set(true);
    this.mineOk.set('');
    this.mineErr.set('');
    this.http.post(`/api/v1/expense-approvals/${t.id}/cancel`, reason.trim() ? { comment: reason.trim() } : {}).subscribe({
      next: () => { this.busy.set(false); this.mineOk.set('Request cancelled.'); this.load(); },
      error: e => { this.busy.set(false); this.mineErr.set(errorText(e)); this.load(); },
    });
  }
}
