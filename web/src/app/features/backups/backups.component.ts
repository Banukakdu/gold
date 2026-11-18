import { Component, OnInit, computed, inject, signal } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { FormsModule } from '@angular/forms';
import { firstValueFrom } from 'rxjs';
import { errorText } from '../../core/api.interceptor';
import { colomboParts } from '../../core/settings.service';

/** GET /backups row (docs/specs/backups.md section 5). No paths, disk names or URLs. */
interface Backup {
  id: number; filename: string; status: 'running' | 'succeeded' | 'failed'; triggered_by: 'scheduler' | 'console';
  started_at: string; finished_at: string | null; duration_seconds: number | null; size_bytes: number | null; sha256: string | null;
  db_driver: string; encrypted: boolean; files_count: number | null; kept_as: 'daily' | 'monthly' | 'year_end' | null;
  fiscal_year: number | null; fiscal_year_label: string | null;
  offsite_status: 'copied' | 'failed' | 'not_configured' | null; offsite_error: string | null; local_pruned_at: string | null;
  verify_status: 'passed' | 'failed' | null; verified_at: string | null; verify_error: string | null; failed_stage: string | null; error: string | null;
}
interface Summary {
  last_success_at: string | null;
  last_success: { started_at: string; size_bytes: number | null; offsite_status: string | null } | null;
  latest: { filename: string; status: string; failed_stage: string | null; error: string | null; offsite_status: string | null; offsite_error: string | null } | null;
  offsite_configured: boolean; offsite_required: boolean; encrypted_configured: boolean;
  schedule: { backup: string; verify: string; verify_oldest: string; timezone: string };
  retention: { daily: number; monthly: number; min_keep: number; year_end_years: number };
  last_verify: { at: string | null; status: 'passed' | 'failed' } | null;
}
interface Page { data: Backup[]; meta: { current_page: number; last_page: number; total: number }; summary: Summary }
type Style = 'red' | 'amber' | 'green';

const HOUR = 3600_000;
const OFFSITE: Record<string, string> = { copied: 'Copied', failed: 'Failed', not_configured: 'Not configured' };
const KEPT: Record<string, string> = { daily: 'Daily', monthly: 'First of month' };

/** "YYYY-MM-DD HH:mm" in Asia/Colombo. */
function ts(iso: string | null): string {
  if (!iso) return '';
  const p = colomboParts(iso);
  return `${p.ymd} ${p.hm}`.trim();
}

export function size(bytes: number | null): string {
  if (bytes === null || bytes === undefined) return '';
  const units: [string, number][] = [['GB', 1024 ** 3], ['MB', 1024 ** 2], ['KB', 1024]];
  for (const [u, n] of units) if (bytes >= n) return `${(bytes / n).toFixed(1)} ${u}`;
  return `${bytes} B`;
}

function age(ms: number): string {
  const h = Math.floor(ms / HOUR);
  if (h >= 48) return `${Math.floor(h / 24)} days`;
  return h === 1 ? '1 hour' : `${h} hours`;
}

/**
 * S1 banner: the first matching rule wins (section 4 + accounting review F3, F4, F2). Exported for reuse in tests.
 */
export function banner(s: Summary, now = Date.now()): { style: Style; text: string } {
  const latest = s.latest;
  if (latest?.failed_stage === 'continuity') {
    return { style: 'red', text: `The database has fewer ledger or audit rows than an earlier backup, and no restore was recorded. Investigate before anything else. (${latest.error ?? ''})` };
  }
  if (latest?.failed_stage === 'control') {
    return { style: 'red', text: `The books did not balance in last night's backup: ${latest.error ?? ''} Run php artisan ledger:verify.` };
  }
  if (!s.last_success_at) {
    return { style: 'red', text: 'No backup has completed yet. Check that cron runs `php artisan schedule:run` every minute.' };
  }
  const lastMs = new Date(s.last_success_at).getTime();
  if (now - lastMs > 26 * HOUR) {
    return { style: 'red', text: `The last successful backup was ${age(now - lastMs)} ago (${ts(s.last_success_at)}). Check the scheduler and the failure emails.` };
  }
  if (!s.offsite_configured) {
    return { style: s.offsite_required ? 'red' : 'amber', text: 'Backups are kept only on this server. Configure off-server storage (BACKUP_OFFSITE_DISK) before go-live.' };
  }
  if (latest?.offsite_status === 'failed') {
    return { style: 'amber', text: `Last night's backup was not copied off-server: ${latest.offsite_error ?? ''}` };
  }
  if (!s.encrypted_configured) {
    return { style: 'amber', text: 'Backups are not encrypted (BACKUP_ARCHIVE_PASSWORD is empty). Production refuses to run without it.' };
  }
  const v = s.last_verify;
  if (v?.status === 'failed') return { style: 'amber', text: `The last restore test failed on ${ts(v.at)}.` };
  if (!v?.at || now - new Date(v.at).getTime() > 8 * 24 * HOUR) return { style: 'amber', text: 'No restore test in the last 8 days.' };
  const ls = s.last_success;
  return { style: 'green', text: `Last successful backup: ${ts(s.last_success_at)} (${size(ls?.size_bytes ?? null)}), ${ls?.offsite_status === 'copied' ? 'copied off-server' : 'local only'}.` };
}

/** AG-093 S1 Backups. Super Admin only, read-only: no download, delete or run controls anywhere on the page. */
@Component({
  standalone: true,
  imports: [FormsModule],
  template: `
    <h1>Backups</h1>
    @if (msg()) { <div class="alert">{{ msg() }}</div> }
    @if (page(); as p) {
      @if (bannerOf(); as b) { <div class="banner bk-banner" [class.red]="b.style === 'red'" [class.amber]="b.style === 'amber'" [class.ok]="b.style === 'green'">{{ b.text }}</div> }
      <p class="muted bk-summary">
        Runs daily at {{ p.summary.schedule.backup }} · Restore test Sundays {{ p.summary.schedule.verify.replace('Sun ', '') }} ·
        Keeps {{ p.summary.retention.daily }} daily and {{ p.summary.retention.monthly }} first-of-month backups on this server,
        and every year-end snapshot for {{ p.summary.retention.year_end_years }} years off-server
      </p>
      <div class="filters">
        <label>Status
          <select name="status" [(ngModel)]="status" (ngModelChange)="load(1)">
            <option value="">All</option><option value="succeeded">Succeeded</option><option value="failed">Failed</option>
          </select>
        </label>
      </div>
      <div class="card bk-card">
        @if (!p.data.length) {
          <p class="muted">No backups yet. The first backup runs tonight at {{ p.summary.schedule.backup }}. An operator can run <code>php artisan backup:run</code> now.</p>
        } @else {
          <table class="bk-table">
            <thead><tr>
              <th>Started</th><th>Status</th><th class="num">Duration</th><th class="num">Size</th><th>Contents</th><th>SHA-256</th>
              <th>Off-server</th><th>Kept as</th><th>On this server</th><th>Restore test</th><th>By</th>
            </tr></thead>
            <tbody>
              @for (b of p.data; track b.id) {
                <tr [class.bk-failed]="b.error">
                  <td class="mono">{{ ts(b.started_at) }}</td>
                  <td><span class="tag" [attr.data-s]="'bk-' + b.status">{{ statusName(b.status) }}</span></td>
                  <td class="num">{{ duration(b) }}</td>
                  <td class="num">{{ size(b.size_bytes) }}</td>
                  <td>{{ contents(b) }}</td>
                  <td>@if (b.sha256) { <span class="mono" [title]="b.sha256">{{ b.sha256.slice(0, 12) }}</span> <button type="button" class="link" (click)="copy(b.sha256)">Copy</button> }</td>
                  <td><span [title]="b.offsite_error ?? ''">{{ offsite(b) }}</span></td>
                  <td>{{ keptAs(b) }}</td>
                  <td>{{ b.local_pruned_at ? 'Pruned ' + ts(b.local_pruned_at).slice(0, 10) : (b.status === 'succeeded' || b.size_bytes ? 'Yes' : '—') }}</td>
                  <td><span [title]="b.verify_error ?? ''">{{ verify(b) }}</span></td>
                  <td>{{ b.triggered_by === 'scheduler' ? 'Scheduler' : 'Console' }}</td>
                </tr>
                @if (b.error) { <tr class="bk-error"><td colspan="11"><span class="neg bk-err">{{ stageText(b) }}{{ b.error }}</span></td></tr> }
              }
            </tbody>
          </table>
          <ul class="bk-cards">
            @for (b of p.data; track b.id) {
              <li>
                <div class="bk-l1"><span class="mono">{{ ts(b.started_at) }}</span><span class="tag" [attr.data-s]="'bk-' + b.status">{{ statusName(b.status) }}</span></div>
                <div class="bk-l2">{{ size(b.size_bytes) || '—' }} · {{ contents(b) || '—' }} · {{ duration(b) || '—' }}</div>
                <div class="bk-l3">{{ offsite(b) }} · {{ keptAs(b) }} · Restore test: {{ verify(b) }}</div>
                @if (b.sha256) { <div class="bk-l4"><span class="mono" [title]="b.sha256">{{ b.sha256.slice(0, 12) }}</span> <button type="button" class="link" (click)="copy(b.sha256)">Copy</button></div> }
                @if (b.error) { <div class="neg bk-err">{{ stageText(b) }}{{ b.error }}</div> }
              </li>
            }
          </ul>
          @if (p.meta.last_page > 1) {
            <div class="pager">
              <button class="ghost" [disabled]="p.meta.current_page <= 1" (click)="load(p.meta.current_page - 1)">Previous</button>
              <span class="muted">Page {{ p.meta.current_page }} of {{ p.meta.last_page }} · {{ p.meta.total }} backups</span>
              <button class="ghost" [disabled]="p.meta.current_page >= p.meta.last_page" (click)="load(p.meta.current_page + 1)">Next</button>
            </div>
          }
        }
      </div>
      @if (copied()) { <div class="ok bk-copied">SHA-256 copied.</div> }
    } @else if (!msg()) { <p class="muted">Loading...</p> }
  `,
})
export class BackupsComponent implements OnInit {
  private http = inject(HttpClient);
  page = signal<Page | null>(null);
  msg = signal('');
  copied = signal(false);
  status = '';
  bannerOf = computed(() => { const p = this.page(); return p ? banner(p.summary) : null; });
  ts = ts;
  size = size;

  ngOnInit() { this.load(1); }

  async load(n: number) {
    this.msg.set('');
    const params: Record<string, string> = { page: String(n) };
    if (this.status) params['status'] = this.status;
    try { this.page.set(await firstValueFrom(this.http.get<Page>('/api/v1/backups', { params }))); } catch (e) { this.msg.set(errorText(e)); }
  }

  async copy(sha: string) {
    try {
      await navigator.clipboard.writeText(sha);
      this.copied.set(true);
      setTimeout(() => this.copied.set(false), 2000);
    } catch { this.msg.set('Copy is not available in this browser; select the value in the tooltip instead.'); }
  }

  statusName(s: Backup['status']) { return { running: 'Running', succeeded: 'Succeeded', failed: 'Failed' }[s] ?? s; }
  duration(b: Backup) {
    if (b.status === 'running' || b.duration_seconds === null) return '';
    return `${Math.floor(b.duration_seconds / 60)}:${String(b.duration_seconds % 60).padStart(2, '0')}`;
  }
  contents(b: Backup) { return b.files_count === null ? '' : `DB + ${b.files_count.toLocaleString('en-US')} files`; }
  offsite(b: Backup) { return b.offsite_status ? OFFSITE[b.offsite_status] : '—'; }
  keptAs(b: Backup) {
    if (b.kept_as === 'year_end') return `Year-end ${b.fiscal_year_label ?? ''}`.trim();
    return b.kept_as ? KEPT[b.kept_as] : '—';
  }
  verify(b: Backup) {
    if (!b.verify_status) return '—';
    return `${b.verify_status === 'passed' ? 'Passed' : 'Failed'} ${ts(b.verified_at).slice(0, 10)}`;
  }
  stageText(b: Backup) { return b.failed_stage ? `${b.failed_stage}: ` : ''; }
}
