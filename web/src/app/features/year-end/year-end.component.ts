import { Component, OnInit, inject, signal } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { FormsModule } from '@angular/forms';
import { firstValueFrom } from 'rxjs';
import { AuthService } from '../../core/auth.service';
import { errorText } from '../../core/api.interceptor';
import { MoneyPipe } from '../../core/money.pipe';
import { SettingsService } from '../../core/settings.service';
import { SignaturesComponent } from '../reports/report-common';

interface Person { id: number; name: string }
interface Close {
  id: number; status: 'closed' | 'reopened'; closed_by: Person | null; closed_at: string | null; total_net: string;
  reopened_by: Person | null; reopened_at: string | null; reopen_reason: string | null; transaction_ids: number[];
}
type Status = 'open' | 'ready' | 'closed' | 'reopened' | 'no_result';
/** GET /fiscal-years (settings spec section 5). */
interface Year {
  start_year: number; label: string; start: string; end: string; status: Status; can_close: boolean; result: string;
  checks: { code: string; message: string }[]; close: Close | null; history: Close[];
}
interface Line { branch_code: string; code: string | null; account: string; debit: string; credit: string }
interface BranchPreview {
  branch: { id: number; code: string; name: string; status: string };
  income: string; expense: string; net: string; ie_net: string; difference: string; lines: Line[];
}
interface Preview {
  fiscal_year: { start_year: number; label: string; short_label: string; start: string; end: string; closed: boolean };
  branches: BranchPreview[]; total_net: string; total_ie_net: string; retained_earnings_movement: string;
  checks: { code: string; message: string }[];
}

const STATUS: Record<Status, string> = { open: 'Open', ready: 'Ready to close', closed: 'Closed', reopened: 'Reopened', no_result: 'No result to close' };
const MONTHS = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];

/** AG-092 S5 Year-end. Super Admin only. Posting is the API's (LedgerService::closeYear / reopenYear). */
@Component({
  standalone: true,
  imports: [FormsModule, MoneyPipe, SignaturesComponent],
  template: `
    <h1 class="no-print">Year-end</h1>
    @if (msg()) { <div class="alert">{{ msg() }}</div> }
    @if (ok()) { <div class="ok">{{ ok() }}</div> }

    <div class="card no-print">
      @if (years(); as ys) {
        <table class="list">
          <thead><tr><th>Fiscal year</th><th>Dates</th><th>Status</th><th class="num">Result</th><th>Closed by / at</th><th></th></tr></thead>
          <tbody>
            @for (y of ys; track y.start_year) {
              <tr>
                <td><b>{{ y.label }}</b></td>
                <td>{{ settings.date(y.start) }} – {{ settings.date(y.end) }}</td>
                <td><span class="tag" [attr.data-s]="y.status">{{ statusName(y.status) }}</span>
                  @if (y.status === 'reopened' && y.history.length) { <div class="muted" style="font-size:12px">{{ last(y)?.reopen_reason }}</div> }
                  @if (y.checks.length && y.status !== 'closed') {
                    <ul class="ye-checks">@for (c of y.checks; track c.code) { <li>{{ c.message }}</li> }</ul>
                  }
                </td>
                <td class="num" [class.neg]="+y.result < 0">{{ y.result | money }}</td>
                <td>@if (y.close; as c) { {{ c.closed_by?.name }}<br /><small class="muted">{{ settings.dateTime(c.closed_at) }}</small> }</td>
                <td style="white-space:nowrap">
                  @if (y.status === 'ready' || y.status === 'closed' || y.status === 'no_result' || y.status === 'reopened') {
                    <button class="link" (click)="openPreview(y)">Preview</button>
                  }
                  @if (y.can_close) { <button class="link" (click)="askClose(y)">Close {{ y.label }}</button> }
                  @if (y.start_year === reopenable()) { <button class="link" (click)="askReopen(y)">Reopen year</button> }
                </td>
              </tr>
              @if (y.history.length) {
                <tr><td colspan="6" style="padding-top:0">
                  <ul class="ye-history">
                    @for (h of y.history; track h.id) {
                      <li>Closed by {{ h.closed_by?.name }} on {{ settings.dateTime(h.closed_at) }}, result {{ h.total_net | money }}
                        @if (h.reopened_at) { · reopened by {{ h.reopened_by?.name }} on {{ settings.dateTime(h.reopened_at) }}: {{ h.reopen_reason }} }</li>
                    }
                  </ul>
                </td></tr>
              }
            } @empty { <tr><td colspan="6" class="muted">No entries yet.</td></tr> }
          </tbody>
        </table>
      } @else if (!msg()) { <p class="muted">Loading...</p> }
    </div>

    @if (preview(); as p) {
      <div class="report-head print-only">
        @if (settings.logoUrl(); as logo) { <img [src]="logo" [alt]="settings.companyName()" style="max-height:40px;vertical-align:middle" /> }
        <b>{{ settings.companyName() }}</b> · Year-end close {{ p.fiscal_year.label }} — preview
        <div>Period: {{ settings.date(p.fiscal_year.start) }} to {{ settings.date(p.fiscal_year.end) }} · Amounts in {{ settings.currency() }}</div>
        <div>Printed by {{ auth.me()?.name }} at {{ printedAt() }}</div>
      </div>
      <div style="display:flex;justify-content:space-between;align-items:center;gap:8px;margin-top:16px" class="no-print">
        <h2 style="margin:0">Preview {{ p.fiscal_year.label }} @if (p.fiscal_year.closed) { <span class="tag" data-s="closed">Closed</span> }</h2>
        <span class="export"><button class="ghost" (click)="print()">Print</button><button class="ghost" (click)="preview.set(null)">Close preview</button></span>
      </div>
      @if (p.checks.length) { <ul class="ye-checks">@for (c of p.checks; track c.code) { <li>{{ c.message }}</li> }</ul> }
      <div class="card section" style="margin-top:10px">
        <h2 style="margin-top:0">Summary</h2>
        <table class="list">
          <thead><tr><th>Branch</th><th class="num">Ledger result</th><th class="num">Income &amp; Expense report</th><th class="num">Difference</th></tr></thead>
          <tbody>
            @for (b of p.branches; track b.branch.id) {
              <tr><td>{{ b.branch.code }} · {{ b.branch.name }}</td><td class="num" [class.neg]="+b.net < 0">{{ b.net | money }}</td>
                <td class="num">{{ b.ie_net | money }}</td><td class="num" [class.neg]="+b.difference !== 0">{{ b.difference | money }}</td></tr>
            }
            <tr class="sub"><td>Total</td><td class="num">{{ p.total_net | money }}</td><td class="num">{{ p.total_ie_net | money }}</td>
              <td class="num" [class.neg]="diff(p) !== 0">{{ diff(p) | money }}</td></tr>
          </tbody>
        </table>
        <p>Retained earnings movement (MAIN): <b>{{ abs(p.retained_earnings_movement) | money }} {{ +p.retained_earnings_movement < 0 ? 'Dr' : 'Cr' }}</b></p>
      </div>
      @for (b of p.branches; track b.branch.id) {
        <div class="card section">
          <h2 style="margin-top:0">{{ b.branch.code }} · {{ b.branch.name }}
            @if (b.branch.status !== 'active') { <span class="tag" data-s="inactive">Inactive</span> }</h2>
          <p>Income {{ b.income | money }} · Expense {{ b.expense | money }} · Result <b [class.neg]="+b.net < 0">{{ b.net | money }}</b>
            · I&amp;E report {{ b.ie_net | money }} · Difference <span [class.neg]="+b.difference !== 0">{{ b.difference | money }}</span></p>
          @if (b.lines.length) {
            <table class="list">
              <thead><tr><th>Branch</th><th>Code</th><th>Account</th><th class="num">Debit</th><th class="num">Credit</th></tr></thead>
              <tbody>
                @for (l of b.lines; track $index) {
                  <tr><td>{{ l.branch_code }}</td><td class="mono">{{ l.code }}</td><td>{{ l.account }}</td>
                    <td class="num">{{ +l.debit ? (l.debit | money) : '' }}</td><td class="num">{{ +l.credit ? (l.credit | money) : '' }}</td></tr>
                }
              </tbody>
            </table>
          } @else { <p class="muted">No closing lines.</p> }
        </div>
      } @empty { <div class="card"><p class="muted">No income or expense in this year.</p></div> }
      <ag-signatures />
    }

    @if (closing(); as y) {
      <div class="att-backdrop" (click)="closing.set(null)"></div>
      <div class="att-dialog ye-dialog card" role="dialog" aria-modal="true" aria-labelledby="ye-close-title">
        <h2 id="ye-close-title">Close {{ y.label }}</h2>
        @if (closePreview(); as p) {
          <table class="list">
            <tbody>
              @for (b of p.branches; track b.branch.id) { <tr><td>{{ b.branch.code }}</td><td class="num" [class.neg]="+b.net < 0">{{ b.net | money }}</td></tr> }
              <tr class="sub"><td>Total</td><td class="num">{{ p.total_net | money }}</td></tr>
            </tbody>
          </table>
        } @else { <p class="muted">Loading...</p> }
        <p>This posts closing entries dated {{ settings.date(y.end) }} in every branch and keeps {{ monthOf(y.end) }} locked for all branches.
          It can only be undone by reopening the year.</p>
        <label>Type {{ short(y) }} to confirm <input name="confirm" [(ngModel)]="confirmText" autocomplete="off" /></label>
        @if (dialogMsg()) { <div class="alert">{{ dialogMsg() }}</div> }
        <div class="att-dialog-actions">
          <button type="button" class="ghost" (click)="closing.set(null)">Cancel</button>
          <button type="button" [disabled]="confirmText !== short(y) || busy()" (click)="close(y)">{{ busy() ? 'Closing...' : 'Close ' + y.label }}</button>
        </div>
      </div>
    }

    @if (reopening(); as y) {
      <div class="att-backdrop" (click)="reopening.set(null)"></div>
      <div class="att-dialog ye-dialog card" role="dialog" aria-modal="true" aria-labelledby="ye-reopen-title">
        <h2 id="ye-reopen-title">Reopen {{ y.label }}</h2>
        <div class="warn">Reopen only before the accounts for this year are signed or filed. After that, record corrections in the current year.</div>
        <p>This cancels the closing entries. The months stay locked; unlock them in Period locks if you need to change an entry.</p>
        <label>Reason <textarea name="reason" rows="3" maxlength="500" [(ngModel)]="reason"></textarea></label>
        <small class="muted">10 to 500 characters.</small>
        @if (dialogMsg()) { <div class="alert">{{ dialogMsg() }}</div> }
        <div class="att-dialog-actions">
          <button type="button" class="ghost" (click)="reopening.set(null)">Cancel</button>
          <button type="button" [disabled]="reason.trim().length < 10 || busy()" (click)="reopen(y)">{{ busy() ? 'Reopening...' : 'Reopen year' }}</button>
        </div>
      </div>
    }
  `,
})
export class YearEndComponent implements OnInit {
  auth = inject(AuthService);
  settings = inject(SettingsService);
  private http = inject(HttpClient);
  years = signal<Year[] | null>(null);
  preview = signal<Preview | null>(null);
  closePreview = signal<Preview | null>(null);
  closing = signal<Year | null>(null);
  reopening = signal<Year | null>(null);
  /** Only the latest closed year can be reopened (R11). */
  reopenable = signal<number | null>(null);
  msg = signal('');
  ok = signal('');
  dialogMsg = signal('');
  busy = signal(false);
  printedAt = signal('');
  confirmText = '';
  reason = '';

  ngOnInit() { this.load(); }

  async load() {
    try {
      const r = await firstValueFrom(this.http.get<{ data: Year[] }>('/api/v1/fiscal-years'));
      this.years.set(r.data);
      this.reopenable.set(r.data.find(y => y.status === 'closed')?.start_year ?? null);
    } catch (e) { this.msg.set(errorText(e)); }
  }

  private fetchPreview(y: Year) { return firstValueFrom(this.http.get<Preview>(`/api/v1/fiscal-years/${y.start_year}/preview`)); }

  async openPreview(y: Year) {
    this.msg.set('');
    try { this.preview.set(await this.fetchPreview(y)); } catch (e) { this.msg.set(errorText(e)); }
  }

  async askClose(y: Year) {
    this.confirmText = '';
    this.dialogMsg.set('');
    this.closePreview.set(null);
    this.closing.set(y);
    try { this.closePreview.set(await this.fetchPreview(y)); } catch (e) { this.dialogMsg.set(errorText(e)); }
  }

  askReopen(y: Year) {
    this.reason = '';
    this.dialogMsg.set('');
    this.reopening.set(y);
  }

  async close(y: Year) {
    if (this.busy()) return;
    this.busy.set(true);
    this.dialogMsg.set('');
    try {
      await firstValueFrom(this.http.post(`/api/v1/fiscal-years/${y.start_year}/close`, { confirm: this.confirmText }));
      this.closing.set(null);
      this.preview.set(null);
      this.ok.set(`${y.label} closed.`);
      await this.load();
    } catch (e) { this.dialogMsg.set(errorText(e)); } finally { this.busy.set(false); }
  }

  async reopen(y: Year) {
    if (this.busy()) return;
    this.busy.set(true);
    this.dialogMsg.set('');
    try {
      await firstValueFrom(this.http.post(`/api/v1/fiscal-years/${y.start_year}/reopen`, { reason: this.reason.trim() }));
      this.reopening.set(null);
      this.preview.set(null);
      this.ok.set(`${y.label} reopened. The months stay locked; unlock them in Period locks to change an entry.`);
      await this.load();
    } catch (e) { this.dialogMsg.set(errorText(e)); } finally { this.busy.set(false); }
  }

  print() {
    this.printedAt.set(this.settings.dateTime(new Date().toISOString()) + ' (Asia/Colombo)');
    setTimeout(() => window.print());
  }

  statusName(s: Status) { return STATUS[s] ?? s; }
  last(y: Year): Close | undefined { return y.history.at(-1); }
  /** The label without "FY " ("2026-27"): what the close dialog asks to type. */
  short(y: Year) { return y.label.replace(/^FY /, ''); }
  monthOf(ymd: string) { return `${MONTHS[Number(ymd.slice(5, 7)) - 1]} ${ymd.slice(0, 4)}`; }
  diff(p: Preview) { return Math.round((Number(p.total_net) - Number(p.total_ie_net)) * 100) / 100; }
  abs(v: string) { return v.replace(/^-/, ''); }
}
