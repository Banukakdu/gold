import { Component, OnInit, inject, signal } from '@angular/core';
import { HttpClient, HttpParams } from '@angular/common/http';
import { DatePipe } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { AuthService } from '../../core/auth.service';
import { MoneyPipe, TYPE_LABELS } from '../../core/money.pipe';
import { Page, Transaction } from '../../core/models';
import { errorText } from '../../core/api.interceptor';

@Component({
  standalone: true,
  imports: [FormsModule, MoneyPipe, DatePipe],
  template: `
    <h1>Transactions</h1>
    <form class="filters" (ngSubmit)="load(1)">
      <input type="date" name="from" [(ngModel)]="f.from" />
      <input type="date" name="to" [(ngModel)]="f.to" />
      <select name="type" [(ngModel)]="f.type">
        <option value="">All types</option>
        @for (t of types; track t[0]) { <option [value]="t[0]">{{ t[1] }}</option> }
      </select>
      <input name="q" placeholder="Search ref, payee, note" [(ngModel)]="f.q" />
      <button type="submit">Filter</button>
    </form>
    @if (msg()) { <div class="alert">{{ msg() }}</div> }
    <div class="card">
      <table class="list">
        <thead><tr><th>Date</th><th>Ref</th><th>Branch</th><th>Type</th><th>Category</th><th>Account</th><th>Details</th><th class="num">Amount</th><th>Status</th><th></th></tr></thead>
        <tbody>
          @for (t of page()?.data; track t.id) {
            <tr [class.struck]="t.status === 'void'">
              <td>{{ t.txn_date | date: 'd MMM y' }}</td>
              <td class="mono">{{ t.ref_no }}</td>
              <td>{{ t.branch?.code }}</td>
              <td>{{ label(t.type) }}</td>
              <td>{{ t.category ? (t.category.parent?.name ? t.category.parent?.name + ' › ' : '') + t.category.name : '' }}</td>
              <td>{{ t.account?.name ?? ((t.from_account?.name ?? '') + ' → ' + (t.to_account?.name ?? '')) }}</td>
              <td>{{ t.payee_payer || t.description }}</td>
              <td class="num" [class.neg]="t.type === 'expense'">{{ t.amount | money }}</td>
              <td><span class="tag" [attr.data-s]="t.status">{{ t.status }}</span></td>
              <td>
                @if (t.status === 'posted' && (t.type === 'income' || t.type === 'expense') && auth.can(t.type + '.void')) {
                  <button class="link" (click)="void(t)">Void</button>
                }
                @if (t.status === 'posted' && isTransfer(t.type) && auth.can('transfer.reverse')) {
                  <button class="link" (click)="reverse(t)">Reverse</button>
                }
              </td>
            </tr>
          } @empty { <tr><td colspan="10" class="muted">No transactions match.</td></tr> }
        </tbody>
      </table>
      @if (page(); as p) {
        <div class="pager">
          <button class="ghost" [disabled]="p.current_page <= 1" (click)="load(p.current_page - 1)">Previous</button>
          <span>Page {{ p.current_page }} of {{ p.last_page }} · {{ p.total }} entries</span>
          <button class="ghost" [disabled]="p.current_page >= p.last_page" (click)="load(p.current_page + 1)">Next</button>
        </div>
      }
    </div>
  `,
})
export class TransactionListComponent implements OnInit {
  auth = inject(AuthService);
  private http = inject(HttpClient);
  page = signal<Page<Transaction> | null>(null);
  msg = signal('');
  f = { from: '', to: '', type: '', q: '' };
  types = Object.entries(TYPE_LABELS);

  ngOnInit() { this.load(1); }

  load(p: number) {
    let params = new HttpParams().set('page', p);
    for (const [k, v] of Object.entries(this.f)) if (v) params = params.set(k, v);
    this.http.get<Page<Transaction>>('/api/v1/transactions', { params }).subscribe(r => this.page.set(r));
  }

  label(t: string) { return TYPE_LABELS[t] ?? t; }
  isTransfer(t: string) { return ['opening_allocation', 'fund_allocation', 'fund_return', 'branch_transfer', 'internal_transfer'].includes(t); }

  void(t: Transaction) {
    const reason = prompt(`Void ${t.ref_no}? Enter a reason:`);
    if (!reason) return;
    this.http.post(`/api/v1/transactions/${t.id}/void`, { reason }).subscribe({
      next: () => this.load(this.page()?.current_page ?? 1), error: e => this.msg.set(errorText(e)) });
  }

  reverse(t: Transaction) {
    const reason = prompt(`Reverse ${t.ref_no}? Enter a reason:`);
    if (!reason) return;
    this.http.post(`/api/v1/transactions/${t.id}/reverse`, { reason }).subscribe({
      next: () => this.load(this.page()?.current_page ?? 1), error: e => this.msg.set(errorText(e)) });
  }
}
