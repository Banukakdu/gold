import { Component, OnInit, computed, inject, signal } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { FormsModule } from '@angular/forms';
import { Account } from '../../core/models';
import { AuthService } from '../../core/auth.service';
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
        <select name="from" [ngModel]="fromId()" (ngModelChange)="setFrom($event)" required>
          @for (a of mine(); track a.id) { <option [ngValue]="a.id">{{ a.branch?.code }} · {{ a.name }} ({{ a.current_balance | money }})</option> }
        </select>
      </label>
      <label>To account
        <select name="to" [ngModel]="toId()" (ngModelChange)="toId.set($event)" required>
          @for (g of toGroups(); track g.label) {
            <optgroup [label]="g.label">
              @for (a of g.accounts; track a.id) { <option [ngValue]="a.id">{{ a.branch?.code }} · {{ a.name }}</option> }
            </optgroup>
          }
        </select>
      </label>
      @if (typeLabel(); as t) { <p class="muted wide">Type: {{ t }}</p> }
      <label>Amount <input type="number" name="amount" [(ngModel)]="m.amount" min="0.01" step="0.01" required /></label>
      <label>Date <input type="date" name="txn_date" [(ngModel)]="m.txn_date" [max]="today" required /></label>
      <label class="wide">Reason <input name="note" [(ngModel)]="m.note" required /></label>
      @if (error()) { <div class="alert wide">{{ error() }}</div> }
      @if (saved()) { <div class="ok wide">{{ saved() }} posted.@if (mustConfirm()) { {{ mustConfirm() }} must confirm receipt. }</div> }
      <div class="wide actions"><button type="submit" [disabled]="busy()">Transfer</button></div>
    </form>
  `,
})
export class TransferFormComponent implements OnInit {
  private http = inject(HttpClient);
  private auth = inject(AuthService);
  mine = signal<Account[]>([]);
  destinations = signal<Account[]>([]);
  busy = signal(false);
  error = signal('');
  saved = signal('');
  today = colomboToday();
  fromId = signal<number | null>(null);
  toId = signal<number | null>(null);
  m: any = { amount: null, txn_date: this.today, note: '' };

  private branchOf(id: number | null) {
    return this.destinations().find(a => a.id === id)?.branch ?? this.mine().find(a => a.id === id)?.branch;
  }

  /**
   * Fund return spec section 4: Main accounts first, then one group per branch code. A non-super user sending from a
   * Main account sees Main accounts only (G4; the server refuses Main -> branch for them anyway).
   */
  toGroups = computed(() => {
    const fromId = this.fromId();
    const mainOnly = !!this.branchOf(fromId)?.is_main && !this.auth.isSuperAdmin();
    const groups = new Map<string, Account[]>();
    const list = this.destinations().filter(a => a.id !== fromId && (!mainOnly || a.branch?.is_main));
    for (const a of [...list.filter(a => a.branch?.is_main), ...list.filter(a => !a.branch?.is_main)
      .sort((x, y) => (x.branch?.code ?? '').localeCompare(y.branch?.code ?? ''))]) {
      const label = a.branch?.is_main ? 'Main' : (a.branch?.code ?? '');
      groups.set(label, [...(groups.get(label) ?? []), a]);
    }
    return [...groups].map(([label, accounts]) => ({ label, accounts }));
  });

  /** Display only; the server derives the type (same rule as LedgerService::transferType). */
  typeLabel = computed(() => {
    const from = this.branchOf(this.fromId());
    const to = this.branchOf(this.toId());
    if (!from || !to || this.fromId() === this.toId()) return '';
    if (from.id === to.id) return 'Internal transfer';
    if (from.is_main) return 'Top-up';
    return to.is_main ? 'Fund return to Main' : 'Branch transfer';
  });

  /** Review fix 1: a To account that is no longer offered (same account, or filtered out by G4) is cleared. */
  setFrom(id: number | null) {
    this.fromId.set(id);
    this.dropHiddenTo();
  }

  private dropHiddenTo() {
    if (!this.toGroups().some(g => g.accounts.some(a => a.id === this.toId()))) this.toId.set(null);
  }

  ngOnInit() {
    this.http.get<Account[]>('/api/v1/accounts').subscribe(a => {
      this.mine.set(a.filter(x => x.status !== 'closed'));
      if (this.fromId() === null) this.setFrom(this.mine()[0]?.id ?? null);
    });
    this.http.get<Account[]>('/api/v1/accounts/destinations').subscribe(a => { this.destinations.set(a); this.dropHiddenTo(); });
  }

  mustConfirm = signal('');

  save() {
    if (this.busy()) return; // double-submit guard (QA Q1): a second click can fire before the button disables
    this.busy.set(true);
    this.error.set('');
    // AG-052 F2: the receiving branch, named in the success text when it must confirm receipt.
    const toCode = this.destinations().find(a => a.id === Number(this.toId()))?.branch?.code ?? '';
    this.http.post<{ ref_no: string; type: string; receipt_status?: string | null }>('/api/v1/transfers', { ...this.m, from_account_id: this.fromId(), to_account_id: this.toId() }).subscribe({
      next: r => { this.mustConfirm.set(r.receipt_status === 'awaiting' ? toCode : ''); this.saved.set(`${r.type === 'fund_return' ? 'Fund return' : 'Transfer'} ${r.ref_no}`); this.m['amount'] = null; this.m['note'] = ''; this.busy.set(false); this.ngOnInit(); },
      error: e => { this.error.set(errorText(e)); this.busy.set(false); },
    });
  }
}
