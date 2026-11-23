import { TestBed } from '@angular/core/testing';
import { provideHttpClient } from '@angular/common/http';
import { provideHttpClientTesting } from '@angular/common/http/testing';
import { ActivatedRoute, Router, provideRouter } from '@angular/router';
import { Component, OnInit, inject } from '@angular/core';
import { AuthService } from '../core/auth.service';
import { NotificationsService } from '../core/notifications.service';
import { me } from '../core/notifications.service.spec';
import { writeQuery } from '../features/reports/report-common';
import { ShellComponent } from './shell.component';

@Component({ standalone: true, template: 'dashboard' })
class DashboardStub {}

/** A report screen as they all start: it writes its filters into the URL in ngOnInit. */
@Component({ standalone: true, template: 'report' })
class ReportStub implements OnInit {
  private router = inject(Router);
  private route = inject(ActivatedRoute);
  ngOnInit() { writeQuery(this.router, this.route, { status: 'all', from: '2026-01-01' }); }
}

const KDY = { id: 2, code: 'KDY', name: 'Kandy', is_main: false, roles: ['Viewer'], permissions: ['report.view'] };
const GAL = { id: 3, code: 'GAL', name: 'Galle', is_main: false, roles: ['Viewer'], permissions: ['report.view'] };
const XTR = { id: 4, code: 'XTR', name: 'Extra', is_main: false, roles: ['Cashier'], permissions: ['transaction.view'] };

/** QA D1 (AG-033c AC 19): a branch switch to a branch without report.view leaves /reports on every report screen. */
describe('ShellComponent branch switch', () => {
  let el: HTMLElement;

  afterEach(() => { TestBed.inject(NotificationsService).stop(); el?.remove(); });

  async function start() {
    TestBed.configureTestingModule({
      providers: [provideHttpClient(), provideHttpClientTesting(),
        provideRouter([{ path: 'dashboard', component: DashboardStub }, { path: 'reports/test', component: ReportStub }])],
    });
    const auth = TestBed.inject(AuthService);
    auth.token.set('token');
    auth.me.set(me([KDY, GAL, XTR]));
    auth.meAt = Date.now() + 60_000;
    auth.switchBranch('2');
    const fixture = TestBed.createComponent(ShellComponent);
    el = fixture.nativeElement;
    const router = TestBed.inject(Router);
    await router.navigateByUrl('/reports/test');
    const settle = async () => { for (let i = 0; i < 5; i++) { fixture.detectChanges(); await fixture.whenStable(); } };
    await settle();
    return { fixture, router, settle };
  }

  it('the redirect to /dashboard wins over the report page writing its query', async () => {
    const { fixture, router, settle } = await start();
    expect(router.url).toContain('/reports/test?');
    fixture.componentInstance.switch('4');
    await settle();
    expect(router.url).toBe('/dashboard');
    expect(el.textContent).toContain('dashboard');
  });

  it('a switch to a branch with report.view keeps the report and re-creates it', async () => {
    const { fixture, router, settle } = await start();
    fixture.componentInstance.switch('3');
    await settle();
    expect(router.url).toContain('/reports/test?');
    expect(el.textContent).toContain('report');
  });
});
