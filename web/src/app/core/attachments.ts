import { HttpClient, HttpErrorResponse, HttpEvent, HttpEventType } from '@angular/common/http';
import { Observable, lastValueFrom, tap } from 'rxjs';
import { errorText } from './api.interceptor';

/** AG-062 attachments: shared by the record form (S1) and the entry detail screen (S2). The server is the authority. */
export const ATTACH_ACCEPT = '.jpg,.jpeg,.png,.pdf,image/jpeg,image/png,application/pdf';
export const ATTACH_MAX_FILES = 5;
export const ATTACH_MAX_BYTES = 5 * 1024 * 1024;

export interface AttachmentRow {
  id: number;
  transaction_id: number;
  original_name: string;
  mime: string;
  extension: string;
  size_bytes: number;
  sha256: string;
  uploaded_by: { id: number; name: string; tag: string | null } | null;
  created_at: string;
  /** Accounting review finding 4: evidence added after the month was closed or the entry voided/reversed. */
  added_after: 'close' | 'void' | 'reversal' | null;
  /** Finding 7: other entries holding the same file (same branch; all branches for a Super Admin in All mode). */
  also_on: string[];
  removed_at: string | null;
  removed_by: { id: number; name: string } | null;
  removal_reason: string | null;
  can_remove: boolean;
}

export interface AttachmentList {
  data: AttachmentRow[];
  can_add: boolean;
  max: number;
  max_total: number;
  active_count: number;
  removed_count: number;
  total_uploaded: number;
  can_download_removed: boolean;
  /** F6: "October 2026 is locked: ..." / "This entry is void: ..." */
  note: string | null;
}

/** "200 KB", "1.2 MB". */
export function fileSize(bytes: number): string {
  if (bytes < 1024 * 1024) return `${Math.max(1, Math.round(bytes / 1024))} KB`;
  return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
}

/** Client check of one picked file (spec S1). Null when it may be sent. */
export function fileProblem(f: File): string | null {
  const ext = f.name.includes('.') ? f.name.split('.').pop()!.toLowerCase() : '';
  if (!['jpg', 'jpeg', 'png', 'pdf'].includes(ext)) return `${f.name}: only JPG, PNG or PDF.`;
  if (f.size > ATTACH_MAX_BYTES) return `${f.name} is larger than 5 MB.`;
  return null;
}

/** First client problem of a selection, or null. */
export function selectionProblem(files: File[], alreadyAttached = 0): string | null {
  if (files.length + alreadyAttached > ATTACH_MAX_FILES) return 'At most 5 files.';
  for (const f of files) {
    const p = fileProblem(f);
    if (p) return p;
  }
  return null;
}

/** One file per request, with upload progress (0-100) reported to `progress`. Resolves with the server's Attachment. */
export function uploadAttachment(http: HttpClient, txnId: number, file: File, progress: (pct: number) => void): Promise<AttachmentRow> {
  return uploadTo<AttachmentRow>(http, `/api/v1/transactions/${txnId}/attachments`, file, progress);
}

/** Multipart POST of one file (+ extra fields) with upload progress. */
export function uploadTo<T>(http: HttpClient, url: string, file: File, progress: (pct: number) => void, extra: Record<string, string> = {}): Promise<T> {
  const body = new FormData();
  body.append('file', file, file.name);
  for (const [k, v] of Object.entries(extra)) body.append(k, v);
  const req: Observable<HttpEvent<T>> = http.post<T>(url, body, { reportProgress: true, observe: 'events' });
  let result: T | null = null;
  return lastValueFrom(req.pipe(tap(ev => {
    if (ev.type === HttpEventType.UploadProgress && ev.total) progress(Math.round((100 * ev.loaded) / ev.total));
    if (ev.type === HttpEventType.Response) { progress(100); result = ev.body; }
  }))).then(() => result as T);
}

/** AG-033b: the bank statement copy of a reconciliation statement (docs/specs/bank-statement-attachment.md). */
export interface StatementFile {
  id: number;
  bank_statement_id: number;
  original_name: string;
  mime: string;
  extension: string;
  size_bytes: number;
  sha256: string;
  added_after: 'close' | null;
  /** Accounting review finding 1: uploaded by the statement's preparer (completed_by). */
  by_preparer: boolean;
  /** Finding 3: other statements of the same account holding the same active file. */
  also_on: { statement_id: number; statement_date: string; status: 'completed' | 'undone' }[];
  uploaded_by: { id: number; name: string; tag: string | null } | null;
  created_at: string;
  removed_at: string | null;
  removed_by: { id: number; name: string } | null;
  removal_reason: string | null;
  replaced_by_id: number | null;
  replaced_by_name: string | null;
  can_remove: boolean;
}

export interface StatementFileList {
  data: StatementFile[];
  can_add: boolean;
  can_remove: boolean;
  max: number;
  max_total: number;
  active_count: number;
  removed_count: number;
  total_uploaded: number;
  can_download_removed: boolean;
  /** F6 / F7 notes. */
  note: string | null;
}

export function statementFilesUrl(accountId: number, statementId: number): string {
  return `/api/v1/accounts/${accountId}/reconciliation/statements/${statementId}/attachments`;
}

/** "Bank statement: 2 files (1 removed)" counts; "No copy" for a completed statement without an active file. */
export function statementFilesText(active: number, removed: number): string {
  const n = `${active} ${active === 1 ? 'file' : 'files'}`;
  return removed ? `${n} (${removed} removed)` : n;
}

/** Only JPEG, PNG and PDF are given to the browser as viewable types (AG-062 reviewer N2). */
export const VIEWABLE_TYPES = ['image/jpeg', 'image/png', 'application/pdf'];

/** Fetch a stored file as a File named like the attachment (responseType blob, the server's name). */
export async function fetchStoredFile(http: HttpClient, url: string, name: string, mime: string): Promise<File> {
  const blob = await lastValueFrom(http.get(url, { responseType: 'blob' }));
  return new File([blob], name, { type: VIEWABLE_TYPES.includes(mime) ? mime : 'application/octet-stream' });
}

export function saveFile(file: File) {
  const url = URL.createObjectURL(file);
  const link = document.createElement('a');
  link.href = url;
  link.download = file.name;
  link.click();
  setTimeout(() => URL.revokeObjectURL(url), 1000);
}

/** Open in a new tab (opened first so pop-up blockers allow it); a PDF without a viewer is saved instead (AG-062 QA Q3). */
export async function openStoredFile(load: () => Promise<File | null>, mime: string) {
  const viewable = mime !== 'application/pdf' || navigator.pdfViewerEnabled !== false;
  const win = viewable ? window.open('', '_blank') : null;
  if (win) win.opener = null;
  const file = await load();
  if (!file) { win?.close(); return; }
  if (!viewable) { saveFile(file); return; }
  const url = URL.createObjectURL(file);
  if (win) win.location.href = url;
  else { const link = document.createElement('a'); link.href = url; link.target = '_blank'; link.rel = 'noopener noreferrer'; link.click(); }
  setTimeout(() => URL.revokeObjectURL(url), 60_000);
}

/** Error text of a request made with responseType 'blob' (the body arrives as a Blob), as in report-common.ts. */
export async function blobErrorText(e: unknown): Promise<string> {
  const err = e as HttpErrorResponse;
  if (!err?.status) return 'Could not reach the server. Check your connection and try again.';
  if (err.error instanceof Blob) {
    try { return JSON.parse(await err.error.text())?.message ?? errorText(e); } catch { /* fall through */ }
  }
  if (err.status >= 500) return 'Something went wrong. Please try again.';
  return errorText(e);
}

/** Error text of an upload: 413 comes from the web server, before PHP sees the file. */
export function uploadErrorText(e: unknown): string {
  const status = (e as HttpErrorResponse)?.status ?? 0;
  if (status === 413) return 'Each file may be at most 5 MB.';
  if (status === 0) return 'Could not reach the server. Check your connection and try again.';
  if (status >= 500) return 'The file could not be saved. Please try again.';
  return errorText(e);
}
