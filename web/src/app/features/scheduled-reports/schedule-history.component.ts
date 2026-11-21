import { Component, OnInit, inject, signal } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { ActivatedRoute, RouterLink } from '@angular/router';
import { firstValueFrom } from 'rxjs';
import { errorText } from '../../core/api.interceptor';
import { SettingsService } from '../../core/settings.service';
import {
  DELIVERY_LABELS, RunDelivery, STATUS_LABELS, Schedule, ScheduleRun, fileSize, frequencyLabel, runBadge,
} from './scheduled-reports';

interface RunPage { data: ScheduleRun[]; meta: { current_page: number; last_page: number; per_page: number; total: number } }

/**
 * AG-088 S3 schedule history (docs/specs/scheduled-reports.md): the schedule and its runs, newest first, 25 per page;
 * a run expands to its deliveries. Runs show as cards at phone width. No file is downloadable from here.
 */
@Component({
  standalone: true,
  imports: [RouterLink],
  template: `
    <p class="att-back sr-back"><a routerLink="/scheduled-reports">Back to scheduled reports</a></p>
    @if (error()) { <div class="alert">{{ error() }}</div> }
    @if (s(); as s) {
      <h1>{{ s.name }}</h1>
      <div class="card sr-summary">
        <dl class="entry-detail">
          <dt>Status</dt><dd><span class="tag" [attr.data-s]="s.status === 'active' ? 'sr-green' : 'sr-grey'">{{ statusLabel(s) }}</span>
            @if (s.paused_reason_text) { <span class="muted"> ({{ s.paused_reason_text }})</span> }</dd>
          <dt>Report</dt><dd>{{ s.title }} @if (s.params_label) { <span class="muted">· {{ s.params_label }}</span> }</dd>
          <dt>Branches</dt><dd>{{ s.branches_label }}</dd>
          <dt>Frequency</dt><dd>{{ freq(s) }}</dd>
          <dt>Next email</dt><dd>{{ s.next_run_at ? settings.dateTime(s.next_run_at) : '-' }}</dd>
          <dt>Owner</dt><dd>{{ s.owner?.name }}</dd>
          <dt>Created</dt><dd>{{ s.created_by?.name }} · {{ settings.dateTime(s.created_at) }}</dd>
          @if (s.updated_by) { <dt>Last changed</dt><dd>{{ s.updated_by.name }} · {{ settings.dateTime(s.updated_at) }}</dd> }
          @if (s.archived_at) { <dt>Archived</dt><dd>{{ s.archived_by?.name }} · {{ settings.dateTime(s.archived_at) }} @if (s.archive_reason) { · {{ s.archive_reason }} }</dd> }
          <dt>Recipients</dt>
          <dd class="sr-recips">
            @for (r of s.recipients; track r.id) {
              <div>{{ r.name }} <span class="muted sr-email">{{ r.email }}</span>
                @if (r.status !== 'active') { <span class="tag" data-s="inactive">Inactive</span> }
                @else if (!r.eligible) { <span class="tag" data-s="inactive">Cannot receive: {{ r.reason }}</span> }</div>
            }
          </dd>
        </dl>
      </div>

      <h2>Runs</h2>
      @if (runs(); as page) {
        @if (!page.data.length) { <div class="card"><p class="muted">This schedule has not run yet.</p></div> }
        @else {
          <div class="card sr-table">
            <table class="list">
              <thead><tr><th>Planned</th><th>Period</th><th>Status</th><th class="num">Rows</th><th>Delivery</th><th>Sent / Skipped / Failed</th><th>Data extracted</th><th>Reason</th><th></th></tr></thead>
              <tbody>
                @for (r of page.data; track r.id) {
                  <tr>
                    <td>{{ settings.dateTime(r.planned_at) }}</td>
                    <td>{{ period(r) }}</td>
                    <td><span class="tag" [attr.data-s]="badge(r).s">{{ badge(r).label }}</span></td>
                    <td class="num">{{ r.rows ?? '' }}</td>
                    <td>{{ delivery(r) }}</td>
                    <td>{{ r.sent }} / {{ r.skipped }} / {{ r.failed }}</td>
                    <td>{{ r.data_as_at ? settings.dateTime(r.data_as_at) : '' }}</td>
                    <td class="sr-reason">{{ reason(r) }}</td>
                    <td>@if (r.deliveries.length) { <button type="button" class="link" (click)="toggle(r.id)">{{ open() === r.id ? 'Hide' : 'Recipients' }}</button> }</td>
                  </tr>
                  @if (open() === r.id) {
                    <tr class="sub"><td colspan="9">
                      <table class="list sr-deliveries">
                        <thead><tr><th>Recipient</th><th>Email used</th><th>Status</th><th>Reason</th><th>Time</th></tr></thead>
                        <tbody>
                          @for (d of r.deliveries; track d.user.id) {
                            <tr><td>{{ d.user.name }}</td><td class="sr-email">{{ d.email }}</td><td>{{ dLabel(d) }}</td><td>{{ d.reason_text ?? '' }}</td>
                              <td>{{ settings.dateTime(d.sent_at ?? d.failed_at) }}</td></tr>
                          }
                        </tbody>
                      </table>
                    </td></tr>
                  }
                }
              </tbody>
            </table>
          </div>
          <div class="sr-cards">
            @for (r of page.data; track r.id) {
              <div class="card sr-run-card">
                <div class="ea-line"><b>{{ settings.dateTime(r.planned_at) }}</b><span class="tag" [attr.data-s]="badge(r).s">{{ badge(r).label }}</span></div>
                <div class="ea-line ea-sub"><span>{{ period(r) }}</span>@if (r.rows !== null) { <span>{{ r.rows }} rows</span> }<span>{{ delivery(r) }}</span></div>
                <div class="ea-line ea-sub"><span>Sent {{ r.sent }} · Skipped {{ r.skipped }} · Failed {{ r.failed }}</span></div>
                @if (reason(r)) { <div class="ea-line ea-sub sr-reason"><span>{{ reason(r) }}</span></div> }
                @for (d of r.deliveries; track d.user.id) {
                  <div class="ea-line ea-sub sr-delivery"><span>{{ d.user.name }}</span><span>{{ dLabel(d) }}@if (d.reason_text) { - {{ d.reason_text }} }</span></div>
                }
              </div>
            }
          </div>
          @if (page.meta.last_page > 1) {
            <div class="pager">
              <button type="button" class="ghost" [disabled]="page.meta.current_page <= 1" (click)="loadRuns(page.meta.current_page - 1)">Newer</button>
              <span>Page {{ page.meta.current_page }} of {{ page.meta.last_page }}</span>
              <button type="button" class="ghost" [disabled]="page.meta.current_page >= page.meta.last_page" (click)="loadRuns(page.meta.current_page + 1)">Older</button>
            </div>
          }
        }
      }
    }
  `,
})
export class ScheduleHistoryComponent implements OnInit {
  private http = inject(HttpClient);
  private route = inject(ActivatedRoute);
  settings = inject(SettingsService);
  s = signal<Schedule | null>(null);
  runs = signal<RunPage | null>(null);
  open = signal<string | null>(null);
  error = signal('');
  private id = 0;

  async ngOnInit() {
    this.id = Number(this.route.snapshot.paramMap.get('id'));
    try {
      this.s.set(await firstValueFrom(this.http.get<Schedule>(`/api/v1/report-schedules/${this.id}`)));
      await this.loadRuns(1);
    } catch (e) {
      this.error.set(errorText(e));
    }
  }

  async loadRuns(page: number) {
    try {
      this.runs.set(await firstValueFrom(this.http.get<RunPage>(`/api/v1/report-schedules/${this.id}/runs`, { params: { page } })));
    } catch (e) {
      this.error.set(errorText(e));
    }
  }

  toggle(id: string) { this.open.update(v => (v === id ? null : id)); }
  freq(s: Schedule) { return frequencyLabel(s); }
  statusLabel(s: Schedule) { return STATUS_LABELS[s.status]; }
  badge(r: ScheduleRun) { return runBadge(r); }
  dLabel(d: RunDelivery) { return DELIVERY_LABELS[d.status]; }
  period(r: ScheduleRun) {
    return r.period_from === r.period_to ? this.settings.date(r.period_from) : `${this.settings.date(r.period_from)} - ${this.settings.date(r.period_to)}`;
  }
  delivery(r: ScheduleRun) {
    if (r.status !== 'done' || (r.rows === null && !r.sent && !r.queued)) return '-';
    return r.attached ? `PDF attached (${fileSize(r.size_bytes)})` : 'Link only: too large';
  }
  reason(r: ScheduleRun) {
    if (r.status === 'missed') return `${r.missed_count} scheduled email(s) missed`;
    return r.status === 'failed' ? (r.error ?? '') : '';
  }
}
