import { Component, OnInit, inject, signal } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { RouterLink } from '@angular/router';
import { AppDatePipe } from '../../core/app-date.pipe';
import { AuthService } from '../../core/auth.service';
import { MoneyPipe, TYPE_LABELS } from '../../core/money.pipe';
import { RECEIPT_TAGS, ReceiptDialogComponent, ReceiptResult, ReceiptTarget } from '../../core/receipts';

interface Side { branch_code: string | null; account: string | null }
interface Row {
  id: number; ref_no: string; type: string; txn_date: string; age_days: number; from: Side; to: Side; amount: string;
  receipt_status: string; receipt_note: string | null; receipt_by: { id: number; name: string } | null; receipt_at: string | null;
  created_by: { id: number; name: string } | null; sent_by_you: boolean; can_acknowledge: boolean; note_required: boolean;
}
interface List { data: Row[]; total: number }
interface Receipts {
  required: boolean;
  summary: { buckets: { key: string; count: number; amount: string }[]; count: number; amount: string };
  incoming: List;
  outgoing_disputed: List;
}

const BUCKETS: Record<string, string> = { '0_1': '0-1 days', '2_3': '2-3 days', '4_7': '4-7 days', '8_plus': 'Over 7 days' };

/** AG-052 S2: "Transfers to confirm" on the dashboard (receiving branch; All mode: every branch). */
@Component({
  selector: 'ag-transfer-receipts',
  standalone: true,
  imports: [RouterLink, MoneyPipe, AppDatePipe, ReceiptDialogComponent],
  template: `
    @if (r(); as r) {
      @if (r.required || r.incoming.total || r.outgoing_disputed.total) {
        <section class="card section tr-widget">
          <h2>Transfers to confirm</h2>
          @if (ok()) { <div class="ok">{{ ok() }}</div> }
          <div class="kpis tr-buckets">
            @for (b of r.summary.buckets; track b.key) {
              <div class="kpi" [class.tr-late]="b.key === '8_plus' && b.count > 0">
                <span>{{ bucket(b.key) }}</span><b>{{ b.count }}</b><small>{{ b.amount | money }}</small>
              </div>
            }
          </div>

          @if (r.incoming.data.length) {
            <div class="tr-scroll">
              <table class="list">
                <thead><tr><th>Date</th><th class="num">Age (days)</th><th>Ref</th><th>Type</th><th>From</th>
                  @if (auth.isAllBranches()) { <th>To branch</th> }<th>To account</th><th class="num">Amount</th><th>Receipt</th><th></th></tr></thead>
                <tbody>
                  @for (t of r.incoming.data; track t.id) {
                    <tr [class.tr-amber]="t.age_days >= 4 && t.age_days <= 7" [class.tr-red]="t.age_days > 7">
                      <td>{{ t.txn_date | appDate }}</td>
                      <td class="num">{{ t.age_days }} @if (t.age_days > 7) { <span class="tag" data-s="reversed">Overdue</span> }
                        @else if (t.age_days >= 4) { <span class="tag att-late">Late</span> }</td>
                      <td class="mono"><a [routerLink]="['/transactions', t.id]">{{ t.ref_no }}</a></td>
                      <td>{{ label(t.type) }}</td>
                      <td>{{ t.from.branch_code }} · {{ t.from.account }}</td>
                      @if (auth.isAllBranches()) { <td>{{ t.to.branch_code }}</td> }
                      <td>{{ t.to.account }}</td>
                      <td class="num">{{ t.amount | money }}</td>
                      <td>
                        <span class="tag" [attr.data-s]="t.receipt_status === 'disputed' ? 'reversed' : null">{{ tag(t.receipt_status) }}</span>
                        @if (t.receipt_note) { <details class="tr-note"><summary>Note</summary>{{ t.receipt_note }}</details> }
                      </td>
                      <td class="tr-actions">
                        @if (t.sent_by_you) { <span class="muted">Sent by you</span> }
                        @else if (t.can_acknowledge) {
                          <button type="button" class="link" (click)="open(t, 'received')">Confirm receipt</button>
                          @if (t.receipt_status !== 'disputed') {
                            <button type="button" class="link" (click)="open(t, 'discrepancy')">Report a discrepancy</button>
                          }
                        }
                      </td>
                    </tr>
                  }
                </tbody>
              </table>
            </div>
            @if (r.incoming.total > r.incoming.data.length) { <p class="muted">and {{ r.incoming.total - r.incoming.data.length }} more</p> }
          } @else {
            <p class="muted">No transfers waiting for confirmation.</p>
          }

          @if (!auth.isAllBranches() && r.outgoing_disputed.data.length) {
            <h3>Disputed by the receiving branch</h3>
            <div class="tr-scroll">
              <table class="list">
                <thead><tr><th>Date</th><th>Ref</th><th>To</th><th class="num">Amount</th><th>Note</th><th>Reported by / at</th></tr></thead>
                <tbody>
                  @for (t of r.outgoing_disputed.data; track t.id) {
                    <tr>
                      <td>{{ t.txn_date | appDate }}</td>
                      <td class="mono"><a [routerLink]="['/transactions', t.id]">{{ t.ref_no }}</a></td>
                      <td>{{ t.to.branch_code }} · {{ t.to.account }}</td>
                      <td class="num">{{ t.amount | money }}</td>
                      <td class="ts-wrap">{{ t.receipt_note }}</td>
                      <td>{{ t.receipt_by?.name }} · {{ t.receipt_at | appDate }}</td>
                    </tr>
                  }
                </tbody>
              </table>
            </div>
            @if (r.outgoing_disputed.total > r.outgoing_disputed.data.length) {
              <p class="muted">and {{ r.outgoing_disputed.total - r.outgoing_disputed.data.length }} more</p>
            }
          }
          <p><a routerLink="/transactions" [queryParams]="{ receipt: 'awaiting,disputed' }">View all in Transactions</a></p>
        </section>
      }
    } @else if (error()) { <div class="alert">{{ error() }}</div> }

    @if (dialog(); as d) {
      <ag-receipt-dialog [target]="d.target" [mode]="d.mode" (done)="done($event)" (cancelled)="dialog.set(null)" (refused)="load()" />
    }
  `,
})
export class TransferReceiptsComponent implements OnInit {
  auth = inject(AuthService);
  private http = inject(HttpClient);
  r = signal<Receipts | null>(null);
  error = signal('');
  ok = signal('');
  dialog = signal<{ target: ReceiptTarget; mode: 'received' | 'discrepancy' } | null>(null);

  ngOnInit() { this.load(); }

  load() {
    this.http.get<Receipts>('/api/v1/transfer-receipts').subscribe({
      next: r => { this.r.set(r); this.error.set(''); },
      error: () => this.error.set('Could not load the transfers to confirm.'),
    });
  }

  open(t: Row, mode: 'received' | 'discrepancy') {
    this.ok.set('');
    this.dialog.set({ mode, target: { id: t.id, ref_no: t.ref_no, txn_date: t.txn_date, amount: t.amount, receipt_status: t.receipt_status, note_required: t.note_required } });
  }

  done(res: ReceiptResult) {
    const mode = this.dialog()?.mode;
    this.dialog.set(null);
    this.ok.set(mode === 'received' ? `Receipt of ${res.ref_no} confirmed.` : `Discrepancy on ${res.ref_no} reported.`);
    this.load();
  }

  bucket(k: string) { return BUCKETS[k] ?? k; }
  tag(s: string) { return RECEIPT_TAGS[s] ?? s; }
  label(t: string) { return TYPE_LABELS[t] ?? t; }
}
