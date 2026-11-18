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
  const body = new FormData();
  body.append('file', file, file.name);
  const req: Observable<HttpEvent<AttachmentRow>> = http.post<AttachmentRow>(`/api/v1/transactions/${txnId}/attachments`, body,
    { reportProgress: true, observe: 'events' });
  let result: AttachmentRow | null = null;
  return lastValueFrom(req.pipe(tap(ev => {
    if (ev.type === HttpEventType.UploadProgress && ev.total) progress(Math.round((100 * ev.loaded) / ev.total));
    if (ev.type === HttpEventType.Response) { progress(100); result = ev.body; }
  }))).then(() => result as AttachmentRow);
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
