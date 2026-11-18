import { Component, OnInit, computed, inject, input, signal } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { FormsModule } from '@angular/forms';
import { Router } from '@angular/router';
import { Account, Category } from '../../core/models';
import { MoneyPipe } from '../../core/money.pipe';
import { errorText } from '../../core/api.interceptor';
import { LockBannerComponent } from '../period-locks/lock-banner.component';
import { colomboToday } from '../../core/dates';

/** Record income or expense (route /transactions/new/:kind). */
@Component({
  standalone: true,
  imports: [FormsModule, MoneyPipe, LockBannerComponent],
  template: `
    <h1>Record {{ kind() }}</h1>
    <ag-lock-banner />
    <form class="card form" (ngSubmit)="save()">
      <label>Date <input type="date" name="txn_date" [(ngModel)]="m.txn_date" [max]="today" required /></label>
      <label>{{ kind() === 'income' ? 'Received into' : 'Paid from' }}
        <select name="account_id" [(ngModel)]="m.account_id" required>
          @for (a of accounts(); track a.id) { <option [ngValue]="a.id">{{ a.name }} ({{ a.current_balance | money }})</option> }
        </select>
      </label>
      <label>Category
        <select name="parent" [(ngModel)]="parentId" (ngModelChange)="m.category_id = null" required>
          @for (c of categories(); track c.id) { <option [ngValue]="c.id">{{ c.name }}</option> }
        </select>
      </label>
      <label>Sub-category
        <select name="category_id" [(ngModel)]="m.category_id" required>
          @for (c of subs(); track c.id) { <option [ngValue]="c.id">{{ c.name }}</option> }
        </select>
      </label>
      <label>Amount <input type="number" name="amount" [(ngModel)]="m.amount" min="0.01" step="0.01" required /></label>
      <label>{{ kind() === 'income' ? 'Received from' : 'Paid to' }} <input name="payee_payer" [(ngModel)]="m.payee_payer" /></label>
      <label>Payment method
        <select name="payment_method" [(ngModel)]="m.payment_method">
          <option value="cash">Cash</option><option value="bank_transfer">Bank transfer</option>
          <option value="cheque">Cheque</option><option value="card">Card</option><option value="online">Online</option>
        </select>
      </label>
      <label>Reference / bill no. <input name="reference" [(ngModel)]="m.reference" /></label>
      <label class="wide">Description <textarea name="description" rows="2" [(ngModel)]="m.description"></textarea></label>
      @if (error()) { <div class="alert wide">{{ error() }}</div> }
      @if (saved()) { <div class="ok wide">Saved as {{ saved() }}.</div> }
      <div class="wide actions"><button type="submit" [disabled]="busy()">Save {{ kind() }}</button></div>
    </form>
  `,
})
export class EntryFormComponent implements OnInit {
  kind = input.required<'income' | 'expense'>();
  private http = inject(HttpClient);
  private router = inject(Router);
  today = colomboToday();
  accounts = signal<Account[]>([]);
  categories = signal<Category[]>([]);
  parentIdSig = signal<number | null>(null);
  busy = signal(false);
  error = signal('');
  saved = signal('');
  m: any = this.blank();

  get parentId() { return this.parentIdSig(); }
  set parentId(v: number | null) { this.parentIdSig.set(v); }
  subs = computed(() => this.categories().find(c => c.id === this.parentIdSig())?.children ?? []);

  ngOnInit() {
    this.http.get<Account[]>('/api/v1/accounts').subscribe(a => {
      this.accounts.set(a.filter(x => x.status !== 'closed'));
      this.m['account_id'] ??= a[0]?.id;
    });
    this.http.get<Category[]>('/api/v1/categories', { params: { type: this.kind() } }).subscribe(c => this.categories.set(c));
  }

  save() {
    this.busy.set(true);
    this.error.set('');
    this.http.post<{ ref_no: string }>(`/api/v1/${this.kind() === 'income' ? 'incomes' : 'expenses'}`, this.m).subscribe({
      next: r => {
        this.saved.set(r.ref_no);
        const keep = { account_id: this.m['account_id'], txn_date: this.m['txn_date'] };
        this.m = { ...this.blank(), ...keep };
        this.busy.set(false);
        this.ngOnInit(); // refresh balances
      },
      error: e => { this.error.set(errorText(e)); this.busy.set(false); },
    });
  }

  private blank() {
    return { txn_date: this.today, account_id: null, category_id: null, amount: null, payee_payer: '', payment_method: 'cash', reference: '', description: '' };
  }
}
