import { ComponentFixture, TestBed } from '@angular/core/testing';
import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { provideRouter } from '@angular/router';
import { Component, Type } from '@angular/core';
import { AuthService } from '../../core/auth.service';
import { me } from '../../core/notifications.service.spec';
import { ToastService } from '../../core/toast.service';
import { phoneFrame } from '../../../testing/phone-frame';
import { centsText, ReconcileComponent, ReconLine, Reconciliation, signedCents } from './reconcile.component';
import { AccountListComponent } from './account-list.component';

@Component({ standalone: true, template: '' })
class BlankComponent {}

const ACCOUNTANT = { id: 2, code: 'KDY', name: 'Kandy', is_main: false, roles: ['Accountant'],
  permissions: ['account.view', 'account.manage', 'account.reconcile', 'report.view', 'transaction.view'] };
const CASHIER = { id: 2, code: 'KDY', name: 'Kandy', is_main: false, roles: ['Cashier'], permissions: ['account.view', 'transaction.view'] };
const VIEWER = { id: 2, code: 'KDY', name: 'Kandy', is_main: false, roles: ['Viewer'], permissions: ['account.view', 'transaction.view', 'report.view'] };

function setup(branch: typeof ACCOUNTANT) {
  TestBed.configureTestingModule({
    providers: [provideHttpClient(), provideHttpClientTesting(), provideRouter([{ path: '**', component: BlankComponent }])],
  });
  const auth = TestBed.inject(AuthService);
  auth.token.set('token');
  auth.me.set(me([branch]));
  auth.meAt = Date.now() + 60_000;
  auth.switchBranch('2');
}

function line(over: Partial<ReconLine> = {}): ReconLine {
  return { entry_id: 1, transaction_id: 11, date: '2026-10-05', ref_no: 'KDY-INC-2026-000101', type: 'income', status: 'posted',
    reversal_of_ref: null, description: 'Customer deposit with a long description that must wrap on a phone', reference: 'DEP-1',
    in: '100000.00', out: '0.00', mark: null, frozen: null, self: false, cleared_before_booked: false, ...over };
}

function recon(over: Partial<Reconciliation> = {}): Reconciliation {
  return {
    account: { id: 5, code: 'B1', name: 'BOC Current', status: 'active', branch: { id: 2, code: 'KDY', name: 'Kandy' } },
    last_statement: { id: 3, statement_date: '2026-01-31', statement_balance: '50000.00' },
    book_balance_at: '130000.00', reconciled_at: '50000.00',
    lines: [
      line(),
      line({ entry_id: 2, transaction_id: 12, ref_no: 'KDY-EXP-2026-000102', type: 'expense', in: '0.00', out: '20000.00', description: 'Cheque 1001' }),
      line({ entry_id: 3, transaction_id: 13, ref_no: 'KDY-INC-2026-000050', date: '2026-01-20', in: '50000.00',
        mark: { cleared_date: '2026-01-25', marked_by: { id: 7, name: 'M. Silva', tag: null }, marked_at: '2026-02-01T10:00:00Z' },
        frozen: { statement_id: 3, statement_date: '2026-01-31' } }),
    ],
    late_entries_count: 0, statements: [], ...over,
  };
}

function mount<T>(type: Type<T>): { fixture: ComponentFixture<T>; el: HTMLElement } {
  const fixture = TestBed.createComponent(type);
  const el = fixture.nativeElement as HTMLElement;
  document.body.appendChild(el);
  return { fixture, el };
}

function button(el: ParentNode, text: string): HTMLButtonElement {
  return Array.from(el.querySelectorAll<HTMLButtonElement>('button')).find(b => b.textContent!.trim().startsWith(text))!;
}

async function settle(f: ComponentFixture<unknown>) {
  f.detectChanges();
  await f.whenStable();
  f.detectChanges();
}

/** AG-033 S1 (docs/specs/bank-reconciliation.md): AC 2, AC 17, AC 34, AC 36 on the web side. */
describe('AG-033 bank reconciliation (web)', () => {
  let http: HttpTestingController;
  let el: HTMLElement;

  afterEach(() => { el?.remove(); TestBed.inject(ToastService).clear(); });

  async function screen(data = recon()) {
    setup(ACCOUNTANT);
    http = TestBed.inject(HttpTestingController);
    const m = mount(ReconcileComponent);
    el = m.el;
    m.fixture.componentRef.setInput('id', '5');
    m.fixture.detectChanges();
    http.match('/api/v1/accounts').forEach(r => r.flush([{ id: 5, name: 'BOC Current', type: 'bank', branch: { id: 2, code: 'KDY' } }]));
    http.expectOne(r => r.url === '/api/v1/accounts/5/reconciliation').flush(data);
    await settle(m.fixture);
    return m.fixture;
  }

  async function typeIn(f: ComponentFixture<unknown>, selector: string, value: string) {
    const input = el.querySelector<HTMLInputElement>(selector)!;
    input.value = value;
    input.dispatchEvent(new Event('input'));
    await settle(f);
  }

  function boxes(): HTMLInputElement[] {
    return Array.from(el.querySelectorAll<HTMLInputElement>('.rc-table .rc-check input[type="checkbox"]'));
  }

  it('money helpers work in integer cents, signed', () => {
    expect(signedCents('125000.5')).toBe(12500050);
    expect(signedCents('-2500.00')).toBe(-250000);
    expect(signedCents('1.234')).toBeNull();
    expect(signedCents('abc')).toBeNull();
    expect(centsText(-250000)).toBe('-2,500.00');
  });

  it('summary: ticking moves the reconciled balance and the difference reaches zero (R2)', async () => {
    const f = await screen();
    const c = f.componentInstance as ReconcileComponent;
    await typeIn(f, 'input[name="statement_balance"]', '130000.00');
    expect(c.reconciled()).toBe(5_000_000);
    expect(c.difference()).toBe(8_000_000);
    expect(button(el, 'Complete reconciliation').disabled).toBeTrue();

    boxes()[0].click(); // +100,000.00 receipt
    await settle(f);
    expect(c.difference()).toBe(-2_000_000);
    boxes()[1].click(); // -20,000.00 payment
    await settle(f);
    expect(c.reconciled()).toBe(13_000_000);
    expect(c.difference()).toBe(0);
    expect(el.querySelector('.rc-diff')!.classList).toContain('ok');
    expect(c.changes().count).toBe(2);
    expect(button(el, 'Save').textContent).toContain('(2)');
    expect(button(el, 'Complete reconciliation').disabled).toBeFalse();
  });

  it('AC 36: a negative statement balance is echoed as "Overdrawn"', async () => {
    const f = await screen();
    await typeIn(f, 'input[name="statement_balance"]', '-2500.00');
    expect(el.querySelector('.rc-od')!.textContent!.trim()).toBe('Overdrawn 2,500.00');
    await typeIn(f, 'input[name="statement_balance"]', '2500.00');
    expect(el.querySelector('.rc-od')).toBeNull();
    await typeIn(f, 'input[name="statement_balance"]', '25.001');
    expect(el.textContent).toContain('Enter the statement balance (for example 125000.00 or -2500.00).');
  });

  it('AC 17: a line of a completed statement is ticked, disabled and explains why', async () => {
    await screen();
    const frozen = boxes()[2];
    expect(frozen.checked).toBeTrue();
    expect(frozen.disabled).toBeTrue();
    expect(frozen.closest('label')!.getAttribute('title')).toBe('Reconciled in the statement of 31 Jan 2026. Undo that statement first.');
    expect(boxes()[0].disabled).toBeFalse();
    expect(boxes()[0].closest('label')!.getAttribute('title')).toBe('');
  });

  it('AC 34: at 390px cards, 44px checkboxes, sticky difference and action bar, no horizontal scroll', async () => {
    const f = await screen();
    const frame = await phoneFrame();
    frame.adopt(el);
    await settle(f);
    const win = frame.win;
    expect(win.getComputedStyle(el.querySelector('.rc-table')!).display).toBe('none');
    expect(win.getComputedStyle(el.querySelector('.rc-cards')!).display).toBe('block');
    const checks = Array.from(el.querySelectorAll<HTMLElement>('.rc-cards .rc-check'));
    expect(checks.length).toBe(3);
    for (const ch of checks) {
      const r = ch.getBoundingClientRect();
      expect(r.width).toBeGreaterThanOrEqual(44);
      expect(r.height).toBeGreaterThanOrEqual(44);
    }
    const summary = win.getComputedStyle(el.querySelector('.rc-summary')!);
    expect(summary.position).toBe('sticky');
    expect(summary.top).toBe('0px');
    const diff = el.querySelector<HTMLElement>('.rc-diff')!;
    expect(win.getComputedStyle(diff).order).toBe('-1'); // the Difference tile comes first, full width
    const bar = el.querySelector<HTMLElement>('.rc-bar')!;
    expect(win.getComputedStyle(bar).position).toBe('sticky');
    expect(win.getComputedStyle(bar).bottom).toBe('0px');
    expect(button(bar, 'Save')).toBeTruthy();
    expect(button(bar, 'Complete reconciliation')).toBeTruthy();
    expect(button(bar, 'Save').getBoundingClientRect().height).toBeGreaterThanOrEqual(44);
    expect(frame.doc.documentElement.scrollWidth).toBeLessThanOrEqual(390);
    frame.remove();
  });

  describe('AC 2: the Reconcile action needs account.reconcile', () => {
    async function list(branch: typeof ACCOUNTANT) {
      setup(branch);
      http = TestBed.inject(HttpTestingController);
      const m = mount(AccountListComponent);
      el = m.el;
      m.fixture.detectChanges();
      http.expectOne('/api/v1/accounts').flush([
        { id: 5, name: 'BOC Current', type: 'bank', status: 'active', current_balance: '130000.00', branch: { id: 2, code: 'KDY' } },
        { id: 6, name: 'Cash', type: 'cash', status: 'active', current_balance: '1000.00', branch: { id: 2, code: 'KDY' } },
      ]);
      await settle(m.fixture);
    }
    const links = () => Array.from(el.querySelectorAll('a')).filter(a => a.textContent!.trim() === 'Reconcile');

    it('shown on bank accounts for the Accountant', async () => {
      await list(ACCOUNTANT);
      expect(links().length).toBe(1);
    });

    it('hidden for the Cashier and the Viewer', async () => {
      await list(CASHIER);
      expect(links().length).toBe(0);
      el.remove();
      TestBed.resetTestingModule();
      await list(VIEWER);
      expect(links().length).toBe(0);
    });
  });
});
