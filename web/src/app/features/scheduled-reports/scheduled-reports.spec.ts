import { ComponentFixture, TestBed } from '@angular/core/testing';
import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { ActivatedRoute, ActivatedRouteSnapshot, Router, RouterStateSnapshot, UrlTree, convertToParamMap, provideRouter } from '@angular/router';
import { Component, Type } from '@angular/core';
import { AuthService } from '../../core/auth.service';
import { me } from '../../core/notifications.service.spec';
import { BranchAccess } from '../../core/models';
import { reportBranchGuard } from '../../core/guards';
import { phoneFrame } from '../../../testing/phone-frame';
import { ShellComponent } from '../../layout/shell.component';
import { CashBookComponent } from '../reports/cash-book.component';
import { ScheduleFormComponent } from './schedule-form.component';
import { ScheduleHistoryComponent } from './schedule-history.component';
import { ScheduleListComponent } from './schedule-list.component';
import { RecipientOption, Schedule, ScheduleRun, nextEmailLine, reportUrl, runBadge } from './scheduled-reports';
import { SettingsService } from '../../core/settings.service';

@Component({ standalone: true, template: '' })
class BlankComponent {}

const MAIN = { id: 1, code: 'MAIN', name: 'Main', is_main: true };
const KDY = { id: 2, code: 'KDY', name: 'Kandy', is_main: false };
const GAL = { id: 3, code: 'GAL', name: 'Galle', is_main: false };
const SA_BRANCHES: BranchAccess[] = [MAIN, KDY, GAL].map(b => ({ ...b, roles: [], permissions: ['*'] }));
const BRANCHES = [MAIN, KDY, GAL].map(b => ({ ...b, status: 'active' }));

function d1(over: Partial<Schedule> = {}): Schedule {
  return {
    id: 5, name: 'KDY daily I&E', report: 'income-expense', title: 'Income & Expense by Category', params: { type: 'all' }, params_label: 'Type: All',
    branches: [{ id: 2, code: 'KDY', status: 'active' }], branches_label: 'KDY', frequency: 'daily', weekday: null, send_time: '07:00',
    status: 'active', paused_reason: null, paused_reason_text: null, owner: { id: 7, name: 'M. Silva' }, created_by: { id: 7, name: 'M. Silva' },
    created_at: '2026-11-20T04:30:00Z', updated_by: null, updated_at: '2026-11-20T04:30:00Z', archived_at: null, archived_by: null, archive_reason: null,
    next_run_at: '2026-11-22T01:30:00Z', next_period: { from: '2026-11-21', to: '2026-11-21' },
    recipients: [
      { id: 7, name: 'M. Silva', email: 'm@x.lk', status: 'active', is_super_admin: true, eligible: true, reason: null },
      { id: 9, name: 'A. Perera', email: 'ak@x.lk', status: 'active', is_super_admin: false, eligible: false, reason: 'No View reports or Export permission in KDY' },
    ],
    cannot_receive: 1,
    last_run: { id: 'r1', planned_at: '2026-11-21T01:30:00Z', status: 'done', sent: 1, skipped: 1, failed: 0, queued: 0 },
    ...over,
  };
}

function run(): ScheduleRun {
  return {
    id: 'r1', planned_at: '2026-11-21T01:30:00Z', period_from: '2026-11-20', period_to: '2026-11-20', status: 'done', failure_code: null, error: null,
    missed_count: null, rows: 4, attached: true, size_bytes: 20480, data_as_at: '2026-11-21T01:30:05Z', started_at: '2026-11-21T01:30:01Z',
    finished_at: '2026-11-21T01:30:09Z', attempts: 1, sent: 1, skipped: 1, failed: 0, queued: 0,
    deliveries: [
      { user: { id: 9, name: 'A. Perera' }, email: 'ak@x.lk', status: 'skipped', reason: 'missing_permission', reason_text: 'No View reports or Export permission in KDY', sent_at: null, failed_at: null },
      { user: { id: 7, name: 'M. Silva' }, email: 'm@x.lk', status: 'sent', reason: null, reason_text: null, sent_at: '2026-11-21T01:31:00Z', failed_at: null },
    ],
  };
}

function options(branches: number[]): RecipientOption[] {
  const multi = branches.length !== 1;
  return [
    { id: 7, name: 'M. Silva', email: 'm@x.lk', is_super_admin: true, status: 'active', eligible: true, reason: null },
    { id: 8, name: 'S. Two', email: 's2@x.lk', is_super_admin: true, status: 'active', eligible: true, reason: null },
    { id: 9, name: 'A. Perera', email: 'ak@x.lk', is_super_admin: false, status: 'active', eligible: !multi,
      reason: multi ? 'Only Super Admins can receive reports covering more than one branch' : null },
  ];
}

function setup(branches: BranchAccess[], superAdmin: boolean, active: string, params: Record<string, string> = {}, query: Record<string, string> = {}) {
  TestBed.configureTestingModule({
    providers: [provideHttpClient(), provideHttpClientTesting(), provideRouter([{ path: '**', component: BlankComponent }]),
      { provide: ActivatedRoute, useValue: { snapshot: { paramMap: convertToParamMap(params), queryParamMap: convertToParamMap(query) } } }],
  });
  const auth = TestBed.inject(AuthService);
  auth.token.set('token');
  auth.me.set(me(branches, superAdmin));
  auth.meAt = Date.now() + 60_000;
  auth.switchBranch(active);
  return { auth, http: TestBed.inject(HttpTestingController) };
}

function mount<T>(type: Type<T>): { fixture: ComponentFixture<T>; el: HTMLElement } {
  const fixture = TestBed.createComponent(type);
  const el = fixture.nativeElement as HTMLElement;
  document.body.appendChild(el);
  return { fixture, el };
}

async function settle(f: ComponentFixture<unknown>) { for (let i = 0; i < 3; i++) { f.detectChanges(); await f.whenStable(); } f.detectChanges(); }

function text(el: Element) { return (el.textContent ?? '').replace(/\s+/g, ' ').trim(); }

/** docs/specs/scheduled-reports.md web: ACs 5, 19, 34, 35, 36. */
describe('Scheduled reports (AG-088)', () => {
  it('helpers: next email line, preview link, badges', () => {
    TestBed.configureTestingModule({ providers: [provideHttpClient(), provideHttpClientTesting()] });
    const settings = TestBed.inject(SettingsService);
    expect(nextEmailLine(settings, '2026-11-21T01:30:00Z', '2026-11-20', '2026-11-20')).toBe('Next email: Sat 21 Nov 2026 07:00, covering Fri 20 Nov 2026');
    expect(nextEmailLine(settings, '2026-11-23T01:30:00Z', '2026-11-16', '2026-11-22'))
      .toBe('Next email: Mon 23 Nov 2026 07:00, covering Mon 16 Nov 2026 - Sun 22 Nov 2026');
    expect(reportUrl('income-expense', '2026-11-20', '2026-11-20', [2])).toBe('/reports/income-expense?from=2026-11-20&to=2026-11-20&branch=2');
    expect(reportUrl('branch-comparison', '2026-11-16', '2026-11-22', [])).toBe('/reports/branch-comparison?from=2026-11-16&to=2026-11-22&branch=all');
    expect(reportUrl('cash-book', '2026-11-16', '2026-11-22', [2, 3])).toBe('/reports/cash-book?from=2026-11-16&to=2026-11-22&branch=all&branches=2&branches=3');
    const base = { status: 'done' as const, sent: 2, skipped: 0, failed: 0, queued: 0 };
    expect(runBadge(base)).toEqual({ label: 'Sent', s: 'sr-green' });
    expect(runBadge({ ...base, sent: 1, skipped: 1 })).toEqual({ label: 'Partly sent', s: 'sr-amber' });
    expect(runBadge({ ...base, sent: 0, skipped: 2 })).toEqual({ label: 'Nothing sent', s: 'sr-grey' });
    expect(runBadge({ ...base, status: 'failed' })).toEqual({ label: 'Failed', s: 'sr-red' });
    expect(runBadge({ ...base, status: 'missed' })).toEqual({ label: 'Missed', s: 'sr-red' });
  });

  it('AC 34: S2 live line, multi-branch recipients and the preview link', async () => {
    const { http } = setup(SA_BRANCHES, true, 'all');
    const { fixture, el } = mount(ScheduleFormComponent);
    fixture.detectChanges();
    http.expectOne('/api/v1/branches').flush(BRANCHES);
    await settle(fixture);
    http.expectOne(r => r.url === '/api/v1/report-schedules/recipient-options').flush({ data: options([]) });
    const daily = http.expectOne(r => r.url === '/api/v1/report-schedules/preview-time');
    expect(daily.request.params.get('frequency')).toBe('daily');
    expect(daily.request.params.get('send_time')).toBe('07:00');
    daily.flush({ next_run_at: '2026-11-21T01:30:00Z', period_from: '2026-11-20', period_to: '2026-11-20' });
    await settle(fixture);
    expect(text(el.querySelector('.sr-next')!)).toBe('Next email: Sat 21 Nov 2026 07:00, covering Fri 20 Nov 2026');
    // New: the owner is ticked.
    const owner = Array.from(el.querySelectorAll<HTMLLabelElement>('.sr-recipients label')).find(l => text(l).startsWith('M. Silva'))!;
    expect(owner.querySelector('input')!.checked).toBeTrue();

    // Weekly, Monday.
    const weekly = el.querySelector<HTMLInputElement>('input[type=radio][value=weekly]')!;
    weekly.click();
    await settle(fixture);
    const w = http.expectOne(r => r.url === '/api/v1/report-schedules/preview-time');
    expect(w.request.params.get('weekday')).toBe('1');
    w.flush({ next_run_at: '2026-11-23T01:30:00Z', period_from: '2026-11-16', period_to: '2026-11-22' });
    await settle(fixture);
    expect(text(el.querySelector('.sr-next')!)).toBe('Next email: Mon 23 Nov 2026 07:00, covering Mon 16 Nov 2026 - Sun 22 Nov 2026');
    el.querySelector<HTMLInputElement>('input[type=radio][value=daily]')!.click();
    await settle(fixture);
    http.expectOne(r => r.url === '/api/v1/report-schedules/preview-time').flush({ next_run_at: '2026-11-21T01:30:00Z', period_from: '2026-11-20', period_to: '2026-11-20' });
    await settle(fixture);

    // Branches KDY: single branch, the preview link.
    const branchBox = (code: string) => Array.from(el.querySelectorAll<HTMLLabelElement>('.sr-set label')).find(l => text(l).startsWith(code))!.querySelector('input')!;
    branchBox('KDY').click();
    await settle(fixture);
    const one = http.expectOne(r => r.url === '/api/v1/report-schedules/recipient-options');
    expect(one.request.params.getAll('branches[]')).toEqual(['2']);
    one.flush({ data: options([2]) });
    await settle(fixture);
    const link = Array.from(el.querySelectorAll<HTMLAnchorElement>('a')).find(a => text(a) === 'Preview this report')!;
    expect(link.getAttribute('href')).toBe('/reports/income-expense?from=2026-11-20&to=2026-11-20&branch=2');
    expect(link.target).toBe('_blank');
    expect(el.querySelector('.sr-multi')).toBeNull();

    // KDY + GAL: only Super Admins can be chosen.
    branchBox('GAL').click();
    await settle(fixture);
    const two = http.expectOne(r => r.url === '/api/v1/report-schedules/recipient-options');
    expect(two.request.params.getAll('branches[]')).toEqual(['2', '3']);
    two.flush({ data: options([2, 3]) });
    await settle(fixture);
    expect(text(el.querySelector('.sr-multi')!)).toBe('Only Super Admins can receive reports covering more than one branch.');
    const boxes = Array.from(el.querySelectorAll<HTMLLabelElement>('.sr-recipients label'));
    expect(boxes.filter(l => !l.querySelector('input')!.disabled).map(l => text(l).split(' ').slice(0, 2).join(' '))).toEqual(['M. Silva', 'S. Two']);
    expect(text(boxes.find(l => text(l).startsWith('A. Perera'))!)).toContain('Only Super Admins can receive reports covering more than one branch');
    expect(text(el)).toContain('The report is emailed as a PDF to each recipient. An email cannot be recalled once sent.');
    fixture.destroy();
  });

  it('S2: editing an archived schedule goes to its history', async () => {
    const { http } = setup(SA_BRANCHES, true, 'all', { id: '5' });
    const router = TestBed.inject(Router);
    const nav = spyOn(router, 'navigate').and.returnValue(Promise.resolve(true));
    const { fixture } = mount(ScheduleFormComponent);
    fixture.detectChanges();
    http.expectOne('/api/v1/report-schedules/5').flush(d1({ status: 'archived' }));
    http.expectOne('/api/v1/branches').flush(BRANCHES);
    await settle(fixture);
    expect(nav).toHaveBeenCalledWith(['/scheduled-reports', 5]);
    fixture.destroy();
  });

  it('AC 35: S1 shows "Partly sent" and "1 cannot receive"; S3 shows the deliveries', async () => {
    const { http } = setup(SA_BRANCHES, true, 'all', { id: '5' });
    const list = mount(ScheduleListComponent);
    list.fixture.detectChanges();
    http.expectOne(r => r.url === '/api/v1/report-schedules').flush({ data: [d1()] });
    await settle(list.fixture);
    const row = list.el.querySelector('.sr-table tbody tr')!;
    const partly = Array.from(row.querySelectorAll('.tag')).find(t => text(t) === 'Partly sent')!;
    expect(partly.getAttribute('data-s')).toBe('sr-amber');
    const cannot = Array.from(row.querySelectorAll('.tag')).find(t => text(t) === '1 cannot receive')!;
    expect(cannot.getAttribute('title')).toBe('A. Perera: No View reports or Export permission in KDY');
    expect(text(row)).toContain('Owner: M. Silva');
    expect(text(row)).toContain('Daily 07:00');
    list.fixture.destroy();

    const hist = mount(ScheduleHistoryComponent);
    hist.fixture.detectChanges();
    http.expectOne('/api/v1/report-schedules/5').flush(d1());
    await settle(hist.fixture);
    http.expectOne(r => r.url === '/api/v1/report-schedules/5/runs').flush({ data: [run()], meta: { current_page: 1, last_page: 1, per_page: 25, total: 1 } });
    await settle(hist.fixture);
    expect(text(hist.el.querySelector('.sr-summary')!)).toContain('Cannot receive: No View reports or Export permission in KDY');
    Array.from(hist.el.querySelectorAll<HTMLButtonElement>('.sr-table button')).find(b => text(b) === 'Recipients')!.click();
    await settle(hist.fixture);
    const rows = Array.from(hist.el.querySelectorAll('.sr-deliveries tbody tr')).map(tr => Array.from(tr.querySelectorAll('td')).map(text));
    expect(rows[0].slice(0, 4)).toEqual(['A. Perera', 'ak@x.lk', 'Skipped', 'No View reports or Export permission in KDY']);
    expect(rows[1].slice(0, 3)).toEqual(['M. Silva', 'm@x.lk', 'Sent']);
    const card = Array.from(hist.el.querySelectorAll('.sr-run-card .sr-delivery')).map(l => Array.from(l.querySelectorAll(':scope > span')).map(text));
    expect(card).toEqual([['A. Perera', 'Skipped - No View reports or Export permission in KDY'], ['M. Silva', 'Sent']]);
    hist.fixture.destroy();
  });

  it('S1 empty state', async () => {
    const { http } = setup(SA_BRANCHES, true, 'all');
    const { fixture, el } = mount(ScheduleListComponent);
    fixture.detectChanges();
    http.expectOne(r => r.url === '/api/v1/report-schedules').flush({ data: [] });
    await settle(fixture);
    expect(text(el)).toContain('No scheduled reports yet.');
    expect(text(el)).toContain('Get a daily or weekly report by email as a PDF, without signing in.');
    fixture.destroy();
  });

  it('AC 36: 390px layout of S1, S2 and S3', async () => {
    const { http } = setup(SA_BRANCHES, true, 'all', { id: '5' });
    const frame = await phoneFrame();

    const list = mount(ScheduleListComponent);
    frame.adopt(list.el);
    list.fixture.detectChanges();
    http.expectOne(r => r.url === '/api/v1/report-schedules').flush({ data: [d1(), d1({ id: 6, name: 'A very long schedule name that has to wrap inside its card on a phone', last_run: null })] });
    await settle(list.fixture);
    expect(frame.win.getComputedStyle(list.el.querySelector('.sr-table')!).display).toBe('none');
    expect(list.el.querySelectorAll('.sr-card').length).toBe(2);
    const summary = list.el.querySelector<HTMLElement>('.sr-more summary')!;
    expect(summary.getBoundingClientRect().height).toBeGreaterThanOrEqual(44);
    summary.click();
    await settle(list.fixture);
    list.el.querySelectorAll<HTMLElement>('.sr-more[open] button').forEach(b => expect(b.getBoundingClientRect().height).toBeGreaterThanOrEqual(44));
    list.el.querySelectorAll<HTMLElement>('.sr-toolbar button, .sr-toolbar input').forEach(b => expect(b.getBoundingClientRect().height).toBeGreaterThanOrEqual(44));
    expect(frame.doc.documentElement.scrollWidth).toBeLessThanOrEqual(390);
    list.fixture.destroy();

    const form = mount(ScheduleFormComponent);
    frame.adopt(form.el);
    form.fixture.detectChanges();
    http.expectOne('/api/v1/report-schedules/5').flush(d1());
    http.expectOne('/api/v1/branches').flush(BRANCHES);
    await settle(form.fixture);
    http.expectOne(r => r.url === '/api/v1/report-schedules/recipient-options').flush({ data: options([2]) });
    http.expectOne(r => r.url === '/api/v1/report-schedules/preview-time').flush({ next_run_at: '2026-11-21T01:30:00Z', period_from: '2026-11-20', period_to: '2026-11-20' });
    await settle(form.fixture);
    const save = form.el.querySelector<HTMLElement>('.sr-save')!;
    expect(frame.win.getComputedStyle(save).position).toBe('sticky');
    save.querySelectorAll('button').forEach(b => expect(b.getBoundingClientRect().height).toBeGreaterThanOrEqual(44));
    form.el.querySelectorAll<HTMLElement>('.sr-form select, .sr-form input[name=name]').forEach(i => {
      expect(i.getBoundingClientRect().height).toBeGreaterThanOrEqual(44);
      expect(i.getBoundingClientRect().right).toBeLessThanOrEqual(390);
    });
    expect(frame.doc.documentElement.scrollWidth).toBeLessThanOrEqual(390);
    form.fixture.destroy();

    const hist = mount(ScheduleHistoryComponent);
    frame.adopt(hist.el);
    hist.fixture.detectChanges();
    http.expectOne('/api/v1/report-schedules/5').flush(d1());
    await settle(hist.fixture);
    http.expectOne(r => r.url === '/api/v1/report-schedules/5/runs').flush({ data: [run()], meta: { current_page: 1, last_page: 1, per_page: 25, total: 1 } });
    await settle(hist.fixture);
    expect(frame.win.getComputedStyle(hist.el.querySelector('.sr-table')!).display).toBe('none');
    expect(hist.el.querySelectorAll('.sr-run-card').length).toBe(1);
    expect(hist.el.querySelectorAll('.sr-run-card .sr-delivery').length).toBe(2);
    expect(frame.doc.documentElement.scrollWidth).toBeLessThanOrEqual(390);
    hist.fixture.destroy();
    frame.remove();
  });

  it('AC 5: the menu item is shown to Super Admins in All and branch mode, hidden for branch users', async () => {
    const check = async (branches: BranchAccess[], superAdmin: boolean, active: string) => {
      TestBed.resetTestingModule();
      setup(branches, superAdmin, active);
      const { fixture, el } = mount(ShellComponent);
      fixture.detectChanges();
      const shown = Array.from(el.querySelectorAll('nav a')).some(a => text(a) === 'Scheduled reports');
      fixture.destroy();
      return shown;
    };
    expect(await check(SA_BRANCHES, true, 'all')).toBeTrue();
    expect(await check(SA_BRANCHES, true, '2')).toBeTrue();
    const kba: BranchAccess = { ...KDY, roles: ['Branch Admin'], permissions: ['report.view', 'report.export', 'audit.view'] };
    expect(await check([kba], false, '2')).toBeFalse();
    const ak: BranchAccess = { ...KDY, roles: ['Accountant'], permissions: ['report.view', 'report.export'] };
    expect(await check([ak], false, '2')).toBeFalse();
  });

  describe('AC 19 (W1): report links carry ?branch=', () => {
    const snapshot = (query: Record<string, string>) => ({
      queryParamMap: convertToParamMap(query), queryParams: query, pathFromRoot: [{ url: [] }, { url: [{ path: 'reports' }, { path: 'cash-book' }] }],
    }) as unknown as ActivatedRouteSnapshot;
    const link = { from: '2026-11-20', to: '2026-11-20', branch: '2' };

    it('KBA with GAL active is switched to KDY and the parameter is dropped', () => {
      const kdy: BranchAccess = { ...KDY, roles: ['Branch Admin'], permissions: ['report.view', 'report.export'] };
      const gal: BranchAccess = { ...GAL, roles: ['Viewer'], permissions: ['report.view'] };
      const { auth } = setup([kdy, gal], false, '3');
      const router = TestBed.inject(Router);
      const res = TestBed.runInInjectionContext(() => reportBranchGuard(snapshot(link), {} as RouterStateSnapshot)) as UrlTree;
      expect(auth.activeBranchId()).toBe('2');
      expect(router.serializeUrl(res)).toBe('/reports/cash-book?from=2026-11-20&to=2026-11-20');
    });

    it('a Super Admin can open an All branches link', () => {
      const { auth } = setup(SA_BRANCHES, true, '2');
      const router = TestBed.inject(Router);
      const res = TestBed.runInInjectionContext(() => reportBranchGuard(snapshot({ from: '2026-11-16', to: '2026-11-22', branch: 'all', branches: '2' }), {} as RouterStateSnapshot)) as UrlTree;
      expect(auth.activeBranchId()).toBe('all');
      expect(router.serializeUrl(res)).toBe('/reports/cash-book?from=2026-11-16&to=2026-11-22&branches=2');
    });

    it('GA (no KDY access) stays, sees the message and no report request is sent', async () => {
      const gal: BranchAccess = { ...GAL, roles: ['Accountant'], permissions: ['report.view', 'report.export', 'account.view'] };
      const { auth, http } = setup([gal], false, '3', {}, link);
      const res = TestBed.runInInjectionContext(() => reportBranchGuard(snapshot(link), {} as RouterStateSnapshot));
      expect(res).toBeTrue();
      expect(auth.activeBranchId()).toBe('3');
      const { fixture, el } = mount(CashBookComponent);
      await settle(fixture);
      expect(text(el.querySelector('.alert')!)).toBe('You do not have access to this branch.');
      http.expectNone(() => true);
      http.verify();
      fixture.destroy();
    });
  });
});
