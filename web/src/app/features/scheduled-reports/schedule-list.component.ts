import { Component, OnInit, computed, inject, signal } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { FormsModule } from '@angular/forms';
import { Router, RouterLink } from '@angular/router';
import { firstValueFrom } from 'rxjs';
import { errorText } from '../../core/api.interceptor';
import { SettingsService } from '../../core/settings.service';
import { ToastService } from '../../core/toast.service';
import { STATUS_LABELS, Schedule, frequencyLabel, runBadge } from './scheduled-reports';

type Tab = 'current' | 'archived' | 'all';

/**
 * AG-088 S1 Scheduled reports (docs/specs/scheduled-reports.md): Super Admins only, in every switcher mode. A table on
 * wide screens, stacked cards with a "..." menu at phone width (390px). A schedule only reads: nothing here posts.
 */
@Component({
  standalone: true,
  imports: [FormsModule, RouterLink],
  template: `
    <h1>Scheduled reports</h1>
    <div class="filters sr-toolbar">
      <button type="button" class="sr-new" (click)="go('new')">New schedule</button>
      <span class="sr-tabs" role="tablist">
        @for (t of tabs; track t[0]) {
          <button type="button" role="tab" [class.ghost]="tab() !== t[0]" [attr.aria-selected]="tab() === t[0]" (click)="setTab(t[0])">{{ t[1] }}</button>
        }
      </span>
      <input type="search" name="q" placeholder="Search name" aria-label="Search schedules" [ngModel]="q()" (ngModelChange)="q.set($event)" />
    </div>
    @if (error()) { <div class="alert">{{ error() }}</div> }
    @if (loaded()) {
      @if (!all().length) {
        <div class="card sr-empty">
          <p>No scheduled reports yet.</p>
          <p class="muted">Get a daily or weekly report by email as a PDF, without signing in.</p>
        </div>
      } @else {
        <div class="card sr-table">
          <table class="list">
            <thead><tr><th>Name</th><th>Report</th><th>Branches</th><th>Frequency</th><th>Recipients</th><th>Next email</th><th>Last run</th><th>Status</th><th></th></tr></thead>
            <tbody>
              @for (s of rows(); track s.id) {
                <tr>
                  <td class="sr-name">{{ s.name }}<div class="muted sr-sub">Owner: {{ s.owner?.name }}</div></td>
                  <td>{{ s.title }} @if (s.params_label) { <div class="muted sr-sub">{{ s.params_label }}</div> }</td>
                  <td>{{ s.branches_label }}</td>
                  <td>{{ freq(s) }}</td>
                  <td>{{ s.recipients.length }}
                    @if (s.cannot_receive) { <span class="tag" data-s="inactive" [title]="cannotTitle(s)">{{ s.cannot_receive }} cannot receive</span> }</td>
                  <td>{{ s.next_run_at ? settings.dateTime(s.next_run_at) : '-' }}</td>
                  <td>
                    @if (s.last_run; as r) { {{ settings.dateTime(r.planned_at) }} <span class="tag" [attr.data-s]="badge(s).s">{{ badge(s).label }}</span> }
                    @else { Never }
                  </td>
                  <td><span class="tag" [attr.data-s]="statusTag(s)" [title]="s.paused_reason_text ?? ''">{{ statusLabel(s) }}</span></td>
                  <td class="sr-actions">
                    @if (s.status !== 'archived') { <a class="link" [routerLink]="['/scheduled-reports', s.id, 'edit']">Edit</a> }
                    @if (s.status === 'active') { <button type="button" class="link" (click)="act(s, 'pause')">Pause</button> }
                    @if (s.status === 'paused') { <button type="button" class="link" (click)="act(s, 'resume')">Resume</button> }
                    @if (s.status !== 'archived') { <button type="button" class="link" (click)="archiveTarget.set(s)">Archive</button> }
                    @else { <button type="button" class="link" (click)="act(s, 'restore')">Restore</button> }
                    <a class="link" [routerLink]="['/scheduled-reports', s.id]">History</a>
                  </td>
                </tr>
              } @empty { <tr><td colspan="9" class="muted">No schedules match.</td></tr> }
            </tbody>
          </table>
        </div>
        <div class="card sr-cards">
          @for (s of rows(); track s.id) {
            <div class="ea-card sr-card">
              <div class="ea-line"><b class="sr-name">{{ s.name }}</b>
                <span class="tag" [attr.data-s]="statusTag(s)" [title]="s.paused_reason_text ?? ''">{{ statusLabel(s) }}</span></div>
              <div class="ea-line ea-sub"><span>{{ s.title }}</span><span>{{ s.branches_label }}</span></div>
              <div class="ea-line ea-sub"><span>{{ freq(s) }}</span><span>Next: {{ s.next_run_at ? settings.dateTime(s.next_run_at) : '-' }}</span></div>
              <div class="ea-line ea-sub">
                @if (s.last_run; as r) { <span class="tag" [attr.data-s]="badge(s).s">{{ badge(s).label }}</span> } @else { <span>Never run</span> }
                <span>{{ s.recipients.length }} recipient(s)</span>
                @if (s.cannot_receive) { <span class="tag" data-s="inactive" [title]="cannotTitle(s)">{{ s.cannot_receive }} cannot receive</span> }
              </div>
              <details class="sr-more">
                <summary aria-label="Actions">...</summary>
                <div class="sr-menu">
                  @if (s.status !== 'archived') { <button type="button" class="ghost" (click)="go(s.id, 'edit')">Edit</button> }
                  @if (s.status === 'active') { <button type="button" class="ghost" (click)="act(s, 'pause')">Pause</button> }
                  @if (s.status === 'paused') { <button type="button" class="ghost" (click)="act(s, 'resume')">Resume</button> }
                  @if (s.status !== 'archived') { <button type="button" class="ghost" (click)="archiveTarget.set(s)">Archive</button> }
                  @else { <button type="button" class="ghost" (click)="act(s, 'restore')">Restore</button> }
                  <button type="button" class="ghost" (click)="go(s.id)">History</button>
                </div>
              </details>
            </div>
          } @empty { <p class="muted">No schedules match.</p> }
        </div>
      }
    }

    @if (archiveTarget(); as t) {
      <div class="att-backdrop" (click)="archiveTarget.set(null)"></div>
      <div class="att-dialog card" role="dialog" aria-modal="true" aria-labelledby="sr-archive-title">
        <h2 id="sr-archive-title">Archive "{{ t.name }}"?</h2>
        <p class="muted">No more emails are sent. Restore puts it back as Paused.</p>
        <label>Reason (optional) <textarea name="reason" maxlength="500" rows="3" [(ngModel)]="reason"></textarea></label>
        <div class="att-dialog-actions">
          <button type="button" class="ghost" (click)="archiveTarget.set(null)">Cancel</button>
          <button type="button" (click)="archive(t)">Archive</button>
        </div>
      </div>
    }
  `,
})
export class ScheduleListComponent implements OnInit {
  private http = inject(HttpClient);
  private toast = inject(ToastService);
  private router = inject(Router);
  settings = inject(SettingsService);
  tabs: [Tab, string][] = [['current', 'Active & paused'], ['archived', 'Archived'], ['all', 'All']];
  tab = signal<Tab>('current');
  q = signal('');
  all = signal<Schedule[]>([]);
  loaded = signal(false);
  error = signal('');
  archiveTarget = signal<Schedule | null>(null);
  reason = '';
  rows = computed(() => {
    const q = this.q().trim().toLowerCase();
    return this.all().filter(s => !q || s.name.toLowerCase().includes(q));
  });

  ngOnInit() { this.load(); }

  setTab(t: Tab) {
    this.tab.set(t);
    this.load();
  }

  async load() {
    try {
      const res = await firstValueFrom(this.http.get<{ data: Schedule[] }>('/api/v1/report-schedules', { params: { status: this.tab() } }));
      this.all.set(res.data);
      this.error.set('');
    } catch (e) {
      this.error.set(errorText(e));
    } finally {
      this.loaded.set(true);
    }
  }

  async act(s: Schedule, action: 'pause' | 'resume' | 'restore') {
    try {
      await firstValueFrom(this.http.post<Schedule>(`/api/v1/report-schedules/${s.id}/${action}`, {}));
      this.toast.show(action === 'pause' ? 'Schedule paused.' : action === 'resume' ? 'Schedule resumed.' : 'Schedule restored as paused.');
      await this.load();
    } catch (e) {
      this.error.set(errorText(e));
    }
  }

  async archive(s: Schedule) {
    try {
      await firstValueFrom(this.http.post<Schedule>(`/api/v1/report-schedules/${s.id}/archive`, { reason: this.reason.trim() || null }));
      this.archiveTarget.set(null);
      this.reason = '';
      this.toast.show('Schedule archived.');
      await this.load();
    } catch (e) {
      this.error.set(errorText(e));
    }
  }

  go(...path: (string | number)[]) { this.router.navigate(['/scheduled-reports', ...path]); }
  freq(s: Schedule) { return frequencyLabel(s); }
  badge(s: Schedule) { return runBadge(s.last_run!); }
  statusLabel(s: Schedule) { return STATUS_LABELS[s.status]; }
  statusTag(s: Schedule) { return s.status === 'active' ? 'sr-green' : 'sr-grey'; }
  cannotTitle(s: Schedule) { return s.recipients.filter(r => !r.eligible).map(r => `${r.name}: ${r.reason}`).join('\n'); }
}
