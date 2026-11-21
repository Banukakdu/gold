import { TestBed, fakeAsync, flushMicrotasks, tick } from '@angular/core/testing';
import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { Router, provideRouter } from '@angular/router';
import { Component } from '@angular/core';
import { AuthService } from './auth.service';
import { Me } from './models';
import { NotificationRow, NotificationsService, POLL_MS, badgeText, relativeTime } from './notifications.service';
import { ToastService } from './toast.service';

@Component({ standalone: true, template: '' })
class BlankComponent {}

const MAIN = { id: 1, code: 'MAIN', name: 'Main', is_main: true, roles: ['Branch Admin'], permissions: ['transaction.view', 'transfer.approve'] };
const KDY = { id: 2, code: 'KDY', name: 'Kandy', is_main: false, roles: ['Branch Admin'], permissions: ['transaction.view'] };
const GAL = { id: 3, code: 'GAL', name: 'Galle', is_main: false, roles: ['Cashier'], permissions: ['transaction.view'] };

/** An ordered-UUID high-water mark as the list returns it. */
export const FID = '0193a1b2-c3d4-4e5f-8a9b-0c1d2e3f4a5b';

export function me(branches = [MAIN, KDY], superAdmin = false): Me {
  return { id: 7, name: 'M. Silva', email: 'm@x.lk', is_super_admin: superAdmin, branches };
}

export function row(over: Partial<NotificationRow> = {}): NotificationRow {
  return { id: 'n-1', kind: 'transfer.approval_requested', title: 'KDY-TRF-0012 needs approval', body: 'KDY -> GAL, 150,000.00',
    branch: { id: 1, code: 'MAIN' }, url: '/transactions/41', created_at: new Date().toISOString(), read_at: null, ...over };
}

/** docs/specs/notifications.md R9 polling and the S3 open flow (AC 23-28 at the service level). */
describe('NotificationsService', () => {
  let svc: NotificationsService;
  let http: HttpTestingController;
  let auth: AuthService;
  let router: Router;
  let toast: ToastService;
  let visible: DocumentVisibilityState;

  beforeEach(() => {
    visible = 'visible';
    spyOnProperty(Document.prototype, 'visibilityState', 'get').and.callFake(() => visible);
    TestBed.configureTestingModule({
      providers: [provideHttpClient(), provideHttpClientTesting(), provideRouter([{ path: '**', component: BlankComponent }])],
    });
    svc = TestBed.inject(NotificationsService);
    http = TestBed.inject(HttpTestingController);
    auth = TestBed.inject(AuthService);
    router = TestBed.inject(Router);
    toast = TestBed.inject(ToastService);
    auth.token.set('token');
    auth.me.set(me());
    auth.meAt = Date.now() + 60_000;
    auth.switchBranch('2'); // KDY
    spyOn(router, 'navigateByUrl').and.returnValue(Promise.resolve(true));
  });

  afterEach(() => { svc.stop(); http.verify(); });

  it('AC 23: badge hidden at 0, the number up to 99, "99+" above', () => {
    expect(badgeText(0)).toBe('');
    expect(badgeText(1)).toBe('1');
    expect(badgeText(99)).toBe('99');
    expect(badgeText(120)).toBe('99+');
  });

  it('relative time with the full time left to the tooltip', () => {
    const now = Date.parse('2026-11-20T10:00:00Z');
    expect(relativeTime('2026-11-20T09:59:40Z', now)).toBe('just now');
    expect(relativeTime('2026-11-20T09:55:00Z', now)).toBe('5 min ago');
    expect(relativeTime('2026-11-20T07:00:00Z', now)).toBe('3 h ago');
    expect(relativeTime('2026-11-18T09:00:00Z', now)).toBe('2 days ago');
  });

  it('AC 24: polls every 60 s while visible, never while hidden, and at once when visible again', fakeAsync(() => {
    svc.start();
    http.expectOne('/api/v1/notifications/unread-count').flush({ unread_count: 0 });
    expect(svc.unread()).toBe(0);

    tick(POLL_MS);
    http.expectOne('/api/v1/notifications/unread-count').flush({ unread_count: 1 });
    expect(svc.unread()).toBe(1);

    visible = 'hidden';
    document.dispatchEvent(new Event('visibilitychange'));
    tick(POLL_MS * 3);
    http.expectNone('/api/v1/notifications/unread-count');

    visible = 'visible';
    document.dispatchEvent(new Event('visibilitychange'));
    http.expectOne('/api/v1/notifications/unread-count').flush({ unread_count: 2 });
    expect(svc.unread()).toBe(2);
    tick(POLL_MS);
    http.expectOne('/api/v1/notifications/unread-count').flush({ unread_count: 2 });
    svc.stop();
  }));

  it('R9: polling stops on logout and on a 401', fakeAsync(() => {
    svc.start();
    http.expectOne('/api/v1/notifications/unread-count').flush({ message: 'Unauthenticated.' }, { status: 401, statusText: 'Unauthorized' });
    tick(POLL_MS * 2);
    http.expectNone('/api/v1/notifications/unread-count');
    expect(svc.unread()).toBe(0);

    svc.start();
    http.expectOne('/api/v1/notifications/unread-count').flush({ unread_count: 0 });
    auth.clear();
    TestBed.flushEffects();
    tick(POLL_MS * 2);
    http.expectNone('/api/v1/notifications/unread-count');
  }));

  it('AC 25: a MAIN row marks read, switches to MAIN and opens the transfer', fakeAsync(() => {
    svc.unread.set(1);
    let done: boolean | undefined;
    svc.open(row()).then(r => done = r);
    http.expectOne(r => r.method === 'POST' && r.url === '/api/v1/notifications/n-1/read').flush({ id: 'n-1', read_at: '2026-11-20T10:00:00Z' });
    flushMicrotasks();
    expect(auth.activeBranchId()).toBe('1');
    expect(router.navigateByUrl).toHaveBeenCalledWith('/transactions/41', { replaceUrl: undefined });
    expect(svc.unread()).toBe(0);
    expect(done).toBeTrue();
  }));

  it('AC 26: a branch the user lost (fresh /me) marks read, keeps the branch and says so', fakeAsync(() => {
    auth.me.set(me([GAL, KDY]));
    auth.meAt = 0; // older than the last poll: /me is reloaded first
    svc.start();
    http.expectOne('/api/v1/notifications/unread-count').flush({ unread_count: 1 });
    svc.stop();
    let done: boolean | undefined;
    svc.open(row({ branch: { id: 3, code: 'GAL' }, kind: 'transfer.receipt_requested' })).then(r => done = r);
    http.expectOne('/api/v1/notifications/n-1/read').flush({ id: 'n-1', read_at: '2026-11-20T10:00:00Z' });
    flushMicrotasks();
    http.expectOne('/api/v1/me').flush(me([KDY]));
    flushMicrotasks();
    expect(done).toBeFalse();
    expect(auth.activeBranchId()).toBe('2');
    expect(router.navigateByUrl).not.toHaveBeenCalled();
    expect(toast.text()).toBe('You no longer have access to GAL.');
    toast.clear();
  }));

  it('AC 27: a Super Admin in All branches opens a backup row without switching, and switches for a MAIN row', fakeAsync(() => {
    auth.me.set(me([MAIN, KDY, GAL], true));
    auth.switchBranch('all');
    svc.open(row({ id: 'b-1', kind: 'backup.failed', branch: null, url: '/backups' }));
    http.expectOne('/api/v1/notifications/b-1/read').flush({ id: 'b-1', read_at: '2026-11-20T10:00:00Z' });
    flushMicrotasks();
    expect(router.navigateByUrl).toHaveBeenCalledWith('/backups', { replaceUrl: undefined });
    expect(auth.activeBranchId()).toBe('all');

    svc.open(row());
    http.expectOne('/api/v1/notifications/n-1/read').flush({ id: 'n-1', read_at: '2026-11-20T10:00:00Z' });
    flushMicrotasks();
    expect(auth.activeBranchId()).toBe('1');
    expect(router.navigateByUrl).toHaveBeenCalledWith('/transactions/41', { replaceUrl: undefined });
  }));

  it('R8: mark all sends the fetched_at and high-water mark of the list it showed', fakeAsync(() => {
    svc.unread.set(3);
    svc.markAll({ fetched_at: '2026-11-20T10:00:00+00:00', fetched_id: FID });
    const req = http.expectOne('/api/v1/notifications/read-all');
    expect(req.request.body).toEqual({ before: '2026-11-20T10:00:00+00:00', before_id: FID });
    req.flush({ marked: 3 });
    flushMicrotasks();
    expect(svc.unread()).toBe(0);
    http.expectOne('/api/v1/notifications/unread-count').flush({ unread_count: 1 });
    expect(svc.unread()).toBe(1);
  }));
});
