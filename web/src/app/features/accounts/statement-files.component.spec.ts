import { ComponentFixture, TestBed } from '@angular/core/testing';
import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { provideRouter } from '@angular/router';
import { Component } from '@angular/core';
import { AuthService } from '../../core/auth.service';
import { me } from '../../core/notifications.service.spec';
import { ToastService } from '../../core/toast.service';
import { StatementFile, StatementFileList, statementFilesText } from '../../core/attachments';
import { phoneFrame } from '../../../testing/phone-frame';
import { ReconcileComponent, ReconStatement, Reconciliation } from './reconcile.component';
import { BankReconciliationReportComponent, BankReport } from '../reports/bank-reconciliation.component';

@Component({ standalone: true, template: '' })
class BlankComponent {}

const ACCOUNTANT = { id: 2, code: 'KDY', name: 'Kandy', is_main: false, roles: ['Accountant'],
  permissions: ['account.view', 'account.reconcile', 'report.view', 'audit.view', 'transaction.view'] };

const SHA = '3fa9c01b77de' + 'a'.repeat(52);
const URL = '/api/v1/accounts/5/reconciliation/statements/3/attachments';

function setup() {
  TestBed.configureTestingModule({
    providers: [provideHttpClient(), provideHttpClientTesting(), provideRouter([{ path: '**', component: BlankComponent }])],
  });
  const auth = TestBed.inject(AuthService);
  auth.token.set('token');
  auth.me.set(me([ACCOUNTANT]));
  auth.meAt = Date.now() + 60_000;
  auth.switchBranch('2');
}

function statement(over: Partial<ReconStatement> = {}): ReconStatement {
  return { id: 3, statement_date: '2026-10-31', statement_balance: '479500.00', lines_count: 4, self_count: 2, status: 'completed',
    completed_by: { id: 7, name: 'ACC', tag: null }, completed_at: '2026-11-03T04:00:00Z', undone_by: null, undone_at: null, undo_reason: null,
    changed_since_completion: false, can_undo: true, attachments_count: 0, attachments_removed_count: 0, ...over };
}

function recon(statements: ReconStatement[]): Reconciliation {
  return { account: { id: 5, code: '11002', name: 'KDY Bank', status: 'active', branch: { id: 2, code: 'KDY', name: 'Kandy' } },
    last_statement: statements.length ? { id: 3, statement_date: '2026-10-31', statement_balance: '479500.00' } : null, book_balance_at: '0.00', reconciled_at: '0.00',
    cleared_not_listed: '0.00', lines_limited: false, lines: [], late_entries_count: 0, statements };
}

function file(over: Partial<StatementFile> = {}): StatementFile {
  return { id: 41, bank_statement_id: 3, original_name: 'KDY-Bank-Oct-2026 statement with a very long name.pdf', mime: 'application/pdf', extension: 'pdf',
    size_bytes: 1_258_291, sha256: SHA, added_after: null, by_preparer: true, also_on: [], uploaded_by: { id: 7, name: 'ACC', tag: null },
    created_at: '2026-11-03T04:35:00Z', removed_at: null, removed_by: null, removal_reason: null, replaced_by_id: null, replaced_by_name: null,
    can_remove: true, ...over };
}

function list(data: StatementFile[], over: Partial<StatementFileList> = {}): StatementFileList {
  const active = data.filter(a => !a.removed_at).length;
  return { data, can_add: true, can_remove: true, max: 5, max_total: 10, active_count: active, removed_count: data.length - active,
    total_uploaded: data.length, can_download_removed: true, note: null, ...over };
}

function button(el: ParentNode, text: string): HTMLButtonElement | undefined {
  return Array.from(el.querySelectorAll<HTMLButtonElement>('button')).find(b => b.textContent!.trim().startsWith(text));
}

async function settle(f: ComponentFixture<unknown>) {
  f.detectChanges();
  await f.whenStable();
  f.detectChanges();
}

/** AG-033b S1a / S3 (docs/specs/bank-statement-attachment.md): AC 4, 11, 19, 20, 22, 23 on the web side. */
describe('AG-033b bank statement copy (web)', () => {
  let http: HttpTestingController;
  let el: HTMLElement;

  afterEach(() => { el?.remove(); TestBed.inject(ToastService).clear(); });

  async function screen(statements: ReconStatement[]) {
    setup();
    http = TestBed.inject(HttpTestingController);
    const fixture = TestBed.createComponent(ReconcileComponent);
    el = fixture.nativeElement as HTMLElement;
    document.body.appendChild(el);
    fixture.componentRef.setInput('id', '5');
    fixture.detectChanges();
    http.match('/api/v1/accounts').forEach(r => r.flush([{ id: 5, name: 'KDY Bank', type: 'bank', branch: { id: 2, code: 'KDY' } }]));
    http.expectOne(r => r.url === '/api/v1/accounts/5/reconciliation').flush(recon(statements));
    await settle(fixture);
    // The first load moves the default date to today when the previous month end is already reconciled: a second load.
    http.match(r => r.url === '/api/v1/accounts/5/reconciliation').forEach(r => r.flush(recon(statements)));
    await settle(fixture);
    return fixture;
  }

  /** The next request matching `match`, once the component has sent it. */
  async function next(match: (r: { url: string }) => boolean) {
    for (let i = 0; i < 50; i++) {
      const found = http.match(r => match(r));
      if (found.length) return found[0];
      await new Promise(r => setTimeout(r));
    }
    throw new Error('request not sent');
  }

  async function openPanel(f: ComponentFixture<ReconcileComponent>, rows: StatementFile[], over: Partial<StatementFileList> = {}) {
    el.querySelector<HTMLButtonElement>('.rc-bs-cell')!.click();
    await settle(f);
    http.expectOne(r => r.url === URL).flush(list(rows, over));
    await settle(f);
  }

  it('AC 20: the Bank statement column reads "1 file (1 removed)" or "No copy"', async () => {
    expect(statementFilesText(1, 1)).toBe('1 file (1 removed)');
    expect(statementFilesText(2, 0)).toBe('2 files');
    await screen([statement({ id: 4, statement_date: '2026-11-30' }), statement({ attachments_count: 1, attachments_removed_count: 1 }),
      statement({ id: 2, statement_date: '2026-09-30', status: 'undone' })]);
    const cells = Array.from(el.querySelectorAll('.rc-bs-cell')).map(c => c.textContent!.replace(/\s+/g, ' ').trim());
    expect(cells).toEqual(['No copy', '1 file (1 removed)', '0 files']);
  });

  it('AC 20 / F1: the Complete toast opens the new statement\'s Files panel', async () => {
    const f = await screen([]);
    const toast = TestBed.inject(ToastService);
    const c = f.componentInstance;
    c.statementDate.set('2026-10-31');
    c.balance.set('0.00');
    await settle(f);
    spyOn(window, 'confirm').and.returnValue(true);
    const done = c.complete();
    (await next(r => r.url.endsWith('/reconciliation/complete'))).flush({ id: 3 });
    (await next(r => r.url === '/api/v1/accounts/5/reconciliation')).flush(recon([statement()]));
    await done;
    await settle(f);
    expect(toast.text()).toBe('KDY Bank reconciled to 31 Oct 2026.');
    expect(toast.action()?.label).toBe('Attach bank statement');
    toast.runAction();
    await settle(f);
    expect(c.filesOf()).toBe(3);
    http.expectOne(r => r.url === URL).flush(list([]));
    await settle(f);
    expect(el.querySelector('.sf-panel h2')!.textContent).toBe('Bank statement copy: KDY Bank, 31 Oct 2026 (0 of 5)');
    expect(el.querySelector('.sf-empty')!.textContent).toContain('No bank statement copy attached. Attach it, or file the paper bank statement');
  });

  it('AC 23 / AC 11 / AC 4: tags, short sha256 with the full value in the tooltip, and the actions', async () => {
    const f = await screen([statement({ attachments_count: 2 })]);
    await openPanel(f, [
      file(),
      file({ id: 42, original_name: 'oct-p2.pdf', by_preparer: false, added_after: 'close', can_remove: false,
        also_on: [{ statement_id: 2, statement_date: '2026-10-31', status: 'undone' }] }),
      file({ id: 40, original_name: 'Sep.pdf', removed_at: '2026-11-03T04:40:00Z', removed_by: { id: 7, name: 'ACC' },
        removal_reason: 'September statement uploaded by mistake', replaced_by_id: 41, replaced_by_name: 'Oct.pdf', can_remove: false }),
    ]);
    const rows = Array.from(el.querySelectorAll<HTMLElement>('.sf-row:not(.sf-th)'));
    expect(rows.length).toBe(2);
    expect(el.querySelector('.sf-panel h2')!.textContent).toBe('Bank statement copy: KDY Bank, 31 Oct 2026 (2 of 5)');
    const first = rows[0].textContent!.replace(/\s+/g, ' ');
    expect(first).toContain('Uploaded by the preparer');
    expect(first).toContain('2026-11-03 10:05');
    expect(first).toContain('sha256 3fa9c01b77de…');
    expect(rows[0].querySelector('.sf-sha-btn')!.getAttribute('title')).toBe(SHA);
    for (const b of ['Download', 'Open', 'Replace', 'Remove']) expect(button(rows[0], b)).withContext(b).toBeTruthy();
    const second = rows[1].textContent!.replace(/\s+/g, ' ');
    expect(second).toContain('Added after close');
    expect(second).not.toContain('Uploaded by the preparer');
    expect(second).toContain('Also on 31 Oct 2026 statement');
    expect(second).toContain('This file is also attached to the undone statement of 31 Oct 2026.');
    expect(button(rows[1], 'Replace')).toBeUndefined();
    expect(button(rows[1], 'Remove')).toBeUndefined();

    el.querySelector<HTMLInputElement>('.att-toggle input')!.click();
    await settle(f);
    const removed = el.querySelector<HTMLElement>('.sf-row.att-removed')!;
    expect(removed.textContent!.replace(/\s+/g, ' ')).toContain('Replaced by Oct.pdf, by ACC on 2026-11-03: September statement uploaded by mistake');
  });

  it('F6 / F7: the note is shown and Add files follows can_add and the 5-file limit', async () => {
    const f = await screen([statement({ attachments_count: 5 })]);
    await openPanel(f, [1, 2, 3, 4, 5].map(i => file({ id: i, sha256: String(i).repeat(64), can_remove: false })),
      { note: 'October 2026 is locked: files can be added as evidence but not removed or replaced.', can_remove: false });
    expect(el.querySelector('.att-note')!.textContent).toContain('October 2026 is locked');
    const add = button(el, 'Add files')!;
    expect(add.disabled).toBeTrue();
    expect(add.parentElement!.getAttribute('title')).toBe('5 files attached. Remove one first.');
    expect(el.querySelector('input[type="file"]')!.getAttribute('accept')).toBe('.jpg,.jpeg,.png,.pdf,image/jpeg,image/png,application/pdf');
    expect(button(el, 'Remove')).toBeUndefined();
  });

  it('AC 22: at 390px the Files sheet is full screen, one card per file, 44px buttons, no horizontal scroll', async () => {
    const f = await screen([statement({ attachments_count: 2 })]);
    await openPanel(f, [file(), file({ id: 42, sha256: 'b'.repeat(64) })]);
    const frame = await phoneFrame();
    frame.adopt(el);
    await settle(f);
    const win = frame.win;
    const panel = el.querySelector<HTMLElement>('.sf-panel')!;
    expect(win.getComputedStyle(panel).position).toBe('fixed');
    expect(win.getComputedStyle(el.querySelector('.sf-head')!).position).toBe('sticky');
    expect(el.querySelector<HTMLElement>('.sf-close')!.getBoundingClientRect().height).toBeGreaterThanOrEqual(44);
    expect(win.getComputedStyle(el.querySelector('.sf-th')!).display).toBe('none');
    for (const row of Array.from(el.querySelectorAll<HTMLElement>('.sf-row:not(.sf-th)'))) {
      const acts = Array.from(row.querySelectorAll<HTMLElement>('.sf-actions button'));
      expect(acts.length).toBe(4);
      for (const b of acts) expect(b.getBoundingClientRect().height).toBeGreaterThanOrEqual(44);
      // 2 x 2 grid
      expect(Math.round(acts[0].getBoundingClientRect().top)).toBe(Math.round(acts[1].getBoundingClientRect().top));
      expect(acts[2].getBoundingClientRect().top).toBeGreaterThan(acts[0].getBoundingClientRect().top);
      expect(row.getBoundingClientRect().right).toBeLessThanOrEqual(390);
    }
    expect(button(el, 'Add files')!.getBoundingClientRect().height).toBeGreaterThanOrEqual(44);
    expect(panel.scrollWidth).toBeLessThanOrEqual(panel.clientWidth);
    expect(frame.doc.documentElement.scrollWidth).toBeLessThanOrEqual(390);
    // The history card has the full-width Files button.
    expect(win.getComputedStyle(el.querySelector('.rc-files-btn')!).display).not.toBe('none');
    frame.remove();
  });

  it('review M1: at 390px the Remove and Replace dialogs sit above the Files sheet and can be used', async () => {
    const f = await screen([statement({ attachments_count: 1 })]);
    await openPanel(f, [file()]);
    const frame = await phoneFrame();
    frame.adopt(el);
    await settle(f);
    for (const action of ['Remove', 'Replace']) {
      button(el.querySelector('.sf-row:not(.sf-th)')!, action)!.click();
      await settle(f);
      const dialog = el.querySelector<HTMLElement>('.sf-dialog')!;
      expect(dialog).withContext(action).toBeTruthy();
      const r = dialog.getBoundingClientRect();
      const hit = frame.doc.elementFromPoint(r.left + r.width / 2, r.top + r.height / 2);
      expect(dialog.contains(hit)).withContext(`${action}: the dialog centre is the dialog`).toBeTrue();
      const confirm = button(dialog, action)!;
      const c = confirm.getBoundingClientRect();
      expect(dialog.contains(frame.doc.elementFromPoint(c.left + c.width / 2, c.top + c.height / 2))).withContext(`${action}: confirm reachable`).toBeTrue();
      // The backdrop covers the sheet too.
      const back = el.querySelector<HTMLElement>('.sf-backdrop')!;
      expect(frame.doc.elementFromPoint(5, 400)).toBe(back);
      back.click();
      await settle(f);
      expect(el.querySelector('.sf-dialog')).toBeNull();
    }
    frame.remove();
  });

  it('AC 19: the S3 block on screen, sha256 shortened with a tooltip, removed lines, and "No copy" text', async () => {
    setup();
    http = TestBed.inject(HttpTestingController);
    const fixture = TestBed.createComponent(BankReconciliationReportComponent);
    el = fixture.nativeElement as HTMLElement;
    document.body.appendChild(el);
    const report = (attachments: StatementFile[]): BankReport => ({
      title: 'Bank reconciliation statement', branches: [], all_branches: false, generated_at: '2026-11-20T04:00:00Z', generated_by: 'ACC',
      as_at: '2026-10-31', older_than: null, older_than_ignored: false, filter_note: null, basis: '', accounts: [],
      statement: { id: 3, account_id: 5, status: 'completed', statement_date: '2026-10-31', statement_balance: '479500.00', overdrawn: false,
        expected_bank_balance: '479500.00', difference: '0.00', changed_since_completion: false, late_entries: [], lines_count: 4, self_count: 2,
        completed_by: { id: 7, name: 'ACC', tag: null }, completed_at: '2026-11-03T04:00:00Z', undone: null, attachments },
    } as unknown as BankReport);
    fixture.detectChanges();
    http.match(r => r.url === '/api/v1/accounts').forEach(r => r.flush([]));
    http.expectOne(r => r.url === '/api/v1/reports/bank-reconciliation').flush(report([
      file({ original_name: 'oct.pdf', also_on: [{ statement_id: 2, statement_date: '2026-09-30', status: 'completed' }] }),
      file({ id: 40, original_name: 'sep.pdf', removed_at: '2026-11-03T04:40:00Z', removed_by: { id: 7, name: 'ACC' }, removal_reason: 'Wrong month',
        replaced_by_id: 41, replaced_by_name: 'oct.pdf' })]));
    await settle(fixture);
    const cards = Array.from(el.querySelectorAll<HTMLElement>('.bsc-card'));
    expect(cards.length).toBe(2);
    const line = cards[0].textContent!.replace(/\s+/g, ' ');
    expect(line).toContain('Bank statement attached: oct.pdf (PDF, 1.2 MB), uploaded by ACC on 2026-11-03 10:05');
    expect(line).toContain('sha256 3fa9c01b77de… - uploaded by the preparer - also on the 2026-09-30 statement (completed)');
    expect(cards[0].querySelector('.mono')!.getAttribute('title')).toBe(SHA);
    expect(line.toLowerCase().split('uploaded by the preparer').length - 1).withContext('each tag once (QA D2)').toBe(1);
    expect(cards[1].textContent).toContain('Replaced by oct.pdf: sep.pdf');
    fixture.componentInstance.r.set(report([]));
    fixture.detectChanges();
    expect(el.querySelector('.bsc-card')!.textContent).toContain('No bank statement copy attached. File the paper bank statement with this printout.');
  });
});
