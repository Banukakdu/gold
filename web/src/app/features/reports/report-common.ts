import { Component, computed, inject, input, model, OnInit, signal } from '@angular/core';
import { HttpClient, HttpErrorResponse, HttpParams } from '@angular/common/http';
import { FormsModule } from '@angular/forms';
import { firstValueFrom } from 'rxjs';
import { AuthService } from '../../core/auth.service';
import { errorText } from '../../core/api.interceptor';

export interface ReportBranch { id: number; code: string; name: string; status: string }

/** Header data every report response carries. */
export interface ReportMeta {
  company: string;
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

/** Finding 11: full calendar years are labelled as such. */
export function periodLabel(from: string, to: string): string {
  const y = from.slice(0, 4);
  return from === `${y}-01-01` && to === `${y}-12-31` ? `Year ${y} (calendar), ${from} to ${to}` : `${from} to ${to}`;
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

/** PDF / Excel buttons (only with report.export). The export uses the same filters as the screen. */
@Component({
  selector: 'ag-export-buttons',
  standalone: true,
  template: `
    @if (auth.can('report.export')) {
      <span class="export">
        <button type="button" class="ghost" [disabled]="busy()" (click)="download('pdf')">{{ busy() === 'pdf' ? 'Preparing...' : 'PDF' }}</button>
        <button type="button" class="ghost" [disabled]="busy()" (click)="download('xlsx')">{{ busy() === 'xlsx' ? 'Preparing...' : 'Excel' }}</button>
      </span>
    }
    @if (error()) { <div class="alert">{{ error() }}</div> }
  `,
})
export class ExportButtonsComponent {
  auth = inject(AuthService);
  private http = inject(HttpClient);
  name = input.required<string>();
  params = input.required<ReportParams>();
  busy = signal<'' | 'pdf' | 'xlsx'>('');
  error = signal('');

  async download(format: 'pdf' | 'xlsx') {
    this.busy.set(format);
    this.error.set('');
    const p = { ...this.params() };
    delete p['page'];
    try {
      const blob = await firstValueFrom(this.http.post(`/api/v1/reports/${this.name()}/export`, { ...p, format }, { responseType: 'blob' }));
      const url = URL.createObjectURL(blob);
      const a = document.createElement('a');
      a.href = url;
      a.download = `${this.name()}_${p['from']}_${p['to']}.${format}`;
      a.click();
      setTimeout(() => URL.revokeObjectURL(url), 1000);
    } catch (e) {
      // Error bodies arrive as a Blob because of responseType: 'blob'.
      const err = e as HttpErrorResponse;
      let msg = errorText(e);
      if (err?.error instanceof Blob) {
        try { msg = JSON.parse(await err.error.text())?.message ?? msg; } catch { /* keep default */ }
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
        <div class="print-only"><b>{{ m.company }}</b> · {{ title() }}</div>
        <div>Branches: {{ m.all_branches ? 'All branches' : branchLabel(m) }}
          @for (b of m.branches; track b.id) { @if (b.status !== 'active') { <span class="tag" data-s="inactive">{{ b.code }} inactive</span> } }</div>
        <div>Period: {{ period(m) }} · Amounts in LKR · Posted entries incl. reversals; voided entries excluded</div>
        <div class="print-only">Printed by {{ auth.me()?.name }} at {{ printedAt }}</div>
        @if (m.provisional) { <div class="provisional">PROVISIONAL - period not locked</div> }
      </div>
    }
  `,
})
export class ReportHeadComponent {
  auth = inject(AuthService);
  meta = input<ReportMeta | null>(null);
  title = input('');
  printedAt = new Date().toLocaleString('en-GB', { timeZone: 'Asia/Colombo', hour12: false }) + ' (Asia/Colombo)';

  branchLabel(m: ReportMeta) { return m.branches.map(b => `${b.code} ${b.name}`).join(', '); }
  period(m: ReportMeta) { return periodLabel(m.from, m.to); }
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
