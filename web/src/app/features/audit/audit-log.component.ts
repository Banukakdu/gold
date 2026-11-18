import { Component, OnInit, computed, inject, signal } from '@angular/core';
import { HttpClient, HttpErrorResponse } from '@angular/common/http';
import { FormsModule } from '@angular/forms';
import { firstValueFrom } from 'rxjs';
import { AuthService } from '../../core/auth.service';
import { errorText } from '../../core/api.interceptor';
import { ReportParams, toHttpParams } from '../reports/report-common';

interface Actor { id: number; name: string; tag: string | null }
interface AuditRow {
  id: number; created_at: string; created_at_local: string;
  branch: { id: number; code: string } | null;
  user: Actor | null;
  event: string; event_label: string; subject: string;
  auditable_type: string | null; auditable_id: number | null;
  old_values: Record<string, unknown> | null; new_values: Record<string, unknown> | null;
  summary: string; self: boolean; ip: string | null; user_agent: string | null;
}
interface AuditPage { data: AuditRow[]; meta: { current_page: number; last_page: number; per_page: number; total: number } }
interface DiffLine { field: string; old: string; new: string; changed: boolean }

/** Today in Asia/Colombo as YYYY-MM-DD (audit days are Colombo days, spec R12). */
function colomboToday(): string {
  const p = Object.fromEntries(new Intl.DateTimeFormat('en-GB', { timeZone: 'Asia/Colombo', year: 'numeric', month: '2-digit', day: '2-digit' })
    .formatToParts(new Date()).map(x => [x.type, x.value]));
  return `${p['year']}-${p['month']}-${p['day']}`;
}

function show(v: unknown): string {
  if (v === null || v === undefined) return '—';
  if (typeof v === 'boolean') return v ? 'yes' : 'no';
  if (typeof v === 'object') return JSON.stringify(v);
  return String(v);
}

/** AG-090 audit log (docs/specs/controls.md S1). Read-only: filters, detail panel and Excel. */
@Component({
  standalone: true,
  imports: [FormsModule],
  template: `
    <h1>Audit log</h1>
    <form class="filters" (ngSubmit)="load(1)">
      <label>From <input type="date" name="from" [(ngModel)]="f.from" required /></label>
      <label>To <input type="date" name="to" [(ngModel)]="f.to" required /></label>
      <select name="event_group" [(ngModel)]="f.event_group">
        @for (g of groups; track g[0]) { <option [value]="g[0]">{{ g[1] }}</option> }
      </select>
      <select name="user_id" [(ngModel)]="f.user_id">
        <option value="">Any user</option>
        @for (a of actors(); track a.id) { <option [value]="a.id">{{ a.name }}{{ a.tag ? ' (' + a.tag + ')' : '' }}</option> }
      </select>
      <label><input type="checkbox" name="self" [(ngModel)]="f.self" style="width:auto" /> Self only</label>
      <input name="ref_no" placeholder="Ref no (exact)" [(ngModel)]="f.ref_no" />
      @if (auth.isAllBranches()) {
        <span class="picker-inline">
          @for (b of branchOptions(); track b.id) {
            <label><input type="checkbox" style="width:auto" [checked]="branches.includes(b.id)" (change)="toggleBranch(b.id)" /> {{ b.label }}</label>
          }
        </span>
      }
      <button type="submit">Show</button>
      <button type="button" class="ghost" [disabled]="busy()" (click)="excel()">{{ busy() ? 'Preparing...' : 'Excel' }}</button>
    </form>
    @if (msg()) { <div class="alert">{{ msg() }}</div> }

    <div class="card">
      <table class="list">
        <thead><tr><th>Date time</th><th>Branch</th><th>User</th><th>Event</th><th>Subject</th><th>Summary</th><th>Self</th><th>IP</th></tr></thead>
        <tbody>
          @for (r of page()?.data; track r.id) {
            <tr (click)="open(r)" style="cursor:pointer" [class.sub]="selected()?.id === r.id">
              <td class="mono">{{ r.created_at_local }}</td>
              <td>{{ r.branch?.code ?? '—' }}</td>
              <td>{{ r.user?.name ?? '—' }} @if (r.user?.tag) { <span class="tag" data-s="inactive">{{ r.user?.tag }}</span> }</td>
              <td>{{ r.event_label }}</td>
              <td class="mono">{{ r.subject }}</td>
              <td>{{ r.summary }}</td>
              <td>@if (r.self) { <span class="tag">Self</span> }</td>
              <td class="mono">{{ r.ip }}</td>
            </tr>
          } @empty { <tr><td colspan="8" class="muted">No audit entries for these filters.</td></tr> }
        </tbody>
      </table>
      @if (page(); as p) {
        <div class="pager">
          <button class="ghost" [disabled]="p.meta.current_page <= 1" (click)="load(p.meta.current_page - 1)">Previous</button>
          <span>Page {{ p.meta.current_page }} of {{ p.meta.last_page }} · {{ p.meta.total }} entries</span>
          <button class="ghost" [disabled]="p.meta.current_page >= p.meta.last_page" (click)="load(p.meta.current_page + 1)">Next</button>
        </div>
      }
    </div>

    @if (selected(); as r) {
      <div class="card section" style="margin-top:12px">
        <h2 style="margin-top:0">{{ r.event_label }} <small class="muted">{{ r.created_at_local }} (Asia/Colombo)</small>
          <button class="link" (click)="selected.set(null)">Close</button></h2>
        <p class="muted">
          Event key <span class="mono">{{ r.event }}</span>
          · Subject {{ r.auditable_type ?? '—' }} {{ r.auditable_id ? '#' + r.auditable_id : '' }}
          · User agent {{ r.user_agent || '—' }}
        </p>
        @if (created()) {
          <p><span class="tag">Created</span></p>
          <table><thead><tr><th>Field</th><th>New</th></tr></thead>
            <tbody>@for (d of diff(); track d.field) { <tr><td class="mono">{{ d.field }}</td><td>{{ d.new }}</td></tr> }</tbody></table>
        } @else {
          <table><thead><tr><th>Field</th><th>Old</th><th>New</th></tr></thead>
            <tbody>
              @for (d of diff(); track d.field) {
                <tr [class.sub]="d.changed"><td class="mono">{{ d.field }}</td><td>{{ d.old }}</td><td>{{ d.new }}</td></tr>
              } @empty { <tr><td colspan="3" class="muted">No values recorded.</td></tr> }
            </tbody>
          </table>
        }
      </div>
    }
  `,
  styles: [`.picker-inline { display: inline-flex; gap: 10px; flex-wrap: wrap; align-items: center; }`],
})
export class AuditLogComponent implements OnInit {
  auth = inject(AuthService);
  private http = inject(HttpClient);
  groups: [string, string][] = [['', 'All events'], ['transactions', 'Transactions'], ['voids', 'Voids and reversals'], ['users', 'Users'],
    ['roles', 'Roles'], ['accounts', 'Accounts'], ['branches', 'Branches'], ['periods', 'Period locks'], ['reports', 'Reports'], ['logins', 'Logins']];
  f = { from: colomboToday().slice(0, 8) + '01', to: colomboToday(), event_group: '', user_id: '', self: false, ref_no: '' };
  branches: string[] = [];
  page = signal<AuditPage | null>(null);
  actors = signal<Actor[]>([]);
  branchOptions = signal<{ id: string; label: string }[]>([]);
  selected = signal<AuditRow | null>(null);
  msg = signal('');
  busy = signal(false);

  created = computed(() => { const r = this.selected(); return !!r && r.old_values === null && r.new_values !== null; });
  diff = computed<DiffLine[]>(() => {
    const r = this.selected();
    if (!r) return [];
    const o = r.old_values ?? {}, n = r.new_values ?? {};
    const lines: DiffLine[] = [];
    for (const k of Array.from(new Set([...Object.keys(o), ...Object.keys(n)]))) {
      const a = o[k], b = n[k];
      const changed = JSON.stringify(a) !== JSON.stringify(b);
      if (Array.isArray(a) && Array.isArray(b)) {
        // Arrays (e.g. permissions) as Added / Removed.
        const added = b.filter(x => !a.includes(x)).map(String), removed = a.filter(x => !b.includes(x)).map(String);
        lines.push({ field: k, old: removed.length ? 'Removed: ' + removed.join(', ') : '—', new: added.length ? 'Added: ' + added.join(', ') : '—', changed });
      } else {
        lines.push({ field: k, old: k in o ? show(a) : '—', new: k in n ? show(b) : '—', changed });
      }
    }
    return lines;
  });

  ngOnInit() {
    this.load(1);
    this.http.get<Actor[]>('/api/v1/audit-logs/actors').subscribe({ next: a => this.actors.set(a), error: () => this.actors.set([]) });
    if (this.auth.isAllBranches()) {
      this.http.get<{ id: number; code: string; name: string }[]>('/api/v1/branches').subscribe(bs =>
        this.branchOptions.set([...bs.map(b => ({ id: String(b.id), label: b.code })), { id: 'none', label: 'No branch (global)' }]));
    }
  }

  private params(): ReportParams {
    return { from: this.f.from, to: this.f.to, event_group: this.f.event_group, user_id: this.f.user_id, self: this.f.self ? 1 : '',
      ref_no: this.f.ref_no.trim(), branches: this.auth.isAllBranches() ? this.branches : [] };
  }

  toggleBranch(id: string) {
    this.branches = this.branches.includes(id) ? this.branches.filter(b => b !== id) : [...this.branches, id];
  }

  load(p: number) {
    this.selected.set(null);
    this.http.get<AuditPage>('/api/v1/audit-logs', { params: toHttpParams({ ...this.params(), page: p }) }).subscribe({
      next: r => { this.page.set(r); this.msg.set(''); },
      error: e => this.msg.set(errorText(e)),
    });
  }

  open(r: AuditRow) { this.selected.set(this.selected()?.id === r.id ? null : r); }

  async excel() {
    this.busy.set(true);
    this.msg.set('');
    const p = this.params();
    const body: Record<string, unknown> = {};
    for (const [k, v] of Object.entries(p)) if (v !== '' && !(Array.isArray(v) && !v.length)) body[k] = v;
    try {
      const blob = await firstValueFrom(this.http.post('/api/v1/audit-logs/export', body, { responseType: 'blob' }));
      const url = URL.createObjectURL(blob);
      const a = document.createElement('a');
      a.href = url;
      a.download = `audit-log_${p['from']}_${p['to']}.xlsx`;
      a.click();
      setTimeout(() => URL.revokeObjectURL(url), 1000);
    } catch (e) {
      const err = e as HttpErrorResponse;
      let msg = errorText(e);
      if (err?.error instanceof Blob) {
        try { const j = JSON.parse(await err.error.text()); msg = j?.errors ? Object.values(j.errors).flat().join(' ') : j?.message ?? msg; } catch { /* keep */ }
      }
      this.msg.set(msg);
    } finally {
      this.busy.set(false);
    }
  }
}
