import { Component, OnInit, computed, inject, signal } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { FormsModule } from '@angular/forms';
import { firstValueFrom } from 'rxjs';
import { errorText } from '../../core/api.interceptor';
import { colomboDateTime } from '../../core/dates';

interface Person { id: number; name: string; tag: string | null }
interface Lock {
  id: number; branch_id: number | null; period: string;
  locked_by: Person | null; locked_at: string; unlocked_at: string | null; unlocked_by: Person | null; unlock_reason: string | null;
  /** R7: which months an unlock reopens (active locks only). */
  reopens: string | null;
}
interface Grid {
  year: number; current_period: string;
  branches: { id: number; code: string; name: string; status: string }[];
  closed_through: { global: string | null; branches: Record<string, string> };
  locks: Lock[];
}
interface Cell {
  text: string; sub?: string;
  action?: { kind: 'lock' | 'unlock'; label: string; lock?: Lock };
  future: boolean; history: Lock[];
}

const MONTHS = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
const label = (period: string) => `${MONTHS[+period.slice(5, 7) - 1]} ${period.slice(0, 4)}`;
const short = (period: string) => `${MONTHS[+period.slice(5, 7) - 1].slice(0, 3)} ${period.slice(0, 4)}`;

/** AG-091 period locks (docs/specs/controls.md S2 + accounting review finding 1, 7). Super Admin only. */
@Component({
  standalone: true,
  imports: [FormsModule],
  template: `
    <h1>Period locks</h1>
    <form class="filters" (ngSubmit)="load()">
      <select name="year" [(ngModel)]="year" (ngModelChange)="load()">
        @for (y of years; track y) { <option [ngValue]="y">{{ y }}</option> }
      </select>
      <span class="muted">A lock closes the month and every earlier month for its branch (books closed through).</span>
    </form>
    @if (msg()) { <div class="alert">{{ msg() }}</div> }
    @if (ok()) { <div class="ok">{{ ok() }}</div> }

    @if (grid(); as g) {
      <div class="card">
        <table class="list">
          <thead><tr><th>Month</th><th>All branches</th>@for (b of g.branches; track b.id) { <th>{{ b.code }}@if (b.status !== 'active') { <span class="muted"> (inactive)</span> }</th> }</tr></thead>
          <tbody>
            @for (p of periods(); track p) {
              <tr>
                <td>{{ monthName(p) }}</td>
                @for (col of columns(); track col.id) {
                  @let c = cell(p, col.id);
                  <td>
                    <div>{{ c.text }}</div>
                    @if (c.sub) { <small class="muted">{{ c.sub }}</small> }
                    <div>
                      @if (c.action; as a) {
                        <button class="link" [disabled]="c.future || busy()" [title]="c.future ? 'Future months cannot be locked.' : ''"
                          (click)="a.kind === 'lock' ? lock(p, col.id) : startUnlock(a.lock!)">{{ a.label }}</button>
                      }
                      @if (c.history.length) { <button class="link" (click)="toggleHistory(p, col.id)">History</button> }
                    </div>
                  </td>
                }
              </tr>
            }
          </tbody>
        </table>
      </div>
    }

    @if (unlocking(); as l) {
      <div class="card section" style="margin-top:12px">
        <h2 style="margin-top:0">Unlock {{ monthName(l.period) }} for {{ scopeName(l.branch_id) }}</h2>
        <label>Reason (10-500 characters)
          <textarea rows="3" [(ngModel)]="reason" name="reason" maxlength="500"></textarea>
        </label>
        <p><b>{{ l.reopens }}</b></p>
        <p class="muted">Reports for the reopened months will show PROVISIONAL again. The lock is kept as history.</p>
        <button [disabled]="busy() || reason.trim().length < 10" (click)="unlock(l)">Unlock</button>
        <button class="ghost" (click)="unlocking.set(null)">Cancel</button>
      </div>
    }

    @if (history(); as h) {
      <div class="card section" style="margin-top:12px">
        <h2 style="margin-top:0">History: {{ monthName(h.period) }}, {{ scopeName(h.branch) }} <button class="link" (click)="history.set(null)">Close</button></h2>
        <table class="list">
          <thead><tr><th>Locked by</th><th>Locked at</th><th>Unlocked by</th><th>Unlocked at</th><th>Reason</th></tr></thead>
          <tbody>
            @for (l of historyRows(); track l.id) {
              <tr>
                <td>{{ l.locked_by?.name }}</td><td class="mono">{{ when(l.locked_at) }}</td>
                <td>{{ l.unlocked_by?.name ?? '—' }}</td><td class="mono">{{ l.unlocked_at ? when(l.unlocked_at) : 'active' }}</td>
                <td>{{ l.unlock_reason ?? '' }}</td>
              </tr>
            }
          </tbody>
        </table>
      </div>
    }
  `,
})
export class PeriodLocksComponent implements OnInit {
  private http = inject(HttpClient);
  year = new Date().getFullYear();
  years = Array.from({ length: 8 }, (_, i) => new Date().getFullYear() - 6 + i);
  grid = signal<Grid | null>(null);
  msg = signal('');
  ok = signal('');
  busy = signal(false);
  unlocking = signal<Lock | null>(null);
  history = signal<{ period: string; branch: number | null } | null>(null);
  reason = '';

  periods = computed(() => { const y = this.grid()?.year ?? this.year; return MONTHS.map((_, i) => `${y}-${String(i + 1).padStart(2, '0')}`); });
  columns = computed(() => [{ id: null as number | null }, ...(this.grid()?.branches ?? []).map(b => ({ id: b.id as number | null }))]);
  historyRows = computed(() => { const h = this.history(); return h ? this.locksAt(h.period, h.branch) : []; });

  ngOnInit() { this.load(); }

  load() {
    this.http.get<Grid>('/api/v1/period-locks', { params: { year: this.year } }).subscribe({
      next: g => { this.grid.set(g); this.msg.set(''); },
      error: e => this.msg.set(errorText(e)),
    });
  }

  monthName(p: string) { return label(p); }
  scopeName(branchId: number | null) { return branchId === null ? 'all branches' : this.grid()?.branches.find(b => b.id === branchId)?.code ?? '?'; }
  /** Q1: times in Asia/Colombo, like the audit log. */
  when(iso: string) { return colomboDateTime(iso); }

  private locksAt(period: string, branch: number | null): Lock[] {
    return (this.grid()?.locks ?? []).filter(l => l.period === period && l.branch_id === branch);
  }

  /** Cell state: Locked / Locked (all branches) / Closed (through ...) / Open (finding 1: cumulative locks). */
  cell(p: string, branch: number | null): Cell {
    const g = this.grid()!;
    const future = p > g.current_period;
    const history = this.locksAt(p, branch);
    const active = history.find(l => !l.unlocked_at);
    const by = (l: Lock) => `by ${l.locked_by?.name ?? '?'} ${this.when(l.locked_at).slice(0, 10)}`;
    const global = g.closed_through.global ?? '';

    // Q2: a later lock keeps this month closed, so its unlock would be refused: say which one to unlock first.
    const later = (through: string, scope: string): Cell | null => through > p && active
      ? { text: 'Locked', sub: `${by(active)} · unlock ${short(through)}${scope} first`, future: false, history } : null;

    if (branch === null) {
      const blocked = later(global, ' (all branches)');
      if (blocked) return blocked;
      if (active) return { text: 'Locked', sub: by(active), action: { kind: 'unlock', label: 'Unlock all branches', lock: active }, future: false, history };
      if (global > p) return { text: `Closed (through ${short(global)})`, future, history };
      return { text: 'Open', action: { kind: 'lock', label: 'Lock all branches' }, future, history };
    }
    if (this.locksAt(p, null).some(l => !l.unlocked_at)) return { text: 'Locked (all branches)', future, history };
    const own = g.closed_through.branches[String(branch)] ?? '';
    const blocked = global > own ? later(global, ' (all branches)') : later(own, '');
    if (blocked) return blocked;
    if (active) return { text: 'Locked', sub: by(active), action: { kind: 'unlock', label: 'Unlock', lock: active }, future: false, history };
    const through = [global, g.closed_through.branches[String(branch)] ?? ''].sort().pop() ?? '';
    if (through > p) return { text: `Closed (through ${short(through)})`, future, history };
    return { text: 'Open', action: { kind: 'lock', label: 'Lock' }, future, history };
  }

  async lock(p: string, branch: number | null) {
    this.msg.set('');
    this.ok.set('');
    const scope = branch === null ? 'all branches' : this.scopeName(branch);
    let text = `Lock ${label(p)} for ${scope}? No entry dated in this month can be created, edited, voided or reversed until it is unlocked.`;
    if (p === this.grid()?.current_period) text += ' This blocks today\'s entries.';
    try {
      // Finding 7: information only, it never blocks the lock.
      const params: Record<string, string> = { period: p };
      if (branch !== null) params['branch_id'] = String(branch);
      const r = await firstValueFrom(this.http.get<{ self_voids: number; self_reversals: number }>('/api/v1/period-locks/review', { params }));
      if (r.self_voids || r.self_reversals) {
        const parts = [r.self_voids ? `${r.self_voids} self-void${r.self_voids === 1 ? '' : 's'}` : '', r.self_reversals ? `${r.self_reversals} self-reversal${r.self_reversals === 1 ? '' : 's'}` : ''];
        text += `\n\n${parts.filter(Boolean).join(' and ')} this month - review the audit log first.`;
      }
    } catch { /* the count is optional */ }
    if (!confirm(text)) return;
    this.busy.set(true);
    this.http.post('/api/v1/period-locks', { period: p, branch_id: branch }).subscribe({
      next: () => { this.busy.set(false); this.ok.set(`${label(p)} locked for ${scope}.`); this.load(); },
      error: e => { this.busy.set(false); this.msg.set(errorText(e)); },
    });
  }

  startUnlock(l: Lock) {
    this.reason = '';
    this.msg.set('');
    this.ok.set('');
    this.unlocking.set(l);
  }

  unlock(l: Lock) {
    this.busy.set(true);
    this.http.post(`/api/v1/period-locks/${l.id}/unlock`, { reason: this.reason.trim() }).subscribe({
      next: (r: any) => { this.busy.set(false); this.unlocking.set(null); this.ok.set(`${label(l.period)} unlocked for ${this.scopeName(l.branch_id)}. ${r?.message ?? ''}`); this.load(); },
      error: e => { this.busy.set(false); this.msg.set(errorText(e)); },
    });
  }

  toggleHistory(period: string, branch: number | null) {
    const h = this.history();
    this.history.set(h && h.period === period && h.branch === branch ? null : { period, branch });
  }
}
