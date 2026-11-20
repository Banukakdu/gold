import { Component } from '@angular/core';
import { RouterLink } from '@angular/router';

@Component({
  standalone: true,
  imports: [RouterLink],
  template: `
    <h1>Reports</h1>
    <section class="grid2">
      @for (r of reports; track r.link) {
        <a class="card hub" [routerLink]="r.link"><h2>{{ r.title }}</h2><p class="muted">{{ r.question }}</p></a>
      }
    </section>
  `,
})
export class ReportsHubComponent {
  reports = [
    { link: '/reports/income-expense', title: 'Income & Expense', question: 'Where does our money come from and where does it go, by category?' },
    { link: '/reports/branch-comparison', title: 'Branch Comparison', question: 'How is each branch doing: funds received, income, expenses and balance?' },
    { link: '/reports/transfers', title: 'Transfers & Allocations', question: 'How much money moved between Main and the branches, and between branches?' },
    { link: '/reports/cash-book', title: 'Daily Cash Book', question: 'What came in and went out of each account, day by day?' },
    { link: '/reports/trial-balance', title: 'Trial Balance', question: 'Do debits equal credits, and what is the balance of every ledger account?' },
  ];
}
