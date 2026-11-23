import { ComponentFixture, TestBed } from '@angular/core/testing';
import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { provideRouter } from '@angular/router';
import { Component, Type } from '@angular/core';
import { AuthService } from '../../core/auth.service';
import { me } from '../../core/notifications.service.spec';
import { colomboToday } from '../../core/dates';
import { Transaction } from '../../core/models';
import { lateEntryWarnings } from '../../core/reconciliation';
import { AllocationFormComponent } from './allocation-form.component';
import { TransferFormComponent } from './transfer-form.component';
import { TransactionListComponent } from '../transactions/transaction-list.component';

@Component({ standalone: true, template: '' })
class BlankComponent {}

const KDY = { id: 2, code: 'KDY', name: 'Kandy', is_main: false, roles: ['Branch Admin'],
  permissions: ['account.view', 'transaction.view', 'transfer.create', 'transfer.reverse', 'expense.void', 'income.void'] };

const WARN_KDY = 'KDY Bank is reconciled to 31 Oct 2026. This entry will be a late entry for that statement.';
const WARN_MAIN = 'Main Bank is reconciled to 30 Sep 2026. This entry will be a late entry for that statement.';

const MAIN_BANK = { id: 1, branch_id: 1, name: 'Main Bank', type: 'bank', current_balance: '900000.00', status: 'active',
  branch: { id: 1, code: 'MAIN', name: 'Main', is_main: true }, last_reconciled_date: '2026-09-30' };
const KDY_BANK = { id: 5, branch_id: 2, name: 'KDY Bank', type: 'bank', current_balance: '470000.00', status: 'active',
  branch: { id: 2, code: 'KDY', name: 'Kandy' }, last_reconciled_date: '2026-10-31' };
const KDY_CASH = { id: 6, branch_id: 2, name: 'KDY Cash', type: 'cash', current_balance: '1000.00', status: 'active',
  branch: { id: 2, code: 'KDY', name: 'Kandy' } };

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

function warnings(el: HTMLElement): string[] {
  return Array.from(el.querySelectorAll('.warn')).map(w => w.textContent!.trim());
}

/** AG-033 review finding 10 (follow-up): the late-entry warning on every money-posting form on a bank account. */
describe('AG-033 late-entry warning at posting time', () => {
  let http: HttpTestingController;

  it('lateEntryWarnings: one per reconciled bank account dated on or before its statement, the same account once', () => {
    expect(lateEntryWarnings([KDY_BANK, MAIN_BANK, KDY_BANK, KDY_CASH, null], '2026-09-30')).toEqual([WARN_KDY, WARN_MAIN]);
    expect(lateEntryWarnings([KDY_BANK, MAIN_BANK], '2026-10-31')).toEqual([WARN_KDY]);
    expect(lateEntryWarnings([KDY_BANK, MAIN_BANK], '2026-11-01')).toEqual([]);
    // No date from the API (another branch's account the user may not see): no warning.
    expect(lateEntryWarnings([{ ...KDY_BANK, last_reconciled_date: undefined }], '2026-10-01')).toEqual([]);
  });

  it('review nit 2: the same account counts once by id; two accounts with one name are told apart by branch code', () => {
    // Same id twice (one object from each list): one warning.
    expect(lateEntryWarnings([KDY_BANK, { ...KDY_BANK }], '2026-10-01')).toEqual([WARN_KDY]);
    // Main "BOC Current" -> KDY "BOC Current", both reconciled to the same date: two warnings.
    const mainBoc = { ...MAIN_BANK, id: 1, name: 'BOC Current', last_reconciled_date: '2026-10-31' };
    const kdyBoc = { ...KDY_BANK, id: 5, name: 'BOC Current' };
    expect(lateEntryWarnings([mainBoc, kdyBoc], '2026-10-01')).toEqual([
      'MAIN BOC Current is reconciled to 31 Oct 2026. This entry will be a late entry for that statement.',
      'KDY BOC Current is reconciled to 31 Oct 2026. This entry will be a late entry for that statement.',
    ]);
    // Transaction rows: no type, and no id on old payloads: still one warning per side.
    expect(lateEntryWarnings([{ name: 'BOC Current', last_reconciled_date: '2026-10-31', branch: { code: 'MAIN' } },
      { name: 'BOC Current', last_reconciled_date: '2026-10-31', branch: { code: 'KDY' } }], '2026-10-01').length).toBe(2);
  });

  it('shows the warning on the allocation form for two same-named accounts, once each', async () => {
    setup(true);
    http = TestBed.inject(HttpTestingController);
    const { fixture, el } = mount(AllocationFormComponent);
    fixture.detectChanges();
    http.expectOne('/api/v1/branches').flush([{ id: 1, is_main: true }, { id: 2, is_main: false }]);
    http.expectOne('/api/v1/accounts').flush([{ ...MAIN_BANK, name: 'BOC Current', last_reconciled_date: '2026-10-31' }, { ...KDY_BANK, name: 'BOC Current' }]);
    Object.assign(fixture.componentInstance.m, { from_account_id: 1, to_account_id: 5, txn_date: '2026-10-31' });
    fixture.detectChanges();
    expect(warnings(el)).toEqual([
      'MAIN BOC Current is reconciled to 31 Oct 2026. This entry will be a late entry for that statement.',
      'KDY BOC Current is reconciled to 31 Oct 2026. This entry will be a late entry for that statement.',
    ]);
  });

  describe('Fund a branch (Super Admin)', () => {
    async function form() {
      setup(true);
      http = TestBed.inject(HttpTestingController);
      const m = mount(AllocationFormComponent);
      m.fixture.detectChanges();
      http.expectOne('/api/v1/branches').flush([{ id: 1, is_main: true }, { id: 2, is_main: false }]);
      http.expectOne('/api/v1/accounts').flush([MAIN_BANK, KDY_BANK, KDY_CASH]);
      m.fixture.detectChanges();
      return m;
    }

    it('warns for both sides of a top-up dated on or before their statements, and still posts', async () => {
      const { fixture, el } = await form();
      const c = fixture.componentInstance;
      Object.assign(c.m, { kind: 'top_up', from_account_id: 1, to_account_id: 5, amount: '1000.00', txn_date: '2026-09-15' });
      fixture.detectChanges();
      expect(warnings(el)).toEqual([WARN_MAIN, WARN_KDY]);

      c.m.txn_date = '2026-10-31';
      fixture.detectChanges();
      expect(warnings(el)).toEqual([WARN_KDY]);

      c.m.txn_date = '2026-11-01';
      fixture.detectChanges();
      expect(warnings(el)).toEqual([]);

      // Never blocking: the button stays enabled and the post goes out with a warning showing.
      c.m.txn_date = '2026-10-01';
      fixture.detectChanges();
      expect(warnings(el)).toEqual([WARN_KDY]);
      expect(el.querySelector<HTMLButtonElement>('button[type=submit]')!.disabled).toBeFalse();
      c.save();
      expect(http.expectOne('/api/v1/allocations').request.body.txn_date).toBe('2026-10-01');
    });

    it('no warning for a cash account or an unreconciled bank account', async () => {
      const { fixture, el } = await form();
      Object.assign(fixture.componentInstance.m, { from_account_id: 1, to_account_id: 6, txn_date: '2026-10-01' });
      fixture.detectChanges();
      expect(warnings(el)).toEqual([]);
      http.match(() => true);
    });

    it('warns on the capital form for the Main bank account, and still posts', async () => {
      const { fixture, el } = await form();
      const c = fixture.componentInstance;
      Object.assign(c.c, { account_id: 1, amount: '5000.00', txn_date: '2026-09-30' });
      fixture.detectChanges();
      expect(warnings(el)).toEqual([WARN_MAIN]);
      c.c.txn_date = '2026-10-01';
      fixture.detectChanges();
      expect(warnings(el)).toEqual([]);
      c.c.txn_date = '2026-09-01';
      c.capital();
      expect(http.expectOne('/api/v1/capital').request.body.txn_date).toBe('2026-09-01');
    });
  });

  describe('Transfer money', () => {
    it('warns for the To account from the destinations list (fund return to Main) and never blocks', async () => {
      setup(false);
      http = TestBed.inject(HttpTestingController);
      const { fixture, el } = mount(TransferFormComponent);
      fixture.detectChanges();
      http.expectOne('/api/v1/accounts').flush([KDY_BANK, KDY_CASH]);
      http.expectOne('/api/v1/accounts/destinations').flush([MAIN_BANK, KDY_BANK, KDY_CASH]);
      http.match(() => true);
      const c = fixture.componentInstance;
      c.setFrom(6);
      c.toId.set(1);
      c.m.txn_date = '2026-09-30';
      fixture.detectChanges();
      expect(warnings(el)).toEqual([WARN_MAIN]);
      c.setFrom(5);
      c.toId.set(1);
      fixture.detectChanges();
      expect(warnings(el)).toEqual([WARN_KDY, WARN_MAIN]);
      c.m.txn_date = '2026-11-01';
      fixture.detectChanges();
      expect(warnings(el)).toEqual([]);
      c.m.txn_date = '2026-09-30';
      fixture.detectChanges();
      expect(el.querySelector<HTMLButtonElement>('button[type=submit]')!.disabled).toBeFalse();
    });
  });

  describe('Void and reverse dialogs (transactions list)', () => {
    function list() {
      setup(true);
      http = TestBed.inject(HttpTestingController);
      return mount(TransactionListComponent).fixture.componentInstance;
    }

    function row(over: Partial<Transaction>): Transaction {
      return { id: 41, ref_no: 'KDY-EXP-2026-000041', type: 'expense', txn_date: '2026-10-25', amount: '1000.00', status: 'posted', ...over } as Transaction;
    }

    it('void: warns when the entry date is on or before the bank account statement, and still voids', () => {
      const c = list();
      const prompt = spyOn(window, 'prompt').and.returnValue('Recorded twice by mistake');
      c.void(row({ account: { name: 'KDY Bank', last_reconciled_date: '2026-10-31' } }));
      expect(prompt.calls.mostRecent().args[0]).toContain(WARN_KDY);
      expect(http.expectOne('/api/v1/transactions/41/void').request.body).toEqual({ reason: 'Recorded twice by mistake' });
    });

    it('void: no warning after the statement date, for a cash account or without a date', () => {
      const c = list();
      const prompt = spyOn(window, 'prompt').and.returnValue(null);
      for (const t of [
        row({ txn_date: '2026-11-01', account: { name: 'KDY Bank', last_reconciled_date: '2026-10-31' } }),
        row({ account: { name: 'KDY Cash' } }),
        row({ account: { name: 'KDY Bank', last_reconciled_date: null } }),
      ]) {
        c.void(t);
        expect(prompt.calls.mostRecent().args[0]).not.toContain('late entry');
      }
      http.expectNone('/api/v1/transactions/41/void');
    });

    it('reverse: warns for each side reconciled to today or later (the reversal posts today), and still reverses', () => {
      const c = list();
      const today = colomboToday();
      const prompt = spyOn(window, 'prompt').and.returnValue('Sent to the wrong branch');
      c.reverse(row({ type: 'fund_allocation', ref_no: 'MAIN-TRF-2026-000007', txn_date: '2026-10-01',
        from_account: { name: 'Main Bank', last_reconciled_date: today },
        to_account: { name: 'KDY Bank', last_reconciled_date: today } }));
      const text = prompt.calls.mostRecent().args[0]!;
      expect(text).toContain(`Main Bank is reconciled to `);
      expect(text).toContain(`KDY Bank is reconciled to `);
      expect(http.expectOne('/api/v1/transactions/41/reverse').request.body).toEqual({ reason: 'Sent to the wrong branch' });
    });

    it('reverse: no warning when the last statement is before today', () => {
      const c = list();
      const prompt = spyOn(window, 'prompt').and.returnValue(null);
      c.reverse(row({ type: 'internal_transfer', from_account: { name: 'KDY Bank', last_reconciled_date: '2026-01-31' },
        to_account: { name: 'KDY Cash' } }));
      expect(prompt.calls.mostRecent().args[0]).not.toContain('late entry');
    });
  });
});
