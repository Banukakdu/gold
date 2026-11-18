import { Component, OnInit, inject, signal } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { FormsModule } from '@angular/forms';
import { AuthService } from '../../core/auth.service';
import { MoneyPipe } from '../../core/money.pipe';
import { errorText } from '../../core/api.interceptor';

interface Branch { id: number; code: string; name: string; address?: string; phone?: string; is_main: boolean; status: string; balance: string | null }

@Component({
  standalone: true,
  imports: [FormsModule, MoneyPipe],
  template: `
    <h1>Branches</h1>
    <div class="card">
      <table class="list">
        <thead><tr><th>Code</th><th>Name</th><th>Phone</th><th>Status</th><th class="num">Balance</th><th></th></tr></thead>
        <tbody>
          @for (b of branches(); track b.id) {
            <tr>
              <td class="mono">{{ b.code }}</td><td>{{ b.name }} @if (b.is_main) { <span class="tag">Main</span> }</td>
              <td>{{ b.phone }}</td><td><span class="tag" [attr.data-s]="b.status">{{ b.status }}</span></td>
              <td class="num">{{ b.balance | money }}</td>
              <td>@if (!b.is_main) { <button class="link" (click)="toggle(b)">{{ b.status === 'active' ? 'Deactivate' : 'Activate' }}</button> }</td>
            </tr>
          }
        </tbody>
      </table>
    </div>
    <h2>New branch</h2>
    <form class="card form" (ngSubmit)="add()">
      <label>Code <input name="code" [(ngModel)]="m.code" maxlength="10" required placeholder="KDY" /></label>
      <label>Name <input name="name" [(ngModel)]="m.name" required placeholder="Kandy" /></label>
      <label>Address <input name="address" [(ngModel)]="m.address" /></label>
      <label>Phone <input name="phone" [(ngModel)]="m.phone" /></label>
      @if (error()) { <div class="alert wide">{{ error() }}</div> }
      <div class="wide actions"><button type="submit">Create branch</button></div>
      <p class="muted wide">A Cash account is created automatically. Use “Fund a branch” to give it an opening balance.</p>
    </form>
  `,
})
export class BranchListComponent implements OnInit {
  private http = inject(HttpClient);
  private auth = inject(AuthService);
  branches = signal<Branch[]>([]);
  error = signal('');
  m: any = { code: '', name: '', address: '', phone: '' };

  ngOnInit() { this.http.get<Branch[]>('/api/v1/branches').subscribe(b => this.branches.set(b)); }

  add() {
    this.http.post('/api/v1/branches', { ...this.m, code: this.m['code'].toUpperCase() }).subscribe({
      next: () => { this.m = { code: '', name: '', address: '', phone: '' }; this.error.set(''); this.ngOnInit(); this.auth.loadMe(); },
      error: e => this.error.set(errorText(e)),
    });
  }

  toggle(b: Branch) {
    this.http.put(`/api/v1/branches/${b.id}`, { status: b.status === 'active' ? 'inactive' : 'active' }).subscribe({
      next: () => this.ngOnInit(), error: e => this.error.set(errorText(e)) });
  }
}
