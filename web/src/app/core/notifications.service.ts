import { Injectable, NgZone, effect, inject, signal } from '@angular/core';
import { HttpClient, HttpErrorResponse } from '@angular/common/http';
import { Router } from '@angular/router';
import { firstValueFrom } from 'rxjs';
import { AuthService } from './auth.service';
import { ToastService } from './toast.service';

/** GET /notifications row (docs/specs/notifications.md section 5). body is null for a redacted row (R5). */
export interface NotificationRow {
  id: string;
  kind: string;
  title: string;
  body: string | null;
  branch: { id: number; code: string } | null;
  url: string | null;
  created_at: string;
  read_at: string | null;
}

export interface NotificationPage {
  data: NotificationRow[];
  meta: { current_page: number; last_page: number; per_page: number; total: number };
  unread_count: number;
  fetched_at: string;
  /** R8 high-water mark of the list (review finding 3): rows written after it stay unread on "Mark all as read". */
  fetched_id: string;
}

/** The list a "Mark all as read" refers to. There is no mark-all without a loaded list (review finding 4). */
export type FetchMark = Pick<NotificationPage, 'fetched_at' | 'fetched_id'>;

export interface NotificationPrefs { approval_request_email: boolean; can_approve: boolean }

/** R9: the unread count is polled every 60 s while the tab is visible. */
export const POLL_MS = 60_000;

/** S1 badge: hidden at 0 (''), "99+" above 99. */
export function badgeText(n: number): string {
  return n <= 0 ? '' : n > 99 ? '99+' : String(n);
}

/** "just now", "5 min ago", "3 h ago", "2 days ago". */
export function relativeTime(iso: string | null | undefined, now = Date.now()): string {
  if (!iso) return '';
  const t = new Date(iso).getTime();
  if (isNaN(t)) return '';
  const min = Math.floor((now - t) / 60_000);
  if (min < 1) return 'just now';
  if (min < 60) return `${min} min ago`;
  const h = Math.floor(min / 60);
  if (h < 24) return `${h} h ago`;
  const d = Math.floor(h / 24);
  return d === 1 ? '1 day ago' : `${d} days ago`;
}

/**
 * AG-094 in-app notifications: the unread count (R9 polling), the list calls, and the open flow S3 shared by the bell,
 * the Notifications page and the email link. A notification grants no access: the target page re-checks on the
 * server, and the client re-checks the branch before switching (R5).
 */
@Injectable({ providedIn: 'root' })
export class NotificationsService {
  private http = inject(HttpClient);
  private auth = inject(AuthService);
  private router = inject(Router);
  private toast = inject(ToastService);
  private zone = inject(NgZone);

  readonly unread = signal(0);
  private timer: ReturnType<typeof setInterval> | null = null;
  private running = false;
  private lastPollAt = 0;
  private onVisible = () => {
    if (document.visibilityState === 'visible') { this.refreshCount(); this.arm(); } else this.disarm();
  };

  constructor() {
    // R9: polling stops on logout and on a 401 (the interceptor clears the token).
    effect(() => { if (!this.auth.token()) this.stop(); });
  }

  /** Called by the bell when the shell appears: count now, then every 60 s while visible. */
  start() {
    if (this.running) return;
    this.running = true;
    document.addEventListener('visibilitychange', this.onVisible);
    this.refreshCount();
    if (document.visibilityState === 'visible') this.arm();
  }

  stop() {
    this.running = false;
    this.disarm();
    document.removeEventListener('visibilitychange', this.onVisible);
  }

  private arm() {
    this.disarm();
    if (!this.running) return;
    // Outside Angular's zone so the interval does not keep the app "unstable"; the signal update re-renders anyway.
    this.zone.runOutsideAngular(() => {
      this.timer = setInterval(() => this.zone.run(() => this.refreshCount()), POLL_MS);
    });
  }

  private disarm() {
    if (this.timer) clearInterval(this.timer);
    this.timer = null;
  }

  /** GET /notifications/unread-count. Also called after the user's own approve / reject / receipt actions (R9). */
  refreshCount() {
    if (!this.auth.token() || document.visibilityState !== 'visible') return;
    this.http.get<{ unread_count: number }>('/api/v1/notifications/unread-count').subscribe({
      next: r => { this.unread.set(r.unread_count); this.lastPollAt = Date.now(); },
      error: (e: HttpErrorResponse) => { if (e.status === 401 || e.status === 403) this.stop(); },
    });
  }

  list(params: { filter?: 'unread' | 'all'; page?: number; per_page?: number }) {
    return this.http.get<NotificationPage>('/api/v1/notifications', { params: params as Record<string, string | number> });
  }

  get(id: string) {
    return this.http.get<NotificationRow>(`/api/v1/notifications/${encodeURIComponent(id)}`);
  }

  /** R8: only rows the loaded list could have shown (created at or before its fetch). */
  async markAll(mark: FetchMark): Promise<number> {
    const r = await firstValueFrom(this.http.post<{ marked: number }>('/api/v1/notifications/read-all',
      { before: mark.fetched_at, before_id: mark.fetched_id }));
    this.unread.update(n => Math.max(0, n - r.marked));
    this.refreshCount();
    return r.marked;
  }

  preferences() {
    return this.http.get<NotificationPrefs>('/api/v1/notifications/preferences');
  }

  savePreferences(approval_request_email: boolean) {
    return this.http.put<NotificationPrefs>('/api/v1/notifications/preferences', { approval_request_email });
  }

  /**
   * S3 open flow. 1. mark read; 2. no branch: go to url; 3. Super Admin or a current member of the branch (fresh /me):
   * switch to it and go to url; 4. otherwise toast and stay. Returns false when it stayed.
   */
  async open(row: NotificationRow, opts: { replaceUrl?: boolean } = {}): Promise<boolean> {
    try {
      const r = await firstValueFrom(this.http.post<{ id: string; read_at: string }>(`/api/v1/notifications/${encodeURIComponent(row.id)}/read`, {}));
      if (!row.read_at) this.unread.update(n => Math.max(0, n - 1));
      row.read_at = r.read_at;
    } catch { /* opening still works; the count is corrected by the next poll */ }

    const url = row.url || '/dashboard';
    if (!row.branch) {
      await this.router.navigateByUrl(url, { replaceUrl: opts.replaceUrl });
      return true;
    }
    if (this.auth.meAt < this.lastPollAt || !this.auth.me()) await this.auth.refreshMe();
    const me = this.auth.me();
    const allowed = !!me && (me.is_super_admin || me.branches.some(b => b.id === row.branch!.id));
    if (!allowed) {
      this.toast.show(`You no longer have access to ${row.branch.code}.`);
      return false;
    }
    if (this.auth.activeBranchId() !== String(row.branch.id)) this.auth.switchBranch(String(row.branch.id));
    await this.router.navigateByUrl(url, { replaceUrl: opts.replaceUrl });
    return true;
  }
}
