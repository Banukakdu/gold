import { ComponentFixture, TestBed } from '@angular/core/testing';
import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { provideRouter } from '@angular/router';
import { Component, Type } from '@angular/core';
import { AuthService } from '../../core/auth.service';
import { me } from '../../core/notifications.service.spec';
import { colomboToday } from '../../core/dates';
import { dayLabel } from '../../core/reconciliation';
import { ApprovalDialogComponent, ApprovalTarget } from '../../core/approvals';
import { TransferApprovalsComponent } from './transfer-approvals.component';
import { ExpenseApprovals, ExpenseApprovalsComponent, ExpenseRow } from './expense-approvals.component';
import { TransactionDetailComponent } from '../transactions/transaction-detail.component';

@Component({ standalone: true, template: '' })
class BlankComponent {}

const KDY = { id: 2, code: 'KDY', name: 'Kandy', is_main: false, roles: ['Branch Admin'],
  permissions: ['account.view', 'transaction.view', 'expense.create', 'expense.approve'] };

/** The approval posts on today's Colombo date (R6): a statement dated today makes it a late entry, an older one not. */
const TODAY = colomboToday();
const OLDER = '2000-01-31';
const warn = (name: string) => `${name} is reconciled to ${dayLabel(TODAY)}. This entry will be a late entry for that statement.`;

function setup(superAdmin: boolean) {
  TestBed.configureTestingModule({
    providers: [provideHttpClient(), provideHttpClientTesting(), provideRouter([{ path: '**', component: BlankComponent }])],
  });
  const auth = TestBed.inject(AuthService);
  auth.token.set('token');
  auth.me.set(me([KDY], superAdmin));
  auth.meAt = Date.now() + 60_000;
  auth.switchBranch(superAdmin ? 'all' : '2');
}

function mount<T>(type: Type<T>): { fixture: ComponentFixture<T>; el: HTMLElement } {
  const fixture = TestBed.createComponent(type);
  return { fixture, el: fixture.nativeElement as HTMLElement };
}

function dialog(el: HTMLElement): HTMLElement { return el.querySelector<HTMLElement>('.ap-dialog')!; }

function warnings(el: HTMLElement): string[] {
  return Array.from(dialog(el).querySelectorAll('p.warn')).map(w => w.textContent!.trim());
}

function approveButton(el: HTMLElement): HTMLButtonElement {
  return Array.from(dialog(el).querySelectorAll<HTMLButtonElement>('button')).find(b => b.textContent!.trim() === 'Approve')!;
}

function transferRow(from: string | null | undefined, to: string | null | undefined) {
  const side = (code: string, account: string, d: string | null | undefined) => d === undefined ? { branch_code: code, account }
    : { branch_code: code, account, last_reconciled_date: d };
  return {
    id: 41, ref_no: 'KDY-TRF-2026-000012', type: 'branch_transfer', requested_date: '2026-11-18', requested_on: '2026-11-18', age_days: 2,
    expires_on: '2026-12-18', from: { ...side('KDY', 'KDY Bank', from), available: '479500.00' }, to: side('MAIN', 'Main Bank', to),
    amount: '150000.00', other_pending_from_account: '0.00', below_threshold_7d: { count: 0, amount: '0.00' }, note: null,
    requested_by: { id: 9, name: 'KDY Accountant' }, requested_by_you: false, requester_inactive: false, can_approve: true, can_cancel: false,
  };
}

function expenseRow(d: string | null | undefined): ExpenseRow {
  return {
    id: 42, ref_no: 'KDY-EXP-2026-000456', branch: { id: 2, code: 'KDY' }, requested_date: '2026-11-18', requested_on: '2026-11-18',
    age_days: 2, expires_on: '2026-12-18', account: { id: 5, name: 'KDY Bank', available: '479500.00', ...(d === undefined ? {} : { last_reconciled_date: d }) },
    category_path: 'Utilities > Electricity', payee_payer: 'CEB', amount: '75000.00', other_pending_from_account: '0.00', files_count: 1,
    below_threshold_7d: { count: 0, amount: '0.00' }, requested_by: { id: 9, name: 'KDY Cashier' }, requested_by_you: false,
    requester_inactive: false, requester_left_branch: false, super_admin_only: false, approver_onboarded_requester: false,
    requested_period_locked: false, can_approve: true, can_cancel: false,
  };
}

/** AG-033 review finding 10 (sign-off row 10): the late-entry warning in the AG-051 / AG-064 approve dialogs. */
describe('AG-033 late-entry warning in the approve dialogs', () => {
  let http: HttpTestingController;

  describe('Transfers to approve (AG-051)', () => {
    async function open(from: string | null | undefined, to: string | null | undefined) {
      setup(true);
      http = TestBed.inject(HttpTestingController);
      const m = mount(TransferApprovalsComponent);
      m.fixture.detectChanges();
      http.expectOne('/api/v1/transfer-approvals').flush({ threshold: '100000.00',
        to_approve: { data: [transferRow(from, to)], total: 1, amount: '150000.00' }, my_requests: { data: [], total: 0 } });
      m.fixture.detectChanges();
      Array.from(m.el.querySelectorAll<HTMLButtonElement>('button')).find(b => b.textContent!.trim() === 'Approve')!.click();
      m.fixture.detectChanges();
      return m;
    }

    it('warns for both sides reconciled to today (the posting date), and still approves', async () => {
      const { fixture, el } = await open(TODAY, TODAY);
      expect(warnings(el)).toEqual([warn('KDY Bank'), warn('Main Bank')]);
      expect(approveButton(el).disabled).toBeFalse();
      approveButton(el).click();
      fixture.detectChanges();
      const req = http.expectOne('/api/v1/transfer-approvals/41/approve');
      expect(req.request.method).toBe('POST');
      expect(req.request.body.posting_date).toBeUndefined();
    });

    it('only the side the API sent a date for; none for an older statement or no date', async () => {
      let m = await open(undefined, TODAY);
      expect(warnings(m.el)).toEqual([warn('Main Bank')]);
      TestBed.resetTestingModule();
      m = await open(OLDER, null);
      expect(warnings(m.el)).toEqual([]);
      expect(approveButton(m.el).disabled).toBeFalse();
    });
  });

  describe('Expenses to approve (AG-064)', () => {
    async function open(d: string | null | undefined) {
      setup(false);
      http = TestBed.inject(HttpTestingController);
      const m = mount(ExpenseApprovalsComponent);
      m.fixture.detectChanges();
      const body: ExpenseApprovals = { threshold: '50000.00', negative_balance: 'block',
        to_approve: { data: [expenseRow(d)], total: 1, amount: '75000.00' }, my_requests: { data: [], total: 0 } };
      http.expectOne('/api/v1/expense-approvals').flush(body);
      m.fixture.detectChanges();
      Array.from(m.el.querySelectorAll<HTMLButtonElement>('button')).find(b => b.textContent!.trim() === 'Approve')!.click();
      m.fixture.detectChanges();
      return m;
    }

    it('warns when the bank account is reconciled to today, and still approves', async () => {
      const { fixture, el } = await open(TODAY);
      expect(warnings(el)).toEqual([warn('KDY Bank')]);
      approveButton(el).click();
      fixture.detectChanges();
      expect(http.expectOne('/api/v1/expense-approvals/42/approve').request.method).toBe('POST');
    });

    it('no warning for an older statement or without a date', async () => {
      let m = await open(OLDER);
      expect(warnings(m.el)).toEqual([]);
      TestBed.resetTestingModule();
      m = await open(undefined);
      expect(warnings(m.el)).toEqual([]);
    });
  });

  describe('Transaction detail approve action', () => {
    function detailTarget(detail: object): ApprovalTarget {
      setup(true);
      const c = TestBed.createComponent(TransactionDetailComponent).componentInstance;
      return c.approvalTarget(detail as Parameters<typeof c.approvalTarget>[0]);
    }

    function dialogFor(target: ApprovalTarget) {
      const m = mount(ApprovalDialogComponent);
      m.fixture.componentRef.setInput('target', target);
      m.fixture.componentRef.setInput('mode', 'approve');
      m.fixture.detectChanges();
      return m;
    }

    it('transfer: both sides go to the dialog', () => {
      const target = detailTarget({ id: 41, ref_no: 'KDY-TRF-2026-000012', type: 'branch_transfer', amount: '150000.00', requested_date: '2026-11-18',
        from_account: { id: 5, name: 'KDY Bank', type: 'bank', branch: { code: 'KDY' }, last_reconciled_date: TODAY },
        to_account: { id: 1, name: 'Main Bank', type: 'bank', branch: { code: 'MAIN' }, last_reconciled_date: TODAY } });
      const { el } = dialogFor(target);
      expect(Array.from(el.querySelectorAll('p.warn')).map(w => w.textContent!.trim())).toEqual([warn('KDY Bank'), warn('Main Bank')]);
    });

    it('expense: the paying account; no date, no warning', () => {
      const target = detailTarget({ id: 42, ref_no: 'KDY-EXP-2026-000456', type: 'expense', amount: '75000.00', requested_date: '2026-11-18',
        branch: { code: 'KDY', name: 'Kandy' }, account: { id: 5, name: 'KDY Bank', type: 'bank', last_reconciled_date: TODAY } });
      let m = dialogFor(target);
      expect(Array.from(m.el.querySelectorAll('p.warn')).map(w => w.textContent!.trim())).toEqual([warn('KDY Bank')]);
      const none = { ...target, accounts: [{ id: 5, name: 'KDY Bank', type: 'bank' }] };
      m = dialogFor(none);
      expect(m.el.querySelectorAll('p.warn').length).toBe(0);
    });
  });
});
