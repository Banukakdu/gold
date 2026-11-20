import { Component, OnInit, computed, inject, signal } from '@angular/core';
import { HttpClient, HttpParams } from '@angular/common/http';
import { AppDatePipe } from '../../core/app-date.pipe';
import { SettingsService } from '../../core/settings.service';
import { FormsModule } from '@angular/forms';
import { ActivatedRoute, RouterLink } from '@angular/router';
import { firstValueFrom } from 'rxjs';
import { AuthService } from '../../core/auth.service';
import { MoneyPipe, TYPE_LABELS } from '../../core/money.pipe';
import { errorText } from '../../core/api.interceptor';
import {
  ATTACH_ACCEPT, AttachmentList, AttachmentRow, blobErrorText, fileSize, selectionProblem, uploadAttachment, uploadErrorText,
} from '../../core/attachments';
import { LockBannerComponent } from '../period-locks/lock-banner.component';
import { ReceiptDialogComponent, ReceiptResult, receiptLabel } from '../../core/receipts';
import { ApprovalDialogComponent, ApprovalTarget, approvalLabel } from '../../core/approvals';
import { TemplateFacts, approvalTemplateLines, changesText, useText } from '../../core/expense-templates';

interface Detail extends TemplateFacts {
  id: number; ref_no: string; type: string; status: string; txn_date: string; amount: string;
  payee_payer?: string | null; payment_method?: string | null; reference?: string | null; description?: string | null;
  branch?: { code: string; name: string };
  account?: { name: string } | null;
  from_account?: { name: string; branch?: { code: string } } | null;
  to_account?: { name: string; branch?: { code: string } } | null;
  category?: { name: string; parent?: { name: string } | null } | null;
  creator?: { name: string } | null;
  creator_tag?: string | null;
  legacy_void_note?: string;
  /** Double-entry S3. */
  entries?: LedgerLine[];
  balanced?: boolean;
  totals?: { debit: string; credit: string };
  in_active_branch?: boolean;
  backfilled_at?: string | null;
  /** AG-052 S3. */
  receipt_status?: string | null;
  received_on?: string | null;
  receipt_note?: string | null;
  receipt_by?: { id: number; name: string; is_super_admin?: boolean } | null;
  receipt_at?: string | null;
  can_acknowledge?: boolean;
  receipt_note_required?: boolean;
  /** The latest discrepancy report (kept visible after it was settled). */
  receipt_discrepancy?: { note: string | null; by: { id: number; name: string } | null; at: string | null } | null;
  /** AG-051 S5. */
  approval_status?: string | null;
  requested_date?: string | null;
  requested_on?: string | null;
  approval_threshold?: string | null;
  approval_note?: string | null;
  decided_by?: { id: number; name: string } | null;
  decided_at?: string | null;
  expires_on?: string | null;
  can_approve?: boolean;
  can_cancel?: boolean;
  requester_inactive?: boolean;
  /** AG-064 S5 (expense requests). */
  super_admin_only?: boolean;
  approver_onboarded_requester?: boolean;
  requested_period_locked?: boolean;
  changed_after_approval?: boolean;
  attachments_count?: number;
}

interface LedgerLine {
  id: number; entry_date: string; debit: string; credit: string; contra_of_id: number | null; origin: 'posting' | 'reclass' | 'backfill';
  account: { id: number; code: string | null; name: string; class: string; type: string } | null;
  branch: { id: number; code: string } | null;
}

interface UploadLine { name: string; pct: number; error?: string }

const VIEWABLE = ['image/jpeg', 'image/png', 'application/pdf'];

/**
 * AG-062 S2: minimal read-only entry detail (route /transactions/:id) that hosts the attachments of income and
 * expense entries. Downloads go through HttpClient (bearer token + X-Branch-Id), never a plain link.
 */
@Component({
  standalone: true,
  imports: [FormsModule, MoneyPipe, AppDatePipe, RouterLink, LockBannerComponent, ReceiptDialogComponent, ApprovalDialogComponent],
  template: `
    <p class="att-back"><a routerLink="/transactions">Back to transactions</a></p>
    @if (msg()) { <div class="alert">{{ msg() }}</div> }
    @if (t(); as t) {
      <h1><span class="att-ref">{{ t.ref_no }}</span> <span class="tag" [attr.data-s]="t.status">{{ t.approval_status && t.status !== 'posted' && t.status !== 'reversed' ? approvalText(t.approval_status) : t.status }}</span></h1>
      <ag-lock-banner />
      @if (t.legacy_void_note) { <div class="alert" style="margin-bottom:12px">{{ t.legacy_void_note }}</div> }
      <div class="card section">
        <dl class="entry-detail">
          <dt>Type</dt><dd>{{ label(t.type) }}</dd>
          <dt>Date</dt><dd>{{ t.txn_date | appDate }}</dd>
          <dt>Branch</dt><dd>{{ t.branch?.code }} {{ t.branch?.name }}</dd>
          <dt>Account</dt>
          <dd>{{ t.account?.name ?? ((t.from_account?.name ?? '') + ' → ' + (t.to_account?.name ?? '')) }}</dd>
          @if (t.category) {
            <dt>Category</dt><dd>{{ t.category.parent?.name ? t.category.parent?.name + ' › ' : '' }}{{ t.category.name }}</dd>
          }
          <dt>Amount</dt><dd [class.neg]="t.type === 'expense'">{{ t.type === 'year_end_close' ? '— (not money moved; see the ledger lines)' : (t.amount | money) }}</dd>
          @if (t.payee_payer) { <dt>{{ t.type === 'income' ? 'Received from' : 'Paid to' }}</dt><dd>{{ t.payee_payer }}</dd> }
          @if (t.reference) { <dt>Reference</dt><dd>{{ t.reference }}</dd> }
          @if (t.description) { <dt>Description</dt><dd class="att-pre">{{ t.description }}</dd> }
          <dt>Created by</dt>
          <dd>{{ t.creator?.name }} @if (t.creator_tag) { <span class="tag" data-s="inactive">{{ t.creator_tag }}</span> }</dd>
          @if (t.expense_template; as tpl) {
            <!-- AG-066 S3: the template link and what its use recorded (R11). -->
            <dt>From template</dt>
            <dd class="tpl-detail">{{ tpl.name }} @if (tpl.archived) { <span class="tag" data-s="inactive">Archived</span> }
              @if (t.template_changes?.length) { <br /><span>Changed from template: {{ changes(t) }}</span> }
              @if (t.template_changed_since_last_use) { <br /><span>Template changed since its last use</span> }
              @if (t.duplicate_confirmed) { <br /><span class="tpl-warn">Second use for {{ t.duplicate_period }} confirmed: {{ duplicates(t) }}</span> }
            </dd>
          }
        </dl>
      </div>

      <!-- AG-051 S5 -->
      @if (t.approval_status) {
        <div class="card section tr-panel">
          <h2>Approval</h2>
          @if (approvalOk()) { <div class="ok">{{ approvalOk() }}</div> }
          <dl class="entry-detail">
            <dt>Status</dt><dd><span class="tag" [attr.data-s]="t.approval_status === 'approved' ? null : 'reversed'">{{ approvalText(t.approval_status) }}</span>
              @if (t.super_admin_only) { <span class="tag" data-s="pending">Super Admin approval</span> }
              @if (t.changed_after_approval) { <a class="tag" data-s="pending" [routerLink]="['/audit-log']" [queryParams]="{ approval: 'after_approval', ref_no: t.ref_no }">Changed after approval</a> }</dd>
            <dt>Requested on / by</dt><dd>{{ t.requested_on | appDate }} · {{ t.creator?.name }}</dd>
            <dt>Requested for</dt><dd>{{ t.requested_date | appDate }}</dd>
            <dt>{{ t.type === 'expense' ? 'Limit at request' : 'Threshold at request' }}</dt><dd>{{ t.approval_threshold | money }}</dd>
            @if (t.approval_status === 'pending' && t.expires_on) { <dt>Expires on</dt><dd>{{ t.expires_on | appDate }}</dd> }
            @if (t.decided_at) { <dt>Decided by / at</dt><dd>{{ t.decided_by?.name ?? 'System (expired)' }} · {{ settings.dateTime(t.decided_at) }}</dd> }
            @if (t.approval_status === 'approved') { <dt>Posting date</dt><dd>{{ t.txn_date | appDate }}</dd> }
            @if (t.approval_note) { <dt>Comment</dt><dd class="att-pre">{{ t.approval_note }}</dd> }
          </dl>
          @if (t.can_approve || t.can_cancel) {
            <p>
              @if (t.can_approve) {
                <button type="button" (click)="approvalMode.set('approve')">Approve</button>
                <button type="button" class="ghost" style="margin-left:8px" (click)="approvalMode.set('reject')">Reject</button>
              }
              @if (t.can_cancel) { <button type="button" class="ghost" (click)="cancelRequest(t)">Cancel request</button> }
            </p>
          }
        </div>
        @if (approvalMode(); as mode) {
          <ag-approval-dialog [mode]="mode" (cancelled)="approvalMode.set(null)" (done)="approvalDone($event, mode)" (refused)="reload()"
            [target]="approvalTarget(t)" />
        }
      }

      @if (t.receipt_status) {
        <div class="card section tr-panel">
          <h2>Receipt</h2>
          @if (receiptOk()) { <div class="ok">{{ receiptOk() }}</div> }
          <dl class="entry-detail">
            <dt>Status</dt>
            <dd><span class="tag" [attr.data-s]="t.receipt_status === 'disputed' || t.receipt_status === 'cancelled' ? 'reversed' : null">{{ receiptText(t.receipt_status) }}</span></dd>
            @if (t.received_on) { <dt>Received on</dt><dd>{{ t.received_on | appDate }}</dd> }
            @if (t.receipt_by) {
              <dt>By / at</dt>
              <dd>{{ t.receipt_by.name }} · {{ settings.dateTime(t.receipt_at) }}
                @if (t.receipt_by.is_super_admin && t.receipt_status === 'received') { <span class="tag">Confirmed by Super Admin</span> }</dd>
            }
            @if (t.receipt_note) { <dt>{{ t.receipt_status === 'received' && t.receipt_discrepancy ? 'How it was settled' : 'Note' }}</dt><dd class="att-pre">{{ t.receipt_note }}</dd> }
            @if (t.receipt_discrepancy && t.receipt_status !== 'disputed') {
              <dt>Discrepancy reported</dt>
              <dd class="att-pre">{{ t.receipt_discrepancy.note }}<br /><small class="muted">{{ t.receipt_discrepancy.by?.name }} · {{ settings.dateTime(t.receipt_discrepancy.at) }}</small></dd>
            }
          </dl>
          @if (t.can_acknowledge) {
            <p>
              <button type="button" (click)="receiptMode.set('received')">Confirm receipt</button>
              @if (t.receipt_status !== 'disputed') {
                <button type="button" class="ghost" style="margin-left:8px" (click)="receiptMode.set('discrepancy')">Report a discrepancy</button>
              }
            </p>
          }
        </div>
        @if (receiptMode(); as mode) {
          <ag-receipt-dialog [mode]="mode" (cancelled)="receiptMode.set(null)" (done)="receiptDone($event, mode)" (refused)="receiptRefused($event)"
            [target]="{ id: t.id, ref_no: t.ref_no, txn_date: t.txn_date, amount: t.amount, receipt_status: t.receipt_status, note_required: t.receipt_note_required }" />
        }
      }

      @if (t.entries?.length) {
        <div class="card section">
          <h2>Ledger lines</h2>
          @if (t.in_active_branch === false) {
            <p class="muted">This entry belongs to {{ t.branch?.code }}. It is shown because it has lines in the selected branch; it can only be changed there.</p>
          }
          <table class="list">
            <thead><tr><th>Date</th><th>Branch</th><th>Code</th><th>Account</th><th class="num">Debit</th><th class="num">Credit</th><th>Note</th></tr></thead>
            <tbody>
              @for (e of t.entries; track e.id) {
                <tr>
                  <td>{{ e.entry_date | appDate }}</td>
                  <td>{{ e.branch?.code }}</td>
                  <td class="mono">{{ e.account?.code }}</td>
                  <td>{{ e.account?.name }}</td>
                  <td class="num">{{ +e.debit ? (e.debit | money) : '' }}</td>
                  <td class="num">{{ +e.credit ? (e.credit | money) : '' }}</td>
                  <td class="muted">{{ lineNote(t, e) }}</td>
                </tr>
              }
              <tr class="sub">
                <td colspan="4">Total
                  <span class="tag" [attr.data-s]="t.balanced ? 'balanced' : 'unbalanced'">{{ t.balanced ? 'Balanced' : 'Not balanced' }}</span></td>
                <td class="num">{{ t.totals?.debit | money }}</td><td class="num">{{ t.totals?.credit | money }}</td><td></td>
              </tr>
            </tbody>
          </table>
        </div>
      }

      @if (isEntry(t) && list(); as l) {
        <div class="card section att">
          <div class="att-head">
            <h2>Attachments ({{ l.active_count }} of {{ l.max }})</h2>
            @if (l.can_add) {
              <input #picker type="file" multiple [accept]="accept" hidden (change)="add(picker)" />
              <span class="att-add-wrap" [title]="limitText(l) ?? ''">
                <button type="button" class="att-add" [disabled]="!!limitText(l) || busy()" (click)="picker.click()">Add files</button>
              </span>
            }
          </div>
          @if (l.can_add && limitText(l)) { <p class="muted att-small">{{ limitText(l) }}</p> }
          @if (l.note) { <div class="att-note">{{ l.note }}</div> }
          @if (uploading().length) {
            <ul class="att-uploads">
              @for (u of uploading(); track $index) {
                <li><span class="att-upname">{{ u.name }}</span> <progress max="100" [value]="u.pct"></progress>
                  @if (u.error) { <span class="neg">{{ u.error }}</span> }</li>
              }
            </ul>
          }
          @for (e of uploadErrors(); track $index) { <div class="alert">{{ e }}</div> }
          @if (attMsg()) { <div class="alert">{{ attMsg() }}</div> }

          @if (shown().length) {
            <div class="att-list" role="table" aria-label="Attachments">
              <div class="att-row att-th" role="row">
                <span role="columnheader">Name</span><span role="columnheader">Type</span><span role="columnheader">Size</span>
                <span role="columnheader">Uploaded by</span><span role="columnheader">Uploaded at</span><span role="columnheader"></span>
              </div>
              @for (a of shown(); track a.id) {
                <div class="att-row" role="row" [class.att-removed]="!!a.removed_at">
                  <div class="att-name" role="cell">
                    <span class="att-file" [title]="a.original_name">{{ a.original_name }}</span>
                    @if (a.added_after) { <span class="tag att-late">{{ afterLabel(a) }}</span> }
                    @if (a.also_on.length) { <div class="att-also">This file is also attached to {{ a.also_on.join(', ') }}.</div> }
                    @if (a.removed_at) {
                      <div class="att-why">Removed by {{ a.removed_by?.name }} on {{ a.removed_at | appDate }}: {{ a.removal_reason }}</div>
                    }
                  </div>
                  <div class="att-meta">
                    <span role="cell">{{ a.extension.toUpperCase() }}</span>
                    <span role="cell">{{ size(a.size_bytes) }}</span>
                    <span role="cell">{{ a.uploaded_by?.name }} @if (a.uploaded_by?.tag) { <span class="tag" data-s="inactive">{{ a.uploaded_by?.tag }}</span> }</span>
                    <span role="cell">{{ when(a.created_at) }}</span>
                  </div>
                  <div class="att-actions" role="cell">
                    @if (!a.removed_at || l.can_download_removed) {
                      <button type="button" class="ghost" [disabled]="busy()" (click)="download(a)">Download</button>
                      <button type="button" class="ghost" [disabled]="busy()" (click)="open(a)">Open</button>
                    }
                    @if (a.can_remove) {
                      <button type="button" class="ghost att-danger" [disabled]="busy()" (click)="startRemove(a)">Remove</button>
                    }
                  </div>
                </div>
              }
            </div>
          } @else {
            <p class="muted">No files attached.</p>
          }
          @if (l.removed_count) {
            <label class="att-toggle"><input type="checkbox" [ngModel]="showRemoved()" (ngModelChange)="toggleRemoved($event)" />
              Show removed ({{ l.removed_count }})</label>
          }
        </div>
      }

      @if (removing(); as r) {
        <div class="att-backdrop" (click)="removing.set(null)"></div>
        <div class="card att-dialog" role="dialog" aria-modal="true" aria-labelledby="rm-title">
          <h2 id="rm-title">Remove file</h2>
          <p>Remove {{ r.original_name }} from {{ t.ref_no }}? The file is kept for the audit trail and can still be seen by auditors.</p>
          <label>Reason (3-500 characters)
            <textarea rows="3" name="reason" [(ngModel)]="reason" maxlength="500"></textarea>
          </label>
          @if (removeMsg()) { <div class="alert">{{ removeMsg() }}</div> }
          <div class="att-dialog-actions">
            <button type="button" class="ghost" (click)="removing.set(null)">Cancel</button>
            <button type="button" [disabled]="busy() || reason.trim().length < 3" (click)="confirmRemove(r)">Remove</button>
          </div>
        </div>
      }
    }
  `,
})
export class TransactionDetailComponent implements OnInit {
  auth = inject(AuthService);
  settings = inject(SettingsService);
  private http = inject(HttpClient);
  private route = inject(ActivatedRoute);
  t = signal<Detail | null>(null);
  list = signal<AttachmentList | null>(null);
  showRemoved = signal(false);
  shown = computed(() => (this.list()?.data ?? []).filter(a => this.showRemoved() || !a.removed_at));
  msg = signal('');
  attMsg = signal('');
  uploadErrors = signal<string[]>([]);
  uploading = signal<UploadLine[]>([]);
  busy = signal(false);
  removing = signal<AttachmentRow | null>(null);
  removeMsg = signal('');
  reason = '';
  accept = ATTACH_ACCEPT;
  private id = 0;

  /** AG-052 S3: the open receipt dialog and its success line. */
  receiptMode = signal<'received' | 'discrepancy' | null>(null);
  receiptOk = signal('');

  receiptText(s: string | null | undefined) { return receiptLabel(s); }

  /** AG-051 S5: the open approve / reject dialog and the panel's success line. */
  approvalMode = signal<'approve' | 'reject' | null>(null);
  approvalOk = signal('');

  approvalText(s: string | null | undefined) { return approvalLabel(s); }

  /** AG-066 S3 */
  changes(t: Detail): string { return changesText(t); }

  duplicates(t: Detail): string {
    return t.duplicate_entries?.length ? t.duplicate_entries.map(useText).join(', ') : (t.duplicate_of ?? []).join(', ');
  }

  approvalDone(r: { ref_no: string }, mode: 'approve' | 'reject') {
    this.approvalMode.set(null);
    this.approvalOk.set(mode === 'approve' ? `${r.ref_no} approved and posted.` : `${r.ref_no} rejected.`);
    this.reload();
  }

  /** AG-051 / AG-064: the dialog target by kind (expense: account -> category path). */
  approvalTarget(t: Detail): ApprovalTarget {
    const base = { id: t.id, ref_no: t.ref_no, amount: t.amount, requested_date: t.requested_date ?? null, requester_inactive: !!t.requester_inactive };
    if (t.type === 'expense') {
      const c = t.category;
      return { ...base, kind: 'expense', from: `${t.branch?.code ?? ''} ${t.account?.name ?? ''}`.trim(),
        to: c ? (c.parent ? c.parent.name + ' > ' : '') + c.name : '', files_count: t.attachments_count ?? 0,
        approver_onboarded_requester: !!t.approver_onboarded_requester, requested_period_locked: !!t.requested_period_locked,
        template_lines: approvalTemplateLines(t) };
    }
    return { ...base, from: (t.from_account?.branch?.code ?? '') + ' · ' + (t.from_account?.name ?? ''), to: (t.to_account?.branch?.code ?? '') + ' · ' + (t.to_account?.name ?? '') };
  }

  cancelRequest(t: Detail) {
    const reason = prompt(`Cancel request ${t.ref_no}? Reason (optional):`);
    if (reason === null) return;
    this.http.post(`/api/v1/${t.type === 'expense' ? 'expense' : 'transfer'}-approvals/${t.id}/cancel`, reason.trim() ? { comment: reason.trim() } : {}).subscribe({
      next: () => { this.approvalOk.set('Request cancelled.'); this.reload(); },
      error: e => { this.msg.set(errorText(e)); this.reload(); },
    });
  }

  receiptDone(r: ReceiptResult, mode: 'received' | 'discrepancy') {
    this.receiptMode.set(null);
    this.receiptOk.set(mode === 'received' ? `Receipt of ${r.ref_no} confirmed.` : `Discrepancy on ${r.ref_no} reported.`);
    this.reload();
  }

  reload(after?: (t: Detail) => void) {
    this.http.get<Detail>(`/api/v1/transactions/${this.id}`).subscribe({ next: t => { this.t.set(t); after?.(t); }, error: e => this.msg.set(errorText(e)) });
  }

  /** A 422 from the dialog: reload; the open dialog picks up the new state, or closes when nothing is left to do. */
  receiptRefused(message: string) {
    const mode = this.receiptMode();
    this.reload(t => {
      if (!t.can_acknowledge || (mode === 'discrepancy' && t.receipt_status !== 'awaiting')) {
        this.receiptMode.set(null);
        this.msg.set(message);
      }
    });
  }

  ngOnInit() {
    this.id = Number(this.route.snapshot.paramMap.get('id'));
    this.http.get<Detail>(`/api/v1/transactions/${this.id}`).subscribe({
      // Files, edit and void follow the entry's own branch (R10): a reader from another branch only sees the lines.
      next: t => { this.t.set(t); if (this.isEntry(t) && t.in_active_branch !== false) this.loadFiles(); },
      error: e => this.msg.set(e?.status === 404 ? 'This entry does not exist in the selected branch.' : errorText(e)),
    });
  }

  isEntry(t: Detail) { return t.type === 'income' || t.type === 'expense'; }
  label(type: string) { return TYPE_LABELS[type] ?? type; }

  /** Double-entry S3 note column: lines are numbered 1.. in id order. */
  lineNote(t: Detail, e: LedgerLine): string {
    if (t.type === 'year_end_close') {
      // Settings spec S7: the closing lines, and their contras after a reopen.
      const fy = this.settings.fiscalYearOf(t.txn_date).label;
      return e.contra_of_id ? `Cancelled by reopening ${fy}` : `Year-end close ${fy}`;
    }
    if (e.contra_of_id) {
      const n = (t.entries ?? []).findIndex(x => x.id === e.contra_of_id) + 1;
      return n ? `Void contra of line ${n}` : 'Void contra';
    }
    if (e.origin === 'reclass') return 'Reclassification';
    if (e.origin === 'backfill') return `Added by double-entry migration ${t.backfilled_at ?? ''}`.trim();
    return '';
  }
  size(n: number) { return fileSize(n); }
  when(iso: string | null) { return this.settings.dateTime(iso); }
  afterLabel(a: AttachmentRow) { return { close: 'Added after close', void: 'Added after void', reversal: 'Added after reversal' }[a.added_after!]; }

  /** "Add files" is disabled at the limit (5 active, or 10 uploads in total). */
  limitText(l: AttachmentList): string | null {
    if (l.active_count >= l.max) return `${l.max} files attached. Remove one first.`;
    if (l.total_uploaded >= l.max_total) return `${l.max_total} files were already uploaded to this entry (including removed ones).`;
    return null;
  }

  loadFiles() {
    // Removed rows are always fetched so the toggle can show them without another request.
    const params = new HttpParams().set('include_removed', 1);
    this.http.get<AttachmentList>(`/api/v1/transactions/${this.id}/attachments`, { params }).subscribe({
      next: l => {
        this.list.set(l);
        if (!l.removed_count) this.showRemoved.set(false);
      },
      error: e => this.attMsg.set(errorText(e)),
    });
  }

  toggleRemoved(v: boolean) { this.showRemoved.set(v); }

  async add(input: HTMLInputElement) {
    const files = Array.from(input.files ?? []);
    input.value = '';
    const l = this.list();
    if (!files.length || !l || this.busy()) return;
    this.attMsg.set('');
    this.uploadErrors.set([]);
    const problem = selectionProblem(files, l.active_count);
    if (problem) { this.attMsg.set(problem === 'At most 5 files.' ? `At most 5 files: ${l.max - l.active_count} more can be added.` : problem); return; }

    this.busy.set(true);
    this.uploading.set(files.map(f => ({ name: f.name, pct: 0 })));
    const errors: string[] = [];
    for (const [i, f] of files.entries()) {
      try {
        await uploadAttachment(this.http, this.id, f, pct => this.patch(i, { pct }));
        this.patch(i, { pct: 100 });
      } catch (e) {
        const m = uploadErrorText(e);
        errors.push(`${f.name}: ${m}`);
        this.patch(i, { error: m });
      }
    }
    this.uploadErrors.set(errors);
    this.uploading.set([]);
    this.busy.set(false);
    this.loadFiles();
    this.refreshHeader();
  }

  async download(a: AttachmentRow) {
    const file = await this.fetchFile(a);
    if (file) this.saveAs(file);
  }

  /**
   * Opens the file in a new tab (the phone's viewer). The tab is opened first so pop-up blockers allow it, and it
   * gets no opener. A PDF on a browser without a PDF viewer is downloaded under its own name instead (QA Q3).
   */
  async open(a: AttachmentRow) {
    const viewable = a.mime !== 'application/pdf' || navigator.pdfViewerEnabled !== false;
    const win = viewable ? window.open('', '_blank') : null;
    if (win) win.opener = null;
    const file = await this.fetchFile(a);
    if (!file) { win?.close(); return; }
    if (!viewable) { this.saveAs(file); return; }
    const url = URL.createObjectURL(file);
    if (win) win.location.href = url;
    else { const link = document.createElement('a'); link.href = url; link.target = '_blank'; link.rel = 'noopener noreferrer'; link.click(); }
    setTimeout(() => URL.revokeObjectURL(url), 60_000);
  }

  startRemove(a: AttachmentRow) {
    this.reason = '';
    this.removeMsg.set('');
    this.removing.set(a);
  }

  confirmRemove(a: AttachmentRow) {
    if (this.busy()) return; // double-click guard (QA Q1)
    this.busy.set(true);
    this.removeMsg.set('');
    this.http.post<AttachmentRow>(`/api/v1/transactions/${this.id}/attachments/${a.id}/remove`, { reason: this.reason.trim() }).subscribe({
      next: () => { this.busy.set(false); this.removing.set(null); this.loadFiles(); },
      error: e => { this.busy.set(false); this.removeMsg.set(errorText(e)); },
    });
  }

  /**
   * The download as a File named like the attachment. Its type is re-set from the stored mime, and only JPEG, PNG
   * and PDF are ever given to the browser as viewable types (reviewer N2).
   */
  private async fetchFile(a: AttachmentRow): Promise<File | null> {
    if (this.busy()) return null;
    this.attMsg.set('');
    this.busy.set(true);
    try {
      const blob = await firstValueFrom(this.http.get(`/api/v1/transactions/${this.id}/attachments/${a.id}/download`, { responseType: 'blob' }));
      const type = VIEWABLE.includes(a.mime) ? a.mime : 'application/octet-stream';
      return new File([blob], a.original_name, { type });
    } catch (e) {
      this.attMsg.set(await blobErrorText(e));
      return null;
    } finally {
      this.busy.set(false);
    }
  }

  private saveAs(file: File) {
    const url = URL.createObjectURL(file);
    const link = document.createElement('a');
    link.href = url;
    link.download = file.name;
    link.click();
    setTimeout(() => URL.revokeObjectURL(url), 1000);
  }

  private patch(i: number, p: Partial<UploadLine>) {
    this.uploading.update(lines => lines.map((l, j) => (j === i ? { ...l, ...p } : l)));
  }

  private refreshHeader() {
    this.http.get<Detail>(`/api/v1/transactions/${this.id}`).subscribe({ next: t => this.t.set(t), error: () => undefined });
  }
}
