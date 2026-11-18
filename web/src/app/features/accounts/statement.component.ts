import { Component, OnInit, inject, input, signal } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { DatePipe } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { MoneyPipe, TYPE_LABELS } from '../../core/money.pipe';

interface Statement {
  account: { name: string; branch: { code: string; name: string } };
  opening_balance: string; closing_balance: string;
  entries: { date: string; ref_no: string; type: string; description: string; in: string; out: string; balance: string }[];
}

@Component({
  standalone: true,
  imports: [FormsModule, MoneyPipe, DatePipe],
  template: `
    @if (s(); as s) { <h1>{{ s.account.branch.code }} · {{ s.account.name }} <small class="muted">statement</small></h1> }
    <form class="filters" (ngSubmit)="load()">
      <input type="date" name="from" [(ngModel)]="from" /> <input type="date" name="to" [(ngModel)]="to" />
      <button type="submit">Show</button> <button type="button" class="ghost" (click)="print()">Print</button>
    </form>
    @if (s(); as s) {
      <div class="card">
        <table class="list">
          <thead><tr><th>Date</th><th>Ref</th><th>Type</th><th>Description</th><th class="num">In</th><th class="num">Out</th><th class="num">Balance</th></tr></thead>
          <tbody>
            <tr class="sub"><td colspan="6">Opening balance</td><td class="num">{{ s.opening_balance | money }}</td></tr>
            @for (e of s.entries; track $index) {
              <tr>
                <td>{{ e.date | date: 'd MMM y' }}</td><td class="mono">{{ e.ref_no }}</td><td>{{ label(e.type) }}</td><td>{{ e.description }}</td>
                <td class="num pos">{{ +e.in ? (e.in | money) : '' }}</td><td class="num neg">{{ +e.out ? (e.out | money) : '' }}</td>
                <td class="num">{{ e.balance | money }}</td>
              </tr>
            }
            <tr class="sub"><td colspan="6">Closing balance</td><td class="num"><b>{{ s.closing_balance | money }}</b></td></tr>
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
  to = new Date().toISOString().slice(0, 10);
  from = this.to.slice(0, 8) + '01';

  ngOnInit() { this.load(); }
  load() {
    this.http.get<Statement>(`/api/v1/accounts/${this.id()}/statement`, { params: { from: this.from, to: this.to } }).subscribe(s => this.s.set(s));
  }
  label(t: string) { return TYPE_LABELS[t] ?? t; }
  print() { window.print(); }
}
