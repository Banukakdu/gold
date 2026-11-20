import { Component, DestroyRef, ElementRef, HostListener, OnInit, computed, inject, signal } from '@angular/core';
import { NavigationEnd, Router, RouterLink } from '@angular/router';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { filter } from 'rxjs';
import { colomboDateTime } from '../core/dates';
import { NotificationRow, NotificationsService, badgeText, relativeTime } from '../core/notifications.service';

/** Kind icons (text glyphs, no icon library). */
export const KIND_ICON: Record<string, string> = {
  'transfer.approval_requested': '?',
  'transfer.approval_decided': '✓',
  'transfer.receipt_requested': '⇄',
  'transfer.receipt_disputed': '!',
  'transfer.receipt_settled': '✓',
  'export.ready': '↓',
  'export.failed': '!',
  'backup.failed': '!',
};

export function kindIcon(kind: string): string { return KIND_ICON[kind] ?? '•'; }

/**
 * AG-094 S1: the bell in the shell header. Badge with the unread count (polled, R9); the dropdown loads the latest 10
 * only when opened, closes on outside click, Esc and navigation, and opens a row with the shared S3 flow.
 * At phone width the dropdown is a full-width panel under the header (styles.scss .nb-panel).
 */
@Component({
  selector: 'ag-notification-bell',
  standalone: true,
  imports: [RouterLink],
  template: `
    <button type="button" class="nb-bell" [attr.aria-label]="label()" [attr.aria-expanded]="openState()" aria-haspopup="true"
      (click)="toggle()">
      <svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true" focusable="false">
        <path fill="currentColor" d="M12 22a2.5 2.5 0 0 0 2.45-2h-4.9A2.5 2.5 0 0 0 12 22Zm7-6V11a7 7 0 0 0-5.5-6.84V3.5a1.5 1.5 0 0 0-3 0v.66A7 7 0 0 0 5 11v5l-2 2v1h18v-1l-2-2Z"/>
      </svg>
      @if (badge()) { <span class="nb-badge" aria-hidden="true">{{ badge() }}</span> }
    </button>
    @if (openState()) {
      <div class="nb-panel card" role="dialog" aria-label="Notifications">
        <div class="nb-head">
          <b>Notifications</b>
          <button type="button" class="link" [disabled]="svc.unread() === 0 || marking()" (click)="markAll()">Mark all as read</button>
        </div>
        <div class="nb-body">
          @if (error()) {
            <p class="nb-empty">Could not load notifications. Try again. <button type="button" class="link" (click)="load()">Retry</button></p>
          } @else if (rows() === null) {
            <p class="nb-empty muted">Loading...</p>
          } @else {
            @for (n of rows(); track n.id) {
              <a class="nb-row" [class.unread]="!n.read_at" [href]="n.url" (click)="open($event, n)">
                <span class="nb-icon" aria-hidden="true">{{ icon(n.kind) }}</span>
                <span class="nb-text">
                  <span class="nb-title">@if (!n.read_at) { <i class="nb-dot" aria-label="Unread"></i> }{{ n.title }}</span>
                  @if (n.body) { <span class="nb-line muted">{{ n.body }}</span> }
                  <span class="nb-meta muted">
                    @if (n.branch) { <span class="tag">{{ n.branch.code }}</span> }
                    <span [title]="full(n.created_at)">{{ ago(n.created_at) }}</span>
                  </span>
                </span>
              </a>
            } @empty {
              <p class="nb-empty muted">No notifications yet.</p>
            }
          }
        </div>
        <div class="nb-foot"><a routerLink="/notifications" (click)="close()">See all</a></div>
      </div>
    }
  `,
})
export class NotificationBellComponent implements OnInit {
  svc = inject(NotificationsService);
  private host = inject(ElementRef<HTMLElement>);
  private router = inject(Router);
  private destroyRef = inject(DestroyRef);

  openState = signal(false);
  rows = signal<NotificationRow[] | null>(null);
  error = signal(false);
  marking = signal(false);
  private fetchedAt: string | null = null;

  badge = computed(() => badgeText(this.svc.unread()));
  label = computed(() => `Notifications, ${this.svc.unread()} unread`);

  ngOnInit() {
    this.svc.start();
    this.destroyRef.onDestroy(() => this.svc.stop());
    this.router.events.pipe(filter(e => e instanceof NavigationEnd), takeUntilDestroyed(this.destroyRef)).subscribe(() => this.close());
  }

  icon(kind: string) { return kindIcon(kind); }
  ago(iso: string) { return relativeTime(iso); }
  full(iso: string) { return `${colomboDateTime(iso)} (Asia/Colombo)`; }

  toggle() {
    if (this.openState()) { this.close(); return; }
    this.openState.set(true);
    this.load();
  }

  close() { this.openState.set(false); }

  load() {
    this.error.set(false);
    this.svc.list({ filter: 'all', per_page: 10 }).subscribe({
      next: p => { this.rows.set(p.data); this.fetchedAt = p.fetched_at; this.svc.unread.set(p.unread_count); },
      // The badge keeps its last value.
      error: () => this.error.set(true),
    });
  }

  async markAll() {
    this.marking.set(true);
    try {
      await this.svc.markAll(this.fetchedAt);
      const before = this.fetchedAt ? new Date(this.fetchedAt).getTime() : Infinity;
      const now = new Date().toISOString();
      this.rows.update(rs => (rs ?? []).map(r => !r.read_at && new Date(r.created_at).getTime() <= before ? { ...r, read_at: now } : r));
    } catch {
      this.error.set(true);
    } finally {
      this.marking.set(false);
    }
  }

  async open(ev: Event, n: NotificationRow) {
    ev.preventDefault();
    this.close();
    await this.svc.open(n);
  }

  @HostListener('document:click', ['$event'])
  onDocClick(ev: MouseEvent) {
    if (this.openState() && !this.host.nativeElement.contains(ev.target as Node)) this.close();
  }

  @HostListener('document:keydown.escape')
  onEsc() { this.close(); }
}
