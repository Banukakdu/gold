import { Component, OnInit, inject, signal, computed } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { DatePipe } from '@angular/common';
import { AuthService } from '../../core/auth.service';
import { MoneyPipe, TYPE_LABELS } from '../../core/money.pipe';

interface Dash {
  scope: 'all' | 'branch';
  total_balance: string; income: string; expense: string; net: string;
  accounts: { id: number; name: string; type: string; current_balance: string; branch: { code: string } }[];
  branch_balances: { id: number; code: string; name: string; balance: string }[] | null;
  top_expense_categories: { category: string; total: string }[];
  trend: { month: string; income: string; expense: string }[];
  recent: { id: number; ref_no: string; type: string; txn_date: string; amount: string; status: string; branch: { code: string } }[];
}

@Component({
  standalone: true,
  imports: [MoneyPipe, DatePipe],
  template: `
    <h1>{{ auth.isAllBranches() ? 'All branches' : auth.activeBranch()?.name }} <small class="muted">this month</small></h1>
    @if (d(); as d) {
      <section class="kpis">
        <div class="kpi"><span>Total cash</span><b>{{ d.total_balance | money }}</b></div>
        <div class="kpi"><span>Income</span><b class="pos">{{ d.income | money }}</b></div>
        <div class="kpi"><span>Expenses</span><b class="neg">{{ d.expense | money }}</b></div>
        <div class="kpi"><span>Net</span><b [class.neg]="+d.net < 0">{{ d.net | money }}</b></div>
      </section>

      <section class="grid2">
        <div class="card">
          <h2>{{ d.branch_balances ? 'Balance by branch' : 'Balance by account' }}</h2>
          @for (row of bars(); track row.label) {
            <div class="bar"><span>{{ row.label }}</span><i [style.width.%]="row.pct"></i><b>{{ row.value | money }}</b></div>
          } @empty { <p class="muted">No accounts yet.</p> }
        </div>
        <div class="card">
          <h2>Income vs expenses, last 12 months</h2>
          <div class="trend">
            @for (m of d.trend; track m.month) {
              <div class="col" [title]="m.month + ': in ' + m.income + ' / out ' + m.expense">
                <i class="in" [style.height.%]="pct(m.income)"></i><i class="out" [style.height.%]="pct(m.expense)"></i>
                <small>{{ m.month.slice(5) }}</small>
              </div>
            }
          </div>
          <p class="legend"><i class="in"></i> Income <i class="out"></i> Expenses</p>
        </div>
      </section>

      <section class="grid2">
        <div class="card">
          <h2>Top expense categories</h2>
          <table>
            @for (c of d.top_expense_categories; track c.category) { <tr><td>{{ c.category }}</td><td class="num">{{ c.total | money }}</td></tr> }
            @empty { <tr><td class="muted">No expenses this month.</td></tr> }
          </table>
        </div>
        <div class="card">
          <h2>Latest transactions</h2>
          <table>
            @for (t of d.recent; track t.id) {
              <tr [class.struck]="t.status !== 'posted'">
                <td>{{ t.txn_date | date: 'd MMM' }}</td><td>{{ t.branch.code }}</td><td>{{ label(t.type) }}</td>
                <td class="num">{{ t.amount | money }}</td>
              </tr>
            } @empty { <tr><td class="muted">Nothing recorded yet.</td></tr> }
          </table>
        </div>
      </section>
    } @else if (error()) { <div class="alert">{{ error() }}</div> } @else { <p class="muted">Loading…</p> }
  `,
})
export class DashboardComponent implements OnInit {
  auth = inject(AuthService);
  private http = inject(HttpClient);
  d = signal<Dash | null>(null);
  error = signal('');

  bars = computed(() => {
    const d = this.d();
    if (!d) return [];
    const rows = d.branch_balances
      ? d.branch_balances.map(b => ({ label: b.code, value: b.balance }))
      : d.accounts.map(a => ({ label: a.name, value: a.current_balance }));
    const max = Math.max(1, ...rows.map(r => Math.abs(+r.value)));
    return rows.map(r => ({ ...r, pct: (Math.abs(+r.value) / max) * 100 }));
  });

  private trendMax = computed(() => Math.max(1, ...(this.d()?.trend ?? []).flatMap(m => [+m.income, +m.expense])));

  ngOnInit() {
    this.http.get<Dash>('/api/v1/dashboard').subscribe({ next: d => this.d.set(d), error: () => this.error.set('Could not load the dashboard.') });
  }

  pct(v: string) { return (+v / this.trendMax()) * 100; }
  label(t: string) { return TYPE_LABELS[t] ?? t; }
}
