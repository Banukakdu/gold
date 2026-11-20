import { Component, OnInit, inject, signal } from '@angular/core';
import { HttpClient, HttpHeaders } from '@angular/common/http';
import { FormsModule } from '@angular/forms';
import { AuthService } from '../../core/auth.service';
import { MoneyPipe } from '../../core/money.pipe';
import { errorText } from '../../core/api.interceptor';

interface Branch {
  id: number; code: string; name: string; address?: string; phone?: string; is_main: boolean; status: string; balance: string | null;
  /** AG-064 S1: decimal string, or null for "never". */
  expense_approval_threshold?: string | null;
}
const LIMIT = /^\d{1,13}(\.\d{1,2})?$/;

@Component({
  standalone: true,
  imports: [FormsModule, MoneyPipe],
  template: `
    <h1>Branches</h1>
    <div class="card">
      <table class="list">
        <thead><tr><th>Code</th><th>Name</th><th>Phone</th><th>Status</th><th class="num">Balance</th><th class="num">Expense approval above</th><th></th></tr></thead>
        <tbody>
          @for (b of branches(); track b.id) {
            <tr>
              <td class="mono">{{ b.code }}</td><td>{{ b.name }} @if (b.is_main) { <span class="tag">Main</span> }</td>
              <td>{{ b.phone }}</td><td><span class="tag" [attr.data-s]="b.status">{{ b.status }}</span></td>
              <td class="num">{{ b.balance | money }}</td>
              <td class="num">@if (b.expense_approval_threshold != null) { {{ b.expense_approval_threshold | money }} } @else { - }</td>
              <td>
                @if (auth.isSuperAdmin()) { <button class="link" (click)="editLimit(b)">Edit</button> }
                <button class="link" (click)="copyTemplates(b)">Copy role templates</button>
                @if (!b.is_main) { <button class="link" (click)="toggle(b)">{{ b.status === 'active' ? 'Deactivate' : 'Activate' }}</button> }
              </td>
            </tr>
          }
        </tbody>
      </table>
    </div>
    @if (ok()) { <div class="ok">{{ ok() }}</div> }
    @if (limitWarning()) { <div class="warn">{{ limitWarning() }}</div> }
    @if (editing(); as b) {
      <!-- AG-064 S1: Super Admin edit dialog for the expense approval limit. -->
      <div class="att-backdrop" (click)="editing.set(null)"></div>
      <form class="card att-dialog ap-dialog" role="dialog" aria-modal="true" aria-labelledby="br-title" (ngSubmit)="saveLimit(b)">
        <h2 id="br-title">Edit {{ b.code }}</h2>
        <label>Expenses above this amount need approval
          <input name="limit" inputmode="decimal" [(ngModel)]="limit" placeholder="Never" />
        </label>
        <p class="muted ap-hint">Leave empty for never. 0 means every expense of this branch. Super Admin expenses are never held. Approvers: users with 'Approve expenses' in this branch.</p>
        @if (limitError()) { <div class="alert">{{ limitError() }}</div> }
        <div class="att-dialog-actions">
          <button type="button" class="ghost" (click)="editing.set(null)">Cancel</button>
          <button type="submit" [disabled]="busy()">Save</button>
        </div>
      </form>
    }
    <h2>New branch</h2>
    <form class="card form" (ngSubmit)="add()">
      <label>Code <input name="code" [(ngModel)]="m.code" maxlength="10" required placeholder="KDY" /></label>
      <label>Name <input name="name" [(ngModel)]="m.name" required placeholder="Kandy" /></label>
      <label>Address <input name="address" [(ngModel)]="m.address" /></label>
      <label>Phone <input name="phone" [(ngModel)]="m.phone" /></label>
      @if (error()) { <div class="alert wide">{{ error() }}</div> }
      <div class="wide actions"><button type="submit" [disabled]="busy()">Create branch</button></div>
      <p class="muted wide">A Cash account is created automatically. Use “Fund a branch” to give it an opening balance.</p>
    </form>
  `,
})
export class BranchListComponent implements OnInit {
  private http = inject(HttpClient);
  auth = inject(AuthService);
  branches = signal<Branch[]>([]);
  error = signal('');
  /** QA Q1 sweep: a double click must not create the record twice. */
  busy = signal(false);
  ok = signal('');
  /** AG-064 S1 */
  editing = signal<Branch | null>(null);
  limit = '';
  limitError = signal('');
  limitWarning = signal('');
  m: any = { code: '', name: '', address: '', phone: '' };

  ngOnInit() { this.http.get<Branch[]>('/api/v1/branches').subscribe(b => this.branches.set(b)); }

  add() {
    if (this.busy()) return;
    this.busy.set(true);
    this.http.post('/api/v1/branches', { ...this.m, code: this.m['code'].toUpperCase() }).subscribe({
      next: () => { this.busy.set(false); this.m = { code: '', name: '', address: '', phone: '' }; this.error.set(''); this.ngOnInit(); this.auth.loadMe(); },
      error: e => { this.busy.set(false); this.error.set(errorText(e)); },
    });
  }

  /** F6: copy Branch Admin, Accountant, Cashier, Viewer into the branch; existing names are skipped. */
  copyTemplates(b: Branch) {
    this.http.post<{ created: string[]; skipped: string[] }>('/api/v1/roles/copy-templates', {},
      { headers: new HttpHeaders({ 'X-Branch-Id': String(b.id) }) }).subscribe({
      next: r => {
        this.error.set('');
        this.ok.set(`${b.code}: created ${r.created.join(', ') || 'none'}` + (r.skipped.length ? `; skipped ${r.skipped.join(', ')}` : '') + '.');
      },
      error: e => this.error.set(errorText(e)),
    });
  }

  editLimit(b: Branch) {
    this.limit = b.expense_approval_threshold ?? '';
    this.limitError.set('');
    this.editing.set(b);
  }

  saveLimit(b: Branch) {
    if (this.busy()) return;
    const v = String(this.limit ?? '').trim();
    if (v !== '' && !LIMIT.test(v)) {
      this.limitError.set('Enter an amount of 0 or more with up to 2 decimals, or leave it empty.');
      return;
    }
    this.busy.set(true);
    this.http.put<{ expense_approval_warning?: string | null }>(`/api/v1/branches/${b.id}`, { expense_approval_threshold: v === '' ? null : v }).subscribe({
      next: r => {
        this.busy.set(false);
        this.editing.set(null);
        this.ok.set('Branch saved.');
        this.limitWarning.set(r.expense_approval_warning ?? '');
        this.ngOnInit();
      },
      error: e => { this.busy.set(false); this.limitError.set(errorText(e)); },
    });
  }

  toggle(b: Branch) {
    this.http.put(`/api/v1/branches/${b.id}`, { status: b.status === 'active' ? 'inactive' : 'active' }).subscribe({
      next: () => this.ngOnInit(), error: e => this.error.set(errorText(e)) });
  }
}
