import { Component, EventEmitter, HostListener, Input, OnInit, Output, inject, signal } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { FormsModule } from '@angular/forms';
import { errorText } from './api.interceptor';
import { MoneyPipe } from './money.pipe';
import { AuthService } from './auth.service';
import { colomboToday } from './dates';

/** AG-052 R2 labels (same as the API's TransferReceipts::LABELS). A null receipt is "Not required". */
export const RECEIPT_LABELS: Record<string, string> = {
  awaiting: 'Awaiting receipt',
  received: 'Received',
  disputed: 'Discrepancy reported',
  cancelled: 'Cancelled (reversed)',
};

/** Short tags for lists (S2 / S4). */
export const RECEIPT_TAGS: Record<string, string> = { awaiting: 'Awaiting', received: 'Received', disputed: 'Discrepancy', cancelled: 'Cancelled' };

export function receiptLabel(s: string | null | undefined): string {
  return s ? RECEIPT_LABELS[s] ?? s : 'Not required';
}

/** What the confirm / discrepancy dialog needs to know about the transfer. */
export interface ReceiptTarget {
  id: number;
  ref_no: string;
  txn_date: string;
  amount: string;
  receipt_status: string | null;
  /** Review findings 3 / 4: settling a discrepancy, or confirming from All branches. */
  note_required?: boolean;
}

/** Fields of POST /transactions/{id}/receipt's answer. */
export interface ReceiptResult {
  id: number; ref_no: string; receipt_status: string; received_on: string | null; receipt_note: string | null;
  receipt_by: { id: number; name: string; is_super_admin?: boolean } | null; receipt_at: string | null;
}

/**
 * S2 / S3 dialogs: "Confirm receipt" (Received on + Note) and "Report a discrepancy" (Note, 10-500). Errors show the
 * API message in the dialog; a 422 also tells the host, which reloads and passes the new state back in (the
 * dialog stays open on the fresh state, or the host closes it when nothing is left to do). Esc closes it.
 */
@Component({
  selector: 'ag-receipt-dialog',
  standalone: true,
  imports: [FormsModule, MoneyPipe],
  template: `
    <div class="att-backdrop" (click)="cancelled.emit()"></div>
    <div class="card att-dialog" role="dialog" aria-modal="true" aria-labelledby="rc-title">
      @if (mode === 'received') {
        <h2 id="rc-title">Confirm receipt of {{ target.ref_no }}</h2>
        <p class="muted">{{ target.amount | money }}. Confirming changes no balance; it records that the cash or deposit arrived.</p>
        <label>Received on
          <input type="date" name="received_on" [(ngModel)]="receivedOn" [min]="target.txn_date" [max]="today" required />
        </label>
        <label>{{ noteLabel() }}
          <textarea rows="3" name="note" [(ngModel)]="note" maxlength="500"></textarea>
        </label>
      } @else {
        <h2 id="rc-title">Report a discrepancy</h2>
        <p>Report that the cash or deposit does not match {{ target.ref_no }} ({{ target.amount | money }}). Balances do not change. The sending branch sees your note.</p>
        <label>Note (10-500 characters, e.g. the amount counted and who counted it)
          <textarea rows="3" name="note" [(ngModel)]="note" maxlength="500"></textarea>
        </label>
      }
      @if (error()) { <div class="alert">{{ error() }}</div> }
      <div class="att-dialog-actions">
        <button type="button" class="ghost" (click)="cancelled.emit()">Cancel</button>
        <button type="button" [disabled]="busy() || !valid()" (click)="send()">{{ mode === 'received' ? 'Confirm' : 'Report' }}</button>
      </div>
    </div>
  `,
})
export class ReceiptDialogComponent implements OnInit {
  @Input({ required: true }) target!: ReceiptTarget;
  @Input({ required: true }) mode!: 'received' | 'discrepancy';
  @Output() done = new EventEmitter<ReceiptResult>();
  @Output() cancelled = new EventEmitter<void>();
  /** A 422 (e.g. the receipt changed meanwhile): carries the API message; the host reloads. */
  @Output() refused = new EventEmitter<string>();

  private http = inject(HttpClient);
  private auth = inject(AuthService);
  today = '';
  receivedOn = '';
  note = '';
  busy = signal(false);
  error = signal('');

  ngOnInit() {
    this.today = colomboToday();
    this.receivedOn = this.today;
  }

  @HostListener('document:keydown.escape')
  onEscape() {
    if (!this.busy()) this.cancelled.emit();
  }

  private noteRequired(): boolean {
    return this.mode === 'discrepancy' || !!this.target.note_required;
  }

  noteLabel(): string {
    if (this.target.receipt_status === 'disputed') return 'How was it settled? (10-500 characters)';
    if (this.target.note_required || this.auth.isAllBranches()) return 'What did you check? e.g. bank statement date (10-500 characters)';
    return 'Note (optional)';
  }

  valid(): boolean {
    const n = this.note.trim().length;
    if (this.noteRequired() ? n < 10 || n > 500 : n > 500) return false;
    if (this.mode === 'received') {
      return !!this.receivedOn && this.receivedOn >= this.target.txn_date && this.receivedOn <= this.today;
    }
    return true;
  }

  send() {
    if (this.busy()) return;
    this.busy.set(true);
    this.error.set('');
    // R6: the state the user saw; the API answers 422 when someone else changed it meanwhile.
    const body: Record<string, string> = { action: this.mode };
    if (this.target.receipt_status) body['expected_status'] = this.target.receipt_status;
    if (this.mode === 'received') body['received_on'] = this.receivedOn;
    if (this.note.trim()) body['note'] = this.note.trim();
    this.http.post<ReceiptResult>(`/api/v1/transactions/${this.target.id}/receipt`, body).subscribe({
      next: r => { this.busy.set(false); this.done.emit(r); },
      error: e => {
        this.busy.set(false);
        this.error.set(errorText(e));
        if (e?.status === 422 && !e?.error?.errors) this.refused.emit(errorText(e));
      },
    });
  }
}

/** AG-052 S7: the in-transit fields of the Period locks review and the Year-end preview (R14). */
export interface InTransitInfo {
  transfers_awaiting_receipt?: number;
  transfers_awaiting_receipt_amount?: string;
  transfers_disputed?: number;
  transfers_disputed_amount?: string;
}

/** S7 information line ('' when nothing is in transit). Information only: it never blocks a lock or the close. */
export function inTransitLine(i: InTransitInfo | null | undefined, asAt: string, money: (v: string) => string): string {
  const n = i?.transfers_awaiting_receipt ?? 0;
  if (!n) return '';
  const d = i?.transfers_disputed ?? 0;
  return `${n} transfer${n === 1 ? '' : 's'} (${money(i?.transfers_awaiting_receipt_amount ?? '0')}) in transit at ${asAt}`
    + (d ? `, of which ${d} (${money(i?.transfers_disputed_amount ?? '0')}) with a discrepancy reported` : '')
    + '. Run the Transfers report with Receipt = In transit at end date.';
}
