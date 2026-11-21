import { Component, DestroyRef, OnInit, inject, signal } from '@angular/core';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { colomboDateTime } from '../../core/dates';
import { NotificationPage, NotificationPrefs, NotificationRow, NotificationsService, relativeTime } from '../../core/notifications.service';
import { ToastService } from '../../core/toast.service';
import { kindIcon } from '../../layout/notification-bell.component';

/**
 * AG-094 S2 Notifications (docs/specs/notifications.md): the user's own notifications of the last 90 days, Unread / All,
 * 20 per page, "Mark all as read", and the approval-request email preference for approvers. Each row opens with the
 * shared S3 flow. Rows are a list (not a table) that stacks into cards at phone width.
 */
@Component({
  standalone: true,
  template: `
    <div class="nt-head">
      <h1>Notifications</h1>
      <div class="nt-actions">
        <div class="nt-toggle" role="group" aria-label="Show">
          <button type="button" [class.ghost]="filter() !== 'unread'" [attr.aria-pressed]="filter() === 'unread'" (click)="setFilter('unread')">Unread</button>
          <button type="button" [class.ghost]="filter() !== 'all'" [attr.aria-pressed]="filter() === 'all'" (click)="setFilter('all')">All</button>
        </div>
        <button type="button" class="ghost" [disabled]="!page() || page()!.unread_count === 0 || marking()" (click)="markAll()">Mark all as read</button>
      </div>
    </div>
    @if (msg()) { <div class="alert">{{ msg() }}</div> }

    <div class="card nt-card">
      <div class="nt-row nt-th" aria-hidden="true"><span>When</span><span>Branch</span><span>Message</span><span>State</span></div>
      <ul class="nt-list">
        @for (n of page()?.data; track n.id) {
          <li>
            <a class="nt-row" [class.unread]="!n.read_at" [href]="n.url" (click)="open($event, n)">
              <span class="nt-when" [title]="full(n.created_at)">{{ ago(n.created_at) }}<small class="muted nt-full">{{ full(n.created_at) }}</small></span>
              <span class="nt-branch">@if (n.branch) { <span class="tag">{{ n.branch.code }}</span> } @else { - }</span>
              <span class="nt-msg">
                <span class="nt-title"><span class="nb-icon" aria-hidden="true">{{ icon(n.kind) }}</span>{{ n.title }}
                  @if (!n.read_at) { <i class="nb-dot" aria-label="Unread"></i> }</span>
                @if (n.body) { <span class="nt-body muted">{{ n.body }}</span> }
              </span>
              <span class="nt-state">@if (n.read_at) { <span class="muted">Read {{ ago(n.read_at) }}</span> } @else { <b>Unread</b> }</span>
            </a>
          </li>
        } @empty {
          @if (page()) { <li class="muted nt-empty">{{ filter() === 'unread' ? 'You have no unread notifications.' : 'No notifications in the last 90 days.' }}</li> }
        }
      </ul>
      @if (page(); as p) {
        @if (p.meta.last_page > 1) {
          <div class="pager nt-pager">
            <button class="ghost" [disabled]="p.meta.current_page <= 1" (click)="load(p.meta.current_page - 1)">Previous</button>
            <span>Page {{ p.meta.current_page }} of {{ p.meta.last_page }}</span>
            <button class="ghost" [disabled]="p.meta.current_page >= p.meta.last_page" (click)="load(p.meta.current_page + 1)">Next</button>
          </div>
        }
      }
    </div>

    @if (prefs()?.can_approve) {
      <h2>Email</h2>
      <div class="card">
        <label class="nt-check">
          <input type="checkbox" [checked]="prefs()!.approval_request_email" [disabled]="saving()" (change)="savePref($any($event.target))" />
          Email me when a transfer or expense needs my approval
        </label>
        <p class="muted small">Emails about your own requests and exports are sent as before.</p>
        @if (prefError()) { <div class="alert">{{ prefError() }}</div> }
      </div>
    }
    <p class="muted nt-note">Notifications are kept for 90 days.</p>
  `,
})
export class NotificationsComponent implements OnInit {
  private svc = inject(NotificationsService);
  private toast = inject(ToastService);
  private destroyRef = inject(DestroyRef);

  page = signal<NotificationPage | null>(null);
  filter = signal<'unread' | 'all'>('unread');
  msg = signal('');
  marking = signal(false);
  prefs = signal<NotificationPrefs | null>(null);
  saving = signal(false);
  prefError = signal('');
  private first = true;

  ngOnInit() {
    this.load(1);
    this.svc.preferences().pipe(takeUntilDestroyed(this.destroyRef)).subscribe({ next: p => this.prefs.set(p), error: () => this.prefs.set(null) });
  }

  icon(kind: string) { return kindIcon(kind); }
  ago(iso: string | null) { return relativeTime(iso); }
  full(iso: string | null) { return colomboDateTime(iso); }

  setFilter(f: 'unread' | 'all') {
    if (this.filter() === f) return;
    this.filter.set(f);
    this.load(1);
  }

  /** Default Unread when there is any, else All (S2). */
  load(n: number) {
    this.svc.list({ filter: this.filter(), page: n, per_page: 20 }).pipe(takeUntilDestroyed(this.destroyRef)).subscribe({
      next: p => {
        this.svc.unread.set(p.unread_count);
        if (this.first && this.filter() === 'unread' && p.unread_count === 0) {
          this.first = false;
          this.filter.set('all');
          this.load(1);
          return;
        }
        this.first = false;
        this.page.set(p);
        this.msg.set('');
      },
      error: () => this.msg.set('Could not load notifications. Try again.'),
    });
  }

  async markAll() {
    const p = this.page();
    if (!p || this.marking()) return; // one request at a time, only for a loaded list (R8)
    this.marking.set(true);
    try {
      await this.svc.markAll(p);
      this.load(p.meta.current_page);
    } catch {
      this.msg.set('Could not mark the notifications as read. Try again.');
    } finally {
      this.marking.set(false);
    }
  }

  async open(ev: Event, n: NotificationRow) {
    ev.preventDefault();
    const left = await this.svc.open(n);
    if (!left) this.page.update(p => p ? { ...p, data: [...p.data] } : p); // shows the row as read
  }

  /** Saves on change; on error the checkbox reverts (S2). */
  savePref(box: HTMLInputElement) {
    const want = box.checked;
    this.saving.set(true);
    this.prefError.set('');
    this.svc.savePreferences(want).subscribe({
      next: p => { this.prefs.set(p); this.saving.set(false); this.toast.show('Preference saved.'); },
      error: () => {
        box.checked = !want;
        this.saving.set(false);
        this.prefError.set('Could not save. Try again.');
      },
    });
  }
}
