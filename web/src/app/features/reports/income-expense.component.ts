import { Component, OnInit, computed, inject, signal } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { FormsModule } from '@angular/forms';
import { ActivatedRoute, Router, RouterLink } from '@angular/router';
import { AuthService } from '../../core/auth.service';
import { MoneyPipe } from '../../core/money.pipe';
import {
  BranchPickerComponent, ExportButtonsComponent, FiscalPresetsComponent, ReportHeadComponent, ReportMeta, ReportParams, currentMonth, fieldErrors, toHttpParams,
  readQuery, reportError, writeQuery,
} from './report-common';

interface Line { id: number | null; name: string; is_active: boolean; amount: string; percent: string | null; code?: string | null; branch_only?: string | null }
interface Cat extends Line { subs: Line[] }
interface Section { total: string; categories: Cat[] }
interface IncomeExpense extends ReportMeta { income: Section | null; expense: Section | null; net: string | null }

/** AG-082 */
@Component({
  standalone: true,
  imports: [FormsModule, RouterLink, MoneyPipe, FiscalPresetsComponent, BranchPickerComponent, ExportButtonsComponent, ReportHeadComponent],
  template: `
    <h1>Income &amp; Expense <small class="muted">by category</small></h1>
    <form class="filters" (ngSubmit)="load()">
      <label>From <input type="date" name="from" [(ngModel)]="f.from" required /></label>
      <label>To <input type="date" name="to" [(ngModel)]="f.to" required /></label>
      <ag-fy-presets (pick)="preset($event)" />
      <ag-branch-picker [(selected)]="branches" />
      <select name="type" [(ngModel)]="f.type">
        <option value="all">All</option><option value="income">Income</option><option value="expense">Expense</option>
      </select>
      <button type="submit">Show</button>
      <ag-export-buttons name="income-expense" [params]="params()" />
    </form>
    @if (errLine(); as e) { <p class="field-error">{{ e }}</p> }
    @if (msg()) { <div class="alert">{{ msg() }}</div> }

    @if (loading()) { <p class="muted">Loading...</p> }
    @if (!loading() && r(); as r) {
      <ag-report-head [meta]="r" title="Income & Expense by Category" />
      @if (empty()) { <div class="card"><p class="muted">No income or expenses in this period.</p></div> }
      @else {
        @for (s of sections(); track s.key) {
          <div class="card section">
            <h2>{{ s.label }}</h2>
            <table class="list">
              <thead><tr><th>Category</th><th>Sub-category</th><th class="num">Amount</th><th class="num">% of type total</th></tr></thead>
              <tbody>
                @for (c of s.data.categories; track c.id) {
                  <tr class="sub"><td><span class="mono muted">{{ c.code }}</span> {{ c.name }} @if (!c.is_active) { <span class="tag" data-s="inactive">Inactive</span> }</td><td></td>
                    <td class="num">{{ c.amount | money }}</td><td class="num">{{ c.percent ?? '—' }}</td></tr>
                  @for (sub of c.subs; track sub.id) {
                    <tr><td></td>
                      <!-- Categories review finding 8: the code, and "<code> only" for a branch-only sub. -->
                      <td><span class="mono muted">{{ sub.code }}</span> {{ sub.name }}
                        @if (sub.branch_only) { <span class="tag">{{ sub.branch_only }} only</span> }
                        @if (!sub.is_active) { <span class="tag" data-s="inactive">Inactive</span> }</td>
                      <td class="num"><a [routerLink]="['/transactions']" [queryParams]="drill(s.key, c, sub)">{{ sub.amount | money }}</a></td>
                      <td class="num">{{ sub.percent ?? '—' }}</td></tr>
                  }
                } @empty { <tr><td colspan="4" class="muted">Nothing in this period.</td></tr> }
                <tr class="sub"><td>{{ s.totalLabel }}</td><td></td><td class="num">{{ s.data.total | money }}</td><td></td></tr>
              </tbody>
            </table>
          </div>
        }
        @if (r.net !== null) {
          <div class="card"><table><tr class="sub"><td>Net (income - expenses)</td><td class="num" [class.neg]="+r.net < 0">{{ r.net | money }}</td></tr></table></div>
        }
      }
    }
  `,
})
export class IncomeExpenseComponent implements OnInit {
  private http = inject(HttpClient);
  private router = inject(Router);
  private route = inject(ActivatedRoute);
  private auth = inject(AuthService);
  f = { ...currentMonth(), type: 'all' };
  branches = signal<number[]>([]);
  r = signal<IncomeExpense | null>(null);
  loading = signal(false);
  msg = signal('');
  errs = signal<Record<string, string>>({});
  private applied = signal<ReportParams>({});
  params = computed(() => this.applied());

  sections = computed(() => {
    const r = this.r();
    if (!r) return [];
    return [
      { key: 'income' as const, label: 'Income', totalLabel: 'Total income', data: r.income },
      { key: 'expense' as const, label: 'Expenses', totalLabel: 'Total expenses', data: r.expense },
    ].filter((s): s is { key: 'income' | 'expense'; label: string; totalLabel: string; data: Section } => !!s.data);
  });
  empty = computed(() => this.sections().every(s => s.data.categories.length === 0));

  errLine() { return Object.values(this.errs()).join(' '); }

  ngOnInit() {
    const { q, branches } = readQuery(this.route, this.auth.isAllBranches());
    this.f = { from: q.get('from') ?? this.f.from, to: q.get('to') ?? this.f.to, type: q.get('type') ?? this.f.type };
    this.branches.set(branches);
    this.load();
  }

  preset(r: { from: string; to: string }) {
    this.f = { ...this.f, ...r };
    this.load();
  }

  load() {
    const p: ReportParams = { from: this.f.from, to: this.f.to, type: this.f.type, branches: this.branches() };
    this.applied.set(p);
    writeQuery(this.router, this.route, p);
    this.loading.set(true);
    this.http.get<IncomeExpense>('/api/v1/reports/income-expense', { params: toHttpParams(p) }).subscribe({
      next: r => { this.r.set(r); this.msg.set(''); this.errs.set({}); this.loading.set(false); },
      error: e => { this.r.set(null); this.errs.set(fieldErrors(e)); this.msg.set(this.errLine() ? '' : reportError(e)); this.loading.set(false); },
    });
  }

  /** F3: open the transactions behind a sub-category amount (originals + reversals). */
  drill(type: 'income' | 'expense', c: Cat, sub: Line) {
    const r = this.r()!;
    const q: Record<string, unknown> = {
      from: r.from, to: r.to, type: `${type},reversal`,
      category_id: sub.id ?? c.id, category_only: 1,
      category_name: sub.id ? `${c.name} › ${sub.name}` : c.name,
    };
    // branches[] only means something in All mode; in branch mode the active branch already limits the list, and the
    // key is left out (AG-065 QA F5: an empty value read as a branch filter).
    if (this.auth.isAllBranches() && !r.all_branches) q['branches'] = r.branches.map(b => b.id);
    return q;
  }
}
