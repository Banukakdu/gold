import { Component, OnInit, computed, inject, signal } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { FormsModule } from '@angular/forms';
import { ActivatedRoute, Router } from '@angular/router';
import { firstValueFrom } from 'rxjs';
import { AuthService } from '../../core/auth.service';
import { errorText } from '../../core/api.interceptor';
import { SettingsService } from '../../core/settings.service';
import { ToastService } from '../../core/toast.service';
import { fieldErrors, toHttpParams } from '../reports/report-common';
import {
  MOVEMENT_TYPES, MULTI_BRANCH_NOTE, PreviewTime, RecipientOption, SCHEDULE_NOTE, SCHEDULE_REPORTS, SEND_TIMES, Schedule, WEEKDAYS,
  nextEmailLine, reportUrl,
} from './scheduled-reports';

interface BranchRow { id: number; code: string; name: string; status: string }

/**
 * AG-088 S2 New / Edit schedule (docs/specs/scheduled-reports.md). Recipients are users of the system, checked by the
 * API for the chosen branches (R5); users who cannot receive are shown disabled with the reason. The live line comes
 * from GET /preview-time (R2). One column with a sticky Save at phone width.
 */
@Component({
  standalone: true,
  imports: [FormsModule],
  template: `
    <h1>{{ id() ? 'Edit schedule' : 'New schedule' }}</h1>
    @if (error()) { <div class="alert">{{ error() }}</div> }
    @if (ready()) {
      <form class="card sr-form" (ngSubmit)="save()">
        <label>Name <input name="name" maxlength="100" required [(ngModel)]="f.name" /></label>
        @if (errs()['name']) { <p class="field-error">{{ errs()['name'] }}</p> }

        <label>Report
          <select name="report" [ngModel]="f.report" (ngModelChange)="f.report = $event; onBranches()">
            @for (r of reports; track r[0]) { <option [value]="r[0]">{{ r[1] }}</option> }
          </select>
        </label>
        @if (errs()['report']) { <p class="field-error">{{ errs()['report'] }}</p> }

        @if (f.report === 'income-expense') {
          <label>Type
            <select name="type" [(ngModel)]="f.type">
              <option value="all">All</option><option value="income">Income</option><option value="expense">Expense</option>
            </select>
          </label>
        }
        @if (f.report === 'transfers') {
          <fieldset class="sr-set"><legend>Movement types <span class="muted">(none = all)</span></legend>
            @for (t of movementTypes; track t[0]) {
              <label class="sr-check"><input type="checkbox" [checked]="f.types.includes(t[0])" (change)="toggleType(t[0])" /> {{ t[1] }}</label>
            }
          </fieldset>
        }

        <fieldset class="sr-set"><legend>Branches</legend>
          @for (b of branches(); track b.id) {
            <label class="sr-check"><input type="checkbox" [checked]="f.branches.includes(b.id)" (change)="toggleBranch(b.id)" />
              {{ b.code }} · {{ b.name }} @if (b.status !== 'active') { <span class="tag" data-s="inactive">Inactive</span> }</label>
          }
          <p class="muted sr-hint">None ticked = All branches, including branches added later.</p>
        </fieldset>
        @if (errs()['branches']) { <p class="field-error">{{ errs()['branches'] }}</p> }

        <fieldset class="sr-set"><legend>Frequency</legend>
          <label class="sr-check"><input type="radio" name="frequency" value="daily" [ngModel]="f.frequency" (ngModelChange)="f.frequency = $event; preview()" /> Daily</label>
          <label class="sr-check"><input type="radio" name="frequency" value="weekly" [ngModel]="f.frequency" (ngModelChange)="f.frequency = $event; preview()" /> Weekly</label>
        </fieldset>
        @if (f.frequency === 'weekly') {
          <label>Day
            <select name="weekday" [ngModel]="f.weekday" (ngModelChange)="f.weekday = +$event; preview()">
              @for (d of weekdays; track $index) { @if ($index > 0) { <option [value]="$index">{{ d }}</option> } }
            </select>
          </label>
          @if (errs()['weekday']) { <p class="field-error">{{ errs()['weekday'] }}</p> }
        }
        <label>Time (Asia/Colombo)
          <select name="send_time" [ngModel]="f.send_time" (ngModelChange)="f.send_time = $event; preview()">
            @for (t of times; track t) { <option [value]="t">{{ t }}</option> }
          </select>
        </label>
        @if (errs()['send_time']) { <p class="field-error">{{ errs()['send_time'] }}</p> }

        <fieldset class="sr-set sr-recipients"><legend>Recipients</legend>
          @if (multi()) { <p class="warn sr-multi">{{ multiNote }}</p> }
          @for (u of options(); track u.id) {
            <label class="sr-check" [class.muted]="!u.eligible">
              <input type="checkbox" [checked]="f.recipients.includes(u.id)" [disabled]="!u.eligible && !f.recipients.includes(u.id)" (change)="toggleRecipient(u.id)" />
              {{ u.name }} <span class="muted sr-email">{{ u.email }}</span>
              @if (u.is_super_admin) { <span class="tag">Super Admin</span> }
              @if (!u.eligible) { <span class="tag" data-s="inactive">{{ u.reason }}</span> }
            </label>
          }
        </fieldset>
        @if (errs()['recipient_ids']) { <p class="field-error">{{ errs()['recipient_ids'] }}</p> }

        @if (line()) { <p class="sr-next"><b>{{ line() }}</b></p> }
        @if (previewLink(); as link) { <p><a [href]="link" target="_blank" rel="noopener">Preview this report</a></p> }
        <p class="muted">{{ note }}</p>

        <div class="sr-save">
          <button type="button" class="ghost" (click)="cancel()">Cancel</button>
          <button type="submit" [disabled]="saving()">{{ saving() ? 'Saving...' : 'Save' }}</button>
        </div>
      </form>
    }
  `,
})
export class ScheduleFormComponent implements OnInit {
  private http = inject(HttpClient);
  private route = inject(ActivatedRoute);
  private router = inject(Router);
  private toast = inject(ToastService);
  private auth = inject(AuthService);
  settings = inject(SettingsService);
  reports = SCHEDULE_REPORTS;
  movementTypes = MOVEMENT_TYPES;
  weekdays = WEEKDAYS;
  times = SEND_TIMES;
  note = SCHEDULE_NOTE;
  multiNote = MULTI_BRANCH_NOTE;

  id = signal<number | null>(null);
  ready = signal(false);
  saving = signal(false);
  error = signal('');
  errs = signal<Record<string, string>>({});
  branches = signal<BranchRow[]>([]);
  options = signal<RecipientOption[]>([]);
  next = signal<PreviewTime | null>(null);
  /** Bumped on every branch change, so the recipient list and the preview link follow the form. */
  private rev = signal(0);
  f = {
    name: '', report: 'income-expense', type: 'all', types: [] as string[], branches: [] as number[],
    frequency: 'daily' as 'daily' | 'weekly', weekday: 1, send_time: '07:00', recipients: [] as number[],
  };
  multi = computed(() => { this.rev(); return this.f.branches.length !== 1; });
  line = computed(() => {
    const n = this.next();
    return n ? nextEmailLine(this.settings, n.next_run_at, n.period_from, n.period_to) : '';
  });
  previewLink = computed(() => {
    this.rev();
    const n = this.next();
    return n ? reportUrl(this.f.report, n.period_from, n.period_to, [...this.f.branches].sort((a, b) => a - b)) : '';
  });

  async ngOnInit() {
    const id = Number(this.route.snapshot.paramMap.get('id')) || null;
    this.id.set(id);
    try {
      const branches = firstValueFrom(this.http.get<BranchRow[]>('/api/v1/branches'));
      if (id) {
        const s = await firstValueFrom(this.http.get<Schedule>(`/api/v1/report-schedules/${id}`));
        if (s.status === 'archived') {
          this.toast.show('Restore this schedule before editing it.');
          this.router.navigate(['/scheduled-reports', id]);
          return;
        }
        this.f = {
          name: s.name, report: s.report, type: s.params.type ?? 'all', types: s.params.types ?? [], branches: s.branches.map(b => b.id),
          frequency: s.frequency, weekday: s.weekday ?? 1, send_time: s.send_time, recipients: s.recipients.map(r => r.id),
        };
      } else {
        // S2: the owner (the signed-in Super Admin) is ticked on New.
        const me = this.auth.me();
        if (me) this.f.recipients = [me.id];
      }
      this.branches.set(await branches);
      this.ready.set(true);
      await Promise.all([this.loadOptions(), this.preview()]);
    } catch (e) {
      this.error.set(errorText(e));
    }
  }

  toggleBranch(id: number) {
    this.f.branches = this.f.branches.includes(id) ? this.f.branches.filter(x => x !== id) : [...this.f.branches, id];
    this.onBranches();
  }

  onBranches() {
    this.rev.update(v => v + 1);
    this.loadOptions();
  }

  toggleType(t: string) { this.f.types = this.f.types.includes(t) ? this.f.types.filter(x => x !== t) : [...this.f.types, t]; }

  toggleRecipient(id: number) {
    this.f.recipients = this.f.recipients.includes(id) ? this.f.recipients.filter(x => x !== id) : [...this.f.recipients, id];
  }

  async loadOptions() {
    try {
      const res = await firstValueFrom(this.http.get<{ data: RecipientOption[] }>('/api/v1/report-schedules/recipient-options',
        { params: toHttpParams({ branches: this.f.branches }) }));
      this.options.set(res.data);
    } catch (e) {
      this.error.set(errorText(e));
    }
  }

  async preview() {
    try {
      const params = toHttpParams({ frequency: this.f.frequency, weekday: this.f.frequency === 'weekly' ? this.f.weekday : null, send_time: this.f.send_time });
      this.next.set(await firstValueFrom(this.http.get<PreviewTime>('/api/v1/report-schedules/preview-time', { params })));
    } catch {
      this.next.set(null);
    }
  }

  body() {
    return {
      name: this.f.name, report: this.f.report,
      params: this.f.report === 'income-expense' ? { type: this.f.type } : this.f.report === 'transfers' ? { types: this.f.types } : {},
      branches: this.f.branches, frequency: this.f.frequency, weekday: this.f.frequency === 'weekly' ? this.f.weekday : null,
      send_time: this.f.send_time, recipient_ids: this.f.recipients,
    };
  }

  async save() {
    this.saving.set(true);
    this.error.set('');
    this.errs.set({});
    try {
      const id = this.id();
      await firstValueFrom(id ? this.http.put(`/api/v1/report-schedules/${id}`, this.body()) : this.http.post('/api/v1/report-schedules', this.body()));
      this.toast.show('Schedule saved.');
      this.router.navigate(['/scheduled-reports']);
    } catch (e) {
      const errs = fieldErrors(e);
      this.errs.set(errs);
      if (!Object.keys(errs).length) this.error.set(errorText(e));
    } finally {
      this.saving.set(false);
    }
  }

  cancel() { this.router.navigate(['/scheduled-reports']); }
}
