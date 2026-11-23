import { ComponentFixture, TestBed } from '@angular/core/testing';
import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { Router, provideRouter } from '@angular/router';
import { Component } from '@angular/core';
import { AuthService } from '../../core/auth.service';
import { me } from '../../core/notifications.service.spec';
import { phoneFrame } from '../../../testing/phone-frame';
import { StatementCheck, checkStatusText } from '../../core/reconciliation';
import { bankCheckLines } from '../period-locks/period-locks.component';
import { BankReconciliationReportComponent, BankReport } from './bank-reconciliation.component';

@Component({ standalone: true, template: '' })
class BlankComponent {}

const VIEWER = { id: 2, code: 'KDY', name: 'Kandy', is_main: false, roles: ['Viewer'],
  permissions: ['account.view', 'transaction.view', 'report.view', 'account.reconcile_check'] };
const URL = '/api/v1/reports/bank-reconciliation';

function check(over: Partial<StatementCheck> = {}): StatementCheck {
  return {
    id: 7, bank_statement_id: 1, account_id: 5, branch: { id: 2, code: 'KDY' }, checked_by: { id: 3, name: 'VW', tag: null },
    checked_at: '2026-12-10T08:35:00+00:00', compared_with: 'internet_banking', note: 'Late cheque 25 Oct agreed to chq book', confirmed: true,
    changed_at_check: true, late_count_at_check: 1, checker_recorded_count: 0, checker_uncleared_count: 0, entries_count: 7, after_close: null,
    status: 'active', ended_by: null, ended_at: null, end_reason: null, superseded_by_id: null, ...over,
  };
}

function report(st: Partial<NonNullable<BankReport['statement']>> = {}): BankReport {
  return {
    company: 'Gold', currency: 'LKR', from: '2026-10-31', to: '2026-10-31', branches: [{ id: 2, code: 'KDY', name: 'Kandy', status: 'active' }],
    all_branches: false, subset: false, provisional: false, as_at: '2026-10-31', older_than: null, older_than_ignored: false, filter_note: null, basis: 'Basis',
    accounts: [{ account: { id: 5, code: '11002', name: 'KDY Bank', status: 'active', branch: { id: 2, code: 'KDY', name: 'Kandy' } }, last_statement: null,
      book_balance: '479500.00', uncleared_receipts: '0.00', uncleared_payments: '0.00', cleared_booked_later: '0.00', cleared_booked_later_rows: [],
      expected_bank_balance: '479500.00', rows: [], rows_filtered_out: 0, rows_not_shown: 0, empty_text: 'All reconciled.' }],
    statement: {
      id: 1, status: 'completed', statement_date: '2026-10-31', statement_balance: '479500.00', overdrawn: false, expected_bank_balance: '479500.00',
      difference: '0.00', changed_since_completion: true,
      late_entries: [{ entry_id: 40, ref_no: 'KDY-EXP-1', date: '2026-10-25', amount: '-1000.00', kind: 'recorded', by: 'ACC', at: '2026-11-05T04:30:00+00:00', after_check: false }],
      lines_count: 4, self_count: 2, completed_by: { id: 8, name: 'ACC', tag: null }, completed_at: '2026-11-03T04:30:00+00:00', undone: null,
      check_status: 'not_checked', checks: [], late_since_check: null, check_actions: { can_check: true, can_withdraw: false, blocked_reason: null },
      checker_attachment_ids: null, ...st,
    },
  } as BankReport;
}

async function settle(f: ComponentFixture<unknown>) {
  f.detectChanges();
  await f.whenStable();
  f.detectChanges();
}

function button(el: ParentNode, text: string): HTMLButtonElement | undefined {
  return Array.from(el.querySelectorAll<HTMLButtonElement>('button')).find(b => b.textContent!.trim() === text);
}

/** AG-033d S3 check block and dialogs (docs/specs/reconciliation-checked-by.md AC 7, 24, 26, 30, 31 on the web side). */
describe('AG-033d checked by (web)', () => {
  let http: HttpTestingController;
  let el: HTMLElement;
  let fixture: ComponentFixture<BankReconciliationReportComponent>;

  afterEach(() => { el?.remove(); });

  async function open(data: BankReport) {
    TestBed.configureTestingModule({
      providers: [provideHttpClient(), provideHttpClientTesting(), provideRouter([{ path: '**', component: BlankComponent }])],
    });
    const auth = TestBed.inject(AuthService);
    auth.token.set('token');
    auth.me.set(me([VIEWER]));
    auth.meAt = Date.now() + 60_000;
    auth.switchBranch('2');
    http = TestBed.inject(HttpTestingController);
    await TestBed.inject(Router).navigateByUrl('/reports/bank-reconciliation?statement_id=1');
    fixture = TestBed.createComponent(BankReconciliationReportComponent);
    el = fixture.nativeElement as HTMLElement;
    document.body.appendChild(el);
    fixture.detectChanges();
    http.match(r => r.url === '/api/v1/accounts').forEach(r => r.flush([]));
    http.expectOne(r => r.url === URL).flush(data);
    await settle(fixture);
  }

  it('AC 31: the dialog hint and confirmation wording; AC 5: the note is required with late entries', async () => {
    await open(report());
    expect(el.querySelector('.ck-block')!.textContent).toContain('Not checked in the system.');
    button(el, 'Record check')!.click();
    await settle(fixture);
    const dlg = el.querySelector('.ck-sheet')!;
    const text = dlg.textContent!.replace(/\s+/g, ' ');
    expect(text).toContain("Not the uploaded copy alone. Use the bank's balance at the end of 2026-10-31, not today's balance.");
    expect(text).toContain("I agreed the statement balance (479,500.00) to the bank's own record of the balance at the end of 2026-10-31, and I checked that the uncleared items on this statement are genuine");
    expect(text).toContain('Explain the late entries you checked.');
    const save = button(dlg, 'Save')!;
    expect(save.disabled).toBeTrue();
    fixture.componentInstance.comparedWith.set('internet_banking');
    fixture.componentInstance.confirmed.set(true);
    fixture.componentInstance.note.set('ok');
    await settle(fixture);
    expect(save.disabled).toBeTrue();
    fixture.componentInstance.note.set('Late cheque 25 Oct agreed to chq book');
    await settle(fixture);
    expect(save.disabled).toBeFalse();
    save.click();
    const req = http.expectOne('/api/v1/accounts/5/reconciliation/statements/1/checks');
    expect(req.request.body).toEqual({ compared_with: 'internet_banking', confirmed: true, note: 'Late cheque 25 Oct agreed to chq book' });
    req.flush({ message: 'You completed this statement. Someone else must check it.' }, { status: 403, statusText: 'Forbidden' });
    await settle(fixture);
    expect(el.querySelector('.ck-sheet .alert')!.textContent).toContain('You completed this statement. Someone else must check it.');
    expect(el.querySelector('.ck-sheet')).not.toBeNull();
  });

  it('AC 7: a preparer sees the disabled button with the reason; AC 24: the active check block', async () => {
    await open(report({ check_actions: { can_check: false, can_withdraw: false, blocked_reason: 'You completed this statement. Someone else must check it.' } }));
    expect(button(el, 'Record check')!.disabled).toBeTrue();
    expect(el.querySelector('.ck-why')!.textContent).toContain('You completed this statement. Someone else must check it.');
    el.remove();
    TestBed.resetTestingModule();

    await open(report({ check_status: 'checked', checks: [check()], late_since_check: 0,
      check_actions: { can_check: false, can_withdraw: true, blocked_reason: 'This statement is already checked.' } }));
    const block = el.querySelector('.ck-block')!.textContent!.replace(/\s+/g, ' ');
    expect(block).toContain('Checked by VW on 2026-12-10 14:05 (Asia/Colombo) against internet banking.');
    expect(block).toContain('Note: Late cheque 25 Oct agreed to chq book');
    expect(block).toContain('changed since completion when checked');
    expect(button(el, 'Withdraw check')).toBeDefined();
  });

  it('AC 30: at 390px the dialog is a full-screen sheet with 44px targets and a sticky Save bar; no horizontal scroll', async () => {
    await open(report());
    const frame = await phoneFrame();
    frame.adopt(el);
    await settle(fixture);
    const win = frame.win;
    const rec = button(el, 'Record check')!;
    expect(rec.getBoundingClientRect().height).toBeGreaterThanOrEqual(44);
    rec.click();
    await settle(fixture);
    const sheet = el.querySelector<HTMLElement>('.ck-sheet')!;
    const r = sheet.getBoundingClientRect();
    expect(Math.round(r.width)).toBe(frame.doc.documentElement.clientWidth); // the full viewport (less a classic scrollbar)
    expect(Math.round(r.top)).toBe(0);
    expect(win.getComputedStyle(el.querySelector('.ck-sheet-head')!).position).toBe('sticky');
    expect(el.querySelector<HTMLElement>('.ck-close')!.getBoundingClientRect().height).toBeGreaterThanOrEqual(44);
    for (const opt of Array.from(el.querySelectorAll<HTMLElement>('.ck-opt'))) {
      expect(opt.getBoundingClientRect().height).toBeGreaterThanOrEqual(44);
      expect(opt.getBoundingClientRect().width).toBeGreaterThanOrEqual(44);
    }
    const bar = el.querySelector<HTMLElement>('.ck-sheet-bar')!;
    expect(win.getComputedStyle(bar).position).toBe('sticky');
    const save = button(bar, 'Save')!;
    expect(save.getBoundingClientRect().height).toBeGreaterThanOrEqual(44);
    expect(Math.round(save.getBoundingClientRect().width)).toBeGreaterThan(300); // full width
    expect(frame.doc.documentElement.scrollWidth).toBeLessThanOrEqual(390);
    frame.remove();
  });

  it('AC 30: at 360px the check block stacks and the page does not scroll sideways', async () => {
    await open(report({ check_status: 'recheck', late_since_check: 1, checks: [check({ checker_recorded_count: 2, entries_count: 7, checker_uncleared_count: 1,
      after_close: 'close', note: 'A very long note without spaces: ' + 'x'.repeat(200) })],
      check_actions: { can_check: true, can_withdraw: true, blocked_reason: null } }));
    const frame = await phoneFrame(360);
    frame.adopt(el);
    await settle(fixture);
    expect(frame.win.getComputedStyle(el.querySelector('.ck-l2')!).display).toBe('block');
    expect(frame.win.getComputedStyle(el.querySelector('.ck-tags')!).flexWrap).toBe('wrap');
    expect(button(el, 'Re-check')!.getBoundingClientRect().height).toBeGreaterThanOrEqual(44);
    expect(el.querySelector('.ck-block')!.textContent).toContain('Re-check needed: 1 entry was recorded or voided after the check.');
    expect(frame.doc.documentElement.scrollWidth).toBeLessThanOrEqual(360);
    frame.remove();
  });

  it('AC 22 / AC 26 texts: list status and lock confirm lines', () => {
    const c = { id: 7, checked_by: { id: 3, name: 'VW', tag: null }, checked_at: '2026-12-10T08:35:00+00:00', compared_with: 'internet_banking' as const,
      after_close: null, late_since_check: 1 };
    expect(checkStatusText('checked', c)).toBe('Checked by VW, 2026-12-10');
    expect(checkStatusText('recheck', c)).toBe('Re-check needed (1 since check)');
    expect(checkStatusText('not_checked', null)).toBe('Not checked');
    expect(checkStatusText('cancelled', null)).toBe('Check cancelled');
    expect(checkStatusText(null, null)).toBe('');
    const item = (id: number, d: string) => ({ statement_id: id, account: { id: 5, code: '11002', name: 'KDY Bank' }, statement_date: d });
    expect(bankCheckLines([{ branch: { id: 2, code: 'KDY' }, not_checked: [item(3, '2026-11-30')], recheck: [item(1, '2026-10-31')] }])).toEqual([
      'KDY: 1 completed bank reconciliation not checked (KDY Bank 30 Nov 2026)',
      'KDY: 1 checked bank reconciliation needs a re-check (KDY Bank 31 Oct 2026)',
    ]);
    const many = [1, 2, 3, 4, 5, 6, 7].map(i => item(i, `2026-0${i}-28`));
    expect(bankCheckLines([{ branch: { id: 2, code: 'KDY' }, not_checked: many, recheck: [] }])[0]).toContain('and 2 more)');
    expect(bankCheckLines([{ branch: { id: 2, code: 'KDY' }, not_checked: [], recheck: [] }])).toEqual([]);
  });
});
