import { Component, OnInit, computed, inject, input, output, signal } from '@angular/core';
import { HttpClient, HttpParams } from '@angular/common/http';
import { FormsModule } from '@angular/forms';
import { errorText } from '../../core/api.interceptor';
import {
  ATTACH_ACCEPT, StatementFile, StatementFileList, blobErrorText, fetchStoredFile, fileProblem, fileSize, openStoredFile, saveFile,
  selectionProblem, statementFilesUrl, uploadErrorText, uploadTo,
} from '../../core/attachments';
import { colomboDateTime } from '../../core/dates';
import { dayLabel } from '../../core/reconciliation';

/** The statement a Files panel belongs to (a History row of S1). */
export interface FilesStatement { id: number; statement_date: string; status: 'completed' | 'undone' }

interface UploadLine { name: string; pct: number; error?: string }

/**
 * AG-033b S1a "Bank statement copy" panel of one History row (docs/specs/bank-statement-attachment.md): list, add (one
 * request per file, with progress), replace and remove with a reason, download and open. The server is the authority;
 * buttons follow `can_add` / `can_remove`. A full-screen sheet at 390px.
 */
@Component({
  selector: 'ag-statement-files',
  standalone: true,
  imports: [FormsModule],
  template: `
    <section class="card sf-panel" role="region" [attr.aria-label]="title()">
      <div class="sf-head">
        <h2>{{ title() }}</h2>
        <button type="button" class="ghost sf-close" (click)="closed.emit()" aria-label="Close">Close</button>
      </div>
      @if (list(); as l) {
        @if (l.note) { <div class="att-note">{{ l.note }}</div> }
        @if (l.can_add) {
          <div class="sf-add">
            <input #picker type="file" multiple [accept]="accept" hidden (change)="add(picker)" />
            <span class="att-add-wrap" [title]="limitText(l) ?? ''">
              <button type="button" class="att-add" [disabled]="!!limitText(l) || busy()" (click)="picker.click()">Add files</button>
            </span>
            <p class="muted att-small">PDF or photo of the bank statement for this period. JPG, PNG or PDF, up to 5 files, 5 MB each.</p>
            @if (limitText(l)) { <p class="muted att-small">{{ limitText(l) }}</p> }
          </div>
        }
        @if (uploading().length) {
          <ul class="att-uploads">
            @for (u of uploading(); track $index) {
              <li><span class="att-upname">{{ u.name }}</span> <progress max="100" [value]="u.pct"></progress>
                @if (u.error) { <span class="neg">{{ u.error }}</span> }</li>
            }
          </ul>
        }
        @for (e of uploadErrors(); track $index) { <div class="alert">{{ e }}</div> }
        @if (msg()) { <div class="alert">{{ msg() }}</div> }

        @if (shown().length) {
          <div class="sf-list" role="table" aria-label="Bank statement files">
            <div class="sf-row sf-th" role="row">
              <span role="columnheader">Name</span><span role="columnheader">Type</span><span role="columnheader">Size</span>
              <span role="columnheader">Uploaded by</span><span role="columnheader">Uploaded at</span><span role="columnheader">sha256</span>
              <span role="columnheader"></span>
            </div>
            @for (a of shown(); track a.id) {
              <div class="sf-row" role="row" [class.att-removed]="!!a.removed_at">
                <div class="sf-name" role="cell">
                  <span class="att-file" [title]="a.original_name">{{ a.original_name }}</span>
                  @if (a.removed_at) {
                    <div class="att-why">{{ removedText(a) }}</div>
                  }
                </div>
                <div class="sf-meta">
                  <span role="cell">{{ a.extension.toUpperCase() }}</span>
                  <span role="cell">{{ size(a.size_bytes) }}</span>
                  <span role="cell">{{ a.uploaded_by?.name }}@if (a.uploaded_by?.tag) { <span class="tag" data-s="inactive">{{ a.uploaded_by?.tag }}</span> }</span>
                  <span role="cell">{{ when(a.created_at) }}</span>
                </div>
                <div class="sf-sha" role="cell">
                  <button type="button" class="link mono sf-sha-btn" [title]="a.sha256" (click)="copy(a.sha256)" [attr.aria-label]="'Copy sha256 of ' + a.original_name">sha256 {{ a.sha256.slice(0, 12) }}…</button>
                  @if (a.added_after === 'close') { <span class="tag att-late">Added after close</span> }
                  @if (a.by_preparer) { <span class="tag sf-prep">Uploaded by the preparer</span> }
                  @for (o of a.also_on; track o.statement_id) { <span class="tag att-late">Also on {{ label(o.statement_date) }} statement</span> }
                  @for (o of undoneTwins(a); track o.statement_id) {
                    <div class="att-also">This file is also attached to the undone statement of {{ label(o.statement_date) }}.</div>
                  }
                </div>
                <div class="sf-actions" role="cell">
                  @if (!a.removed_at || l.can_download_removed) {
                    <button type="button" class="ghost" [disabled]="busy()" (click)="download(a)">Download</button>
                    <button type="button" class="ghost" [disabled]="busy()" (click)="open(a)">Open</button>
                  }
                  @if (a.can_remove) {
                    <button type="button" class="ghost" [disabled]="busy()" (click)="startReplace(a)">Replace</button>
                    <button type="button" class="ghost att-danger" [disabled]="busy()" (click)="startRemove(a)">Remove</button>
                  }
                </div>
              </div>
            }
          </div>
        } @else if (!l.active_count) {
          <p class="muted sf-empty">No bank statement copy attached. Attach it, or file the paper bank statement with the printed reconciliation.</p>
        }
        @if (l.removed_count) {
          <label class="att-toggle"><input type="checkbox" [ngModel]="showRemoved()" (ngModelChange)="showRemoved.set($event)" />
            Show removed ({{ l.removed_count }})</label>
        }
      }
    </section>

    @if (removing(); as r) {
      <div class="att-backdrop sf-backdrop" (click)="removing.set(null)"></div>
      <div class="card att-dialog sf-dialog" role="dialog" aria-modal="true" aria-labelledby="sf-rm-title">
        <h2 id="sf-rm-title">Remove file</h2>
        <p>Remove {{ r.original_name }} from the statement of {{ label(statement().statement_date) }}? The file is kept for the audit trail and can still be seen by auditors.</p>
        <label>Reason (3-500 characters) <textarea rows="3" name="reason" [(ngModel)]="reason" maxlength="500"></textarea></label>
        @if (dialogMsg()) { <div class="alert">{{ dialogMsg() }}</div> }
        <div class="att-dialog-actions">
          <button type="button" class="ghost" (click)="removing.set(null)">Cancel</button>
          <button type="button" [disabled]="busy() || reason.trim().length < 3" (click)="confirmRemove(r)">Remove</button>
        </div>
      </div>
    }

    @if (replacing(); as r) {
      <div class="att-backdrop sf-backdrop" (click)="replacing.set(null)"></div>
      <div class="card att-dialog sf-dialog" role="dialog" aria-modal="true" aria-labelledby="sf-rp-title">
        <h2 id="sf-rp-title">Replace file</h2>
        <p>Replace {{ r.original_name }}? The old file is kept for the audit trail.</p>
        <label>File <input type="file" name="file" [accept]="accept" (change)="pickReplacement($any($event.target))" /></label>
        @if (replacement(); as f) { <small class="muted att-picked-name">{{ f.name }} · {{ size(f.size) }}</small> }
        @if (replacePct() !== null) { <progress max="100" [value]="replacePct()"></progress> }
        <label>Reason (3-500 characters) <textarea rows="3" name="reason" [(ngModel)]="reason" maxlength="500"></textarea></label>
        @if (dialogMsg()) { <div class="alert">{{ dialogMsg() }}</div> }
        <div class="att-dialog-actions">
          <button type="button" class="ghost" (click)="replacing.set(null)">Cancel</button>
          <button type="button" [disabled]="busy() || !replacement() || reason.trim().length < 3" (click)="confirmReplace(r)">Replace</button>
        </div>
      </div>
    }
  `,
})
export class StatementFilesComponent implements OnInit {
  accountId = input.required<number>();
  accountName = input.required<string>();
  statement = input.required<FilesStatement>();
  /** Files were added, replaced or removed: the History counts should be reloaded. */
  changed = output<void>();
  closed = output<void>();

  private http = inject(HttpClient);
  readonly accept = ATTACH_ACCEPT;
  list = signal<StatementFileList | null>(null);
  busy = signal(false);
  msg = signal('');
  uploading = signal<UploadLine[]>([]);
  uploadErrors = signal<string[]>([]);
  showRemoved = signal(false);
  removing = signal<StatementFile | null>(null);
  replacing = signal<StatementFile | null>(null);
  replacement = signal<File | null>(null);
  replacePct = signal<number | null>(null);
  dialogMsg = signal('');
  reason = '';

  title = computed(() => {
    const l = this.list();
    return `Bank statement copy: ${this.accountName()}, ${dayLabel(this.statement().statement_date)}` + (l ? ` (${l.active_count} of ${l.max})` : '');
  });
  shown = computed(() => {
    const rows = this.list()?.data ?? [];
    return this.showRemoved() ? rows : rows.filter(a => !a.removed_at);
  });

  private url(): string { return statementFilesUrl(this.accountId(), this.statement().id); }

  ngOnInit() { this.load(); }

  load() {
    // Removed rows are always fetched so the toggle can show them without another request.
    this.http.get<StatementFileList>(this.url(), { params: new HttpParams().set('include_removed', 1) }).subscribe({
      next: l => { this.list.set(l); if (!l.removed_count) this.showRemoved.set(false); },
      error: e => this.msg.set(errorText(e)),
    });
  }

  limitText(l: StatementFileList): string | null {
    if (l.active_count >= l.max) return `${l.max} files attached. Remove one first.`;
    if (l.total_uploaded >= l.max_total) return `${l.max_total} files were already uploaded to this statement (including removed ones).`;
    return null;
  }

  removedText(a: StatementFile): string {
    const who = `${a.removed_by?.name ?? ''} on ${colomboDateTime(a.removed_at).slice(0, 10)}: ${a.removal_reason ?? ''}`;
    return a.replaced_by_id ? `Replaced by ${a.replaced_by_name ?? 'a new file'}, by ${who}` : `Removed by ${who}`;
  }

  undoneTwins(a: StatementFile) { return a.also_on.filter(o => o.status === 'undone'); }
  size(n: number) { return fileSize(n); }
  when(iso: string | null) { return colomboDateTime(iso); }
  label(d: string) { return dayLabel(d); }
  copy(v: string) { navigator.clipboard?.writeText(v).catch(() => undefined); }

  async add(input: HTMLInputElement) {
    const files = Array.from(input.files ?? []);
    input.value = '';
    const l = this.list();
    if (!files.length || !l || this.busy()) return;
    this.msg.set('');
    this.uploadErrors.set([]);
    const problem = selectionProblem(files, l.active_count);
    if (problem) { this.msg.set(problem === 'At most 5 files.' ? `At most 5 files: ${l.max - l.active_count} more can be added.` : problem); return; }

    this.busy.set(true);
    this.uploading.set(files.map(f => ({ name: f.name, pct: 0 })));
    const errors: string[] = [];
    for (const [i, f] of files.entries()) {
      try {
        await uploadTo<StatementFile>(this.http, this.url(), f, pct => this.patch(i, { pct }));
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
    this.load();
    this.changed.emit();
  }

  async download(a: StatementFile) {
    const f = await this.fetch(a);
    if (f) saveFile(f);
  }

  open(a: StatementFile) { return openStoredFile(() => this.fetch(a), a.mime); }

  private async fetch(a: StatementFile): Promise<File | null> {
    if (this.busy()) return null;
    this.msg.set('');
    this.busy.set(true);
    try {
      return await fetchStoredFile(this.http, `${this.url()}/${a.id}/download`, a.original_name, a.mime);
    } catch (e) {
      this.msg.set(await blobErrorText(e));
      return null;
    } finally {
      this.busy.set(false);
    }
  }

  startRemove(a: StatementFile) { this.reason = ''; this.dialogMsg.set(''); this.removing.set(a); }

  confirmRemove(a: StatementFile) {
    if (this.busy()) return;
    this.busy.set(true);
    this.dialogMsg.set('');
    this.http.post<StatementFile>(`${this.url()}/${a.id}/remove`, { reason: this.reason.trim() }).subscribe({
      next: () => { this.busy.set(false); this.removing.set(null); this.load(); this.changed.emit(); },
      error: e => { this.busy.set(false); this.dialogMsg.set(errorText(e)); },
    });
  }

  startReplace(a: StatementFile) {
    this.reason = '';
    this.dialogMsg.set('');
    this.replacement.set(null);
    this.replacePct.set(null);
    this.replacing.set(a);
  }

  pickReplacement(input: HTMLInputElement) {
    const f = input.files?.[0] ?? null;
    this.dialogMsg.set(f ? (fileProblem(f) ?? '') : '');
    this.replacement.set(f && !fileProblem(f) ? f : null);
  }

  async confirmReplace(a: StatementFile) {
    const f = this.replacement();
    if (!f || this.busy()) return;
    this.busy.set(true);
    this.dialogMsg.set('');
    this.replacePct.set(0);
    try {
      await uploadTo<StatementFile>(this.http, this.url(), f, pct => this.replacePct.set(pct), { replaces_id: String(a.id), reason: this.reason.trim() });
      this.replacing.set(null);
      this.load();
      this.changed.emit();
    } catch (e) {
      this.dialogMsg.set(uploadErrorText(e));
    } finally {
      this.busy.set(false);
      this.replacePct.set(null);
    }
  }

  private patch(i: number, p: Partial<UploadLine>) {
    this.uploading.update(lines => lines.map((l, j) => (j === i ? { ...l, ...p } : l)));
  }
}
