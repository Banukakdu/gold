import { Component, HostListener, computed, inject, input, model, OnInit, output, signal } from '@angular/core';
import { HttpClient, HttpErrorResponse, HttpParams, HttpResponse } from '@angular/common/http';
import { FormsModule } from '@angular/forms';
import { ActivatedRoute, ParamMap, Router, RouterLink } from '@angular/router';
import { firstValueFrom } from 'rxjs';
import { AuthService } from '../../core/auth.service';
import { errorText } from '../../core/api.interceptor';
import { SettingsService } from '../../core/settings.service';

export interface ReportBranch { id: number; code: string; name: string; status: string }

/** Header data every report response carries. */
export interface ReportMeta {
  company: string;
  /** Settings spec S4: the currency setting. */
  currency?: string;
  from: string;
  to: string;
  branches: ReportBranch[];
  all_branches: boolean;
  subset: boolean;
  provisional: boolean;
}

export type ReportParams = Record<string, string | number | (string | number)[] | null | undefined>;

/** Local calendar date as YYYY-MM-DD. */
export function isoDate(d: Date): string {
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
}

export function currentMonth(): { from: string; to: string } {
  const now = new Date();
  return { from: isoDate(new Date(now.getFullYear(), now.getMonth(), 1)), to: isoDate(new Date(now.getFullYear(), now.getMonth() + 1, 0)) };
}

/** Array values become `key[]=a&key[]=b`, as Laravel expects. */
export function toHttpParams(p: ReportParams): HttpParams {
  let params = new HttpParams();
  for (const [k, v] of Object.entries(p)) {
    if (v === null || v === undefined || v === '') continue;
    if (Array.isArray(v)) v.forEach(x => (params = params.append(`${k}[]`, String(x))));
    else params = params.set(k, String(v));
  }
  return params;
}

/** Cash flow R6 / review finding 3: the API's message for a report that does not tie to the ledger (shown as is). */
export const UNRECONCILED = 'This report could not be produced because its figures do not agree with the ledger. Nothing was changed. Please report this to your administrator.';

/**
 * Error text for a report request. Server errors (500) and network failures get a friendly line:
 * raw PHP messages are never shown (fix round, QA bug 1).
 */
export function reportError(err: unknown): string {
  const status = (err as HttpErrorResponse)?.status ?? 0;
  if (status === 0) return 'Could not reach the server. Check your connection and try again.';
  if (status >= 500) return 'The report could not be produced. Please try again, or narrow the date range or branches.';
  return errorText(err);
}

/** "YYYY-MM-DD HH:mm (Asia/Colombo)" for printed headers. */
export function colomboNow(d = new Date()): string {
  const parts = Object.fromEntries(new Intl.DateTimeFormat('en-GB', {
    timeZone: 'Asia/Colombo', year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit', hourCycle: 'h23',
  }).formatToParts(d).map(p => [p.type, p.value]));
  return `${parts['year']}-${parts['month']}-${parts['day']} ${parts['hour']}:${parts['minute']} (Asia/Colombo)`;
}

/**
 * Report filters live in the URL query (QA bug 6), so Back from a drill-down and a reload keep them.
 * replaceUrl: each run updates the current history entry instead of adding one.
 */
export function writeQuery(router: Router, route: ActivatedRoute, p: ReportParams) {
  const queryParams: Record<string, string | number | (string | number)[] | null> = {};
  for (const [k, v] of Object.entries(p)) queryParams[k] = v === undefined || v === '' || (Array.isArray(v) && !v.length) ? null : v;
  router.navigate([], { relativeTo: route, queryParams, replaceUrl: true });
}

/** Read the report query. Branch ids are only honoured in "All branches" mode (stale after a branch switch otherwise). */
export function readQuery(route: ActivatedRoute, allBranches: boolean): { q: ParamMap; branches: number[] } {
  const q = route.snapshot.queryParamMap;
  return { q, branches: allBranches ? q.getAll('branches').map(Number).filter(n => n > 0) : [] };
}

/** Laravel 422 field errors as { field: 'message' } (array fields folded onto their base name). */
export function fieldErrors(err: unknown): Record<string, string> {
  const body = (err as HttpErrorResponse)?.error;
  const out: Record<string, string> = {};
  for (const [k, v] of Object.entries((body?.errors ?? {}) as Record<string, string[]>)) out[k.split('.')[0]] ??= v[0];
  return out;
}

/** Spec 4.3 / AC 13: period picker -> from/to (calendar year until the fiscal-year setting exists). */
export function periodRange(type: 'month' | 'quarter' | 'year' | 'custom', v: { month: string; quarter: number; year: number; from: string; to: string }): { from: string; to: string } {
  const last = (y: number, m: number) => isoDate(new Date(y, m, 0)); // m = 1-based month -> last day of it
  if (type === 'month') {
    const [y, m] = v.month.split('-').map(Number);
    return { from: `${v.month}-01`, to: last(y, m) };
  }
  if (type === 'quarter') {
    const m1 = (v.quarter - 1) * 3 + 1;
    return { from: `${v.year}-${String(m1).padStart(2, '0')}-01`, to: last(v.year, m1 + 2) };
  }
  if (type === 'year') return { from: `${v.year}-01-01`, to: `${v.year}-12-31` };
  return { from: v.from, to: v.to };
}

/**
 * Finding 11 and settings spec S6: an exact fiscal year reads "FY 2026-27, <from> to <to>"; a full calendar year is
 * labelled as such when the fiscal year does not start in January. Dates follow the date format setting (S3).
 */
export function periodLabel(from: string, to: string, settings?: SettingsService): string {
  const d = (x: string) => settings ? settings.date(x) : x;
  const range = `${d(from)} to ${d(to)}`;
  if (settings) {
    const fy = settings.fiscalYearOf(from);
    if (fy.start === from && fy.end === to) return `${fy.label}, ${range}`;
  }
  const y = from.slice(0, 4);
  return from === `${y}-01-01` && to === `${y}-12-31` ? `Year ${y} (calendar), ${range}` : range;
}

/** S6: "This fiscal year" / "Last fiscal year" presets for the report filter bars. */
@Component({
  selector: 'ag-fy-presets',
  standalone: true,
  template: `
    <span class="export">
      <button type="button" class="ghost" (click)="use(0)">This fiscal year</button>
      <button type="button" class="ghost" (click)="use(-1)">Last fiscal year</button>
    </span>
  `,
})
export class FiscalPresetsComponent {
  private settings = inject(SettingsService);
  pick = output<{ from: string; to: string }>();

  use(offset: number) {
    const fy = this.settings.fiscalYear(this.settings.currentFiscalYear().start_year + offset);
    this.pick.emit({ from: fy.start, to: fy.end });
  }
}

/** Branches multi-select, shown only in "All branches" mode (spec 4, F1). None ticked = all branches. */
@Component({
  selector: 'ag-branch-picker',
  standalone: true,
  imports: [FormsModule],
  template: `
    @if (auth.isAllBranches()) {
      <details class="picker">
        <summary>{{ selected().length ? 'Branches: ' + label() : 'All branches' }}</summary>
        @for (b of branches(); track b.id) {
          <label><input type="checkbox" [checked]="selected().includes(b.id)" (change)="toggle(b.id)" />
            {{ b.code }} · {{ b.name }} @if (b.status !== 'active') { <span class="tag" data-s="inactive">Inactive</span> }</label>
        }
        @if (selected().length) { <button type="button" class="link" (click)="selected.set([])">All branches</button> }
      </details>
    }
  `,
})
export class BranchPickerComponent implements OnInit {
  auth = inject(AuthService);
  private http = inject(HttpClient);
  selected = model<number[]>([]);
  branches = signal<ReportBranch[]>([]);
  label = computed(() => this.branches().filter(b => this.selected().includes(b.id)).map(b => b.code).join(', '));

  ngOnInit() {
    if (this.auth.isAllBranches()) {
      this.http.get<ReportBranch[]>('/api/v1/branches').subscribe({ next: b => this.branches.set(b), error: () => this.branches.set([]) });
    }
  }

  toggle(id: number) {
    this.selected.update(s => (s.includes(id) ? s.filter(x => x !== id) : [...s, id]));
  }
}

/**
 * AG-087 S1: the 202 answer of an export endpoint (a large Excel export prepared in the background). The body arrives
 * as a Blob (responseType: 'blob'); null when the response is a file.
 */
export async function queuedNotice(res: HttpResponse<Blob>): Promise<string | null> {
  if (res.status !== 202) return null;
  try {
    return JSON.parse(await (res.body as Blob).text())?.message ?? 'This export is being prepared in the background.';
  } catch {
    return 'This export is being prepared in the background.';
  }
}

/** PDF / Excel buttons (only with report.export). The export uses the same filters as the screen. */
@Component({
  selector: 'ag-export-buttons',
  standalone: true,
  imports: [RouterLink],
  template: `
    @if (auth.can('report.export')) {
      <span class="export">
        <button type="button" class="ghost" [disabled]="busy()" (click)="download('pdf')">{{ busy() === 'pdf' ? 'Preparing...' : 'PDF' }}</button>
        <button type="button" class="ghost" [disabled]="busy()" (click)="download('xlsx')">{{ busy() === 'xlsx' ? 'Preparing...' : 'Excel' }}</button>
      </span>
    }
    @if (error()) { <div class="alert">{{ error() }}</div> }
    @if (notice(); as n) { <div class="alert info">{{ n }} <a routerLink="/exports">View exports</a></div> }
  `,
})
export class ExportButtonsComponent {
  auth = inject(AuthService);
  private http = inject(HttpClient);
  name = input.required<string>();
  params = input.required<ReportParams>();
  busy = signal<'' | 'pdf' | 'xlsx'>('');
  error = signal('');
  /** AG-087 S1: the background notice, shown until the filters change (it belongs to the filters it was asked for). */
  private queued = signal<{ key: string; message: string } | null>(null);
  notice = computed(() => {
    const q = this.queued();
    return q && q.key === this.key() ? q.message : '';
  });

  private key() {
    const p = { ...this.params() };
    delete p['page'];
    return `${this.name()}|${JSON.stringify(p)}`;
  }

  async download(format: 'pdf' | 'xlsx') {
    this.busy.set(format);
    this.error.set('');
    this.queued.set(null);
    const p = { ...this.params() };
    delete p['page'];
    const key = this.key();
    try {
      const res = await firstValueFrom(this.http.post(`/api/v1/reports/${this.name()}/export`, { ...p, format }, { responseType: 'blob', observe: 'response' }));
      const message = await queuedNotice(res);
      if (message !== null) {
        this.queued.set({ key, message });
        return;
      }
      const blob = res.body as Blob;
      const url = URL.createObjectURL(blob);
      const a = document.createElement('a');
      a.href = url;
      a.download = `${this.name()}_${p['from']}_${p['to']}.${format}`;
      a.click();
      setTimeout(() => URL.revokeObjectURL(url), 1000);
    } catch (e) {
      // Error bodies arrive as a Blob because of responseType: 'blob'.
      const err = e as HttpErrorResponse;
      let msg = reportError(e);
      if (err?.status && err.error instanceof Blob) {
        try {
          const body = JSON.parse(await err.error.text())?.message;
          // A 500 keeps the generic text, except the reconciliation stop, whose message is meant for the user (m1).
          if (body && (err.status < 500 || body === UNRECONCILED)) msg = body;
        } catch { /* keep default */ }
      }
      this.error.set(msg);
    } finally {
      this.busy.set('');
    }
  }
}

/** Header shown above every report (and on print): company, branches, period, currency, basis, provisional. */
@Component({
  selector: 'ag-report-head',
  standalone: true,
  template: `
    @if (meta(); as m) {
      <div class="report-head">
        <div class="print-only">@if (settings.logoUrl(); as logo) { <img [src]="logo" [alt]="m.company" style="max-height:40px;vertical-align:middle" /> }
          <b>{{ m.company }}</b> · {{ title() }}</div>
        <div>Branches: {{ m.all_branches ? 'All branches' : branchLabel(m) }}
          @for (b of m.branches; track b.id) { @if (b.status !== 'active') { <span class="tag" data-s="inactive">{{ b.code }} inactive</span> } }</div>
        <div>Period: {{ period(m) }} · Amounts in {{ m.currency ?? settings.currency() }} · {{ basis() || 'Posted entries incl. reversals; voided entries excluded' }}</div>
        <div class="print-only">Printed by {{ auth.me()?.name }} at {{ printedAt() }}</div>
        @if (m.provisional) { <div class="provisional">PROVISIONAL - period not locked</div> }
      </div>
    }
  `,
})
export class ReportHeadComponent {
  auth = inject(AuthService);
  settings = inject(SettingsService);
  meta = input<ReportMeta | null>(null);
  title = input('');
  /** Basis line; the trial balance has its own (double-entry S2). */
  basis = input('');
  /** Period label sent by the API (AG-086 R7: a range over two fiscal years names both); default periodLabel(). */
  periodText = input('');
  /** Time of printing (QA bug 3): refreshed when the browser starts printing. */
  printedAt = signal(this.now());

  @HostListener('window:beforeprint')
  onBeforePrint() { this.printedAt.set(this.now()); }

  /** "Printed ... at": the date format setting (S3), 24 h, Asia/Colombo. */
  private now() { return `${this.settings.dateTime(new Date().toISOString())} (Asia/Colombo)`; }

  branchLabel(m: ReportMeta) { return m.branches.map(b => `${b.code} ${b.name}`).join(', '); }
  period(m: ReportMeta) { return this.periodText() || periodLabel(m.from, m.to, this.settings); }
}

/** Prepared / Checked / Approved lines for printed reports (finding 4). */
@Component({
  selector: 'ag-signatures',
  standalone: true,
  template: `
    <div class="signatures print-only">
      @for (who of ['Prepared by', 'Checked by', 'Approved by']; track who) {
        <div><b>{{ who }}</b><span>Name</span><span>Signature</span><span>Date</span></div>
      }
    </div>
  `,
})
export class SignaturesComponent {}
