import { Component, OnInit, computed, inject, signal } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { FormsModule } from '@angular/forms';
import { Account } from '../../core/models';
import { MoneyPipe } from '../../core/money.pipe';
import { errorText } from '../../core/api.interceptor';
import { colomboToday } from '../../core/dates';

/** Super Admin: give a branch its opening balance or a top-up from a Main account. Also owner capital into Main. */
@Component({
  standalone: true,
  imports: [FormsModule, MoneyPipe],
  template: `
    <h1>Fund a branch</h1>
    <form class="card form" (ngSubmit)="save()">
      <label>Kind
        <select name="kind" [(ngModel)]="m.kind">
          <option value="opening">Opening balance</option>
          <option value="top_up">Top-up</option>
        </select>
      </label>
      <label>From (Main)
        <select name="from" [(ngModel)]="m.from_account_id" required>
          @for (a of mainAccounts(); track a.id) { <option [ngValue]="a.id">{{ a.name }} ({{ a.current_balance | money }})</option> }
        </select>
      </label>
      <label>To branch account
        <select name="to" [(ngModel)]="m.to_account_id" required>
          @for (a of branchAccounts(); track a.id) { <option [ngValue]="a.id">{{ a.branch?.code }} · {{ a.name }} ({{ a.current_balance | money }})</option> }
        </select>
      </label>
      <label>Amount <input type="number" name="amount" [(ngModel)]="m.amount" min="0.01" step="0.01" required /></label>
      <label>Date <input type="date" name="txn_date" [(ngModel)]="m.txn_date" [max]="today" required /></label>
      <label>Note <input name="note" [(ngModel)]="m.note" /></label>
      @if (error()) { <div class="alert wide">{{ error() }}</div> }
      @if (saved()) { <div class="ok wide">Posted {{ saved() }}.</div> }
      <div class="wide actions"><button type="submit" [disabled]="busy()">Send funds</button></div>
    </form>

    <h2>Add capital to Main</h2>
    <form class="card form" (ngSubmit)="capital()">
      <label>Main account
        <select name="cap_acc" [(ngModel)]="c.account_id" required>
          @for (a of mainAccounts(); track a.id) { <option [ngValue]="a.id">{{ a.name }}</option> }
        </select>
      </label>
      <label>Amount <input type="number" name="cap_amt" [(ngModel)]="c.amount" min="0.01" step="0.01" required /></label>
      <label>Date <input type="date" name="cap_date" [(ngModel)]="c.txn_date" [max]="today" required /></label>
      <label>Note <input name="cap_note" [(ngModel)]="c.note" /></label>
      <div class="wide actions"><button type="submit" class="ghost" [disabled]="busy()">Add capital</button></div>
    </form>
  `,
})
export class AllocationFormComponent implements OnInit {
  private http = inject(HttpClient);
  accounts = signal<(Account & { branch: { id: number; code: string; name: string; is_main?: boolean } })[]>([]);
  mainAccounts = computed(() => this.accounts().filter(a => a.branch?.code && this.mainIds().has(a.branch_id)));
  branchAccounts = computed(() => this.accounts().filter(a => !this.mainIds().has(a.branch_id)));
  private mainIds = signal(new Set<number>());
  busy = signal(false);
  error = signal('');
  saved = signal('');
  today = colomboToday(); // Q4 / R10: no future dates (Asia/Colombo day)
  m: any = { kind: 'opening', from_account_id: null, to_account_id: null, amount: null, txn_date: this.today, note: '' };
  c: any = { account_id: null, amount: null, txn_date: this.today, note: 'Owner capital' };

  ngOnInit() {
    this.http.get<{ id: number; is_main: boolean }[]>('/api/v1/branches').subscribe(b => this.mainIds.set(new Set(b.filter(x => x.is_main).map(x => x.id))));
    this.http.get<any[]>('/api/v1/accounts').subscribe(a => this.accounts.set(a.filter(x => x.status !== 'closed')));
  }

  save() { this.post('/api/v1/allocations', this.m); }
  capital() { this.post('/api/v1/capital', this.c); }

  private post(url: string, body: object) {
    if (this.busy()) return; // double-submit guard (QA Q1): a second click can fire before the button disables
    this.busy.set(true);
    this.error.set('');
    this.http.post<{ ref_no: string }>(url, body).subscribe({
      next: r => { this.saved.set(r.ref_no); this.busy.set(false); this.ngOnInit(); },
      error: e => { this.error.set(errorText(e)); this.busy.set(false); },
    });
  }
}
