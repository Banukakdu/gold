import { Component, DestroyRef, ElementRef, HostListener, OnInit, computed, inject, signal } from '@angular/core';
import { NavigationEnd, Router, RouterLink } from '@angular/router';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { filter } from 'rxjs';
import { colomboDateTime } from '../core/dates';
import { FetchMark, NotificationRow, NotificationsService, badgeText, relativeTime } from '../core/notifications.service';

/** Kind icons (text glyphs, no icon library). */
export const KIND_ICON: Record<string, string> = {
  'transfer.approval_requested': '?',
  'transfer.approval_decided': '✓',
  'expense.approval_requested': '?',
  'expense.approval_decided': '✓',
  'expense.approval_flagged': '!',
  'transfer.receipt_requested': '⇄',
  'transfer.receipt_disputed': '!',
  'transfer.receipt_settled': '✓',
  'export.ready': '↓',
  'export.failed': '!',
  'backup.failed': '!',
  // AG-033d bank reconciliation checks.
  'reconciliation.checked': '✓',
  'reconciliation.check_withdrawn': '!',
  'reconciliation.check_cancelled': '!',
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
      <div class="nb-panel card" role="dialog" aria-label="Notifications" [style.--nb-top]="panelTop() + 'px'">
        <div class="nb-head">
          <b>Notifications</b>
          <button type="button" class="link" [disabled]="!canMarkAll()" (click)="markAll()">Mark all as read</button>
        </div>
        @if (markError()) { <p class="nb-empty nb-error" role="alert">Could not mark the notifications as read. Try again.</p> }
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
  markError = signal(false);
  /** Phone layout (QA 3): the panel is fixed from just under the bell to the bottom of the visible viewport. */
  panelTop = signal(64);
  private mark: FetchMark | null = null;

  badge = computed(() => badgeText(this.svc.unread()));
  label = computed(() => `Notifications, ${this.svc.unread()} unread`);
  /** Only for a list that loaded (its fetched_at bounds what is marked, R8), one request at a time. */
  canMarkAll = computed(() => this.rows() !== null && !this.error() && !this.marking() && this.svc.unread() > 0);

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
    this.place(true);
    this.load();
  }

  /**
   * Where the phone panel starts: under the bell. When the bell sits so low that little room is left (the sidebar
   * stacks above the header at phone width), the page is first scrolled to bring the bell near the top, so the panel
   * and its "See all" footer fit the visible viewport with the list scrolling inside.
   */
  private place(scroll = false) {
    const btn = this.host.nativeElement.querySelector('.nb-bell') as HTMLElement | null;
    const win = this.host.nativeElement.ownerDocument?.defaultView;
    if (!btn || !win) return;
    let r = btn.getBoundingClientRect();
    if (scroll && win.innerHeight - r.bottom < 320 && r.top > 8) {
      win.scrollBy(0, r.top - 8);
      r = btn.getBoundingClientRect();
    }
    this.panelTop.set(Math.max(8, Math.round(r.bottom + 6)));
  }

  @HostListener('window:resize')
  @HostListener('window:scroll')
  onViewportChange() { if (this.openState()) this.place(); }

  close() { this.openState.set(false); }

  load() {
    this.error.set(false);
    this.markError.set(false);
    this.rows.set(null);
    this.mark = null;
    this.svc.list({ filter: 'all', per_page: 10 }).subscribe({
      next: p => { this.rows.set(p.data); this.mark = { fetched_at: p.fetched_at, fetched_id: p.fetched_id }; this.svc.unread.set(p.unread_count); },
      // The badge keeps its last value.
      error: () => this.error.set(true),
    });
  }

  async markAll() {
    const mark = this.mark;
    if (!mark || !this.canMarkAll()) return;
    this.marking.set(true);
    this.markError.set(false);
    try {
      await this.svc.markAll(mark);
      const before = new Date(mark.fetched_at).getTime();
      const now = new Date().toISOString();
      this.rows.update(rs => (rs ?? []).map(r => !r.read_at && new Date(r.created_at).getTime() <= before ? { ...r, read_at: now } : r));
    } catch {
      this.markError.set(true);
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
