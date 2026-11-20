import { Component, OnInit, inject, input, signal } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { DatePipe } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { RouterLink } from '@angular/router';
import { MoneyPipe, TYPE_LABELS } from '../../core/money.pipe';
import { errorText } from '../../core/api.interceptor';

interface Statement {
  account: { name: string; code?: string | null; type: string; branch: { code: string; name: string } };
  opening_balance: string; closing_balance: string;
  entries: { transaction_id: number; date: string; ref_no: string; type: string; status: string; contra: boolean; description: string; in: string; out: string; balance: string }[];
}

@Component({
  standalone: true,
  imports: [FormsModule, MoneyPipe, DatePipe, RouterLink],
  template: `
    @if (s(); as s) { <h1>{{ s.account.branch.code }} · {{ s.account.code ? s.account.code + ' ' : '' }}{{ s.account.name }} <small class="muted">{{ isMoney(s) ? 'statement' : 'ledger' }}</small></h1> }
    <form class="filters" (ngSubmit)="load()">
      <input type="date" name="from" [(ngModel)]="from" /> <input type="date" name="to" [(ngModel)]="to" />
      <label><input type="checkbox" name="include_voided" [(ngModel)]="showVoided" (ngModelChange)="load()" style="width:auto" /> Show voided</label>
      <button type="submit">Show</button> <button type="button" class="ghost" (click)="print()">Print</button>
    </form>
    @if (msg()) { <div class="alert">{{ msg() }}</div> }
    @if (s(); as s) {
      <div class="card">
        <table class="list">
          <thead><tr><th>Date</th><th>Ref</th><th>Type</th><th>Description</th><th class="num">{{ isMoney(s) ? 'In' : 'Debit' }}</th><th class="num">{{ isMoney(s) ? 'Out' : 'Credit' }}</th><th class="num">Balance</th></tr></thead>
          <tbody>
            <tr class="sub"><td colspan="6">Opening balance</td><td class="num">{{ bal(s, s.opening_balance) }}</td></tr>
            @for (e of s.entries; track $index) {
              <tr>
                <td>{{ e.date | date: 'd MMM y' }}</td><td class="mono"><a [routerLink]="['/transactions', e.transaction_id]">{{ e.ref_no }}</a></td><td>{{ label(e.type) }} @if (e.status === 'void') { <span class="tag" data-s="void">Void</span> }</td><td>{{ e.description }}</td>
                <td class="num pos">{{ +e.in ? (e.in | money) : '' }}</td><td class="num neg">{{ +e.out ? (e.out | money) : '' }}</td>
                <td class="num">{{ bal(s, e.balance) }}</td>
              </tr>
            }
            <tr class="sub"><td colspan="6">Closing balance</td><td class="num"><b>{{ bal(s, s.closing_balance) }}</b></td></tr>
          </tbody>
        </table>
      </div>
    }
  `,
})
export class StatementComponent implements OnInit {
  id = input.required<string>();
  private http = inject(HttpClient);
  s = signal<Statement | null>(null);
  msg = signal('');
  private money = new MoneyPipe();
  to = new Date().toISOString().slice(0, 10);
  from = this.to.slice(0, 8) + '01';
  showVoided = false;

  ngOnInit() { this.load(); }
  load() {
    this.http.get<Statement>(`/api/v1/accounts/${this.id()}/statement`, { params: { from: this.from, to: this.to, include_voided: this.showVoided ? 1 : 0 } }).subscribe({
      next: s => { this.s.set(s); this.msg.set(''); },
      // QA B2: a 403 (e.g. a ledger account without report.view) or 404 shows the API message, not a blank page.
      error: e => { this.s.set(null); this.msg.set(errorText(e)); },
    });
  }
  label(t: string) { return TYPE_LABELS[t] ?? t; }
  /** Cash, bank and wallet keep In / Out; other ledger accounts (double entry) show Debit / Credit. */
  isMoney(s: Statement) { return ['cash', 'bank', 'wallet'].includes(s.account.type); }
  /** Review N9: a ledger account's balance reads "1,000.00 Cr", never a negative number; money accounts are unchanged. */
  bal(s: Statement, v: string): string {
    if (this.isMoney(s)) return this.money.transform(v);
    const n = Number(v);
    return n === 0 ? '0.00' : `${this.money.transform(Math.abs(n))} ${n > 0 ? 'Dr' : 'Cr'}`;
  }
  print() { window.print(); }
}
