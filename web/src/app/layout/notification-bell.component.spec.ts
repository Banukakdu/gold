import { ComponentFixture, TestBed, fakeAsync, flushMicrotasks } from '@angular/core/testing';
import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { Router, provideRouter } from '@angular/router';
import { Component } from '@angular/core';
import { AuthService } from '../core/auth.service';
import { NotificationsService } from '../core/notifications.service';
import { me, row } from '../core/notifications.service.spec';
import { NotificationBellComponent } from './notification-bell.component';
import { phoneFrame } from '../../testing/phone-frame';

@Component({ standalone: true, template: '' })
class BlankComponent {}

/** A header like the shell's: branch switcher, bell, user name (docs/specs/notifications.md S1). */
@Component({
  standalone: true,
  imports: [NotificationBellComponent],
  template: `
    <div class="main"><header class="top">
      <label class="switcher"><span>Branch</span><select><option>KDY · Kandy branch office</option></select></label>
      <ag-notification-bell />
      <div class="who"><span>Anura Kumara Perera with a long name</span><small>Accountant</small><button class="ghost">Log out</button></div>
    </header></div>`,
})
class HeaderHostComponent {}

/** S1 and AC 23, 25, 30 for the bell. */
describe('NotificationBellComponent', () => {
  let fixture: ComponentFixture<HeaderHostComponent>;
  let http: HttpTestingController;
  let svc: NotificationsService;
  let el: HTMLElement;

  beforeEach(() => {
    spyOnProperty(Document.prototype, 'visibilityState', 'get').and.returnValue('visible');
    TestBed.configureTestingModule({
      imports: [HeaderHostComponent],
      providers: [provideHttpClient(), provideHttpClientTesting(), provideRouter([{ path: '**', component: BlankComponent }])],
    });
    const auth = TestBed.inject(AuthService);
    auth.token.set('token');
    auth.me.set(me());
    auth.meAt = Date.now() + 60_000;
    auth.switchBranch('2');
    http = TestBed.inject(HttpTestingController);
    svc = TestBed.inject(NotificationsService);
    fixture = TestBed.createComponent(HeaderHostComponent);
    el = fixture.nativeElement;
    document.body.appendChild(el);
    fixture.detectChanges();
  });

  afterEach(() => { svc.stop(); el.remove(); http.verify(); });

  const bell = () => el.querySelector<HTMLButtonElement>('.nb-bell')!;
  const badge = () => el.querySelector('.nb-badge');

  it('AC 23: no badge at 0; "99+" and the full number in the aria-label at 120', () => {
    http.expectOne('/api/v1/notifications/unread-count').flush({ unread_count: 0 });
    fixture.detectChanges();
    expect(badge()).toBeNull();
    expect(bell().getAttribute('aria-label')).toBe('Notifications, 0 unread');

    svc.unread.set(120);
    fixture.detectChanges();
    expect(badge()!.textContent!.trim()).toBe('99+');
    expect(bell().getAttribute('aria-label')).toBe('Notifications, 120 unread');
  });

  it('S1: the dropdown loads the latest 10 only when opened, shows empty and error states, closes on Esc', () => {
    http.expectOne('/api/v1/notifications/unread-count').flush({ unread_count: 0 });
    expect(el.querySelector('.nb-panel')).toBeNull();
    bell().click();
    fixture.detectChanges();
    const req = http.expectOne(r => r.url === '/api/v1/notifications');
    expect(req.request.params.get('per_page')).toBe('10');
    req.flush({ data: [], meta: { current_page: 1, last_page: 1, per_page: 10, total: 0 }, unread_count: 0, fetched_at: '2026-11-20T10:00:00Z' });
    fixture.detectChanges();
    expect(el.querySelector('.nb-panel')!.textContent).toContain('No notifications yet.');
    expect(el.querySelector<HTMLButtonElement>('.nb-head button')!.disabled).toBeTrue();

    document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }));
    fixture.detectChanges();
    expect(el.querySelector('.nb-panel')).toBeNull();

    svc.unread.set(4);
    bell().click();
    fixture.detectChanges();
    http.expectOne(r => r.url === '/api/v1/notifications').flush({ message: 'x' }, { status: 500, statusText: 'Server Error' });
    fixture.detectChanges();
    expect(el.querySelector('.nb-panel')!.textContent).toContain('Could not load notifications. Try again.');
    expect(badge()!.textContent!.trim()).toBe('4'); // keeps its last value
  });

  it('AC 25: clicking a MAIN row marks it read, switches to MAIN and opens the transfer', fakeAsync(() => {
    const router = TestBed.inject(Router);
    spyOn(router, 'navigateByUrl').and.returnValue(Promise.resolve(true));
    http.expectOne('/api/v1/notifications/unread-count').flush({ unread_count: 1 });
    bell().click();
    fixture.detectChanges();
    http.expectOne(r => r.url === '/api/v1/notifications').flush({ data: [row()], meta: { current_page: 1, last_page: 1, per_page: 10, total: 1 },
      unread_count: 1, fetched_at: '2026-11-20T10:00:00Z' });
    fixture.detectChanges();
    const link = el.querySelector<HTMLAnchorElement>('.nb-row')!;
    expect(link.classList).toContain('unread');
    expect(link.querySelector('.tag')!.textContent).toBe('MAIN');
    link.click();
    http.expectOne(r => r.method === 'POST' && r.url === '/api/v1/notifications/n-1/read').flush({ id: 'n-1', read_at: '2026-11-20T10:00:01Z' });
    flushMicrotasks();
    expect(TestBed.inject(AuthService).activeBranchId()).toBe('1');
    expect(router.navigateByUrl).toHaveBeenCalledWith('/transactions/41', { replaceUrl: undefined });
    expect(svc.unread()).toBe(0);
  }));

  it('AC 30: at 390px the bell is 44 px and next to the switcher; the dropdown is a full-width panel with its own scroll', async () => {
    http.expectOne('/api/v1/notifications/unread-count').flush({ unread_count: 3 });
    const f = await phoneFrame();
    f.adopt(el);
    fixture.detectChanges();
    expect(f.win.innerWidth).toBe(390);
    const b = bell().getBoundingClientRect();
    expect(b.height).toBeGreaterThanOrEqual(44);
    expect(b.width).toBeGreaterThanOrEqual(44);
    expect(b.right).toBeLessThanOrEqual(390);
    const sw = el.querySelector('.switcher')!.getBoundingClientRect();
    expect(b.top).toBeLessThan(sw.bottom); // same line as the branch switcher
    expect(b.bottom).toBeGreaterThan(sw.top);

    bell().click();
    fixture.detectChanges();
    const many = Array.from({ length: 10 }, (_, i) => row({ id: `n-${i}`, body: 'A long body that is cut to one line with an ellipsis '.repeat(3) }));
    http.expectOne(r => r.url === '/api/v1/notifications').flush({ data: many, meta: { current_page: 1, last_page: 1, per_page: 10, total: 10 },
      unread_count: 3, fetched_at: '2026-11-20T10:00:00Z' });
    fixture.detectChanges();
    const panel = el.querySelector<HTMLElement>('.nb-panel')!.getBoundingClientRect();
    const body = el.querySelector<HTMLElement>('.nb-body')!;
    expect(Math.round(panel.left)).toBe(16);
    // Measured against the layout width (a desktop test browser shows a scrollbar; phones overlay it).
    expect(Math.round(f.doc.documentElement.clientWidth - panel.right)).toBe(16);
    expect(panel.height).toBeLessThanOrEqual(844 * 0.7 + 1);
    expect(f.win.getComputedStyle(body).overflowY).toBe('auto');
    expect(body.scrollHeight).toBeGreaterThan(body.clientHeight); // ten rows scroll inside the panel
    el.querySelectorAll<HTMLElement>('.nb-row').forEach(r => expect(r.getBoundingClientRect().height).toBeGreaterThanOrEqual(44));
    expect(f.doc.documentElement.scrollWidth).toBeLessThanOrEqual(f.doc.documentElement.clientWidth);
    f.remove();
  });
});
