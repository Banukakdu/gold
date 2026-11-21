import { Component, OnInit, computed, inject, signal } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { FormsModule } from '@angular/forms';
import { RouterLink } from '@angular/router';
import { AuthService } from '../../core/auth.service';
import { Account } from '../../core/models';
import { MoneyPipe } from '../../core/money.pipe';
import { errorText } from '../../core/api.interceptor';

@Component({
  standalone: true,
  imports: [FormsModule, RouterLink, MoneyPipe],
  template: `
    <h1>Accounts</h1>
    <div class="card">
      <table class="list">
        <thead><tr><th>Branch</th><th>Account</th><th>Type</th><th class="num">Balance</th><th></th></tr></thead>
        <tbody>
          @for (a of accounts(); track a.id) {
            <tr>
              <td>{{ a.branch?.code }}</td>
              <td>{{ a.name }} @if (a.is_main_account) { <span class="tag">Main</span> }</td>
              <td>{{ a.type }}</td>
              <td class="num">{{ a.current_balance | money }}</td>
              <td><a [routerLink]="['/accounts', a.id, 'statement']">Statement</a>
                @if (a.type === 'bank' && auth.can('account.reconcile')) { · <a [routerLink]="['/accounts', a.id, 'reconcile']">Reconcile</a> }</td>
            </tr>
          }
        </tbody>
        <tfoot><tr><th colspan="3">Total</th><th class="num">{{ total() | money }}</th><th></th></tr></tfoot>
      </table>
    </div>

    @if (auth.can('account.manage') && !auth.isAllBranches()) {
      <h2>Add account</h2>
      <form class="card form" (ngSubmit)="add()">
        <label>Name <input name="name" [(ngModel)]="m.name" required /></label>
        <label>Type
          <select name="type" [(ngModel)]="m.type"><option value="cash">Cash</option><option value="bank">Bank</option><option value="wallet">Mobile wallet</option></select>
        </label>
        @if (m.type === 'bank') {
          <label>Bank <input name="bank_name" [(ngModel)]="m.bank_name" /></label>
          <label>Account no. <input name="account_no" [(ngModel)]="m.account_no" /></label>
        }
        @if (error()) { <div class="alert wide">{{ error() }}</div> }
        <div class="wide actions"><button type="submit" [disabled]="busy()">Add account</button></div>
      </form>
    }
  `,
})
export class AccountListComponent implements OnInit {
  auth = inject(AuthService);
  private http = inject(HttpClient);
  accounts = signal<Account[]>([]);
  total = computed(() => this.accounts().reduce((s, a) => s + Number(a.current_balance), 0));
  error = signal('');
  /** QA Q1 sweep: a double click must not create the record twice. */
  busy = signal(false);
  m: any = { name: '', type: 'cash', bank_name: '', account_no: '' };

  ngOnInit() { this.http.get<Account[]>('/api/v1/accounts').subscribe(a => this.accounts.set(a)); }

  add() {
    if (this.busy()) return;
    this.busy.set(true);
    this.http.post('/api/v1/accounts', this.m).subscribe({
      next: () => { this.busy.set(false); this.m = { name: '', type: 'cash', bank_name: '', account_no: '' }; this.error.set(''); this.ngOnInit(); },
      error: e => { this.busy.set(false); this.error.set(errorText(e)); },
    });
  }
}
