import { Component, EventEmitter, HostListener, Input, OnInit, Output, inject, signal } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { FormsModule } from '@angular/forms';
import { errorText } from './api.interceptor';
import { NotificationsService } from './notifications.service';
import { MoneyPipe } from './money.pipe';
import { AppDatePipe } from './app-date.pipe';
import { colomboToday } from './dates';

/** AG-051 R1 labels (same as the API's TransferApprovals::LABELS). A null approval_status is "Not required". */
export const APPROVAL_LABELS: Record<string, string> = {
  pending: 'Pending approval',
  approved: 'Approved',
  rejected: 'Rejected',
  cancelled: 'Cancelled by requester',
  expired: 'Expired',
};

export function approvalLabel(s: string | null | undefined): string {
  return s ? APPROVAL_LABELS[s] ?? s : 'Not required';
}

/** Exact cents of a typed amount ("150000", "150000.5"); null when it is not a plain 2-decimal number. No floats. */
export function toCents(v: unknown): number | null {
  const s = String(v ?? '').trim();
  const m = /^(\d{1,13})(?:\.(\d{1,2}))?$/.exec(s);
  if (!m) return null;
  return Number(m[1]) * 100 + Number((m[2] ?? '').padEnd(2, '0'));
}

/** What the approve / reject dialog needs to know about the request. */
export interface ApprovalTarget {
  id: number;
  ref_no: string;
  amount: string;
  requested_date: string | null;
  from: string;
  to: string;
  requester_inactive?: boolean;
  /** AG-064: an expense request (the dialog posts to /expense-approvals and shows the S3 expense lines). */
  kind?: 'transfer' | 'expense';
  /** AG-064 MUST 4: with no file the comment is required (10-500) and asks why. */
  files_count?: number;
  /** AG-064 S3: the account would go below zero (warn mode only; block mode refuses at approval). */
  below_zero_line?: string | null;
  /** AG-064 R4b: the approver brought the requester into the branch. */
  approver_onboarded_requester?: boolean;
  /** AG-064 SHOULD 7: the requested month is locked or its year closed now. */
  requested_period_locked?: boolean;
}

/**
 * S3 dialogs (AG-051, reused by AG-064 expense approvals with `kind: 'expense'`). Approve: fixed posting date (today,
 * R6), optional comment (an expense with no file: required, "Why is there no receipt?"). Reject: reason 10-500, sent to
 * the requester.
 * Errors show the API message; a 422 that is not a field error also tells the host, which reloads. Esc closes it.
 */
@Component({
  selector: 'ag-approval-dialog',
  standalone: true,
  imports: [FormsModule, MoneyPipe, AppDatePipe],
  template: `
    <div class="att-backdrop" (click)="cancelled.emit()"></div>
    <div class="card att-dialog ap-dialog" role="dialog" aria-modal="true" aria-labelledby="ap-title">
      @if (mode === 'approve') {
        <h2 id="ap-title">Approve {{ target.ref_no }}</h2>
        <p>Posts {{ target.ref_no }} ({{ target.amount | money }}) from {{ target.from }} to {{ target.to }}. Funds and period locks are checked now.</p>
        @if (target.requested_period_locked) {
          <p class="muted">Posting date: today ({{ today | appDate }}). Requested for {{ target.requested_date | appDate }} ({{ monthName(target.requested_date) }} is locked): this posts on today's date.</p>
        } @else {
          <p class="muted">Posting date: today ({{ today | appDate }}). Requested for {{ target.requested_date | appDate }}.</p>
        }
        @if (target.requester_inactive) { <p class="neg">Requester is inactive.</p> }
        @if (target.below_zero_line) { <p class="neg">{{ target.below_zero_line }}</p> }
        @if (target.approver_onboarded_requester) {
          <p class="alert">You added this requester to the branch. Super Admins will be told of this approval.</p>
        }
        @if (isExpense()) {
          <label class="tr-check"><input type="checkbox" name="paid" [(ngModel)]="paid" style="width:auto" /> The bill was already paid before approval</label>
        }
        <label>{{ needsReason() ? 'Why is there no receipt? (10-500 characters)' : 'Comment (optional)' }}
          <textarea rows="3" name="comment" [(ngModel)]="comment" maxlength="500"></textarea>
        </label>
      } @else {
        <h2 id="ap-title">Reject {{ target.ref_no }}</h2>
        <p class="muted">{{ target.amount | money }} from {{ target.from }} to {{ target.to }}. Nothing is posted.</p>
        <label>Reason, sent to the requester (10-500 characters)
          <textarea rows="3" name="comment" [(ngModel)]="comment" maxlength="500"></textarea>
        </label>
      }
      @if (error()) { <div class="alert">{{ error() }}</div> }
      <div class="att-dialog-actions">
        <button type="button" class="ghost" (click)="cancelled.emit()">Cancel</button>
        <button type="button" [disabled]="busy() || !valid()" (click)="send()">{{ mode === 'approve' ? 'Approve' : 'Reject' }}</button>
      </div>
    </div>
  `,
})
export class ApprovalDialogComponent implements OnInit {
  @Input({ required: true }) target!: ApprovalTarget;
  @Input({ required: true }) mode!: 'approve' | 'reject';
  @Output() done = new EventEmitter<{ ref_no: string }>();
  @Output() cancelled = new EventEmitter<void>();
  /** A 422 that is not a field error (e.g. decided meanwhile, insufficient balance): the host reloads. */
  @Output() refused = new EventEmitter<string>();

  private http = inject(HttpClient);
  private notifications = inject(NotificationsService);
  today = '';
  comment = '';
  /** AG-064 SHOULD 8 (expense only). */
  paid = false;
  busy = signal(false);
  error = signal('');

  ngOnInit() { this.today = colomboToday(); }

  @HostListener('document:keydown.escape')
  onEscape() {
    if (!this.busy()) this.cancelled.emit();
  }

  isExpense(): boolean { return this.target.kind === 'expense'; }

  /** AG-064 MUST 4: approving an expense request with no file needs the reason. */
  needsReason(): boolean { return this.mode === 'approve' && this.isExpense() && (this.target.files_count ?? 0) === 0; }

  valid(): boolean {
    const n = this.comment.trim().length;
    return this.mode === 'reject' || this.needsReason() ? n >= 10 && n <= 500 : n <= 500;
  }

  monthName(d: string | null): string {
    if (!d) return '';
    const [y, m] = d.split('-').map(Number);
    return new Date(Date.UTC(y, m - 1, 1)).toLocaleString('en-GB', { month: 'long', timeZone: 'UTC' });
  }

  send() {
    if (this.busy()) return;
    this.busy.set(true);
    this.error.set('');
    const body: Record<string, string | boolean> = {};
    if (this.comment.trim()) body['comment'] = this.comment.trim();
    if (this.isExpense() && this.mode === 'approve') body['paid_before_approval'] = this.paid;
    const base = this.isExpense() ? 'expense-approvals' : 'transfer-approvals';
    this.http.post<{ ref_no: string }>(`/api/v1/${base}/${this.target.id}/${this.mode}`, body).subscribe({
      // AG-094 R9: the user's own action may close or create notifications; refresh the bell's count now.
      next: r => { this.busy.set(false); this.notifications.refreshCount(); this.done.emit(r); },
      error: e => {
        this.busy.set(false);
        this.error.set(errorText(e));
        if (e?.status === 422 && !e?.error?.errors) this.refused.emit(errorText(e));
      },
    });
  }
}

/** AG-051 / AG-064 S8: the pending-approval fields of the Period locks review and the Year-end preview. */
export interface PendingApprovalInfo {
  transfers_pending_approval?: number;
  transfers_pending_approval_amount?: string;
  expenses_pending_approval?: number;
  expenses_pending_approval_amount?: string;
  /** Year-end preview only (SHOULD 7): the pending expense requests, oldest first. */
  expenses_pending_approval_list?: { id: number; ref_no: string; branch: { id: number; code: string }; requested_date: string; amount: string }[];
}

/** S8 information line ('' when nothing is pending). Never blocking: requests post on their approval date. */
export function pendingApprovalLine(i: PendingApprovalInfo | null | undefined, period: string, money: (v: string) => string): string {
  return [
    pendingLine('transfer', i?.transfers_pending_approval ?? 0, i?.transfers_pending_approval_amount ?? '0', period, money),
    pendingLine('expense', i?.expenses_pending_approval ?? 0, i?.expenses_pending_approval_amount ?? '0', period, money),
  ].filter(Boolean).join(' ');
}

function pendingLine(what: string, n: number, amount: string, period: string, money: (v: string) => string): string {
  if (!n) return '';
  const one = n === 1;
  return `${n} ${what} request${one ? '' : 's'} (${money(amount)}) dated in ${period} `
    + `${one ? 'is' : 'are'} still pending approval. ${one ? 'It is' : 'They are'} not in ${period}'s figures and will post on `
    + `${one ? 'its' : 'their'} approval date.`;
}
