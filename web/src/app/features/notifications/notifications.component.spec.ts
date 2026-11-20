import { ComponentFixture, TestBed, fakeAsync, flushMicrotasks } from '@angular/core/testing';
import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { ActivatedRoute, Router, provideRouter } from '@angular/router';
import { Component } from '@angular/core';
import { AuthService } from '../../core/auth.service';
import { NotificationPage } from '../../core/notifications.service';
import { me, row } from '../../core/notifications.service.spec';
import { ToastService } from '../../core/toast.service';
import { NotificationOpenComponent } from './notification-open.component';
import { NotificationsComponent } from './notifications.component';
import { phoneFrame } from '../../../testing/phone-frame';

@Component({ standalone: true, template: '' })
class BlankComponent {}

function page(data = [row()], unread = 1): NotificationPage {
  return { data, meta: { current_page: 1, last_page: 1, per_page: 20, total: data.length }, unread_count: unread, fetched_at: '2026-11-20T10:00:00Z' };
}

function setup(extra: unknown[] = []) {
  spyOnProperty(Document.prototype, 'visibilityState', 'get').and.returnValue('visible');
  TestBed.configureTestingModule({
    providers: [provideHttpClient(), provideHttpClientTesting(), provideRouter([{ path: '**', component: BlankComponent }]), ...(extra as [])],
  });
  const auth = TestBed.inject(AuthService);
  auth.token.set('token');
  auth.me.set(me());
  auth.meAt = Date.now() + 60_000;
  auth.switchBranch('3');
}

/** S2 Notifications page: AC 29, 30 (stacked cards), F9 and the empty states. */
describe('NotificationsComponent', () => {
  let fixture: ComponentFixture<NotificationsComponent>;
  let http: HttpTestingController;
  let el: HTMLElement;

  beforeEach(() => {
    setup();
    http = TestBed.inject(HttpTestingController);
    fixture = TestBed.createComponent(NotificationsComponent);
    el = fixture.nativeElement;
    document.body.appendChild(el);
    fixture.detectChanges();
  });

  afterEach(() => { el.remove(); TestBed.inject(ToastService).clear(); http.verify(); });

  const list = (filter: string) => http.expectOne(r => r.url === '/api/v1/notifications' && r.params.get('filter') === filter);
  const prefs = () => http.expectOne(r => r.method === 'GET' && r.url === '/api/v1/notifications/preferences');

  it('defaults to All when nothing is unread, with the 90-day empty text and footer note', () => {
    list('unread').flush(page([], 0));
    list('all').flush(page([], 0));
    prefs().flush({ approval_request_email: true, can_approve: false });
    fixture.detectChanges();
    expect(el.textContent).toContain('No notifications in the last 90 days.');
    expect(el.textContent).toContain('Notifications are kept for 90 days.');
  });

  it('AC 29: no Email section for the GAL Cashier', () => {
    list('unread').flush(page());
    prefs().flush({ approval_request_email: true, can_approve: false });
    fixture.detectChanges();
    expect(el.textContent).not.toContain('Email me when a transfer needs my approval');
  });

  it('AC 29: the approver sees the box ticked; unticking saves and says "Preference saved."; an error reverts it', () => {
    list('unread').flush(page());
    prefs().flush({ approval_request_email: true, can_approve: true });
    fixture.detectChanges();
    const box = el.querySelector<HTMLInputElement>('.nt-check input')!;
    expect(box.checked).toBeTrue();
    expect(el.textContent).toContain('Emails about your own requests and exports are sent as before.');

    box.click();
    const put = http.expectOne(r => r.method === 'PUT' && r.url === '/api/v1/notifications/preferences');
    expect(put.request.body).toEqual({ approval_request_email: false });
    put.flush({ approval_request_email: false, can_approve: true });
    fixture.detectChanges();
    expect(TestBed.inject(ToastService).text()).toBe('Preference saved.');
    expect(el.querySelector<HTMLInputElement>('.nt-check input')!.checked).toBeFalse();

    el.querySelector<HTMLInputElement>('.nt-check input')!.click();
    http.expectOne(r => r.method === 'PUT').flush({ message: 'x' }, { status: 500, statusText: 'Server Error' });
    fixture.detectChanges();
    expect(el.querySelector<HTMLInputElement>('.nt-check input')!.checked).toBeFalse();
    expect(el.textContent).toContain('Could not save. Try again.');
  });

  it('F9: Mark all as read sends the fetched_at of the list', () => {
    list('unread').flush(page());
    prefs().flush({ approval_request_email: true, can_approve: false });
    fixture.detectChanges();
    const btn = Array.from(el.querySelectorAll('button')).find(b => b.textContent!.includes('Mark all as read'))!;
    btn.click();
    const req = http.expectOne('/api/v1/notifications/read-all');
    expect(req.request.body).toEqual({ before: '2026-11-20T10:00:00Z' });
  });

  it('AC 30: at 390px rows are stacked cards of at least 44 px, buttons wrap under the title, no horizontal scroll', async () => {
    const rows = Array.from({ length: 5 }, (_, i) => row({ id: `n-${i}`, title: `KDY-TRF-00${i} needs approval with quite a long title`,
      body: 'KDY -> GAL, 150,000.00, requested by Anura Kumara Perera of the Kandy branch office', read_at: i % 2 ? '2026-11-20T09:00:00Z' : null }));
    list('unread').flush(page(rows, 3));
    prefs().flush({ approval_request_email: true, can_approve: true });
    const f = await phoneFrame();
    f.adopt(el);
    fixture.detectChanges();
    expect(el.querySelector('table')).toBeNull();
    expect(f.win.getComputedStyle(el.querySelector('.nt-th')!).display).toBe('none');
    const cards = el.querySelectorAll<HTMLElement>('a.nt-row');
    expect(cards.length).toBe(5);
    cards.forEach(c => {
      expect(c.getBoundingClientRect().height).toBeGreaterThanOrEqual(44);
      const title = c.querySelector('.nt-msg')!.getBoundingClientRect();
      const when = c.querySelector('.nt-when')!.getBoundingClientRect();
      const tag = c.querySelector('.nt-branch')!.getBoundingClientRect();
      expect(title.bottom).toBeLessThanOrEqual(when.top + 1); // line 1-2 title and body, line 3 branch tag and time
      expect(Math.abs(tag.top - when.top)).toBeLessThan(6);
    });
    const h1 = el.querySelector('h1')!.getBoundingClientRect();
    expect(el.querySelector('.nt-actions')!.getBoundingClientRect().top).toBeGreaterThanOrEqual(h1.bottom);
    expect(f.doc.documentElement.scrollWidth).toBeLessThanOrEqual(f.doc.documentElement.clientWidth);
    f.remove();
  });
});

/** S3 route /notifications/:id (the email link): AC 28. */
describe('NotificationOpenComponent', () => {
  let http: HttpTestingController;
  let router: Router;
  let id = 'n-1';

  beforeEach(() => {
    setup([{ provide: ActivatedRoute, useValue: { snapshot: { paramMap: { get: () => id } } } }]);
    http = TestBed.inject(HttpTestingController);
    router = TestBed.inject(Router);
    spyOn(router, 'navigateByUrl').and.returnValue(Promise.resolve(true));
  });

  afterEach(() => { TestBed.inject(ToastService).clear(); http.verify(); });

  it('AC 28: the owner opening from GAL switches to MAIN, opens the transfer and replaces the link in history', fakeAsync(() => {
    id = 'n-1';
    TestBed.createComponent(NotificationOpenComponent).detectChanges();
    http.expectOne('/api/v1/notifications/n-1').flush(row());
    flushMicrotasks();
    http.expectOne('/api/v1/notifications/n-1/read').flush({ id: 'n-1', read_at: '2026-11-20T10:00:00Z' });
    flushMicrotasks();
    expect(TestBed.inject(AuthService).activeBranchId()).toBe('1');
    expect(router.navigateByUrl).toHaveBeenCalledOnceWith('/transactions/41', { replaceUrl: true });
  }));

  it('AC 28: another user\'s id goes to /notifications with "Notification not found."', fakeAsync(() => {
    id = 'not-mine';
    TestBed.createComponent(NotificationOpenComponent).detectChanges();
    http.expectOne('/api/v1/notifications/not-mine').flush({ message: 'Notification not found.' }, { status: 404, statusText: 'Not Found' });
    flushMicrotasks();
    expect(router.navigateByUrl).toHaveBeenCalledOnceWith('/notifications', { replaceUrl: true });
    expect(TestBed.inject(ToastService).text()).toBe('Notification not found.');
    expect(TestBed.inject(AuthService).activeBranchId()).toBe('3');
    TestBed.inject(ToastService).clear();
  }));
});

