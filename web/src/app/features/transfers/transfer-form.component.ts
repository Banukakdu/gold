import { Component, OnInit, computed, inject, signal } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { FormsModule } from '@angular/forms';
import { Account } from '../../core/models';
import { MoneyPipe } from '../../core/money.pipe';
import { errorText } from '../../core/api.interceptor';
import { LockBannerComponent } from '../period-locks/lock-banner.component';
import { colomboToday } from '../../core/dates';

/** Branch-to-branch, internal, or back-to-Main transfer. */
@Component({
  standalone: true,
  imports: [FormsModule, MoneyPipe, LockBannerComponent],
  template: `
    <h1>Transfer money</h1>
    <ag-lock-banner />
    <form class="card form" (ngSubmit)="save()">
      <label>From account
        <select name="from" [(ngModel)]="m.from_account_id" required>
          @for (a of mine(); track a.id) { <option [ngValue]="a.id">{{ a.branch?.code }} · {{ a.name }} ({{ a.current_balance | money }})</option> }
        </select>
      </label>
      <label>To account
        <select name="to" [(ngModel)]="m.to_account_id" required>
          @for (a of destinations(); track a.id) {
            @if (a.id !== m.from_account_id) { <option [ngValue]="a.id">{{ a.branch?.code }} · {{ a.name }}</option> }
          }
        </select>
      </label>
      <label>Amount <input type="number" name="amount" [(ngModel)]="m.amount" min="0.01" step="0.01" required /></label>
      <label>Date <input type="date" name="txn_date" [(ngModel)]="m.txn_date" [max]="today" required /></label>
      <label class="wide">Reason <input name="note" [(ngModel)]="m.note" required /></label>
      @if (error()) { <div class="alert wide">{{ error() }}</div> }
      @if (saved()) { <div class="ok wide">Transfer {{ saved() }} posted.</div> }
      <div class="wide actions"><button type="submit" [disabled]="busy()">Transfer</button></div>
    </form>
  `,
})
export class TransferFormComponent implements OnInit {
  private http = inject(HttpClient);
  mine = signal<Account[]>([]);
  destinations = signal<Account[]>([]);
  busy = signal(false);
  error = signal('');
  saved = signal('');
  today = colomboToday();
  m: any = { from_account_id: null, to_account_id: null, amount: null, txn_date: this.today, note: '' };

  ngOnInit() {
    this.http.get<Account[]>('/api/v1/accounts').subscribe(a => {
      this.mine.set(a.filter(x => x.status !== 'closed'));
      this.m.from_account_id ??= this.mine()[0]?.id ?? null;
    });
    this.http.get<Account[]>('/api/v1/accounts/destinations').subscribe(a => this.destinations.set(a));
  }

  save() {
    this.busy.set(true);
    this.error.set('');
    this.http.post<{ ref_no: string }>('/api/v1/transfers', this.m).subscribe({
      next: r => { this.saved.set(r.ref_no); this.m['amount'] = null; this.m['note'] = ''; this.busy.set(false); this.ngOnInit(); },
      error: e => { this.error.set(errorText(e)); this.busy.set(false); },
    });
  }
}
