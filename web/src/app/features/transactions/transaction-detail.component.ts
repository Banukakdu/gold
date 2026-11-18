import { Component, OnInit, computed, inject, signal } from '@angular/core';
import { HttpClient, HttpParams } from '@angular/common/http';
import { DatePipe } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { ActivatedRoute, RouterLink } from '@angular/router';
import { firstValueFrom } from 'rxjs';
import { AuthService } from '../../core/auth.service';
import { MoneyPipe, TYPE_LABELS } from '../../core/money.pipe';
import { errorText } from '../../core/api.interceptor';
import { colomboDateTime } from '../../core/dates';
import {
  ATTACH_ACCEPT, AttachmentList, AttachmentRow, blobErrorText, fileSize, selectionProblem, uploadAttachment, uploadErrorText,
} from '../../core/attachments';
import { LockBannerComponent } from '../period-locks/lock-banner.component';

interface Detail {
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
}

interface UploadLine { name: string; pct: number; error?: string }

const VIEWABLE = ['image/jpeg', 'image/png', 'application/pdf'];

/**
 * AG-062 S2: minimal read-only entry detail (route /transactions/:id) that hosts the attachments of income and
 * expense entries. Downloads go through HttpClient (bearer token + X-Branch-Id), never a plain link.
 */
@Component({
  standalone: true,
  imports: [FormsModule, MoneyPipe, DatePipe, RouterLink, LockBannerComponent],
  template: `
    <p class="att-back"><a routerLink="/transactions">Back to transactions</a></p>
    @if (msg()) { <div class="alert">{{ msg() }}</div> }
    @if (t(); as t) {
      <h1><span class="att-ref">{{ t.ref_no }}</span> <span class="tag" [attr.data-s]="t.status">{{ t.status }}</span></h1>
      <ag-lock-banner />
      @if (t.legacy_void_note) { <div class="alert" style="margin-bottom:12px">{{ t.legacy_void_note }}</div> }
      <div class="card section">
        <dl class="entry-detail">
          <dt>Type</dt><dd>{{ label(t.type) }}</dd>
          <dt>Date</dt><dd>{{ t.txn_date | date: 'd MMM y' }}</dd>
          <dt>Branch</dt><dd>{{ t.branch?.code }} {{ t.branch?.name }}</dd>
          <dt>Account</dt>
          <dd>{{ t.account?.name ?? ((t.from_account?.name ?? '') + ' → ' + (t.to_account?.name ?? '')) }}</dd>
          @if (t.category) {
            <dt>Category</dt><dd>{{ t.category.parent?.name ? t.category.parent?.name + ' › ' : '' }}{{ t.category.name }}</dd>
          }
          <dt>Amount</dt><dd [class.neg]="t.type === 'expense'">{{ t.amount | money }}</dd>
          @if (t.payee_payer) { <dt>{{ t.type === 'income' ? 'Received from' : 'Paid to' }}</dt><dd>{{ t.payee_payer }}</dd> }
          @if (t.reference) { <dt>Reference</dt><dd>{{ t.reference }}</dd> }
          @if (t.description) { <dt>Description</dt><dd class="att-pre">{{ t.description }}</dd> }
          <dt>Created by</dt>
          <dd>{{ t.creator?.name }} @if (t.creator_tag) { <span class="tag" data-s="inactive">{{ t.creator_tag }}</span> }</dd>
        </dl>
      </div>

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
                      <div class="att-why">Removed by {{ a.removed_by?.name }} on {{ when(a.removed_at).slice(0, 10) }}: {{ a.removal_reason }}</div>
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

  ngOnInit() {
    this.id = Number(this.route.snapshot.paramMap.get('id'));
    this.http.get<Detail>(`/api/v1/transactions/${this.id}`).subscribe({
      next: t => { this.t.set(t); if (this.isEntry(t)) this.loadFiles(); },
      error: e => this.msg.set(e?.status === 404 ? 'This entry does not exist in the selected branch.' : errorText(e)),
    });
  }

  isEntry(t: Detail) { return t.type === 'income' || t.type === 'expense'; }
  label(type: string) { return TYPE_LABELS[type] ?? type; }
  size(n: number) { return fileSize(n); }
  when(iso: string | null) { return colomboDateTime(iso); }
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
