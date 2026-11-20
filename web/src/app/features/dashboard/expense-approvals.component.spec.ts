import { ComponentFixture, TestBed } from '@angular/core/testing';
import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { provideRouter } from '@angular/router';
import { Component, Type } from '@angular/core';
import { AuthService } from '../../core/auth.service';
import { me } from '../../core/notifications.service.spec';
import { ToastService } from '../../core/toast.service';
import { phoneFrame } from '../../../testing/phone-frame';
import { ExpenseApprovals, ExpenseApprovalsComponent, ExpenseRow } from './expense-approvals.component';
import { EntryFormComponent } from '../transactions/entry-form.component';
import { BranchListComponent } from '../branches/branch-list.component';

@Component({ standalone: true, template: '' })
class BlankComponent {}

const KDY_ADMIN = { id: 2, code: 'KDY', name: 'Kandy', is_main: false, roles: ['Branch Admin'],
  permissions: ['transaction.view', 'expense.create', 'expense.approve'] };
const KDY_CASHIER = { id: 2, code: 'KDY', name: 'Kandy', is_main: false, roles: ['Cashier'], permissions: ['transaction.view', 'expense.create'] };

function setup(branch = KDY_ADMIN, superAdmin = false) {
  TestBed.configureTestingModule({
    providers: [provideHttpClient(), provideHttpClientTesting(), provideRouter([{ path: '**', component: BlankComponent }])],
  });
  const auth = TestBed.inject(AuthService);
  auth.token.set('token');
  auth.me.set(me([branch], superAdmin));
  auth.meAt = Date.now() + 60_000;
  auth.switchBranch('2');
}

function e1(over: Partial<ExpenseRow> = {}): ExpenseRow {
  return {
    id: 41, ref_no: 'KDY-EXP-2026-000456', branch: { id: 2, code: 'KDY' }, requested_date: '2026-11-18', requested_on: '2026-11-18',
    age_days: 2, expires_on: '2026-12-18', account: { id: 5, name: 'KDY Cash', available: '200000.00' },
    category_path: 'Utilities > Electricity and other very long sub-category names that must wrap', payee_payer: 'CEB',
    amount: '75000.00', other_pending_from_account: '0.00', files_count: 1, below_threshold_7d: { count: 0, amount: '0.00' },
    requested_by: { id: 9, name: 'KDY Cashier' }, requested_by_you: false, requester_inactive: false, requester_left_branch: false,
    super_admin_only: false, approver_onboarded_requester: false, requested_period_locked: false, can_approve: true, can_cancel: false, ...over,
  };
}

function widget(rows: ExpenseRow[], mine: ExpenseRow[] = []): ExpenseApprovals {
  return { threshold: '50000.00', negative_balance: 'block', to_approve: { data: rows, total: rows.length, amount: '75000.00' },
    my_requests: { data: mine, total: mine.length } };
}

function mount<T>(type: Type<T>): { fixture: ComponentFixture<T>; el: HTMLElement } {
  const fixture = TestBed.createComponent(type);
  const el = fixture.nativeElement as HTMLElement;
  document.body.appendChild(el);
  return { fixture, el };
}

function button(el: ParentNode, text: string): HTMLButtonElement {
  return Array.from(el.querySelectorAll<HTMLButtonElement>('button')).find(b => b.textContent!.trim() === text)!;
}

function type(el: HTMLElement, selector: string, value: string) {
  const input = el.querySelector<HTMLInputElement | HTMLTextAreaElement>(selector)!;
  input.value = value;
  input.dispatchEvent(new Event('input'));
}

/** AG-064 AC 36 (web) and AC 37 (390px). */
describe('AG-064 expense approvals (web)', () => {
  let http: HttpTestingController;
  let el: HTMLElement;

  afterEach(() => { el?.remove(); TestBed.inject(ToastService).clear(); });

  describe('S2 entry form', () => {
    async function form(superAdmin = false, threshold: string | null = '50000.00') {
      setup(KDY_CASHIER, superAdmin);
      http = TestBed.inject(HttpTestingController);
      const m = mount(EntryFormComponent);
      el = m.el;
      m.fixture.componentRef.setInput('kind', 'expense');
      m.fixture.detectChanges();
      await m.fixture.whenStable();
      http.match('/api/v1/branches').forEach(r => r.flush([{ id: 2, code: 'KDY', expense_approval_threshold: threshold }]));
      http.match('/api/v1/accounts').forEach(r => r.flush([]));
      http.match(r => r.url === '/api/v1/categories').forEach(r => r.flush([]));
      http.match('/api/v1/period-locks/status').forEach(r => r.flush({}));
      m.fixture.detectChanges();
      await m.fixture.whenStable();
      return m.fixture;
    }

    async function amount(fixture: ComponentFixture<unknown>, v: string) {
      type(el, 'input[name="amount"]', v);
      fixture.detectChanges();
      await fixture.whenStable();
      fixture.detectChanges();
    }

    it('AC 36: hint and "Send for approval" for 75,000.00 in KDY, not for 50,000.00', async () => {
      const f = await form();
      await amount(f, '75000');
      expect(el.textContent).toContain('this expense needs approval before it is posted. Do not pay until it is approved.');
      expect(el.textContent).toContain('50,000.00');
      expect(button(el, 'Send for approval')).toBeTruthy();
      await amount(f, '50000');
      expect(el.textContent).not.toContain('needs approval before it is posted');
      expect(button(el, 'Save expense')).toBeTruthy();
      await amount(f, '50000.01');
      expect(button(el, 'Send for approval')).toBeTruthy();
    });

    it('AC 36: never for the Super Admin, nor when the branch has no limit', async () => {
      let f = await form(true);
      await amount(f, '75000');
      expect(el.textContent).not.toContain('needs approval before it is posted');
      expect(button(el, 'Save expense')).toBeTruthy();
      el.remove();
      TestBed.resetTestingModule();
      f = await form(false, null);
      await amount(f, '75000');
      expect(button(el, 'Save expense')).toBeTruthy();
    });

    it('AC 37: at 390px the hint wraps inside the form, no horizontal scroll', async () => {
      const f = await form();
      const frame = await phoneFrame();
      frame.adopt(el);
      await amount(f, '75000');
      const hint = el.querySelector<HTMLElement>('.ap-hint')!;
      expect(hint).toBeTruthy();
      expect(hint.getBoundingClientRect().right).toBeLessThanOrEqual(390);
      expect(hint.getBoundingClientRect().height).toBeGreaterThan(20); // wraps to more than one line
      expect(frame.doc.documentElement.scrollWidth).toBeLessThanOrEqual(390);
      frame.remove();
    });
  });

  describe('S3 widget', () => {
    let fixture: ComponentFixture<ExpenseApprovalsComponent>;

    function start(rows = [e1()], mine: ExpenseRow[] = []) {
      setup();
      http = TestBed.inject(HttpTestingController);
      const m = mount(ExpenseApprovalsComponent);
      fixture = m.fixture;
      el = m.el;
      fixture.detectChanges();
      http.expectOne('/api/v1/expense-approvals').flush(widget(rows, mine));
      fixture.detectChanges();
    }

    async function settle() { fixture.detectChanges(); await fixture.whenStable(); fixture.detectChanges(); }

    it('AC 36: approves with the S3 dialog (text, paid checkbox) and says "<ref> approved and posted."', async () => {
      start();
      button(el.querySelector('.ea-table')!, 'Approve').click();
      await settle();
      const dlg = el.querySelector<HTMLElement>('[role="dialog"]')!;
      expect(dlg.textContent).toContain('Posts KDY-EXP-2026-000456 (75,000.00) from KDY Cash to Utilities > Electricity');
      expect(dlg.textContent).toContain('Funds and period locks are checked now.');
      expect(dlg.textContent).toContain('The bill was already paid before approval');
      expect(dlg.textContent).toContain('Comment (optional)');
      dlg.querySelector<HTMLInputElement>('input[type="checkbox"]')!.click();
      await settle();
      button(dlg, 'Approve').click();
      const req = http.expectOne('/api/v1/expense-approvals/41/approve');
      expect(req.request.body).toEqual({ paid_before_approval: true });
      req.flush({ ref_no: 'KDY-EXP-2026-000456' });
      http.match(() => true).forEach(r => r.flush(widget([])));
      await settle();
      expect(el.textContent).toContain('KDY-EXP-2026-000456 approved and posted.');
      expect(el.textContent).toContain('No expenses waiting for approval.');
    });

    it('AC 35c / MUST 4: with no file the approve comment is required and asks why', async () => {
      start([e1({ files_count: 0 })]);
      expect(el.textContent).toContain('No receipt');
      button(el.querySelector('.ea-table')!, 'Approve').click();
      await settle();
      const dlg = el.querySelector<HTMLElement>('[role="dialog"]')!;
      expect(dlg.textContent).toContain('Why is there no receipt?');
      expect(button(dlg, 'Approve').disabled).toBeTrue();
      type(dlg, 'textarea', 'Paid at the counter');
      await settle();
      expect(button(dlg, 'Approve').disabled).toBeFalse();
    });

    it('AC 36: rejects with a required reason and says "<ref> rejected."', async () => {
      start();
      button(el.querySelector('.ea-table')!, 'Reject').click();
      await settle();
      const dlg = el.querySelector<HTMLElement>('[role="dialog"]')!;
      expect(dlg.textContent).toContain('Reason, sent to the requester');
      expect(button(dlg, 'Reject').disabled).toBeTrue();
      type(dlg, 'textarea', 'Wrong category, record it again');
      await settle();
      button(dlg, 'Reject').click();
      const req = http.expectOne('/api/v1/expense-approvals/41/reject');
      expect(req.request.body).toEqual({ comment: 'Wrong category, record it again' });
      req.flush({ ref_no: 'KDY-EXP-2026-000456' });
      http.match(() => true).forEach(r => r.flush(widget([])));
      await settle();
      expect(el.textContent).toContain('KDY-EXP-2026-000456 rejected.');
    });

    it('S3: Super Admin only rows have the tag and no buttons; own rows say "Requested by you"', () => {
      start([e1({ super_admin_only: true, can_approve: false }), e1({ id: 42, ref_no: 'KDY-EXP-2026-000457', requested_by_you: true, can_approve: false })]);
      const table = el.querySelector('.ea-table')!;
      expect(table.textContent).toContain('Super Admin approval');
      expect(table.textContent).toContain('Requested by you');
      expect(button(table, 'Approve')).toBeUndefined();
    });

    it('AC 37: at 390px stacked cards, Approve / Reject at least 44px, dialog fits with a 16px gutter, no horizontal scroll', async () => {
      start([e1(), e1({ id: 42, ref_no: 'KDY-EXP-2026-000457', files_count: 0, below_threshold_7d: { count: 1, amount: '45000.00' } })]);
      const frame = await phoneFrame();
      frame.adopt(el);
      await settle();
      expect(frame.win.getComputedStyle(el.querySelector('.ea-table')!).display).toBe('none');
      const cards = el.querySelectorAll<HTMLElement>('.ea-card');
      expect(cards.length).toBe(2);
      cards.forEach(c => {
        expect(c.getBoundingClientRect().right).toBeLessThanOrEqual(390);
        const [approve, reject] = [button(c, 'Approve'), button(c, 'Reject')];
        expect(approve.getBoundingClientRect().height).toBeGreaterThanOrEqual(44);
        expect(reject.getBoundingClientRect().height).toBeGreaterThanOrEqual(44);
        expect(Math.abs(approve.getBoundingClientRect().top - reject.getBoundingClientRect().top)).toBeLessThan(2); // side by side
      });
      expect(frame.doc.documentElement.scrollWidth).toBeLessThanOrEqual(390);

      button(cards[0], 'Approve').click();
      await settle();
      const box = el.querySelector<HTMLElement>('[role="dialog"]')!.getBoundingClientRect();
      expect(box.left).toBeGreaterThanOrEqual(15.5);
      expect(390 - box.right).toBeGreaterThanOrEqual(15.5);
      expect(box.bottom).toBeLessThanOrEqual(844);
      expect(frame.doc.documentElement.scrollWidth).toBeLessThanOrEqual(390);
      frame.remove();
    });

    it('S4: my requests at 390px show Cancel request as a 44px button', async () => {
      setup(KDY_CASHIER);
      http = TestBed.inject(HttpTestingController);
      const m = mount(ExpenseApprovalsComponent);
      fixture = m.fixture;
      el = m.el;
      fixture.detectChanges();
      http.expectOne('/api/v1/expense-approvals').flush(widget([], [e1({ requested_by_you: true, can_approve: false, can_cancel: true })]));
      const frame = await phoneFrame();
      frame.adopt(el);
      await settle();
      expect(el.textContent).not.toContain('Expenses to approve');
      expect(el.textContent).toContain('My expense requests');
      const card = el.querySelector<HTMLElement>('.ea-mine .ea-card')!;
      expect(button(card, 'Cancel request').getBoundingClientRect().height).toBeGreaterThanOrEqual(44);
      expect(frame.doc.documentElement.scrollWidth).toBeLessThanOrEqual(390);
      frame.remove();
    });
  });

  describe('S1 branches', () => {
    it('AC 36: the Super Admin edit dialog has the limit field and shows the R18 warning after save', async () => {
      setup(KDY_ADMIN, true);
      http = TestBed.inject(HttpTestingController);
      const m = mount(BranchListComponent);
      el = m.el;
      const settle = async () => { m.fixture.detectChanges(); await m.fixture.whenStable(); m.fixture.detectChanges(); };
      m.fixture.detectChanges();
      const list = [{ id: 2, code: 'KDY', name: 'Kandy', is_main: false, status: 'active', balance: '200000.00', expense_approval_threshold: null }];
      http.expectOne('/api/v1/branches').flush(list);
      await settle();
      expect(el.textContent).toContain('Expense approval above');
      button(el, 'Edit').click();
      await settle();
      const dlg = el.querySelector<HTMLElement>('[role="dialog"]')!;
      expect(dlg.textContent).toContain('Expenses above this amount need approval');
      expect(dlg.textContent).toContain('Leave empty for never. 0 means every expense of this branch.');
      type(dlg, 'input[name="limit"]', '1.234');
      await settle();
      button(dlg, 'Save').click();
      await settle();
      expect(dlg.textContent).toContain('Enter an amount of 0 or more with up to 2 decimals');
      type(dlg, 'input[name="limit"]', '50000.00');
      await settle();
      button(dlg, 'Save').click();
      const put = http.expectOne(r => r.method === 'PUT' && r.url === '/api/v1/branches/2');
      expect(put.request.body).toEqual({ expense_approval_threshold: '50000.00' });
      put.flush({ id: 2, expense_approval_threshold: '50000.00', expense_approval_warning: "Only the Super Admin can approve KDY's expenses above the limit." });
      http.expectOne('/api/v1/branches').flush([{ ...list[0], expense_approval_threshold: '50000.00' }]);
      await settle();
      expect(el.querySelector('.warn')!.textContent).toContain("Only the Super Admin can approve KDY's expenses above the limit.");
      expect(el.querySelector('table')!.textContent).toContain('50,000.00');
    });
  });
});
