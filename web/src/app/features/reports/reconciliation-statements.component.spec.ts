import { ComponentFixture, TestBed } from '@angular/core/testing';
import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, TestRequest, provideHttpClientTesting } from '@angular/common/http/testing';
import { Router, provideRouter } from '@angular/router';
import { Component } from '@angular/core';
import { AuthService } from '../../core/auth.service';
import { me } from '../../core/notifications.service.spec';
import { phoneFrame } from '../../../testing/phone-frame';
import {
  ReconciliationStatementsComponent, StatementList, StatementRow, copyText, coverageText, lateText, yearBefore,
} from './reconciliation-statements.component';
import { ReportsHubComponent } from './reports-hub.component';

@Component({ standalone: true, template: '' })
class BlankComponent {}

const VIEWER = { id: 2, code: 'KDY', name: 'Kandy', is_main: false, roles: ['Viewer'], permissions: ['account.view', 'transaction.view', 'report.view'] };
const ACCOUNTANT = { id: 2, code: 'KDY', name: 'Kandy', is_main: false, roles: ['Accountant'],
  permissions: ['account.view', 'account.reconcile', 'report.view', 'report.export', 'transaction.view'] };
const GALLE = { id: 3, code: 'GAL', name: 'Galle', is_main: false, roles: ['Viewer'], permissions: ['report.view'] };
const URL = '/api/v1/reports/reconciliation-statements';

function setup(branches: typeof VIEWER[], active = '2') {
  TestBed.configureTestingModule({
    providers: [provideHttpClient(), provideHttpClientTesting(), provideRouter([{ path: '**', component: BlankComponent }])],
  });
  const auth = TestBed.inject(AuthService);
  auth.token.set('token');
  auth.me.set(me(branches));
  auth.meAt = Date.now() + 60_000;
  auth.switchBranch(active);
}

function row(over: Partial<StatementRow> = {}): StatementRow {
  return {
    id: 1, branch: { id: 2, code: 'KDY' }, account: { id: 5, code: '11002', name: 'KDY Bank', status: 'active' },
    statement_date: '2026-10-31', statement_balance: '479500.00', lines_count: 4, self_count: 2, status: 'completed',
    completed_by: { id: 8, name: 'ACC', tag: null }, completed_at: '2026-11-03T04:30:00+00:00',
    undone_by: null, undone_at: null, undo_reason: null, changed_since_completion: true, late_entries_count: 1, late_voided_count: 0,
    attachments_count: null, attachments_removed_count: null, ...over,
  };
}

const ST3 = row({ id: 3, statement_date: '2026-11-30', statement_balance: '469500.00', lines_count: 2, self_count: 0, changed_since_completion: false,
  late_entries_count: 0, completed_by: { id: 9, name: 'BA', tag: null }, completed_at: '2026-12-03T04:30:00+00:00' });
const ST2 = row({ id: 2, statement_date: '2026-11-30', statement_balance: '469500.00', lines_count: 2, self_count: 0, status: 'undone',
  changed_since_completion: false, late_entries_count: null, late_voided_count: null, completed_at: '2026-12-02T04:30:00+00:00',
  undone_by: { id: 9, name: 'BA', tag: null }, undone_at: '2026-12-03T03:30:00+00:00',
  undo_reason: 'Wrong closing balance typed, and a long explanation that goes past the eighty characters of the table cell' });
const ST1 = row();

function list(over: Partial<StatementList> = {}): StatementList {
  return {
    company: 'Gold', currency: 'LKR', from: '2025-12-10', to: '2026-12-10', branches: [{ id: 2, code: 'KDY', name: 'Kandy', status: 'active' }],
    all_branches: false, subset: false, provisional: false,
    basis: "Completed and undone bank reconciliation statements by statement date. Figures as stored at completion; 'Changed since completion' and late entries are worked out now.",
    copies_supported: false,
    accounts: [{ id: 5, code: '11002', name: 'KDY Bank', status: 'active', branch: { id: 2, code: 'KDY' }, last_statement_date: '2026-11-30' }],
    coverage: { reconciled_to: '2026-11-30', not_reconciled: [] },
    total: 3, page: 1, per_page: 50, data: [ST3, ST2, ST1], ...over,
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

/** AG-033c S6 (docs/specs/reconciliation-statements-list.md): AC 2-7, 13-15, 18-20 on the web side. */
describe('AG-033c bank reconciliation statements list (web)', () => {
  let http: HttpTestingController;
  let el: HTMLElement;
  let fixture: ComponentFixture<ReconciliationStatementsComponent>;

  afterEach(() => { el?.remove(); });

  async function open(data: StatementList | null = list(), branch = VIEWER, url = '/reports/reconciliation-statements'): Promise<TestRequest | null> {
    setup([branch, GALLE], String(branch.id));
    http = TestBed.inject(HttpTestingController);
    await TestBed.inject(Router).navigateByUrl(url);
    fixture = TestBed.createComponent(ReconciliationStatementsComponent);
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
    return Array.from(el.querySelectorAll('.rs-table tbody tr.rs-row')).map(r => r.textContent!.replace(/\s+/g, ' ').trim());
  }

  it('helpers: default range, late text, copy text and the coverage line', () => {
    expect(yearBefore('2026-12-10')).toBe('2025-12-10');
    expect(yearBefore('2028-02-29')).toBe('2027-02-28');
    expect(lateText(row({ late_entries_count: 2, late_voided_count: 1 }))).toBe('2 (1 voided)');
    expect(lateText(row({ late_entries_count: 0, late_voided_count: 0 }))).toBe('0');
    expect(lateText(ST2)).toBe('');
    expect(copyText(row({ attachments_count: 1, attachments_removed_count: 1 }))).toBe('1 file (1 removed)');
    expect(copyText(row({ attachments_count: 0, attachments_removed_count: 0 }))).toBe('No copy');
    expect(copyText(row({ status: 'undone', attachments_count: 0, attachments_removed_count: 0 }))).toBe('');
    expect(coverageText({ reconciled_to: '2026-11-30', not_reconciled: [] })).toBe('Every bank account in scope is reconciled to 30 Nov 2026.');
    expect(coverageText({ reconciled_to: '2026-11-30', not_reconciled: [
      { account: { id: 7, code: '12002', name: 'GAL Bank', status: 'active' }, branch: { id: 3, code: 'GAL' }, last_statement_date: '2026-10-31' },
      { account: { id: 8, code: '11003', name: 'KDY Bank 2', status: 'active' }, branch: { id: 2, code: 'KDY' }, last_statement_date: null },
    ] })).toBe('Not reconciled to 30 Nov 2026: GAL Bank (12002), last statement 31 Oct 2026; KDY Bank 2 (11003), never reconciled.');
  });

  it('AC 1, 3, 4: default request and the rows as stored, with tags and counts', async () => {
    const req = await open(null);
    expect(req!.request.params.get('status')).toBe('all');
    expect(req!.request.params.get('late')).toBe('any');
    expect(req!.request.params.has('copy')).toBeFalse();
    expect(req!.request.params.get('to')).toBe(fixture.componentInstance.today);
    req!.flush(list());
    await settle(fixture);
    const r = rows();
    expect(r.length).toBe(3);
    expect(r[2]).toContain('KDY Bank (11002)');
    expect(r[2]).toContain('479,500.00');
    expect(r[2]).toContain('Completed');
    expect(r[2]).toContain('Changed since completion');
    expect(r[1]).toContain('Undone');
    expect(r[1]).toContain('by BA on');
    expect(r[1]).not.toContain('Changed since completion');
    expect(r[0]).not.toContain('Changed since completion');
    const lines = el.querySelectorAll<HTMLElement>('.rs-table tbody tr.rs-row')[2].querySelectorAll('td')[3];
    expect(lines.getAttribute('title')).toBe('2 recorded by the preparer');
    const undo = el.querySelector<HTMLElement>('.rs-undo')!;
    expect(undo.getAttribute('title')).toBe(ST2.undo_reason!);
    expect(undo.textContent).toContain('…');
    expect(el.textContent).toContain('3 statements');
    expect(el.textContent).toContain('Figures as stored at completion');
    // AC 20: the coverage line.
    expect(el.querySelector('.rs-coverage')!.textContent!.trim()).toBe('Every bank account in scope is reconciled to 30 Nov 2026.');
    // No Branch column in branch mode.
    expect(Array.from(el.querySelectorAll('.rs-table th')).map(t => t.textContent!.trim())).not.toContain('Branch');
  });

  it('AC 6: late entries read "2 (1 voided)"; AC 20: a warning coverage line', async () => {
    await open(list({ data: [row({ late_entries_count: 2, late_voided_count: 1, changed_since_completion: false })],
      coverage: { reconciled_to: '2026-11-30', not_reconciled: [{ account: { id: 7, code: '12002', name: 'GAL Bank', status: 'active' },
        branch: { id: 3, code: 'GAL' }, last_statement_date: '2026-10-31' }] } }));
    expect(rows()[0]).toContain('2 (1 voided)');
    const cov = el.querySelector('.rs-coverage')!;
    expect(cov.classList).toContain('warn');
    expect(cov.textContent!.trim()).toBe('Not reconciled to 30 Nov 2026: GAL Bank (12002), last statement 31 Oct 2026.');
  });

  it('AC 13: without AG-033b there is no copy column and no copy filter', async () => {
    await open();
    expect(el.textContent).not.toContain('Bank statement copy');
    expect(el.querySelector('select[name="copy"]')).toBeNull();
  });

  it('AC 14: with AG-033b the copy column and filter show', async () => {
    await open(list({ copies_supported: true, data: [
      { ...ST3, attachments_count: 0, attachments_removed_count: 0 },
      { ...ST2, attachments_count: 0, attachments_removed_count: 0 },
      { ...ST1, attachments_count: 1, attachments_removed_count: 1 },
    ] }));
    expect(el.querySelector('select[name="copy"]')).not.toBeNull();
    expect(Array.from(el.querySelectorAll('.rs-table th')).map(t => t.textContent!.trim())).toContain('Bank statement copy');
    const r = rows();
    expect(r[0]).toContain('No copy');
    expect(r[1]).not.toContain('No copy');
    expect(r[1]).not.toContain('file');
    expect(r[2]).toContain('1 file (1 removed)');
  });

  it('AC 5, 7: Apply puts the filters in the URL; a row opens the reprint', async () => {
    await open();
    const c = fixture.componentInstance;
    c.f.status = 'completed';
    c.f.changed = 'yes';
    button(el, 'Apply')!.click();
    const req = http.expectOne(r => r.url === URL);
    expect(req.request.params.get('status')).toBe('completed');
    expect(req.request.params.get('changed')).toBe('yes');
    req.flush(list({ total: 1, data: [ST1] }));
    await settle(fixture);
    const router = TestBed.inject(Router);
    expect(router.url).toContain('status=completed');
    expect(router.url).toContain('changed=yes');
    expect(el.textContent).toContain('Filters (2)');

    el.querySelector<HTMLElement>('.rs-table tbody tr.rs-row td')!.click();
    await settle(fixture);
    expect(router.url).toBe('/reports/bank-reconciliation?statement_id=1');
  });

  it('AC 7: an empty filtered list says so and offers Clear filters; the default empty text', async () => {
    await open(null, VIEWER, '/reports/reconciliation-statements?status=undone&changed=yes').then(req => req!.flush(list({ total: 0, data: [] })));
    await settle(fixture);
    expect(el.textContent).toContain('No statements match these filters.');
    button(el, 'Clear filters')!.click();
    const req = http.expectOne(r => r.url === URL);
    expect(req.request.params.get('status')).toBe('all');
    req.flush(list({ total: 0, data: [] }));
    await settle(fixture);
    expect(el.textContent).toContain('No bank reconciliation statements in the last 12 months.');
  });

  it('errors: 422 under the field, 403 in .alert, network error with Retry', async () => {
    const req = await open(null, VIEWER, '/reports/reconciliation-statements?to=2026-12-31');
    req!.flush({ message: 'x', errors: { to: ['The To date cannot be in the future.'] } }, { status: 422, statusText: 'Unprocessable' });
    await settle(fixture);
    expect(el.querySelector('.rs-panel .field-error')!.textContent).toContain('The To date cannot be in the future.');

    button(el, 'Apply')!.click();
    http.expectOne(r => r.url === URL).flush({ message: 'You do not have access to branch GAL.' }, { status: 403, statusText: 'Forbidden' });
    await settle(fixture);
    expect(el.querySelector('.alert')!.textContent).toContain('You do not have access to branch GAL.');

    button(el, 'Apply')!.click();
    http.expectOne(r => r.url === URL).error(new ProgressEvent('error'), { status: 0 });
    await settle(fixture);
    expect(el.textContent).toContain('Could not load the statements. Try again.');
    button(el, 'Retry')!.click();
    http.expectOne(r => r.url === URL).flush(list());
    await settle(fixture);
    expect(rows().length).toBe(3);
  });

  it('AC 15: export buttons only with report.export', async () => {
    await open();
    expect(button(el, 'PDF')).toBeUndefined();
    el.remove();
    TestBed.resetTestingModule();
    await open(list(), ACCOUNTANT);
    expect(button(el, 'PDF')).toBeDefined();
    expect(button(el, 'Excel')).toBeDefined();
  });

  it('AC 18: at 390px cards of at least 44px open the reprint, filters in a full-width sheet, no horizontal scroll', async () => {
    const many = Array.from({ length: 20 }, (_, i) => row({ id: 100 + i, changed_since_completion: i % 2 === 0, late_entries_count: 2, late_voided_count: 1,
      account: { id: 5, code: '11002', name: 'KDY Bank with a rather long account name for a phone', status: i % 3 ? 'active' : 'closed' } }));
    await open(list({ total: 60, data: many, copies_supported: true }), ACCOUNTANT);
    const frame = await phoneFrame();
    frame.adopt(el);
    await settle(fixture);
    const win = frame.win;
    expect(win.getComputedStyle(el.querySelector('.rs-table')!).display).toBe('none');
    expect(win.getComputedStyle(el.querySelector('.rs-cards')!).display).toBe('block');
    const cards = Array.from(el.querySelectorAll<HTMLAnchorElement>('.rs-cards a.rs-tap'));
    expect(cards.length).toBe(20);
    for (const c of cards) {
      expect(c.getBoundingClientRect().height).toBeGreaterThanOrEqual(44);
      expect(c.getAttribute('href')).toContain('/reports/bank-reconciliation?statement_id=');
    }
    expect(win.getComputedStyle(el.querySelector('.rs-l3')!).flexWrap).toBe('wrap');
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
    // Load more instead of the pager; export buttons full width.
    expect(win.getComputedStyle(el.querySelector('.rs-pager')!).display).toBe('none');
    expect(button(el, 'Load more')!.getBoundingClientRect().height).toBeGreaterThanOrEqual(44);
    expect(frame.doc.documentElement.scrollWidth).toBeLessThanOrEqual(390);

    button(el, 'Load more')!.click();
    const req = http.expectOne(r => r.url === URL);
    expect(req.request.params.get('page')).toBe('2');
    req.flush(list({ total: 60, page: 2, data: [row({ id: 999 })], copies_supported: true }));
    await settle(fixture);
    expect(el.querySelectorAll('.rs-cards a.rs-tap').length).toBe(21);
    frame.remove();
  });

  it('AC 19: after a branch switch the list reloads without the Account filter', async () => {
    await open(null, VIEWER, '/reports/reconciliation-statements?account_ids=5').then(req => {
      expect(req!.request.params.getAll('account_ids[]')).toEqual(['5']);
      req!.flush(list());
    });
    await settle(fixture);
    el.remove();
    // The outlet is keyed on the branch: a new component in GAL, the URL still carries KDY's account.
    TestBed.inject(AuthService).switchBranch('3');
    const again = TestBed.createComponent(ReconciliationStatementsComponent);
    el = again.nativeElement as HTMLElement;
    again.detectChanges();
    const req = http.expectOne(r => r.url === URL);
    expect(req.request.params.has('account_ids[]')).toBeFalse();
    req.flush(list({ data: [] }));
  });

  it('QA D2: a bookmarked copy filter with copies off shows the 422 and the filter; other field errors go to the alert', async () => {
    const req = await open(null, VIEWER, '/reports/reconciliation-statements?copy=none');
    req!.flush({ message: 'x', errors: { copy: ['Bank statement copies are not available yet.'] } }, { status: 422, statusText: 'Unprocessable' });
    await settle(fixture);
    expect(el.querySelector('select[name="copy"]')).not.toBeNull();
    expect(el.querySelector('.rs-panel .field-error')!.textContent).toContain('Bank statement copies are not available yet.');

    button(el, 'Apply')!.click();
    http.expectOne(r => r.url === URL).flush({ message: 'x', errors: { status: ['The selected status is invalid.'], 'branches.0': ['Unknown branch.'] } },
      { status: 422, statusText: 'Unprocessable' });
    await settle(fixture);
    expect(el.querySelector('.alert')!.textContent).toContain('The selected status is invalid. Unknown branch.');
  });

  it('review SHOULD 5: account ids from another branch in a bookmark are dropped once and the list reloads', async () => {
    const req = await open(null, VIEWER, '/reports/reconciliation-statements?account_ids=99');
    req!.flush({ message: 'x', errors: { account_ids: ['Unknown bank account.'] } }, { status: 422, statusText: 'Unprocessable' });
    await settle(fixture);
    const again = http.expectOne(r => r.url === URL);
    expect(again.request.params.has('account_ids[]')).toBeFalse();
    again.flush(list());
    await settle(fixture);
    expect(rows().length).toBe(3);
    expect(TestBed.inject(Router).url).not.toContain('account_ids');
  });

  it('QA D3: deselecting a branch in All mode unticks its accounts', async () => {
    setup([VIEWER], 'all');
    TestBed.inject(AuthService).me.set({ ...me([VIEWER]), is_super_admin: true });
    http = TestBed.inject(HttpTestingController);
    fixture = TestBed.createComponent(ReconciliationStatementsComponent);
    el = fixture.nativeElement as HTMLElement;
    fixture.detectChanges();
    http.match('/api/v1/branches').forEach(r => r.flush([]));
    http.expectOne(r => r.url === URL).flush(list({ all_branches: true, accounts: [
      { id: 5, code: '11002', name: 'KDY Bank', status: 'active', branch: { id: 2, code: 'KDY' }, last_statement_date: null },
      { id: 7, code: '12002', name: 'GAL Bank', status: 'active', branch: { id: 3, code: 'GAL' }, last_statement_date: null },
    ] }));
    await settle(fixture);
    const c = fixture.componentInstance;
    c.toggle(5);
    c.toggle(7);
    c.onBranches([3]);
    expect(c.chosen()).toEqual([7]);
    expect(c.visibleAccounts().map(a => a.id)).toEqual([7]);
  });

  it('QA D4: a double click on PDF sends one export', async () => {
    await open(list(), ACCOUNTANT);
    const pdf = button(el, 'PDF')!;
    pdf.click();
    pdf.click();
    expect(http.match(r => r.url === '/api/v1/reports/reconciliation-statements/export').length).toBe(1);
  });

  it('QA D5: the phone card reads "Completed by Name (tag), date" without a stray space', async () => {
    await open(list({ data: [row({ completed_by: { id: 8, name: 'Dilan', tag: 'inactive' } })] }));
    expect(el.querySelector('.rs-l4')!.textContent!.replace(/\s+/g, ' ').trim()).toMatch(/^Completed by Dilan \(inactive\), \S/);
  });

  it('AC 2: the hub lists the card after "Unreconciled bank entries"', () => {
    setup([VIEWER]);
    const f = TestBed.createComponent(ReportsHubComponent);
    f.detectChanges();
    const titles = Array.from((f.nativeElement as HTMLElement).querySelectorAll('a.card h2')).map(h => h.textContent!.trim());
    expect(titles.indexOf('Bank reconciliation statements')).toBe(titles.indexOf('Unreconciled bank entries') + 1);
  });
});
