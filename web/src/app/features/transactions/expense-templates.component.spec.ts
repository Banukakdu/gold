import { ComponentFixture, TestBed } from '@angular/core/testing';
import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { ActivatedRoute, Router, convertToParamMap, provideRouter } from '@angular/router';
import { Component, Provider, Type } from '@angular/core';
import { AuthService } from '../../core/auth.service';
import { me } from '../../core/notifications.service.spec';
import { ToastService } from '../../core/toast.service';
import { colomboToday } from '../../core/dates';
import { BranchAccess } from '../../core/models';
import { phoneFrame } from '../../../testing/phone-frame';
import { ExpenseTemplate, ExpenseTemplateList, approvalTemplateLines } from '../../core/expense-templates';
import { ExpenseTemplatesComponent } from './expense-templates.component';
import { EntryFormComponent } from './entry-form.component';
import { TransactionDetailComponent } from './transaction-detail.component';
import { ExpenseApprovals, ExpenseApprovalsComponent, ExpenseRowWithTemplate } from '../dashboard/expense-approvals.component';

@Component({ standalone: true, template: '' })
class BlankComponent {}

const KDY = { id: 2, code: 'KDY', name: 'Kandy', is_main: false };
const CASHIER: BranchAccess = { ...KDY, roles: ['Cashier'], permissions: ['transaction.view', 'account.view', 'expense.create'] };
const ACCOUNTANT: BranchAccess = { ...KDY, roles: ['Accountant'], permissions: ['transaction.view', 'account.view', 'expense.create', 'expense.edit'] };
const BRANCH_ADMIN: BranchAccess = { ...KDY, roles: ['Branch Admin'], permissions: ['transaction.view', 'expense.create', 'expense.approve'] };

const ACCOUNTS = [
  { id: 11, branch_id: 2, name: 'Bank', type: 'bank', current_balance: '400000.00', status: 'active' },
  { id: 12, branch_id: 2, name: 'Cash', type: 'cash', current_balance: '200000.00', status: 'active' },
];
const CATEGORIES = [
  { id: 30, name: 'Rent', type: 'expense', children: [{ id: 31, name: 'Office rent', type: 'expense' }] },
  { id: 40, name: 'Utilities', type: 'expense', children: [{ id: 41, name: 'Electricity', type: 'expense' }] },
];

function rent(over: Partial<ExpenseTemplate> = {}): ExpenseTemplate {
  return {
    id: 7, branch: { id: 2, code: 'KDY' }, name: 'Rent - Kandy office', version: 1, account: { id: 11, name: 'Bank', status: 'active' },
    category: { id: 31, parent_id: 30, path: 'Rent > Office rent', active: true }, amount: '150000.00', payee_payer: 'S. Fernando (landlord)',
    payment_method: 'bank_transfer', description: 'Monthly office rent', frequency: 'monthly', archived_at: null, archived_by: null,
    archive_reason: null, created_by: { id: 3, name: 'KDY Accountant' }, this_period: { label: 'November 2026', transactions: [] },
    last_used_on: null, changed_since_last_use: false, last_changed: null, can_use: true, can_edit: false, ...over,
  };
}

function elec(over: Partial<ExpenseTemplate> = {}): ExpenseTemplate {
  return rent({ id: 8, name: 'Electricity - Kandy', account: { id: 12, name: 'Cash', status: 'active' },
    category: { id: 41, parent_id: 40, path: 'Utilities > Electricity', active: true }, amount: null, payee_payer: 'Ceylon Electricity Board',
    payment_method: 'cash', description: 'Monthly bill', ...over });
}

function list(data: ExpenseTemplate[], threshold: string | null = '50000.00'): ExpenseTemplateList {
  return { threshold, archived_count: data.filter(t => t.archived_at).length, data };
}

function setup(branch: BranchAccess | null, superAdmin = false, active = '2', providers: Provider[] = []) {
  TestBed.configureTestingModule({
    providers: [provideHttpClient(), provideHttpClientTesting(), provideRouter([{ path: '**', component: BlankComponent }]), ...providers],
  });
  const auth = TestBed.inject(AuthService);
  auth.token.set('token');
  auth.me.set(me(branch ? [branch] : [{ ...KDY, roles: [], permissions: ['*'] }], superAdmin));
  auth.meAt = Date.now() + 60_000;
  auth.switchBranch(active);
}

function mount<T>(type: Type<T>): { fixture: ComponentFixture<T>; el: HTMLElement } {
  const fixture = TestBed.createComponent(type);
  const el = fixture.nativeElement as HTMLElement;
  document.body.appendChild(el);
  return { fixture, el };
}

function button(el: ParentNode, text: string): HTMLButtonElement | undefined {
  return Array.from(el.querySelectorAll<HTMLButtonElement>('button')).find(b => b.textContent!.trim() === text);
}

async function settle(f: ComponentFixture<unknown>) { f.detectChanges(); await f.whenStable(); f.detectChanges(); }

/** AG-066 (docs/specs/recurring-expenses.md) web: AC 10, 11, 14, 28a, 33 and 34. */
describe('AG-066 expense templates (web)', () => {
  let http: HttpTestingController;
  let el: HTMLElement;

  afterEach(() => { el?.remove(); TestBed.inject(ToastService).clear(); });

  describe('S2 Record expense', () => {
    async function form(templates: ExpenseTemplate[], query: Record<string, string> = {}, branch = CASHIER) {
      setup(branch, false, '2', [{ provide: ActivatedRoute, useValue: { snapshot: { queryParamMap: convertToParamMap(query) } } }]);
      http = TestBed.inject(HttpTestingController);
      const m = mount(EntryFormComponent);
      el = m.el;
      m.fixture.componentRef.setInput('kind', 'expense');
      m.fixture.detectChanges();
      await m.fixture.whenStable();
      http.match('/api/v1/branches').forEach(r => r.flush([{ id: 2, code: 'KDY', expense_approval_threshold: '50000.00' }]));
      http.match('/api/v1/accounts').forEach(r => r.flush(ACCOUNTS));
      http.match(r => r.url === '/api/v1/categories').forEach(r => r.flush(CATEGORIES));
      http.match('/api/v1/expense-templates').forEach(r => r.flush(list(templates)));
      http.match('/api/v1/period-locks/status').forEach(r => r.flush({}));
      await settle(m.fixture);
      return m.fixture;
    }

    const value = (name: string) => el.querySelector<HTMLInputElement | HTMLSelectElement>(`[name="${name}"]`)!.value;
    const selectedText = (name: string) => {
      const s = el.querySelector<HTMLSelectElement>(`select[name="${name}"]`)!;
      return s.selectedIndex >= 0 ? s.options[s.selectedIndex].textContent!.trim() : '';
    };

    it('AC 10: ?template= pre-fills everything but the date and reference, with the info line and "Send for approval"', async () => {
      const f = await form([rent()], { template: '7' });
      expect(selectedText('account_id')).toContain('Bank');
      expect(selectedText('parent')).toBe('Rent');
      expect(selectedText('category_id')).toBe('Office rent');
      expect(value('amount')).toBe('150000.00');
      expect(value('payee_payer')).toBe('S. Fernando (landlord)');
      expect(selectedText('payment_method')).toBe('Bank transfer');
      expect(value('description')).toBe('Monthly office rent');
      expect(value('txn_date')).toBe(colomboToday());
      expect(value('reference')).toBe('');
      expect(el.querySelectorAll('.att-picked li').length).toBe(0);
      expect(el.textContent).toContain('Filled from template Rent - Kandy office. Check the date, amount and reference against the bill before saving.');
      expect(button(el, 'Send for approval')).toBeTruthy();
      expect(selectedText('template')).toContain('Rent - Kandy office');

      el.querySelector<HTMLInputElement>('[name="reference"]')!.value = 'INV-11';
      el.querySelector<HTMLInputElement>('[name="reference"]')!.dispatchEvent(new Event('input'));
      el.querySelector<HTMLFormElement>('form')!.dispatchEvent(new Event('submit'));
      f.detectChanges();
      const req = http.expectOne(r => r.method === 'POST' && r.url === '/api/v1/expenses');
      expect(req.request.body).toEqual(jasmine.objectContaining({ template_id: 7, account_id: 11, category_id: 31, amount: '150000.00', reference: 'INV-11',
        payee_payer: 'S. Fernando (landlord)', payment_method: 'bank_transfer', description: 'Monthly office rent', txn_date: colomboToday() }));
      expect(req.request.body.confirm_duplicate).toBeUndefined();
    });

    it('S2: an unknown or archived template id says so and leaves the form blank', async () => {
      await form([rent()], { template: '99' });
      expect(el.textContent).toContain('This template cannot be used (archived or not in this branch).');
      expect(value('payee_payer')).toBe('');
    });

    it('AC 11: T-ELEC leaves Amount empty and focused; replacing typed values asks first', async () => {
      const f = await form([rent(), elec()]);
      const payee = el.querySelector<HTMLInputElement>('[name="payee_payer"]')!;
      payee.value = 'Someone';
      payee.dispatchEvent(new Event('input'));
      await settle(f);
      const confirm = spyOn(window, 'confirm').and.returnValue(false);
      const pick = el.querySelector<HTMLSelectElement>('select[name="template"]')!;
      pick.value = '8';
      pick.dispatchEvent(new Event('change'));
      await settle(f);
      expect(confirm).toHaveBeenCalledWith('Replace what you typed with the template?');
      expect(value('payee_payer')).toBe('Someone');
      expect(pick.value).toBe('');

      confirm.and.returnValue(true);
      pick.value = '8';
      pick.dispatchEvent(new Event('change'));
      await settle(f);
      await new Promise(r => setTimeout(r));
      expect(value('payee_payer')).toBe('Ceylon Electricity Board');
      expect(value('amount')).toBe('');
      expect(document.activeElement).toBe(el.querySelector('[name="amount"]'));
      expect(button(el, 'Save expense')).toBeTruthy();
    });

    it('F5 / R5: an inactive account or category is left empty with its message; S2 shows the R6 and MUST 2 lines', async () => {
      await form([rent({ account: { id: 99, name: 'Old Bank', status: 'inactive' }, category: { id: 31, parent_id: 30, path: 'Rent > Office rent', active: false },
        this_period: { label: 'November 2026', transactions: [{ id: 510, ref_no: 'KDY-EXP-2026-000510', date: '2026-11-20', status: 'posted', status_label: 'Posted' }] },
        changed_since_last_use: true, version: 2, last_changed: { on: '2026-11-19', by: { id: 3, name: 'KDY Accountant' } } })], { template: '7' });
      expect(el.textContent).toContain("The template's account Old Bank is inactive. Choose another account.");
      expect(el.textContent).toContain("The template's category Rent > Office rent is inactive. Choose another category.");
      expect(el.querySelector<HTMLSelectElement>('select[name="account_id"]')!.selectedIndex).toBe(-1);
      expect(el.querySelector<HTMLSelectElement>('select[name="category_id"]')!.selectedIndex).toBe(-1);
      expect(el.textContent).toContain('Already used for November 2026: KDY-EXP-2026-000510 (Posted, 2026-11-20).');
      expect(el.textContent).toContain('This template was changed on');
      expect(el.textContent).toContain('by KDY Accountant since it was last used.');
    });

    it('AC 33: a 409 shows the message and "Save anyway", which saves with confirm_duplicate on the second press', async () => {
      const f = await form([rent()], { template: '7' });
      el.querySelector<HTMLFormElement>('form')!.dispatchEvent(new Event('submit'));
      f.detectChanges();
      const msg = 'This template was already used for November 2026: KDY-EXP-2026-000510 (Pending approval, 2026-11-20). Save anyway only if this is a separate bill.';
      http.expectOne(r => r.method === 'POST' && r.url === '/api/v1/expenses').flush({ message: msg, duplicate_of: [] }, { status: 409, statusText: 'Conflict' });
      await settle(f);
      expect(el.querySelector('.tpl-dup')!.textContent).toContain(msg);
      button(el, 'Save anyway')!.click();
      f.detectChanges();
      const req = http.expectOne(r => r.method === 'POST' && r.url === '/api/v1/expenses');
      expect(req.request.body).toEqual(jasmine.objectContaining({ template_id: 7, confirm_duplicate: true }));
      req.flush({ id: 511, ref_no: 'KDY-EXP-2026-000511', status: 'pending', hold_reason: 'threshold' });
      http.match(() => true).forEach(r => r.flush([]));
      await settle(f);
      expect(el.querySelector('.tpl-dup')).toBeNull();
      expect(el.textContent).not.toContain('Filled from template');
    });

    it('AC 34: at 390px the picker is full width and the 409 alert and "Save anyway" wrap, no horizontal scroll', async () => {
      const f = await form([rent()], { template: '7' });
      const frame = await phoneFrame();
      frame.adopt(el);
      el.querySelector<HTMLFormElement>('form')!.dispatchEvent(new Event('submit'));
      f.detectChanges();
      http.expectOne(r => r.method === 'POST').flush({ message: 'This template was already used for November 2026: KDY-EXP-2026-000510 (Pending approval, 2026-11-20). Save anyway only if this is a separate bill.' },
        { status: 409, statusText: 'Conflict' });
      await settle(f);
      const pick = el.querySelector<HTMLSelectElement>('select[name="template"]')!.getBoundingClientRect();
      const formBox = el.querySelector('form')!.getBoundingClientRect();
      expect(pick.width).toBeGreaterThan(formBox.width - 40);
      const alert = el.querySelector<HTMLElement>('.tpl-dup')!.getBoundingClientRect();
      expect(alert.right).toBeLessThanOrEqual(390);
      expect(alert.height).toBeGreaterThan(40);
      const anyway = button(el, 'Save anyway')!.getBoundingClientRect();
      expect(anyway.height).toBeGreaterThanOrEqual(44);
      expect(anyway.width).toBeGreaterThan(alert.width - 40);
      expect(frame.doc.documentElement.scrollWidth).toBeLessThanOrEqual(390);
      frame.remove();
    });
  });

  describe('S1 Expense templates', () => {
    function start(branch: BranchAccess | null, data: ExpenseTemplate[], superAdmin = false, active = '2') {
      setup(branch, superAdmin, active);
      http = TestBed.inject(HttpTestingController);
      const m = mount(ExpenseTemplatesComponent);
      el = m.el;
      m.fixture.detectChanges();
      http.expectOne(r => r.url === '/api/v1/expense-templates').flush(list(data, active === 'all' ? null : '50000.00'));
      m.fixture.detectChanges();
      return m.fixture;
    }

    it('AC 33: the Cashier sees Use but no New / Edit / Archive; tags and This period', () => {
      start(CASHIER, [rent({ this_period: { label: 'November 2026', transactions: [{ id: 510, ref_no: 'KDY-EXP-2026-000510', date: '2026-11-20', status: 'pending', status_label: 'Pending approval' }] },
        last_used_on: '2026-11-20' }), elec({ account: { id: 12, name: 'Cash', status: 'inactive' } })]);
      const table = el.querySelector('.tpl-table')!;
      expect(button(el, 'New template')).toBeUndefined();
      expect(button(table, 'Use')).toBeTruthy();
      expect(button(table, 'Edit')).toBeUndefined();
      expect(button(table, 'Archive')).toBeUndefined();
      expect(table.textContent).toContain('Needs approval (above 50,000.00)');
      expect(table.textContent).toContain('Used: KDY-EXP-2026-000510');
      expect(table.textContent).toContain('Pending approval');
      expect(table.textContent).toContain('Not used yet (November 2026)');
      expect(table.textContent).toContain('Enter each time');
      expect(table.textContent).toContain('Account inactive');
      expect(table.textContent).toContain('Never');
    });

    it('AC 33: the Accountant sees New / Edit / Archive; the dialog defaults the payment method by account type (SHOULD 7)', async () => {
      const f = start(ACCOUNTANT, [rent({ can_edit: true })]);
      const table = el.querySelector('.tpl-table')!;
      expect(button(el, 'New template')).toBeTruthy();
      expect(button(table, 'Edit')).toBeTruthy();
      expect(button(table, 'Archive')).toBeTruthy();
      button(el, 'New template')!.click();
      await settle(f);
      http.expectOne('/api/v1/accounts').flush(ACCOUNTS);
      http.expectOne(r => r.url === '/api/v1/categories').flush(CATEGORIES);
      await settle(f);
      const dlg = el.querySelector<HTMLElement>('[role="dialog"]')!;
      expect(dlg.textContent).toContain('A template only fills in the expense form. Nothing is posted until someone saves the expense.');
      const method = () => dlg.querySelector<HTMLSelectElement>('select[name="payment_method"]')!.value;
      expect(method()).toBe('bank_transfer'); // first account: Bank
      const acc = dlg.querySelector<HTMLSelectElement>('select[name="account_id"]')!;
      acc.value = acc.options[1].value; // Cash
      acc.dispatchEvent(new Event('change'));
      await settle(f);
      expect(method()).toBe('cash');
      expect(dlg.querySelector<HTMLSelectElement>('select[name="frequency"]')!.value).toBe('monthly');

      const name = dlg.querySelector<HTMLInputElement>('[name="name"]')!;
      name.value = 'Rent - Kandy office';
      name.dispatchEvent(new Event('input'));
      button(dlg, 'Save')!.click();
      await settle(f);
      const post = http.expectOne(r => r.method === 'POST' && r.url === '/api/v1/expense-templates');
      expect(post.request.body).toEqual(jasmine.objectContaining({ name: 'Rent - Kandy office', account_id: 12, payment_method: 'cash', amount: null, frequency: 'monthly' }));
      post.flush({ message: 'A template named Rent - Kandy office already exists in this branch.',
        errors: { name: ['A template named Rent - Kandy office already exists in this branch.'] } }, { status: 422, statusText: 'Unprocessable' });
      await settle(f);
      expect(dlg.querySelector('.field-error')!.textContent).toContain('already exists in this branch');
    });

    it('AC 33: the archive dialog and its toast', async () => {
      const f = start(ACCOUNTANT, [rent({ can_edit: true })]);
      button(el.querySelector('.tpl-table')!, 'Archive')!.click();
      await settle(f);
      const dlg = el.querySelector<HTMLElement>('[role="dialog"]')!;
      expect(dlg.textContent).toContain('Archive Rent - Kandy office?');
      expect(dlg.textContent).toContain('It can no longer be used, but expenses already recorded from it are not changed.');
      button(dlg, 'Archive')!.click();
      http.expectOne('/api/v1/expense-templates/7/archive').flush({});
      http.expectOne(r => r.url === '/api/v1/expense-templates').flush(list([]));
      await settle(f);
      expect(TestBed.inject(ToastService).text()).toBe('Template archived.');
      expect(el.textContent).toContain('No expense templates yet.');
    });

    it('AC 33: Super Admin in All mode sees the Branch column and "Switch to KDY and use", which switches and opens the form', async () => {
      const f = start(null, [rent({ can_edit: false })], true, 'all');
      const table = el.querySelector('.tpl-table')!;
      expect(table.querySelector('thead')!.textContent).toContain('Branch');
      expect(button(el, 'New template')).toBeUndefined();
      expect(button(table, 'Edit')).toBeUndefined();
      const nav = spyOn(TestBed.inject(Router), 'navigate').and.resolveTo(true);
      button(table, 'Switch to KDY and use')!.click();
      await settle(f);
      expect(TestBed.inject(AuthService).activeBranchId()).toBe('2');
      expect(nav).toHaveBeenCalledWith(['/transactions/new/expense'], { queryParams: { template: 7 } });
    });

    it('AC 34: at 390px stacked cards with a 44px Use, the dialog fits with a 16px gutter in one column, no horizontal scroll', async () => {
      const f = start(ACCOUNTANT, [rent({ can_edit: true, name: 'Rent - Kandy office with a very long name that must wrap inside the card' }), elec({ can_edit: true })]);
      const frame = await phoneFrame();
      frame.adopt(el);
      await settle(f);
      expect(frame.win.getComputedStyle(el.querySelector('.tpl-table')!).display).toBe('none');
      const cards = el.querySelectorAll<HTMLElement>('.tpl-card');
      expect(cards.length).toBe(2);
      cards.forEach(c => {
        expect(c.getBoundingClientRect().right).toBeLessThanOrEqual(390);
        expect(button(c, 'Use')!.getBoundingClientRect().height).toBeGreaterThanOrEqual(44);
        expect(c.querySelector<HTMLElement>('.tpl-more summary')!.getBoundingClientRect().height).toBeGreaterThanOrEqual(44);
      });
      const newBtn = button(el, 'New template')!.getBoundingClientRect();
      const search = el.querySelector<HTMLInputElement>('input[type="search"]')!.getBoundingClientRect();
      expect(newBtn.bottom).toBeLessThanOrEqual(search.top + 1); // the toolbar stacks
      expect(newBtn.height).toBeGreaterThanOrEqual(44);
      expect(frame.doc.documentElement.scrollWidth).toBeLessThanOrEqual(390);

      button(el, 'New template')!.click();
      await settle(f);
      http.expectOne('/api/v1/accounts').flush(ACCOUNTS);
      http.expectOne(r => r.url === '/api/v1/categories').flush(CATEGORIES);
      await settle(f);
      const dlg = el.querySelector<HTMLElement>('[role="dialog"]')!;
      const box = dlg.getBoundingClientRect();
      expect(box.left).toBeGreaterThanOrEqual(15.5);
      expect(390 - box.right).toBeGreaterThanOrEqual(15.5);
      expect(box.bottom).toBeLessThanOrEqual(844);
      const [name, amount] = [dlg.querySelector('[name="name"]')!.getBoundingClientRect(), dlg.querySelector('[name="amount"]')!.getBoundingClientRect()];
      expect(Math.abs(name.left - amount.left)).toBeLessThan(2); // one column
      expect(frame.win.getComputedStyle(dlg).overflowY).toBe('auto');
      expect(frame.doc.documentElement.scrollWidth).toBeLessThanOrEqual(390);
      frame.remove();
    });
  });

  describe('S3 / S5 approver lines (review MUST 2)', () => {
    const facts = {
      template: { id: 7, name: 'Rent - Kandy office', version: 2 }, template_changes: ['amount', 'payee_payer'],
      template_values: { amount: '150000.00', payee_payer: 'S. Fernando (landlord)' }, template_changed_since_last_use: true,
      template_previous_version: 1, template_changed_on: '2026-11-19', template_changed_by: 'KDY Accountant', duplicate_confirmed: true,
      duplicate_of: ['KDY-EXP-2026-000510'], duplicate_period: 'November 2026',
      duplicate_entries: [{ id: 510, ref_no: 'KDY-EXP-2026-000510', date: '2026-11-20', status: 'pending' }],
    };

    it('builds the R7a lines', () => {
      expect(approvalTemplateLines(facts).map(l => l.text)).toEqual([
        'From template Rent - Kandy office',
        'Changed from template: Amount (template 150,000.00), Paid to (template S. Fernando (landlord))',
        'Template changed since its last use (version 1 -> 2, 2026-11-19 by KDY Accountant)',
        'Second use for November 2026, confirmed by the requester: KDY-EXP-2026-000510 (Pending approval, 2026-11-20)',
      ]);
      expect(approvalTemplateLines(facts)[3].warn).toBeTrue();
      expect(approvalTemplateLines({ template: null })).toEqual([]);
    });

    it('AC 28a (web): the widget row and the approve dialog show the R7a lines', async () => {
      setup(BRANCH_ADMIN);
      http = TestBed.inject(HttpTestingController);
      const m = mount(ExpenseApprovalsComponent);
      el = m.el;
      m.fixture.detectChanges();
      const row = {
        id: 511, ref_no: 'KDY-EXP-2026-000511', branch: { id: 2, code: 'KDY' }, requested_date: '2026-11-25', requested_on: '2026-11-25', age_days: 0,
        expires_on: '2026-12-25', account: { id: 11, name: 'Bank', available: '400000.00' }, category_path: 'Rent > Office rent', payee_payer: 'S. Fernando',
        amount: '140000.00', other_pending_from_account: '0.00', files_count: 1, below_threshold_7d: { count: 0, amount: '0.00' },
        requested_by: { id: 9, name: 'KDY Cashier' }, requested_by_you: false, requester_inactive: false, requester_left_branch: false,
        super_admin_only: false, approver_onboarded_requester: false, requested_period_locked: false, can_approve: true, can_cancel: false, ...facts,
      } as ExpenseRowWithTemplate;
      const w: ExpenseApprovals = { threshold: '50000.00', negative_balance: 'block', to_approve: { data: [row], total: 1, amount: '140000.00' },
        my_requests: { data: [], total: 0 } };
      http.expectOne('/api/v1/expense-approvals').flush(w);
      await settle(m.fixture);
      const table = el.querySelector('.ea-table')!;
      expect(table.textContent).toContain('From template Rent - Kandy office');
      expect(table.textContent).toContain('Second use for November 2026, confirmed by the requester');
      expect(el.querySelector('.ea-cards')!.textContent).toContain('Changed from template: Amount (template 150,000.00)');
      button(table, 'Approve')!.click();
      await settle(m.fixture);
      const dlg = el.querySelector<HTMLElement>('[role="dialog"]')!;
      expect(dlg.textContent).toContain('From template Rent - Kandy office');
      expect(dlg.textContent).toContain('Template changed since its last use (version 1 -> 2');
      expect(dlg.querySelector('.tpl-facts li.warn')!.textContent).toContain('Second use for November 2026');
    });

    it('AC 14 / 21 (web): the detail shows the template, the changes and "(archived)"', async () => {
      setup(ACCOUNTANT, false, '2', [{ provide: ActivatedRoute, useValue: { snapshot: { paramMap: convertToParamMap({ id: '511' }) } } }]);
      http = TestBed.inject(HttpTestingController);
      const m = mount(TransactionDetailComponent);
      el = m.el;
      m.fixture.detectChanges();
      http.expectOne(r => r.url === '/api/v1/transactions/511').flush({
        id: 511, ref_no: 'KDY-EXP-2026-000511', type: 'expense', status: 'posted', txn_date: '2026-11-25', amount: '140000.00',
        branch: { code: 'KDY', name: 'Kandy' }, account: { name: 'Bank' }, category: { name: 'Office rent', parent: { name: 'Rent' } },
        creator: { name: 'KDY Cashier' }, entries: [], expense_template: { id: 7, name: 'Rent - Kandy office', archived: true }, ...facts,
      });
      http.match(() => true).forEach(r => r.flush([]));
      await settle(m.fixture);
      const detail = el.querySelector('.tpl-detail')!.textContent!;
      expect(detail).toContain('Rent - Kandy office');
      expect(detail).toContain('Archived');
      expect(detail).toContain('Changed from template: Amount (template 150,000.00), Paid to');
      expect(detail).toContain('Template changed since its last use');
      expect(detail).toContain('Second use for November 2026 confirmed: KDY-EXP-2026-000510');
    });
  });
});
