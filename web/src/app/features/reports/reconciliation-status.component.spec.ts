import { ComponentFixture, TestBed } from '@angular/core/testing';
import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, TestRequest, provideHttpClientTesting } from '@angular/common/http/testing';
import { ActivatedRouteSnapshot, CanActivateFn, Route, Router, RouterStateSnapshot, UrlTree, provideRouter } from '@angular/router';
import { routes } from '../../app.routes';
import { Component } from '@angular/core';
import { AuthService } from '../../core/auth.service';
import { me } from '../../core/notifications.service.spec';
import { phoneFrame } from '../../../testing/phone-frame';
import { ReconciliationStatusComponent, StatusReport, StatusRow, checkLine, statusTag, summaryText } from './reconciliation-status.component';
import { ReconciliationStatementsComponent, StatementList } from './reconciliation-statements.component';
import { ReportsHubComponent } from './reports-hub.component';

@Component({ standalone: true, template: '' })
class BlankComponent {}

const VIEWER = { id: 2, code: 'KDY', name: 'Kandy', is_main: false, roles: ['Viewer'], permissions: ['account.view', 'transaction.view', 'report.view'] };
const ACCOUNTANT = { id: 2, code: 'KDY', name: 'Kandy', is_main: false, roles: ['Accountant'],
  permissions: ['account.view', 'account.reconcile', 'report.view', 'report.export', 'transaction.view'] };
const CASHIER = { id: 2, code: 'KDY', name: 'Kandy', is_main: false, roles: ['Cashier'], permissions: ['transaction.view', 'transaction.create'] };
const GALLE = { id: 3, code: 'GAL', name: 'Galle', is_main: false, roles: ['Viewer'], permissions: ['report.view'] };
const URL = '/api/v1/reports/reconciliation-status';

function setup(branches: typeof VIEWER[], active = '2', superAdmin = false) {
  TestBed.configureTestingModule({
    providers: [provideHttpClient(), provideHttpClientTesting(), provideRouter([{ path: '**', component: BlankComponent }])],
  });
  const auth = TestBed.inject(AuthService);
  auth.token.set('token');
  auth.me.set(me(branches, superAdmin));
  auth.meAt = Date.now() + 60_000;
  auth.switchBranch(active);
}

function row(over: Partial<StatusRow> = {}): StatusRow {
  return {
    account: { id: 5, code: '11002', name: 'KDY Bank', status: 'active' }, branch: { id: 2, code: 'KDY', is_main: false },
    status: 'up_to_date', not_required_reason: null, never_reconciled: false, months_overdue: 0, final_month_end: null, due_by: null,
    last_statement: { id: 3, statement_date: '2026-11-30', completed_by: { id: 9, name: 'BA', tag: null }, completed_at: '2026-12-03T04:30:00+00:00' },
    check_status: 'not_checked', check: null, recheck_needed: false, ...over,
  };
}

const BANK5 = row({ account: { id: 9, code: '11006', name: 'KDY Bank 5', status: 'active' }, status: 'overdue', months_overdue: 3,
  last_statement: { id: 7, statement_date: '2026-09-15', completed_by: { id: 8, name: 'ACC', tag: null }, completed_at: '2026-12-10T04:30:00+00:00' } });
const BANK2 = row({ account: { id: 6, code: '11003', name: 'KDY Bank 2', status: 'active' }, status: 'overdue', months_overdue: 1, never_reconciled: true,
  last_statement: null, check_status: null });
const KDY = row();
const BANK3 = row({ account: { id: 7, code: '11004', name: 'KDY Bank 3', status: 'active' }, status: 'not_required', not_required_reason: 'no_entries',
  months_overdue: null, never_reconciled: true, last_statement: null, check_status: null });
const BANK4 = row({ account: { id: 8, code: '11005', name: 'KDY Bank 4', status: 'closed' }, status: 'not_required', not_required_reason: 'closed_nil',
  months_overdue: null, final_month_end: '2026-06-30',
  last_statement: { id: 6, statement_date: '2026-06-30', completed_by: { id: 8, name: 'ACC', tag: null }, completed_at: '2026-12-10T04:30:00+00:00' },
  check_status: 'checked', check: { id: 2, checked_by: { id: 4, name: 'VW', tag: null }, checked_at: '2026-12-10T04:30:00+00:00', late_since_check: 0 } });

function report(over: Partial<StatusReport> = {}): StatusReport {
  return {
    company: 'Gold', currency: 'LKR', from: '2026-12-15', to: '2026-12-15', branches: [{ id: 2, code: 'KDY', name: 'Kandy', status: 'active' }],
    all_branches: false, subset: false, provisional: false, as_at: '2026-12-15', grace_days: 10, due_month_end: '2026-11-30', overdue_through: '2026-11-30',
    basis: "As at 15 Dec 2026. A month's bank reconciliation is due within 10 days after month-end. Overdue counts the month-ends after the last completed statement, up to 30 Nov 2026. Undone statements do not count.",
    filters: { status: 'all' }, summary: { overdue: 2, due: 0, up_to_date: 1, not_required: 2, not_checked: 2, recheck: 0 },
    data: [BANK5, BANK2, KDY, BANK3, BANK4], ...over,
  };
}

async function settle(f: ComponentFixture<unknown>) {
  f.detectChanges();
  await f.whenStable();
  f.detectChanges();
}

function button(el: ParentNode, text: string): HTMLButtonElement | undefined {
  return Array.from(el.querySelectorAll<HTMLButtonElement>('button')).find(b => b.textContent!.trim().startsWith(text));
}

function link(el: ParentNode, text: string): HTMLAnchorElement[] {
  return Array.from(el.querySelectorAll<HTMLAnchorElement>('a')).filter(a => a.textContent!.trim() === text);
}

/** AG-033e S7 (docs/specs/reconciliation-overdue.md): AC 1, 2, 8, 9, 11, 12, 14, 18, 19 on the web side. */
describe('AG-033e bank reconciliation status (web)', () => {
  let http: HttpTestingController;
  let el: HTMLElement;
  let fixture: ComponentFixture<ReconciliationStatusComponent>;

  afterEach(() => { el?.remove(); });

  async function open(data: StatusReport | null = report(), branch = VIEWER, url = '/reports/reconciliation-status'): Promise<TestRequest | null> {
    setup([branch, GALLE], String(branch.id));
    http = TestBed.inject(HttpTestingController);
    await TestBed.inject(Router).navigateByUrl(url);
    fixture = TestBed.createComponent(ReconciliationStatusComponent);
    el = fixture.nativeElement as HTMLElement;
    document.body.appendChild(el);
    fixture.detectChanges();
    const req = http.expectOne(r => r.url === URL);
    if (data === null) return req;
    req.flush(data);
    await settle(fixture);
    return null;
  }

  function rows(): string[] {
    return Array.from(el.querySelectorAll('.rst-table tbody tr.rst-row')).map(r => r.textContent!.replace(/\s+/g, ' ').trim());
  }

  it('helpers: the status tags, the summary and the check line', () => {
    expect(statusTag(BANK5, '2026-11-30')).toBe('Overdue 3 months');
    expect(statusTag(BANK2, '2026-11-30')).toBe('Never reconciled · overdue 1 month');
    expect(statusTag(row({ status: 'due', months_overdue: 0, due_by: '2026-12-10' }), '2026-11-30')).toBe('Due by 10 Dec 2026');
    expect(statusTag(row({ status: 'due', months_overdue: 0, due_by: '2026-12-10', never_reconciled: true }), '2026-11-30')).toBe('Never reconciled · due by 10 Dec 2026');
    expect(statusTag(KDY, '2026-11-30')).toBe('Up to date');
    expect(statusTag(BANK3, '2026-11-30')).toBe('Not required: no entries up to 30 Nov 2026');
    expect(statusTag(BANK4, '2026-11-30')).toBe('Not required: inactive, nil balance, reconciled to its last month (30 Jun 2026)');
    expect(summaryText(report().summary)).toBe('Overdue 2 · Due 0 · Up to date 1 · Not required 2');
    expect(checkLine(report().summary)).toBe('Latest statement not checked 2 · Re-check needed 0');
  });

  it('AC 1, 2: default request, rows in API order with their texts, the basis, summary and check lines', async () => {
    const req = await open(null);
    expect(req!.request.params.get('status')).toBe('all');
    expect(req!.request.params.has('as_at')).toBeFalse();
    req!.flush(report());
    await settle(fixture);
    const r = rows();
    expect(r.length).toBe(5);
    expect(r[0]).toContain('KDY Bank 5 (11006)');
    expect(r[0]).toContain('Overdue 3 months');
    expect(r[0]).toContain('15 Sep 2026');
    expect(r[0]).toContain('by ACC');
    expect(r[0]).toContain('Not checked');
    expect(r[1]).toContain('Never reconciled · overdue 1 month');
    expect(r[1]).toContain('Never');
    expect(r[1]).not.toContain('checked');
    expect(r[2]).toContain('Up to date');
    expect(r[2]).toContain('30 Nov 2026');
    expect(r[2]).toContain('by BA');
    expect(r[2]).toContain('Not checked');
    expect(r[3]).toContain('Not required: no entries up to 30 Nov 2026');
    expect(r[4]).toContain('KDY Bank 4 (11005) (inactive)');
    expect(r[4]).toContain('Not required: inactive, nil balance, reconciled to its last month (30 Jun 2026)');
    expect(r[4]).toContain('Checked by VW, 2026-12-10');
    // Months overdue: "0" for Up to date, blank for Not required.
    const months = Array.from(el.querySelectorAll('.rst-table tbody tr.rst-row')).map(tr => tr.querySelectorAll('td')[2].textContent!.trim());
    expect(months).toEqual(['3', '1', '0', '', '']);
    // Tag colours: red, red, green, grey, grey; check tags amber / green.
    const tags = Array.from(el.querySelectorAll('.rst-table tbody tr.rst-row')).map(tr => tr.querySelectorAll('td')[1].querySelector('.tag')!.getAttribute('data-s'));
    expect(tags).toEqual(['sr-red', 'sr-red', 'sr-green', 'sr-grey', 'sr-grey']);
    expect(el.querySelectorAll('.rst-table tbody tr.rst-row')[4].querySelectorAll('td')[4].querySelector('.tag')!.getAttribute('data-s')).toBe('sr-green');
    expect(el.querySelectorAll('.rst-table tbody tr.rst-row')[0].querySelectorAll('td')[4].querySelector('.tag')!.getAttribute('data-s')).toBe('sr-amber');
    expect(el.querySelector('.rst-basis')!.textContent).toContain('As at 15 Dec 2026.');
    expect(el.querySelector('.rst-summary')!.textContent!.trim()).toBe('Overdue 2 · Due 0 · Up to date 1 · Not required 2');
    const check = el.querySelector('.rst-check')!;
    expect(check.textContent!.trim()).toBe('Latest statement not checked 2 · Re-check needed 0');
    expect(check.classList).toContain('warn');
    // No Branch column in branch mode.
    expect(Array.from(el.querySelectorAll('.rst-table th')).map(t => t.textContent!.trim())).not.toContain('Branch');
  });

  it('AC 6: a re-check shows "Re-check needed (1 since check)" in amber; no amber check line when nothing waits', async () => {
    await open(report({ summary: { overdue: 0, due: 0, up_to_date: 1, not_required: 0, not_checked: 0, recheck: 0 }, data: [row({ check_status: 'recheck', recheck_needed: true,
      check: { id: 3, checked_by: { id: 4, name: 'VW', tag: null }, checked_at: '2026-12-10T04:30:00+00:00', late_since_check: 1 } })] }));
    expect(rows()[0]).toContain('Re-check needed (1 since check)');
    expect(rows()[0]).toContain('Up to date');
    expect(el.querySelector('.rst-check')!.classList).not.toContain('warn');
  });

  it('AC 8: Apply puts the status in the URL; an empty filtered list says so and offers Clear filters', async () => {
    await open();
    const c = fixture.componentInstance;
    c.status = 'due';
    button(el, 'Apply')!.click();
    const req = http.expectOne(r => r.url === URL);
    expect(req.request.params.get('status')).toBe('due');
    req.flush(report({ filters: { status: 'due' }, data: [] }));
    await settle(fixture);
    expect(TestBed.inject(Router).url).toContain('status=due');
    expect(el.textContent).toContain('Filters (1)');
    expect(el.textContent).toContain('No bank accounts with this status.');
    // The summary does not change with the filter.
    expect(el.querySelector('.rst-summary')!.textContent!.trim()).toBe('Overdue 2 · Due 0 · Up to date 1 · Not required 2');
    button(el.querySelector('.rst-table')!, 'Clear filters')!.click();
    const again = http.expectOne(r => r.url === URL);
    expect(again.request.params.get('status')).toBe('all');
    again.flush(report({ data: [] }));
    await settle(fixture);
    expect(el.textContent).toContain('No bank accounts in this branch.');
  });

  it('errors: 422 under the Status field, 403 in .alert, network error with Retry', async () => {
    const req = await open(null);
    req!.flush({ message: 'x', errors: { status: ['Choose a valid status.'] } }, { status: 422, statusText: 'Unprocessable' });
    await settle(fixture);
    expect(el.querySelector('.rs-panel .field-error')!.textContent).toContain('Choose a valid status.');

    button(el, 'Apply')!.click();
    http.expectOne(r => r.url === URL).flush({ message: 'You do not have access to branch GAL.' }, { status: 403, statusText: 'Forbidden' });
    await settle(fixture);
    expect(el.querySelector('.alert')!.textContent).toContain('You do not have access to branch GAL.');

    button(el, 'Apply')!.click();
    http.expectOne(r => r.url === URL).error(new ProgressEvent('error'), { status: 0 });
    await settle(fixture);
    expect(el.textContent).toContain('Could not load the reconciliation status. Try again.');
    button(el, 'Retry')!.click();
    http.expectOne(r => r.url === URL).flush(report());
    await settle(fixture);
    expect(rows().length).toBe(5);
  });

  it('review SHOULD 1: an unknown status from a bookmark falls back to All', async () => {
    const req = await open(null, VIEWER, '/reports/reconciliation-status?status=late');
    expect(req!.request.params.get('status')).toBe('all');
    req!.flush(report());
    await settle(fixture);
    expect(rows().length).toBe(5);
    expect(el.querySelector<HTMLSelectElement>('select[name="status"]')!.value).toBe('all');
  });

  it('review SHOULD 1: a 422 on Status opens the filter sheet at 390px, so the message is visible', async () => {
    await open();
    const frame = await phoneFrame();
    frame.adopt(el);
    await settle(fixture);
    const panel = el.querySelector<HTMLElement>('.rs-panel')!;
    expect(frame.win.getComputedStyle(panel).display).toBe('none');
    button(el, 'Apply')!.click();
    http.expectOne(r => r.url === URL).flush({ message: 'x', errors: { status: ['Choose a valid status.'] } }, { status: 422, statusText: 'Unprocessable' });
    await settle(fixture);
    expect(frame.win.getComputedStyle(panel).display).toBe('flex');
    const error = panel.querySelector<HTMLElement>('.field-error')!;
    expect(error.textContent).toContain('Choose a valid status.');
    expect(error.getBoundingClientRect().height).toBeGreaterThan(0);
    frame.remove();
  });

  it('QA D1: a double click on Apply sends one request', async () => {
    await open();
    const apply = button(el, 'Apply')!;
    apply.click();
    apply.click();
    const reqs = http.match(r => r.url === URL);
    expect(reqs.length).toBe(1);
    reqs[0].flush(report());
    await settle(fixture);
    button(el, 'Apply')!.click();
    http.expectOne(r => r.url === URL).flush(report());
  });

  it('AC 11: a Cashier cannot open the hub or S7 (no card to see); a Viewer can', () => {
    const shell = routes.find(r => r.children)!.children!;
    const guards = (path: string) => (shell.find((r: Route) => r.path === path)!.canActivate ?? []) as CanActivateFn[];
    const run = (path: string) => guards(path).map(g => TestBed.runInInjectionContext(() => g({} as ActivatedRouteSnapshot, {} as RouterStateSnapshot)));
    setup([CASHIER]);
    for (const path of ['reports', 'reports/reconciliation-status']) {
      const out = run(path);
      expect(out.some(o => o instanceof UrlTree)).withContext(path).toBeTrue();
    }
    TestBed.resetTestingModule();
    setup([VIEWER]);
    for (const path of ['reports', 'reports/reconciliation-status']) expect(run(path).every(o => o === true)).withContext(path).toBeTrue();
  });

  it('AC 12: Viewer has no Reconcile links and no export buttons; Statements opens S6 for the account', async () => {
    await open();
    expect(link(el.querySelector('.rst-table')!, 'Reconcile').length).toBe(0);
    expect(button(el, 'PDF')).toBeUndefined();
    const statements = link(el.querySelector('.rst-table')!, 'Statements');
    expect(statements.length).toBe(5);
    expect(statements[2].getAttribute('href')).toBe('/reports/reconciliation-statements?account_ids=5');
  });

  it('AC 12, F1: Accountant sees Reconcile (S1) and the export buttons', async () => {
    await open(report(), ACCOUNTANT);
    const reconcile = link(el.querySelector('.rst-table')!, 'Reconcile');
    expect(reconcile.length).toBe(5);
    expect(reconcile[0].getAttribute('href')).toBe('/accounts/9/reconcile');
    expect(button(el, 'PDF')).toBeDefined();
    expect(button(el, 'Excel')).toBeDefined();
    button(el, 'PDF')!.click();
    const exp = http.expectOne('/api/v1/reports/reconciliation-status/export');
    expect(exp.request.body).toEqual({ branches: [], status: 'all', format: 'pdf' });
  });

  it('AC 9, Q8: All mode shows the Branch column; Reconcile only for MAIN accounts; Statements carries the branch', async () => {
    setup([VIEWER], 'all', true);
    http = TestBed.inject(HttpTestingController);
    fixture = TestBed.createComponent(ReconciliationStatusComponent);
    el = fixture.nativeElement as HTMLElement;
    fixture.detectChanges();
    http.match('/api/v1/branches').forEach(r => r.flush([]));
    const main = row({ account: { id: 1, code: '10001', name: 'Main Account', status: 'active' }, branch: { id: 1, code: 'MAIN', is_main: true },
      status: 'overdue', months_overdue: 4, never_reconciled: true, last_statement: null, check_status: null });
    const gal = row({ account: { id: 11, code: '12002', name: 'GAL Bank', status: 'active' }, branch: { id: 3, code: 'GAL', is_main: false }, status: 'overdue', months_overdue: 1 });
    http.expectOne(r => r.url === URL).flush(report({ all_branches: true, data: [main, gal] }));
    await settle(fixture);
    expect(Array.from(el.querySelectorAll('.rst-table th')).map(t => t.textContent!.trim())).toContain('Branch');
    expect(rows()[0]).toContain('MAIN');
    expect(rows()[0]).toContain('Never reconciled · overdue 4 months');
    const reconcile = link(el.querySelector('.rst-table')!, 'Reconcile');
    expect(reconcile.map(a => a.getAttribute('href'))).toEqual(['/accounts/1/reconcile']);
    expect(link(el.querySelector('.rst-table')!, 'Statements')[1].getAttribute('href')).toBe('/reports/reconciliation-statements?account_ids=11&branches=3');
  });

  it('AC 18: at 390px and 360px rows are cards with the tags and 44px buttons, filters in a full-width sheet, no horizontal scroll', async () => {
    const many = Array.from({ length: 12 }, (_, i) => row({
      account: { id: 100 + i, code: `1100${i}`, name: 'KDY Bank with a rather long account name for a phone screen', status: i % 4 ? 'active' : 'closed' },
      ...(i % 3 === 0 ? { status: 'not_required', not_required_reason: 'closed_nil', months_overdue: null, final_month_end: '2026-06-30' }
        : i % 3 === 1 ? { status: 'overdue', months_overdue: 3, never_reconciled: true, last_statement: null, check_status: null } : {}),
    } as Partial<StatusRow>));
    await open(report({ data: many }), ACCOUNTANT);
    for (const width of [390, 360]) {
      const frame = await phoneFrame(width);
      frame.adopt(el);
      await settle(fixture);
      const win = frame.win;
      expect(win.getComputedStyle(el.querySelector('.rst-table')!).display).toBe('none');
      expect(win.getComputedStyle(el.querySelector('.rst-cards')!).display).toBe('block');
      const cards = Array.from(el.querySelectorAll<HTMLElement>('.rst-cards li.rst-card'));
      expect(cards.length).toBe(12);
      cards.forEach((c, i) => {
        expect(c.querySelector('.rs-l1 .tag')).not.toBeNull();
        expect(c.querySelector('.rs-l4')!.textContent!.trim()).toMatch(i % 3 === 1 ? /^Never reconciled$/ : /^Last reconciled 30 Nov 2026 by BA$/);
        if (i % 3 !== 1) expect(c.querySelector('.rs-l3 .tag')!.textContent).toContain('Not checked');
        const buttons = Array.from(c.querySelectorAll<HTMLElement>('.rst-button'));
        expect(buttons.map(b => b.textContent!.trim())).toEqual(['Statements', 'Reconcile']);
        for (const b of buttons) expect(b.getBoundingClientRect().height).toBeGreaterThanOrEqual(44);
        expect(c.getBoundingClientRect().right).toBeLessThanOrEqual(width);
      });
      // Tags and the summary wrap.
      expect(win.getComputedStyle(el.querySelector('.rst-cards .rst-tag')!).whiteSpace).toBe('normal');
      expect(win.getComputedStyle(el.querySelector('.rst-cards .rs-l1')!).flexWrap).toBe('wrap');
      expect(win.getComputedStyle(el.querySelector('.rst-summary')!).overflowWrap).toBe('anywhere');
      // Filters fold into a full-width sheet.
      const panel = el.querySelector<HTMLElement>('.rs-panel')!;
      expect(win.getComputedStyle(panel).display).toBe('none');
      const toggle = button(el, 'Filters')!;
      expect(toggle.getBoundingClientRect().height).toBeGreaterThanOrEqual(44);
      toggle.click();
      await settle(fixture);
      expect(win.getComputedStyle(panel).display).toBe('flex');
      expect(Math.round(panel.getBoundingClientRect().width)).toBe(Math.round(el.querySelector<HTMLElement>('.rs-card')!.getBoundingClientRect().width));
      expect(button(panel, 'Apply')!.getBoundingClientRect().height).toBeGreaterThanOrEqual(44);
      expect(button(panel, 'Clear filters')!.getBoundingClientRect().height).toBeGreaterThanOrEqual(44);
      toggle.click();
      await settle(fixture);
      // QA D2: export buttons full width.
      const pdf = button(el, 'PDF')!;
      const excel = button(el, 'Excel')!;
      const head = el.querySelector<HTMLElement>('.rs-head')!.getBoundingClientRect().width;
      for (const b of [pdf, excel]) {
        expect(b.getBoundingClientRect().height).toBeGreaterThanOrEqual(44);
        expect(Math.round(b.getBoundingClientRect().width)).toBe(Math.round(head));
      }
      expect(frame.doc.documentElement.scrollWidth).toBeLessThanOrEqual(width);
      document.body.appendChild(el);
      frame.remove();
    }
  });

  it('AC 19: after a branch switch the report reloads for the new branch without the All-mode Branches', async () => {
    await open(null, VIEWER, '/reports/reconciliation-status?branches=3&status=overdue').then(req => {
      expect(req!.request.params.has('branches[]')).toBeFalse();
      req!.flush(report());
    });
    await settle(fixture);
    el.remove();
    TestBed.inject(AuthService).switchBranch('3');
    const again = TestBed.createComponent(ReconciliationStatusComponent);
    el = again.nativeElement as HTMLElement;
    again.detectChanges();
    const req = http.expectOne(r => r.url === URL);
    expect(req.request.params.get('status')).toBe('overdue');
    expect(req.request.params.has('branches[]')).toBeFalse();
    req.flush(report({ data: [] }));
  });

  it('S7 hub card right after "Bank reconciliation statements"', () => {
    setup([VIEWER]);
    const f = TestBed.createComponent(ReportsHubComponent);
    f.detectChanges();
    const cards = Array.from((f.nativeElement as HTMLElement).querySelectorAll<HTMLAnchorElement>('a.card'));
    const titles = cards.map(c => c.querySelector('h2')!.textContent!.trim());
    const i = titles.indexOf('Bank reconciliation status');
    expect(i).toBe(titles.indexOf('Bank reconciliation statements') + 1);
    expect(cards[i].getAttribute('href')).toBe('/reports/reconciliation-status');
    expect(cards[i].textContent).toContain('Which bank accounts are behind with reconciliation, and is the last statement checked?');
  });

  it('AC 14, F6: S6 shows "Status per account", with the current Branches in All mode', async () => {
    const list = (over: Partial<StatementList> = {}): StatementList => ({
      company: 'Gold', currency: 'LKR', from: '2025-12-15', to: '2026-12-15', branches: [], all_branches: true, subset: false, provisional: false,
      basis: '', copies_supported: false, accounts: [], coverage: { reconciled_to: '2026-11-30', not_reconciled: [] }, total: 0, page: 1, per_page: 50, data: [], ...over,
    });
    setup([VIEWER], 'all', true);
    http = TestBed.inject(HttpTestingController);
    await TestBed.inject(Router).navigateByUrl('/reports/reconciliation-statements?branches=3&branches=2');
    const f = TestBed.createComponent(ReconciliationStatementsComponent);
    const s6 = f.nativeElement as HTMLElement;
    f.detectChanges();
    http.match('/api/v1/branches').forEach(r => r.flush([]));
    http.expectOne(r => r.url === '/api/v1/reports/reconciliation-statements').flush(list());
    await settle(f);
    const a = link(s6, 'Status per account');
    expect(a.length).toBe(1);
    expect(a[0].getAttribute('href')).toBe('/reports/reconciliation-status?branches=3&branches=2');
    // Shown whether or not the sentence names an account; the sentence is unchanged.
    expect(s6.querySelector('.rs-coverage')!.textContent!.trim()).toBe('Every bank account in scope is reconciled to 30 Nov 2026.');
  });

  it('AC 14: in branch mode the S6 link carries no branches', async () => {
    setup([VIEWER]);
    http = TestBed.inject(HttpTestingController);
    await TestBed.inject(Router).navigateByUrl('/reports/reconciliation-statements');
    const f = TestBed.createComponent(ReconciliationStatementsComponent);
    const s6 = f.nativeElement as HTMLElement;
    f.detectChanges();
    http.expectOne(r => r.url === '/api/v1/reports/reconciliation-statements').flush({
      company: 'Gold', currency: 'LKR', from: '2025-12-15', to: '2026-12-15', branches: [], all_branches: false, subset: false, provisional: false,
      basis: '', copies_supported: false, accounts: [], total: 0, page: 1, per_page: 50, data: [],
      coverage: { reconciled_to: '2026-11-30', not_reconciled: [{ account: { id: 6, code: '11003', name: 'KDY Bank 2', status: 'active' }, branch: { id: 2, code: 'KDY' }, last_statement_date: null }] },
    } as StatementList);
    await settle(f);
    expect(link(s6, 'Status per account')[0].getAttribute('href')).toBe('/reports/reconciliation-status');
  });
});
