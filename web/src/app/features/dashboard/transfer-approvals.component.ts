import { Component, OnInit, inject, signal } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { RouterLink } from '@angular/router';
import { AppDatePipe } from '../../core/app-date.pipe';
import { AuthService } from '../../core/auth.service';
import { MoneyPipe, TYPE_LABELS } from '../../core/money.pipe';
import { errorText } from '../../core/api.interceptor';
import { ApprovalDialogComponent, ApprovalTarget } from '../../core/approvals';

interface Side { branch_code: string | null; account: string | null; available?: string }
interface Row {
  id: number; ref_no: string; type: string; requested_date: string | null; requested_on: string | null; age_days: number; expires_on: string | null;
  from: Side; to: Side; amount: string; other_pending_from_account: string; below_threshold_7d: { count: number; amount: string };
  note: string | null; requested_by: { id: number; name: string } | null; requested_by_you: boolean; requester_inactive: boolean;
  can_approve: boolean; can_cancel: boolean;
}
interface Approvals {
  threshold: string | null;
  to_approve: { data: Row[]; total: number; amount: string };
  my_requests: { data: Row[]; total: number };
}

/** AG-051 S3 "Transfers to approve" (Main approvers, Super Admin) and S4 "My transfer requests" (the requester). */
@Component({
  selector: 'ag-transfer-approvals',
  standalone: true,
  imports: [RouterLink, MoneyPipe, AppDatePipe, ApprovalDialogComponent],
  template: `
    @if (r(); as r) {
      @if (showApprovals()) {
        <section class="card section tr-widget">
          <h2>Transfers to approve @if (r.to_approve.total) { <small class="muted">{{ r.to_approve.total }} · {{ r.to_approve.amount | money }}</small> }</h2>
          @if (ok()) { <div class="ok">{{ ok() }}</div> }
          @if (warn()) { <div class="alert">{{ warn() }}</div> }
          @if (r.to_approve.data.length) {
            <div class="tr-scroll">
              <table class="list">
                <thead><tr><th>Requested on</th><th class="num">Age (days)</th><th>Ref</th><th>Type</th><th>From</th><th>To</th>
                  <th class="num">Amount</th><th class="num">Available now</th><th class="num">Other pending</th><th>Below threshold (7 days)</th>
                  <th>Requested by</th><th>Note</th><th></th></tr></thead>
                <tbody>
                  @for (t of r.to_approve.data; track t.id) {
                    <tr>
                      <td>{{ t.requested_on | appDate }}</td>
                      <td class="num">{{ t.age_days }}</td>
                      <td class="mono"><a [routerLink]="['/transactions', t.id]">{{ t.ref_no }}</a></td>
                      <td>{{ label(t.type) }}</td>
                      <td>{{ t.from.branch_code }} · {{ t.from.account }}</td>
                      <td>{{ t.to.branch_code }} · {{ t.to.account }}</td>
                      <td class="num">{{ t.amount | money }}</td>
                      <td class="num">{{ t.from.available | money }} @if (short(t)) { <span class="tag" data-s="reversed">Short</span> }</td>
                      <td class="num">{{ t.other_pending_from_account | money }}</td>
                      <td>@if (t.below_threshold_7d.count) { {{ t.below_threshold_7d.count }}, {{ t.below_threshold_7d.amount | money }} }</td>
                      <td>{{ t.requested_by?.name }} @if (t.requester_inactive) { <span class="tag">Inactive</span> }</td>
                      <td class="ts-wrap">{{ t.note }}</td>
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
            @if (r.to_approve.total > r.to_approve.data.length) { <p class="muted">and {{ r.to_approve.total - r.to_approve.data.length }} more</p> }
          } @else { <p class="muted">No transfers waiting for approval.</p> }
        </section>
      }

      @if (!auth.isAllBranches() && auth.can('transfer.create') && r.my_requests.data.length) {
        <section class="card section tr-widget">
          <h2>My transfer requests</h2>
          @if (mineOk()) { <div class="ok">{{ mineOk() }}</div> }
          @if (mineErr()) { <div class="alert">{{ mineErr() }}</div> }
          <div class="tr-scroll">
            <table class="list">
              <thead><tr><th>Requested on</th><th>Ref</th><th>To</th><th class="num">Amount</th><th>Expires on</th><th></th></tr></thead>
              <tbody>
                @for (t of r.my_requests.data; track t.id) {
                  <tr>
                    <td>{{ t.requested_on | appDate }}</td>
                    <td class="mono"><a [routerLink]="['/transactions', t.id]">{{ t.ref_no }}</a></td>
                    <td>{{ t.to.branch_code }} · {{ t.to.account }}</td>
                    <td class="num">{{ t.amount | money }}</td>
                    <td>{{ t.expires_on | appDate }}</td>
                    <td>@if (t.can_cancel) { <button type="button" class="link" [disabled]="busy()" (click)="cancel(t)">Cancel request</button> }</td>
                  </tr>
                }
              </tbody>
            </table>
          </div>
          @if (r.my_requests.total > r.my_requests.data.length) { <p class="muted">and {{ r.my_requests.total - r.my_requests.data.length }} more</p> }
        </section>
      }
    } @else if (error()) { <div class="alert">{{ error() }}</div> }

    @if (dialog(); as d) {
      <ag-approval-dialog [target]="d.target" [mode]="d.mode" (done)="done($event)" (cancelled)="dialog.set(null)" (refused)="refused($event)" />
    }
  `,
})
export class TransferApprovalsComponent implements OnInit {
  auth = inject(AuthService);
  private http = inject(HttpClient);
  r = signal<Approvals | null>(null);
  error = signal('');
  ok = signal('');
  warn = signal('');
  mineOk = signal('');
  mineErr = signal('');
  busy = signal(false);
  dialog = signal<{ target: ApprovalTarget; mode: 'approve' | 'reject' } | null>(null);

  ngOnInit() { this.load(); }

  /** S3: Super Admin, or transfer.approve while the active branch is MAIN. */
  showApprovals(): boolean {
    if (this.auth.isSuperAdmin()) return true;
    return !!this.auth.activeBranch()?.is_main && this.auth.can('transfer.approve');
  }

  load() {
    this.http.get<Approvals>('/api/v1/transfer-approvals').subscribe({
      next: r => { this.r.set(r); this.error.set(''); },
      error: () => this.error.set('Could not load the transfers to approve.'),
    });
  }

  short(t: Row): boolean { return Number(t.from.available ?? 0) < Number(t.amount); }

  open(t: Row, mode: 'approve' | 'reject') {
    this.ok.set('');
    this.warn.set('');
    this.dialog.set({ mode, target: {
      id: t.id, ref_no: t.ref_no, amount: t.amount, requested_date: t.requested_date, requester_inactive: t.requester_inactive,
      from: `${t.from.branch_code} · ${t.from.account}`, to: `${t.to.branch_code} · ${t.to.account}`,
    } });
  }

  done(res: { ref_no: string }) {
    const mode = this.dialog()?.mode;
    this.dialog.set(null);
    this.ok.set(mode === 'approve' ? `${res.ref_no} approved and posted.` : `${res.ref_no} rejected.`);
    this.load();
  }

  /** A 422 for a request decided meanwhile (or a failed funds / lock check): the message stays in the dialog; reload. */
  refused(_message: string) { this.load(); }

  cancel(t: Row) {
    if (this.busy()) return;
    const reason = prompt(`Cancel request ${t.ref_no}? Reason (optional):`);
    if (reason === null) return;
    this.busy.set(true);
    this.mineOk.set('');
    this.mineErr.set('');
    this.http.post(`/api/v1/transfer-approvals/${t.id}/cancel`, reason.trim() ? { comment: reason.trim() } : {}).subscribe({
      next: () => { this.busy.set(false); this.mineOk.set('Request cancelled.'); this.load(); },
      error: e => { this.busy.set(false); this.mineErr.set(errorText(e)); this.load(); },
    });
  }

  label(t: string) { return TYPE_LABELS[t] ?? t; }
}
