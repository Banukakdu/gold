import { Component, HostListener, OnInit, computed, inject, signal } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { FormsModule } from '@angular/forms';
import { Router, RouterLink } from '@angular/router';
import { AuthService } from '../../core/auth.service';
import { AppDatePipe } from '../../core/app-date.pipe';
import { MoneyPipe } from '../../core/money.pipe';
import { errorText } from '../../core/api.interceptor';
import { ToastService } from '../../core/toast.service';
import { toCents } from '../../core/approvals';
import { Account, Category } from '../../core/models';
import {
  ExpenseTemplate, ExpenseTemplateList, FREQUENCY_LABELS, PAYMENT_METHOD_LABELS, defaultPaymentMethod,
} from '../../core/expense-templates';

interface TemplateForm {
  name: string; account_id: number | null; parent_id: number | null; category_id: number | null; amount: string;
  payee_payer: string; payment_method: string; description: string; frequency: string;
}

/**
 * AG-066 S1 Expense templates (docs/specs/recurring-expenses.md). A template only fills in the Record expense form;
 * nothing here posts. Read with expense.create or expense.edit; New / Edit / Archive / Restore need expense.edit and a
 * single branch (All branches mode is read-only, and Use switches to the template's branch first, F7). A table on wide
 * screens, stacked cards at phone width (390px layout).
 */
@Component({
  standalone: true,
  imports: [FormsModule, RouterLink, MoneyPipe, AppDatePipe],
  template: `
    <h1>Expense templates</h1>
    <div class="filters tpl-toolbar">
      @if (canEdit()) { <button type="button" class="tpl-new" (click)="openNew()">New template</button> }
      <input type="search" name="q" placeholder="Search name or payee" aria-label="Search templates" [ngModel]="q()" (ngModelChange)="q.set($event)" />
      <label class="tpl-archived"><input type="checkbox" name="archived" style="width:auto" [ngModel]="showArchived()" (ngModelChange)="showArchived.set($event)" />
        Show archived ({{ archivedCount() }})</label>
    </div>
    @if (error()) { <div class="alert">{{ error() }}</div> }
    @if (loaded()) {
      @if (!all().length) {
        <div class="card">
          <p>No expense templates yet.</p>
          @if (auth.can('expense.edit')) { <p class="muted">Save the expenses you record every month (rent, salaries, internet) as templates so they are quicker to enter.</p> }
        </div>
      } @else {
        <div class="card tpl-table ea-table">
          <table class="list">
            <thead><tr>
              @if (auth.isAllBranches()) { <th>Branch</th> }
              <th>Name</th><th>Paid from</th><th>Category</th><th class="num">Amount</th><th>Paid to</th><th>Repeats</th>
              <th>This period</th><th>Last used</th><th></th><th class="tr-actions"></th>
            </tr></thead>
            <tbody>
              @for (t of rows(); track t.id) {
                <tr [class.tpl-muted]="!!t.archived_at">
                  @if (auth.isAllBranches()) { <td class="mono">{{ t.branch.code }}</td> }
                  <td class="tpl-name">{{ t.name }}</td>
                  <td>{{ t.account.name }} @if (accountInactive(t)) { <span class="tag" data-s="inactive">Account inactive</span> }</td>
                  <td><span class="ea-path" [title]="t.category.path">{{ t.category.path }}</span>
                    @if (!t.category.active) { <span class="tag" data-s="inactive">Category inactive</span> }</td>
                  <td class="num">{{ t.amount === null ? 'Enter each time' : (t.amount | money) }}</td>
                  <td class="ta-note">{{ t.payee_payer }}</td>
                  <td>{{ frequency(t) }}</td>
                  <td>
                    @if (t.this_period; as p) {
                      @for (u of p.transactions; track u.id) {
                        <div>Used: <a class="mono" [routerLink]="['/transactions', u.id]">{{ u.ref_no }}</a> ({{ u.date | appDate }}, {{ u.status_label ?? u.status }})</div>
                      } @empty { <span class="muted">Not used yet ({{ p.label }})</span> }
                    } @else { - }
                  </td>
                  <td>{{ t.last_used_on ? (t.last_used_on | appDate) : 'Never' }}</td>
                  <td>
                    @if (needsApproval(t)) { <span class="tag">Needs approval (above {{ threshold() | money }})</span> }
                    @if (t.archived_at) { <span class="tag" data-s="inactive" [title]="t.archive_reason ?? ''">Archived {{ t.archived_at | appDate }} by {{ t.archived_by?.name }}</span> }
                  </td>
                  <td class="tr-actions">
                    @if (canUse(t)) { <button type="button" class="link" (click)="use(t)">{{ useLabel(t) }}</button> }
                    @if (canChange(t) && !t.archived_at) {
                      <button type="button" class="link" (click)="openEdit(t)">Edit</button>
                      <button type="button" class="link" (click)="archiveTarget.set(t)">Archive</button>
                    }
                    @if (canChange(t) && t.archived_at) { <button type="button" class="link" (click)="restore(t)">Restore</button> }
                  </td>
                </tr>
              } @empty { <tr><td colspan="11" class="muted">No templates match.</td></tr> }
            </tbody>
          </table>
        </div>
        <div class="card ea-cards tpl-cards">
          @for (t of rows(); track t.id) {
            <div class="ea-card tpl-card" [class.tpl-muted]="!!t.archived_at">
              <div class="ea-line"><b class="tpl-name">{{ t.name }}</b><b class="num">{{ t.amount === null ? 'Enter each time' : (t.amount | money) }}</b></div>
              <div class="ea-line ea-sub"><span>{{ t.account.name }}</span><span class="ea-path" [title]="t.category.path">{{ t.category.path }}</span>
                @if (auth.isAllBranches()) { <span>{{ t.branch.code }}</span> }</div>
              <div class="ea-line ea-sub"><span>{{ t.payee_payer }}</span><span>{{ frequency(t) }}</span></div>
              <div class="ea-line ea-sub">
                @if (t.this_period; as p) {
                  @for (u of p.transactions; track u.id) {
                    <span>Used: <a class="mono" [routerLink]="['/transactions', u.id]">{{ u.ref_no }}</a> ({{ u.date | appDate }}, {{ u.status_label ?? u.status }})</span>
                  } @empty { <span>Not used yet ({{ p.label }})</span> }
                }
                <span>Last used {{ t.last_used_on ? (t.last_used_on | appDate) : 'Never' }}</span>
              </div>
              @if (accountInactive(t) || !t.category.active || needsApproval(t) || t.archived_at) {
                <div class="ea-line ea-sub">
                  @if (accountInactive(t)) { <span class="tag" data-s="inactive">Account inactive</span> }
                  @if (!t.category.active) { <span class="tag" data-s="inactive">Category inactive</span> }
                  @if (needsApproval(t)) { <span class="tag">Needs approval (above {{ threshold() | money }})</span> }
                  @if (t.archived_at) { <span class="tag" data-s="inactive" [title]="t.archive_reason ?? ''">Archived {{ t.archived_at | appDate }} by {{ t.archived_by?.name }}</span> }
                </div>
              }
              @if (canUse(t) || canChange(t)) {
                <div class="ea-buttons tpl-buttons">
                  @if (canUse(t)) { <button type="button" class="tpl-use" (click)="use(t)">{{ useLabel(t) }}</button> }
                  @if (canChange(t)) {
                    <details class="tpl-more">
                      <summary>More</summary>
                      @if (!t.archived_at) {
                        <button type="button" class="ghost" (click)="openEdit(t)">Edit</button>
                        <button type="button" class="ghost" (click)="archiveTarget.set(t)">Archive</button>
                      } @else { <button type="button" class="ghost" (click)="restore(t)">Restore</button> }
                    </details>
                  }
                </div>
              }
            </div>
          } @empty { <p class="muted">No templates match.</p> }
        </div>
      }
    }

    @if (form(); as f) {
      <div class="att-backdrop" (click)="closeForm()"></div>
      <form class="card att-dialog ap-dialog tpl-dialog" role="dialog" aria-modal="true" aria-labelledby="tpl-title" (ngSubmit)="saveForm()">
        <h2 id="tpl-title">{{ editing() ? 'Edit template' : 'New template' }}</h2>
        <p class="muted">A template only fills in the expense form. Nothing is posted until someone saves the expense.</p>
        <label>Name <input name="name" [(ngModel)]="f.name" maxlength="100" required /></label>
        @if (fieldError('name'); as e) { <p class="field-error">{{ e }}</p> }
        <label>Paid from
          <select name="account_id" [(ngModel)]="f.account_id" (ngModelChange)="accountChanged()" required>
            @for (a of accounts(); track a.id) { <option [ngValue]="a.id">{{ a.name }}</option> }
          </select>
        </label>
        @if (fieldError('account_id'); as e) { <p class="field-error">{{ e }}</p> }
        <label>Category
          <select name="parent_id" [(ngModel)]="f.parent_id" (ngModelChange)="f.category_id = null" required>
            @for (c of categories(); track c.id) { <option [ngValue]="c.id">{{ c.name }}</option> }
          </select>
        </label>
        <label>Sub-category
          <select name="category_id" [(ngModel)]="f.category_id" required>
            @for (c of subs(f.parent_id); track c.id) { <option [ngValue]="c.id">{{ c.name }}</option> }
          </select>
        </label>
        @if (fieldError('category_id'); as e) { <p class="field-error">{{ e }}</p> }
        <label>Amount <input name="amount" inputmode="decimal" [(ngModel)]="f.amount" placeholder="Enter each time" /></label>
        @if (fieldError('amount'); as e) { <p class="field-error">{{ e }}</p> }
        <label>Paid to <input name="payee_payer" [(ngModel)]="f.payee_payer" maxlength="255" /></label>
        @if (fieldError('payee_payer'); as e) { <p class="field-error">{{ e }}</p> }
        <label>Payment method
          <select name="payment_method" [(ngModel)]="f.payment_method" (ngModelChange)="methodTouched = true">
            @for (m of methods; track m[0]) { <option [value]="m[0]">{{ m[1] }}</option> }
          </select>
        </label>
        <label>Description <textarea name="description" rows="2" [(ngModel)]="f.description" maxlength="2000"></textarea></label>
        @if (fieldError('description'); as e) { <p class="field-error">{{ e }}</p> }
        <label>Repeats
          <select name="frequency" [(ngModel)]="f.frequency" required>
            @for (o of frequencies; track o[0]) { <option [value]="o[0]">{{ o[1] }}</option> }
          </select>
        </label>
        @if (formError()) { <div class="alert">{{ formError() }}</div> }
        <div class="att-dialog-actions">
          <button type="button" class="ghost" (click)="closeForm()">Cancel</button>
          <button type="submit" [disabled]="busy()">Save</button>
        </div>
      </form>
    }

    @if (archiveTarget(); as t) {
      <div class="att-backdrop" (click)="archiveTarget.set(null)"></div>
      <div class="card att-dialog ap-dialog tpl-dialog" role="dialog" aria-modal="true" aria-labelledby="tpl-archive-title">
        <h2 id="tpl-archive-title">Archive {{ t.name }}?</h2>
        <p>It can no longer be used, but expenses already recorded from it are not changed.</p>
        <label>Reason (optional) <textarea name="reason" rows="2" maxlength="500" [(ngModel)]="archiveReason"></textarea></label>
        @if (formError()) { <div class="alert">{{ formError() }}</div> }
        <div class="att-dialog-actions">
          <button type="button" class="ghost" (click)="archiveTarget.set(null)">Cancel</button>
          <button type="button" [disabled]="busy()" (click)="archive(t)">Archive</button>
        </div>
      </div>
    }
  `,
})
export class ExpenseTemplatesComponent implements OnInit {
  auth = inject(AuthService);
  private http = inject(HttpClient);
  private router = inject(Router);
  private toast = inject(ToastService);

  all = signal<ExpenseTemplate[]>([]);
  threshold = signal<string | null>(null);
  loaded = signal(false);
  error = signal('');
  q = signal('');
  showArchived = signal(false);
  archivedCount = computed(() => this.all().filter(t => !!t.archived_at).length);
  /** By name A-Z (the API order), filtered by the search box (name or payee) and Show archived. */
  rows = computed(() => {
    const q = this.q().trim().toLowerCase();
    return this.all().filter(t => (this.showArchived() || !t.archived_at)
      && (!q || t.name.toLowerCase().includes(q) || (t.payee_payer ?? '').toLowerCase().includes(q)));
  });

  accounts = signal<Account[]>([]);
  categories = signal<Category[]>([]);
  form = signal<TemplateForm | null>(null);
  editing = signal<ExpenseTemplate | null>(null);
  errors = signal<Record<string, string[]>>({});
  formError = signal('');
  busy = signal(false);
  archiveTarget = signal<ExpenseTemplate | null>(null);
  archiveReason = '';
  /** Review SHOULD 7: the payment method follows Paid from until the user picks one. */
  methodTouched = false;

  methods = Object.entries(PAYMENT_METHOD_LABELS);
  frequencies = Object.entries(FREQUENCY_LABELS);

  ngOnInit() { this.load(); }

  /** New / Edit / Archive / Restore: expense.edit in a single branch (All mode is read-only, F7). */
  canEdit(): boolean { return !this.auth.isAllBranches() && this.auth.can('expense.edit'); }

  canChange(t: ExpenseTemplate): boolean { return this.canEdit() && t.can_edit; }

  canUse(t: ExpenseTemplate): boolean { return !t.archived_at && t.can_use && this.auth.can('expense.create'); }

  useLabel(t: ExpenseTemplate): string { return this.auth.isAllBranches() ? `Switch to ${t.branch.code} and use` : 'Use'; }

  frequency(t: ExpenseTemplate): string { return FREQUENCY_LABELS[t.frequency] ?? t.frequency; }

  accountInactive(t: ExpenseTemplate): boolean { return t.account.status !== 'active'; }

  /** Information only (R7): strictly above the branch's current limit, in cents. */
  needsApproval(t: ExpenseTemplate): boolean {
    const limit = toCents(this.threshold());
    const amount = toCents(t.amount);
    return !t.archived_at && limit !== null && amount !== null && amount > limit;
  }

  load() {
    this.http.get<ExpenseTemplateList>('/api/v1/expense-templates', { params: { archived: 1 } }).subscribe({
      next: r => { this.all.set(r.data); this.threshold.set(r.threshold); this.loaded.set(true); this.error.set(''); },
      error: e => { this.error.set(errorText(e)); this.loaded.set(false); },
    });
  }

  /** F2 / F7: open the pre-filled form; in All branches mode switch to the template's branch first. */
  use(t: ExpenseTemplate) {
    if (this.auth.isAllBranches()) this.auth.switchBranch(String(t.branch.id));
    this.router.navigate(['/transactions/new/expense'], { queryParams: { template: t.id } });
  }

  @HostListener('document:keydown.escape')
  onEscape() {
    if (this.busy()) return;
    this.closeForm();
    this.archiveTarget.set(null);
  }

  openNew() {
    this.editing.set(null);
    this.methodTouched = false;
    this.openForm({ name: '', account_id: null, parent_id: null, category_id: null, amount: '', payee_payer: '', payment_method: 'cash',
      description: '', frequency: 'monthly' });
  }

  openEdit(t: ExpenseTemplate) {
    this.editing.set(t);
    this.methodTouched = true;
    this.openForm({ name: t.name, account_id: t.account.id, parent_id: t.category.parent_id, category_id: t.category.id, amount: t.amount ?? '',
      payee_payer: t.payee_payer ?? '', payment_method: t.payment_method ?? '', description: t.description ?? '', frequency: t.frequency });
  }

  private openForm(f: TemplateForm) {
    this.errors.set({});
    this.formError.set('');
    this.form.set(f);
    // R4: this branch's active cash, bank and wallet accounts; active expense sub-categories (global or this branch).
    this.http.get<Account[]>('/api/v1/accounts').subscribe(a => {
      this.accounts.set(a.filter(x => ['cash', 'bank', 'wallet'].includes(x.type) && (x.status ?? 'active') === 'active'));
      const form = this.form();
      if (form && form.account_id === null && this.accounts().length) {
        form.account_id = this.accounts()[0].id;
        this.accountChanged();
      }
    });
    this.http.get<Category[]>('/api/v1/categories', { params: { type: 'expense' } })
      .subscribe(c => this.categories.set(c.filter(p => (p.children ?? []).length > 0)));
  }

  subs(parentId: number | null): Category[] { return this.categories().find(c => c.id === parentId)?.children ?? []; }

  accountChanged() {
    const f = this.form();
    if (!f || this.methodTouched) return;
    f.payment_method = defaultPaymentMethod(this.accounts().find(a => a.id === f.account_id)?.type);
  }

  fieldError(k: string): string | null { return this.errors()[k]?.[0] ?? null; }

  closeForm() { this.form.set(null); this.editing.set(null); }

  saveForm() {
    const f = this.form();
    if (!f || this.busy()) return;
    this.busy.set(true);
    this.errors.set({});
    this.formError.set('');
    const body = { name: f.name, account_id: f.account_id, category_id: f.category_id, amount: String(f.amount ?? '').trim() || null,
      payee_payer: f.payee_payer, payment_method: f.payment_method || null, description: f.description, frequency: f.frequency };
    const t = this.editing();
    const req = t ? this.http.put(`/api/v1/expense-templates/${t.id}`, body) : this.http.post('/api/v1/expense-templates', body);
    req.subscribe({
      next: () => { this.busy.set(false); this.closeForm(); this.toast.show('Template saved.'); this.load(); },
      error: e => {
        this.busy.set(false);
        if (e?.status === 422 && e?.error?.errors) this.errors.set(e.error.errors);
        else this.formError.set(errorText(e));
      },
    });
  }

  archive(t: ExpenseTemplate) {
    if (this.busy()) return;
    this.busy.set(true);
    this.formError.set('');
    const reason = this.archiveReason.trim();
    this.http.post(`/api/v1/expense-templates/${t.id}/archive`, reason ? { reason } : {}).subscribe({
      next: () => { this.busy.set(false); this.archiveTarget.set(null); this.archiveReason = ''; this.toast.show('Template archived.'); this.load(); },
      error: e => { this.busy.set(false); this.formError.set(errorText(e)); },
    });
  }

  restore(t: ExpenseTemplate) {
    if (this.busy()) return;
    this.busy.set(true);
    this.error.set('');
    this.http.post(`/api/v1/expense-templates/${t.id}/restore`, {}).subscribe({
      next: () => { this.busy.set(false); this.toast.show('Template restored.'); this.load(); },
      error: e => { this.busy.set(false); this.error.set(errorText(e)); },
    });
  }
}
