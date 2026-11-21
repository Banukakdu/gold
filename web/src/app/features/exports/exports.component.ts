import { Component, DestroyRef, OnInit, inject, signal } from '@angular/core';
import { HttpClient, HttpErrorResponse } from '@angular/common/http';
import { ActivatedRoute, Router } from '@angular/router';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { firstValueFrom } from 'rxjs';
import { errorText } from '../../core/api.interceptor';
import { SettingsService } from '../../core/settings.service';
import { size } from '../backups/backups.component';

/** GET /exports row (docs/specs/background-exports.md section 5): no file path, user id or params internals. */
export interface ExportRow {
  id: string; kind: 'report' | 'transactions'; name: string; title: string; format: string;
  branches_label: string; period_label: string; rows: number;
  status: 'queued' | 'running' | 'ready' | 'failed' | 'expired'; error: string | null;
  requested_at: string; data_as_at: string | null; ready_at: string | null; expires_at: string | null;
  size_bytes: number | null; download_count: number; rerun_url: string;
}
interface Page { data: ExportRow[]; meta: { current_page: number; last_page: number; per_page: number; total: number } }

const STATUS: Record<ExportRow['status'], string> = { queued: 'Queued', running: 'Preparing', ready: 'Ready', failed: 'Failed', expired: 'Expired' };
const POLL_MS = 10_000;

/** S2 "Expires": "in 23 h" / "in 40 min" (ready rows only). */
export function expiresIn(iso: string | null, now = Date.now()): string {
  if (!iso) return '';
  const min = Math.floor((new Date(iso).getTime() - now) / 60_000);
  if (min <= 0) return '';
  return min >= 60 ? `in ${Math.floor(min / 60)} h` : `in ${min} min`;
}

/**
 * AG-087 S2 Exports (docs/specs/background-exports.md): the user's own background exports of the last 30 days. Polls
 * every 10 s while a row is Queued or Preparing and the tab is visible. Downloads go through the API with the user's
 * token (no public link); "Run again" opens the original screen with the stored filters (F6).
 */
@Component({
  standalone: true,
  template: `
    <h1>Exports</h1>
    <p class="muted">Exports over 5,000 rows are prepared in the background. Files can be downloaded for 24 hours after they are ready.</p>
    <p class="muted">Save the file if you need to keep it. A file run again later may differ if entries in an unlocked period were voided or changed.</p>
    @if (msg()) { <div class="alert">{{ msg() }}</div> }

    <div class="card">
      <table class="list">
        <thead><tr>
          <th class="ex-wide">Requested</th><th>Export</th><th class="ex-wide">Format</th><th class="ex-wide">Branches</th><th class="ex-wide">Period</th>
          <th class="num ex-wide">Rows</th><th>Status</th><th class="ex-wide">Data extracted</th><th class="ex-wide">Expires</th><th class="num ex-wide">Size</th><th></th>
        </tr></thead>
        <tbody>
          @for (r of page()?.data; track r.id) {
            <tr [class.sub]="r.id === highlight()" [attr.id]="'export-' + r.id">
              <td class="mono ex-wide">{{ settings.dateTime(r.requested_at) }}</td>
              <td>{{ r.title }}
                <!-- QA L2: at phone width the detail columns fold into one line under the title. -->
                <div class="muted small ex-narrow">{{ r.branches_label }} · {{ r.period_label }} · {{ r.rows.toLocaleString('en-US') }} rows
                  @if (r.status === 'ready') { · {{ fileSize(r.size_bytes) }} · expires {{ expires(r) }} }</div>
              </td>
              <td class="ex-wide">Excel</td>
              <td class="ex-wide">{{ r.branches_label }}</td>
              <td class="ex-wide">{{ r.period_label }}</td>
              <td class="num ex-wide">{{ r.rows.toLocaleString('en-US') }}</td>
              <td>
                <span class="tag" [attr.data-s]="'ex-' + r.status">{{ label(r.status) }}</span>
                @if (r.status === 'failed' && r.error) { <div class="muted small">{{ r.error }}</div> }
              </td>
              <td class="mono ex-wide">{{ r.status === 'ready' ? settings.dateTime(r.data_as_at) : '' }}</td>
              <td class="ex-wide">{{ r.status === 'ready' ? expires(r) : '' }}</td>
              <td class="num ex-wide">{{ r.status === 'ready' ? fileSize(r.size_bytes) : '' }}</td>
              <td>
                @if (r.status === 'ready') {
                  <button type="button" class="ghost" [disabled]="downloading() === r.id" (click)="download(r)">{{ downloading() === r.id ? 'Downloading...' : 'Download' }}</button>
                } @else if (r.status === 'failed' || r.status === 'expired') {
                  <button type="button" class="link" (click)="runAgain(r)">Run again</button>
                }
              </td>
            </tr>
          } @empty {
            @if (page()) { <tr><td colspan="11" class="muted">No background exports in the last 30 days.</td></tr> }
          }
        </tbody>
      </table>
      @if (page(); as p) {
        @if (p.meta.last_page > 1) {
          <div class="pager">
            <button class="ghost" [disabled]="p.meta.current_page <= 1" (click)="load(p.meta.current_page - 1)">Previous</button>
            <span>Page {{ p.meta.current_page }} of {{ p.meta.last_page }} · {{ p.meta.total }} exports</span>
            <button class="ghost" [disabled]="p.meta.current_page >= p.meta.last_page" (click)="load(p.meta.current_page + 1)">Next</button>
          </div>
        }
      }
    </div>
  `,
})
export class ExportsComponent implements OnInit {
  settings = inject(SettingsService);
  private http = inject(HttpClient);
  private route = inject(ActivatedRoute);
  private router = inject(Router);
  private destroyRef = inject(DestroyRef);

  page = signal<Page | null>(null);
  msg = signal('');
  highlight = signal<string | null>(null);
  downloading = signal<string | null>(null);
  private timer: ReturnType<typeof setTimeout> | null = null;
  private current = 1;
  private destroyed = false;
  /** The message on screen is a failed load's (cleared by the next good one), not a download's (kept). */
  private loadFailed = false;
  private onVisible = () => { if (document.visibilityState === 'visible') this.schedule(); };

  ngOnInit() {
    this.highlight.set(this.route.snapshot.queryParamMap.get('id'));
    document.addEventListener('visibilitychange', this.onVisible);
    this.destroyRef.onDestroy(() => {
      this.destroyed = true;
      document.removeEventListener('visibilitychange', this.onVisible);
      this.stop();
    });
    this.load(1);
  }

  label(s: ExportRow['status']) { return STATUS[s] ?? s; }
  expires(r: ExportRow) { return expiresIn(r.expires_at); }
  fileSize(b: number | null) { return size(b); }

  /**
   * Load a page. $keepMsg keeps the message on screen (a download error, QA B2): the refresh after it only brings the
   * row's new state (Failed, Expired). A response that arrives after the page is left is dropped (reviewer m4).
   */
  load(n: number, keepMsg = false) {
    this.current = n;
    this.stop();
    this.http.get<Page>('/api/v1/exports', { params: { page: n } }).pipe(takeUntilDestroyed(this.destroyRef)).subscribe({
      next: p => {
        this.page.set(p);
        if (!keepMsg || this.loadFailed) this.msg.set('');
        this.loadFailed = false;
        const id = this.highlight();
        if (id && n === 1 && !keepMsg) setTimeout(() => document.getElementById('export-' + id)?.scrollIntoView({ block: 'center' }));
        this.schedule();
      },
      // A failed poll keeps polling (the next one may succeed); the message stays until a load succeeds.
      error: e => { this.msg.set(errorText(e)); this.loadFailed = true; this.schedule(); },
    });
  }

  /** Poll while a row is Queued or Preparing, only when the tab is visible (S2). */
  private schedule() {
    this.stop();
    const active = (this.page()?.data ?? []).some(r => r.status === 'queued' || r.status === 'running');
    if (this.destroyed || !active || document.visibilityState !== 'visible') return;
    this.timer = setTimeout(() => this.load(this.current, true), POLL_MS);
  }

  private stop() {
    if (this.timer) clearTimeout(this.timer);
    this.timer = null;
  }

  async download(r: ExportRow) {
    this.downloading.set(r.id);
    this.msg.set('');
    try {
      const res = await firstValueFrom(this.http.get(`/api/v1/exports/${r.id}/download`, { responseType: 'blob', observe: 'response' }));
      const disposition = res.headers.get('Content-Disposition') ?? '';
      const name = /filename="?([^";]+)"?/.exec(disposition)?.[1] ?? `${r.name}.xlsx`;
      const url = URL.createObjectURL(res.body as Blob);
      const a = document.createElement('a');
      a.href = url;
      a.download = name;
      a.click();
      setTimeout(() => URL.revokeObjectURL(url), 1000);
      this.load(this.current);
    } catch (e) {
      // Error bodies arrive as a Blob because of responseType: 'blob'.
      const err = e as HttpErrorResponse;
      let msg = errorText(e);
      if (err?.error instanceof Blob) {
        try { msg = JSON.parse(await err.error.text())?.message ?? msg; } catch { /* keep default */ }
      }
      this.msg.set(msg);
      // QA B2: the message stays; the row shows its new state (Failed after a missing file, Expired after a 410).
      this.load(this.current, true);
    } finally {
      this.downloading.set(null);
    }
  }

  runAgain(r: ExportRow) {
    this.router.navigateByUrl(r.rerun_url);
  }
}
