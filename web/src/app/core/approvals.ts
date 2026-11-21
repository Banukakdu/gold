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
}

/**
 * S3 dialogs. Approve: fixed posting date (today, R6), optional comment. Reject: reason 10-500, sent to the requester.
 * Errors show the API message; a 422 that is not a field error also tells the host, which reloads. Esc closes it.
 */
@Component({
  selector: 'ag-approval-dialog',
  standalone: true,
  imports: [FormsModule, MoneyPipe, AppDatePipe],
  template: `
    <div class="att-backdrop" (click)="cancelled.emit()"></div>
    <div class="card att-dialog" role="dialog" aria-modal="true" aria-labelledby="ap-title">
      @if (mode === 'approve') {
        <h2 id="ap-title">Approve {{ target.ref_no }}</h2>
        <p>Posts {{ target.ref_no }} ({{ target.amount | money }}) from {{ target.from }} to {{ target.to }}. Funds and period locks are checked now.</p>
        <p class="muted">Posting date: today ({{ today | appDate }}). Requested for {{ target.requested_date | appDate }}.</p>
        @if (target.requester_inactive) { <p class="neg">Requester is inactive.</p> }
        <label>Comment (optional)
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
  busy = signal(false);
  error = signal('');

  ngOnInit() { this.today = colomboToday(); }

  @HostListener('document:keydown.escape')
  onEscape() {
    if (!this.busy()) this.cancelled.emit();
  }

  valid(): boolean {
    const n = this.comment.trim().length;
    return this.mode === 'reject' ? n >= 10 && n <= 500 : n <= 500;
  }

  send() {
    if (this.busy()) return;
    this.busy.set(true);
    this.error.set('');
    const body: Record<string, string> = {};
    if (this.comment.trim()) body['comment'] = this.comment.trim();
    this.http.post<{ ref_no: string }>(`/api/v1/transfer-approvals/${this.target.id}/${this.mode}`, body).subscribe({
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

/** AG-051 S8: the pending-approval fields of the Period locks review and the Year-end preview. */
export interface PendingApprovalInfo {
  transfers_pending_approval?: number;
  transfers_pending_approval_amount?: string;
}

/** S8 information line ('' when nothing is pending). Never blocking: requests post on their approval date. */
export function pendingApprovalLine(i: PendingApprovalInfo | null | undefined, period: string, money: (v: string) => string): string {
  const n = i?.transfers_pending_approval ?? 0;
  if (!n) return '';
  const one = n === 1;
  return `${n} transfer request${one ? '' : 's'} (${money(i?.transfers_pending_approval_amount ?? '0')}) dated in ${period} `
    + `${one ? 'is' : 'are'} still pending approval. ${one ? 'It is' : 'They are'} not in ${period}'s figures and will post on `
    + `${one ? 'its' : 'their'} approval date.`;
}
