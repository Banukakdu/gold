import { ComponentFixture, TestBed } from '@angular/core/testing';
import { HttpClient, provideHttpClient, withInterceptors } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { ActivatedRoute, Router, convertToParamMap, provideRouter } from '@angular/router';
import { Component, Type } from '@angular/core';
import { firstValueFrom } from 'rxjs';
import { AuthService } from '../../core/auth.service';
import { apiInterceptor } from '../../core/api.interceptor';
import { BranchAccess, Me, UserRow } from '../../core/models';
import { ToastService } from '../../core/toast.service';
import { CODE_ERROR, TwoFactorSetup, groupKey, recoveryFileText } from '../../core/two-factor';
import { phoneFrame } from '../../../testing/phone-frame';
import { ShellComponent } from '../../layout/shell.component';
import { LoginComponent } from '../auth/login.component';
import { UserDetailComponent } from '../users/user-detail.component';
import { AccountSecurityComponent } from './account-security.component';
import { RecoveryCodesComponent } from './recovery-codes.component';
import { TwoFactorSetupComponent } from './two-factor-setup.component';

@Component({ standalone: true, template: '' })
class BlankComponent {}

const KDY: BranchAccess = { id: 2, code: 'KDY', name: 'Kandy', is_main: false, roles: ['Cashier'], permissions: ['transaction.view'] };
const SECRET = 'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP';
const CODES = ['abcde-fghjk', 'mnpqr-stuvw', 'xyz23-45678', '9abcd-efghj', 'kmnpq-rstuv', 'wxyz2-34567', '89abc-defgh', 'jkmnp-qrstu',
  'vwxyz-23456', '789ab-cdefg'];
const SETUP: TwoFactorSetup = { secret: SECRET, otpauth_uri: `otpauth://totp/Account-gold:u%40x.lk?secret=${SECRET}&issuer=Account-gold`,
  expires_at: '2026-11-20T05:27:00Z' };

function user(over: Partial<Me> = {}): Me {
  return { id: 7, name: 'M. Silva', email: 'm@x.lk', is_super_admin: false, branches: [KDY],
    two_factor: { enabled: false, required: false, recovery_codes_left: 0 }, ...over };
}

function setup(query: Record<string, string> = {}, params: Record<string, string> = {}, signedIn: Me | null = user()) {
  TestBed.configureTestingModule({
    providers: [provideHttpClient(withInterceptors([apiInterceptor])), provideHttpClientTesting(),
      provideRouter([{ path: '**', component: BlankComponent }]),
      { provide: ActivatedRoute, useValue: { snapshot: { paramMap: convertToParamMap(params), queryParamMap: convertToParamMap(query) } } }],
  });
  const auth = TestBed.inject(AuthService);
  if (signedIn) {
    auth.token.set('token');
    auth.me.set(signedIn);
    auth.switchBranch(signedIn.is_super_admin ? 'all' : '2');
  }
  return { auth, http: TestBed.inject(HttpTestingController), router: TestBed.inject(Router) };
}

function mount<T>(type: Type<T>): { fixture: ComponentFixture<T>; el: HTMLElement } {
  const fixture = TestBed.createComponent(type);
  const el = fixture.nativeElement as HTMLElement;
  document.body.appendChild(el);
  return { fixture, el };
}

async function settle(f: ComponentFixture<unknown>) { for (let i = 0; i < 3; i++) { f.detectChanges(); await f.whenStable(); } f.detectChanges(); }

function text(el: Element | null) { return (el?.textContent ?? '').replace(/\s+/g, ' ').trim(); }

function type(input: HTMLInputElement, value: string) {
  input.value = value;
  input.dispatchEvent(new Event('input'));
}

function button(el: HTMLElement, label: string): HTMLButtonElement {
  const b = Array.from(el.querySelectorAll('button')).find(x => text(x) === label);
  if (!b) throw new Error(`No button "${label}"`);
  return b as HTMLButtonElement;
}

/** Signs in to the code step: email + password, then the API's challenge. */
async function toCodeStep(kind: 'challenge' | 'enrol' = 'challenge') {
  const ctx = setup({}, {}, null);
  localStorage.removeItem('ag.token');
  const m = mount(LoginComponent);
  await settle(m.fixture);
  type(m.el.querySelector('input[name=email]')!, 'u@x.lk');
  type(m.el.querySelector('input[name=password]')!, 'secret-pass1');
  m.el.querySelector('form')!.dispatchEvent(new Event('submit'));
  ctx.http.expectOne('/api/v1/auth/login').flush({ two_factor: kind, challenge_token: 'CHALLENGE-TOKEN-1234567890', expires_at: '2026-11-20T05:05:00Z' });
  await settle(m.fixture);
  return { ...ctx, ...m };
}

/** docs/specs/two-factor.md (AG-005) web: ACs 35, 53-59. */
describe('Two-factor sign-in (AG-005)', () => {
  afterEach(() => { localStorage.removeItem('ag.token'); });

  it('AC 53: the code step, auto-submit on the 6th digit, a wrong code clears the field, nothing is stored', async () => {
    const { el, fixture, http } = await toCodeStep();
    expect(text(el)).toContain('Two-factor sign-in');
    expect(text(el)).toContain('Enter the 6-digit code from your authenticator app.');
    const code = el.querySelector<HTMLInputElement>('input.tf-code')!;
    expect(code.getAttribute('inputmode')).toBe('numeric');
    expect(code.getAttribute('autocomplete')).toBe('one-time-code');
    expect(code.getAttribute('maxlength')).toBe('6');
    expect(document.activeElement).toBe(code);

    type(code, '123 45');
    await settle(fixture);
    http.expectNone('/api/v1/auth/two-factor/verify');
    type(code, '123 456'); // spaces are removed; the 6th digit submits without a click
    const req = http.expectOne('/api/v1/auth/two-factor/verify');
    expect(req.request.body).toEqual({ challenge_token: 'CHALLENGE-TOKEN-1234567890', code: '123456' });
    req.flush({ message: CODE_ERROR, errors: { code: [CODE_ERROR] } }, { status: 422, statusText: 'Unprocessable' });
    await settle(fixture);
    expect(el.querySelector<HTMLInputElement>('input.tf-code')!.value).toBe('');
    expect(text(el.querySelector('.field-error'))).toBe(CODE_ERROR);

    // The challenge token is in memory only.
    for (let i = 0; i < localStorage.length; i++) {
      expect(localStorage.getItem(localStorage.key(i)!) ?? '').not.toContain('CHALLENGE-TOKEN');
    }
    expect(location.href).not.toContain('CHALLENGE-TOKEN');
    fixture.destroy();
    // A reload is a new component: back to email and password.
    const again = mount(LoginComponent);
    await settle(again.fixture);
    expect(again.el.querySelector('input[name=password]')).not.toBeNull();
    expect(again.el.querySelector('input.tf-code')).toBeNull();
    again.fixture.destroy();
  });

  it('AC 53 / S2: an expired challenge returns to step 1, a 429 disables the form, Back forgets the challenge', async () => {
    const { el, fixture, http } = await toCodeStep();
    type(el.querySelector<HTMLInputElement>('input.tf-code')!, '111111');
    http.expectOne('/api/v1/auth/two-factor/verify').flush({ message: 'x', errors: { challenge_token: ['x'] } }, { status: 422, statusText: 'x' });
    await settle(fixture);
    expect(el.querySelector('input[name=password]')).not.toBeNull();
    expect(text(el.querySelector('.alert'))).toBe('Your sign-in has expired. Enter your email and password again.');
    fixture.destroy();
    TestBed.resetTestingModule();

    const second = await toCodeStep();
    type(second.el.querySelector<HTMLInputElement>('input.tf-code')!, '222222');
    second.http.expectOne('/api/v1/auth/two-factor/verify')
      .flush({ message: 'Too many wrong codes. Try again in 15 minutes.' }, { status: 429, statusText: 'Too Many' });
    await settle(second.fixture);
    expect(text(second.el.querySelector('.alert'))).toBe('Too many wrong codes. Try again in 15 minutes.');
    expect(second.el.querySelector<HTMLInputElement>('input.tf-code')!.disabled).toBeTrue();
    button(second.el, 'Back').click();
    await settle(second.fixture);
    expect(second.el.querySelector('input[name=password]')).not.toBeNull();
    second.fixture.destroy();
  });

  it('AC 54: a recovery code signs in and the toast shows the codes left', async () => {
    const { el, fixture, http, auth, router } = await toCodeStep();
    button(el, 'Use a recovery code instead').click();
    await settle(fixture);
    const field = el.querySelector<HTMLInputElement>('input[name=recovery_code]')!;
    expect(field).not.toBeNull();
    expect(field.getAttribute('autocomplete')).toBe('off');
    expect(field.getAttribute('autocapitalize')).toBe('off');
    type(field, 'ABCDE-FGHJK');
    await settle(fixture);
    button(el, 'Verify').click();
    http.expectOne('/api/v1/auth/two-factor/verify').flush({ token: 'T1', user: user(), recovery_codes_left: 9, recovery_code_used: true });
    await new Promise(r => setTimeout(r)); // not whenStable: that would wait out the toast's own timer
    expect(auth.token()).toBe('T1');
    expect(TestBed.inject(ToastService).text()).toBe('Recovery code used. 9 left.');
    await settle(fixture);
    expect(router.url).toBe('/dashboard');
    fixture.destroy();
  });

  it('AC 55: the setup key in groups of 4, "Copy key" copies it without spaces, the QR is drawn locally', async () => {
    const { http } = setup();
    const fetchSpy = spyOn(window, 'fetch').and.callThrough();
    const xhrSpy = spyOn(XMLHttpRequest.prototype, 'open').and.callThrough();
    const logSpies = (['log', 'info', 'warn', 'error', 'debug'] as const).map(k => spyOn(console, k).and.callThrough());
    const copy = spyOn(navigator.clipboard, 'writeText').and.resolveTo();
    const { fixture, el } = mount(TwoFactorSetupComponent);
    fixture.componentRef.setInput('setup', SETUP);
    fixture.componentRef.setInput('email', 'u@x.lk');
    await settle(fixture);
    expect(text(el.querySelector('.tf-key'))).toBe('JBSW Y3DP EHPK 3PXP JBSW Y3DP EHPK 3PXP');
    expect(groupKey(SECRET)).toBe('JBSW Y3DP EHPK 3PXP JBSW Y3DP EHPK 3PXP');
    button(el, 'Copy key').click();
    expect(copy).toHaveBeenCalledWith(SECRET);
    // Q1 (approved library): a canvas drawn in the browser, no request of any kind, nothing logged, the key still shown.
    await new Promise(r => setTimeout(r, 50));
    const canvas = el.querySelector<HTMLCanvasElement>('app-two-factor-qr canvas')!;
    expect(canvas).not.toBeNull();
    expect(canvas.getAttribute('aria-label')).toBe('QR code for the setup key');
    expect(canvas.width).toBe(200);
    const px = canvas.getContext('2d')!.getImageData(0, 0, canvas.width, canvas.height).data;
    let dark = 0;
    for (let i = 0; i < px.length; i += 4) if (px[i] < 128) dark++;
    expect(dark).toBeGreaterThan(1000); // modules were drawn
    expect(px[0]).toBe(255); // white quiet zone
    expect(el.querySelector('img')).toBeNull();
    http.verify();
    expect(fetchSpy).not.toHaveBeenCalled();
    expect(xhrSpy).not.toHaveBeenCalled();
    logSpies.forEach(sp => expect(sp).not.toHaveBeenCalled());
    expect(el.querySelector('.tf-key')).not.toBeNull();
    expect(text(el)).toContain('Account: u@x.lk · Type: time-based');
    expect(text(el)).toMatch(/This setup expires at \d\d:\d\d\./);
    fixture.destroy();
  });

  it('AC 55 / F3: forced enrolment completes with the key only, then the codes and the dashboard', async () => {
    const { el, fixture, http, auth, router } = await toCodeStep('enrol');
    expect(text(el)).toContain('Your organisation requires two-factor sign-in for administrators. Set it up now to continue.');
    http.expectOne('/api/v1/auth/two-factor/enrol/start').flush(SETUP);
    await settle(fixture);
    expect(text(el.querySelector('.tf-key'))).toContain('JBSW Y3DP');
    type(el.querySelector<HTMLInputElement>('input.tf-code')!, '654321');
    const req = http.expectOne('/api/v1/auth/two-factor/enrol/confirm');
    expect(req.request.body).toEqual({ challenge_token: 'CHALLENGE-TOKEN-1234567890', code: '654321' });
    req.flush({ token: 'T2', user: user(), recovery_codes: CODES });
    await settle(fixture);
    expect(el.querySelectorAll('.tf-codes li').length).toBe(10);
    expect(auth.token()).toBe('T2'); // review N5: stored at once, so a reload does not orphan the token
    expect(router.url).not.toBe('/dashboard'); // "Done" moves on
    el.querySelector<HTMLInputElement>('input[name=saved]')!.click();
    await settle(fixture);
    button(el, 'Done').click();
    await settle(fixture);
    expect(auth.token()).toBe('T2');
    expect(router.url).toBe('/dashboard');
    expect(text(el)).not.toContain(CODES[0]);
    fixture.destroy();
  });

  it('AC 56: "Done" waits for the tick, the .txt holds the codes and no secret, the codes are gone after "Done"', async () => {
    setup();
    let blob: Blob | null = null;
    spyOn(URL, 'createObjectURL').and.callFake((b: Blob | MediaSource) => { blob = b as Blob; return 'blob:x'; });
    const click = spyOn(HTMLAnchorElement.prototype, 'click');
    const { fixture, el } = mount(RecoveryCodesComponent);
    fixture.componentRef.setInput('codes', CODES);
    fixture.componentRef.setInput('email', 'u@x.lk');
    let done = 0;
    fixture.componentInstance.done.subscribe(() => done++);
    await settle(fixture);
    const doneBtn = button(el, 'Done');
    expect(doneBtn.disabled).toBeTrue();
    // Karma reports a real beforeunload event as a page reload, so the listener is called directly.
    const unload = new Event('beforeunload', { cancelable: true }) as BeforeUnloadEvent;
    fixture.componentInstance.beforeUnload(unload);
    expect(unload.defaultPrevented).toBeTrue();

    button(el, 'Download .txt').click();
    expect(click).toHaveBeenCalled();
    const content = await blob!.text();
    CODES.forEach(c => expect(content).toContain(c));
    expect(content).toContain('u@x.lk');
    expect(content).not.toContain(SECRET);
    expect(recoveryFileText(CODES, 'u@x.lk', 'now')).not.toContain('secret');

    el.querySelector<HTMLInputElement>('input[name=saved]')!.click();
    await settle(fixture);
    expect(doneBtn.disabled).toBeFalse();
    const after = new Event('beforeunload', { cancelable: true }) as BeforeUnloadEvent;
    fixture.componentInstance.beforeUnload(after);
    expect(after.defaultPrevented).toBeFalse();
    doneBtn.click();
    expect(done).toBe(1);
    fixture.destroy();
  });

  it('AC 56 / F1: on /account/security the codes are shown once and cleared after "Done"', async () => {
    const { http } = setup();
    const { fixture, el } = mount(AccountSecurityComponent);
    await settle(fixture);
    http.expectOne('/api/v1/me/two-factor').flush({ enabled: false, confirmed_at: null, required: false, recovery_codes_left: 0, setup_pending_until: null });
    await settle(fixture);
    expect(text(el)).toContain('Off. Add a code from your phone when you sign in.');
    button(el, 'Turn on').click();
    await settle(fixture);
    type(el.querySelector<HTMLInputElement>('input[name=password]')!, 'secret-pass1');
    await settle(fixture);
    button(el, 'Continue').click();
    http.expectOne('/api/v1/me/two-factor/setup').flush(SETUP);
    await settle(fixture);
    type(el.querySelector<HTMLInputElement>('.tf-setup input.tf-code')!, '123456');
    http.expectOne('/api/v1/me/two-factor/confirm').flush({ recovery_codes: CODES, tokens_revoked: 2 });
    await settle(fixture);
    expect(el.querySelectorAll('.tf-codes li').length).toBe(10);
    el.querySelector<HTMLInputElement>('input[name=saved]')!.click();
    await settle(fixture);
    button(el, 'Done').click();
    await settle(fixture);
    http.expectOne('/api/v1/me/two-factor').flush({ enabled: true, confirmed_at: '2026-11-20T05:12:00Z', required: false, recovery_codes_left: 10,
      setup_pending_until: null });
    await settle(fixture);
    http.match('/api/v1/me').forEach(r => r.flush(user()));
    await settle(fixture);
    expect(text(el)).toContain('Two-factor sign-in is on. Other devices have been signed out.');
    expect(text(el)).not.toContain(CODES[0]);
    expect(el.querySelector('.tf-codes')).toBeNull();
    fixture.destroy();
  });

  it('QA D1: a double click on the step-up dialog sends one request', async () => {
    const { http } = setup();
    const { fixture, el } = mount(AccountSecurityComponent);
    await settle(fixture);
    http.expectOne('/api/v1/me/two-factor').flush({ enabled: false, confirmed_at: null, required: false, recovery_codes_left: 0, setup_pending_until: null });
    await settle(fixture);
    button(el, 'Turn on').click();
    await settle(fixture);
    type(el.querySelector<HTMLInputElement>('input[name=password]')!, 'secret-pass1');
    await settle(fixture);
    const submit = button(el, 'Continue');
    submit.click();
    submit.click(); // before change detection disables the button
    http.expectOne('/api/v1/me/two-factor/setup').flush(SETUP);
    await settle(fixture);
    http.expectNone('/api/v1/me/two-factor/setup');
    fixture.destroy();
  });

  it('AC 35: "Turn off" is hidden when two-factor sign-in is required; 3 or fewer codes show the banner', async () => {
    const { http } = setup();
    const { fixture, el } = mount(AccountSecurityComponent);
    await settle(fixture);
    http.expectOne('/api/v1/me/two-factor').flush({ enabled: true, confirmed_at: '2026-11-20T05:12:00Z', required: true, recovery_codes_left: 3,
      setup_pending_until: null });
    await settle(fixture);
    expect(el.querySelector('[data-testid=turn-off]')).toBeNull();
    expect(text(el)).toContain('Required for your account');
    expect(text(el)).toContain('Only 3 recovery codes left. Create new ones.');
    expect(el.querySelector('.tf-low')).not.toBeNull();
    fixture.destroy();
  });

  it('AC 57: a 403 two_factor_enrolment_required opens /account/security?required=1 without the menu', async () => {
    const { http, router } = setup();
    const req = firstValueFrom(TestBed.inject(HttpClient).get('/api/v1/transactions')).catch(() => null);
    http.expectOne('/api/v1/transactions').flush({ message: 'Set up two-factor sign-in to continue.', code: 'two_factor_enrolment_required' },
      { status: 403, statusText: 'Forbidden' });
    await req;
    await new Promise(r => setTimeout(r));
    expect(router.url).toBe('/account/security?required=1');

    const { fixture, el } = mount(ShellComponent);
    await settle(fixture);
    http.match(() => true).forEach(r => r.flush({}));
    expect(el.querySelector('nav')).toBeNull();
    expect(el.querySelector('.switcher')).toBeNull();
    expect(el.querySelector('ag-notification-bell')).toBeNull();
    expect(Array.from(el.querySelectorAll('button')).some(b => text(b) === 'Log out')).toBeTrue();
    fixture.destroy();
  });

  it('AC 57: with ?required=1 the page shows the banner; the normal shell has the Security menu', async () => {
    const { http, router } = setup({ required: '1' });
    const { fixture, el } = mount(AccountSecurityComponent);
    await settle(fixture);
    http.expectOne('/api/v1/me/two-factor').flush({ enabled: false, confirmed_at: null, required: true, recovery_codes_left: 0, setup_pending_until: null });
    await settle(fixture);
    expect(text(el.querySelector('.banner'))).toBe('Two-factor sign-in is required for your account. Set it up to continue.');
    fixture.destroy();

    await router.navigateByUrl('/dashboard');
    const shell = mount(ShellComponent);
    await settle(shell.fixture);
    http.match(() => true).forEach(r => r.flush({}));
    expect(shell.el.querySelector('nav')).not.toBeNull();
    shell.el.querySelector<HTMLButtonElement>('.tf-menu-btn')!.click();
    await settle(shell.fixture);
    expect(Array.from(shell.el.querySelectorAll('.tf-menu-list [role=menuitem]')).map(text)).toEqual(['Security', 'Log out']);
    shell.fixture.destroy();
  });

  it('AC 58: "Reset two-factor" for a Super Admin on another user with 2FA on only', async () => {
    const detail = (u: Partial<UserRow>, me: Me) => {
      TestBed.resetTestingModule();
      const { http } = setup({}, {}, me);
      const m = mount(UserDetailComponent);
      m.fixture.componentRef.setInput('id', String(u.id));
      m.fixture.detectChanges();
      http.expectOne(`/api/v1/users/${u.id}`).flush({ name: 'C. Perera', email: 'c@x.lk', status: 'active', is_super_admin: false,
        last_login_at: null, memberships: [], profile_locked_reason: null, ...u });
      m.fixture.detectChanges();
      const shown = !!m.el.querySelector('[data-testid=reset-two-factor]');
      const row = text(m.el.querySelector('[data-testid=two-factor-row]'));
      m.fixture.destroy();
      return { shown, row };
    };
    const sa = user({ id: 1, is_super_admin: true, two_factor: { enabled: true, required: false, recovery_codes_left: 10 } });
    const on = { enabled: true, confirmed_at: '2026-11-20T05:12:00Z', required: false, recovery_codes_left: 8 };
    const other = detail({ id: 9, two_factor: on }, sa);
    expect(other.shown).toBeTrue();
    expect(other.row).toContain('recovery codes left 8');
    expect(detail({ id: 1, is_super_admin: true, two_factor: on }, sa).shown).toBeFalse(); // own account
    expect(detail({ id: 9 }, user({ branches: [{ ...KDY, roles: ['Branch Admin'], permissions: ['*'] }] })).shown).toBeFalse(); // no key for BA
    expect(detail({ id: 9, two_factor: { ...on, enabled: false, required: true } }, sa).row)
      .toBe('Off (required: will be asked at next sign-in)');
  });

  it('AC 59: 390px layout of /login (both steps), S3, S4 and /account/security', async () => {
    const frame = await phoneFrame();
    const fullWidth = (el: HTMLElement, root: HTMLElement) => {
      const card = root.querySelector<HTMLElement>('.card')!.getBoundingClientRect();
      const r = el.getBoundingClientRect();
      expect(r.width).toBeGreaterThanOrEqual(card.width - 40);
      expect(r.right).toBeLessThanOrEqual(390);
    };

    const login = await toCodeStep();
    frame.adopt(login.el);
    await settle(login.fixture);
    const code = login.el.querySelector<HTMLElement>('input.tf-code')!;
    expect(frame.win.getComputedStyle(code).fontSize).toBe('24px');
    expect(frame.win.getComputedStyle(code).fontFamily).toContain('monospace');
    fullWidth(code, login.el);
    fullWidth(button(login.el, 'Verify'), login.el);
    expect(button(login.el, 'Verify').getBoundingClientRect().height).toBeGreaterThanOrEqual(40);
    expect(frame.doc.documentElement.scrollWidth).toBeLessThanOrEqual(390);
    button(login.el, 'Back').click();
    await settle(login.fixture);
    fullWidth(login.el.querySelector<HTMLElement>('input[name=email]')!, login.el);
    fullWidth(button(login.el, 'Sign in'), login.el);
    expect(frame.doc.documentElement.scrollWidth).toBeLessThanOrEqual(390);
    login.fixture.destroy();
    TestBed.resetTestingModule();

    setup();
    const s3 = mount(TwoFactorSetupComponent);
    s3.fixture.componentRef.setInput('setup', { ...SETUP, secret: SECRET + SECRET });
    s3.fixture.componentRef.setInput('email', 'a.very.long.email.address.for.testing@example-company.lk');
    const card = document.createElement('div');
    card.className = 'card tf-card';
    card.appendChild(s3.el);
    frame.adopt(card);
    await settle(s3.fixture);
    const key = s3.el.querySelector<HTMLElement>('.tf-key')!;
    expect(key.getBoundingClientRect().right).toBeLessThanOrEqual(390);
    expect(key.getBoundingClientRect().height).toBeGreaterThan(30); // wrapped onto more lines
    expect(button(s3.el, 'Copy key').getBoundingClientRect().top).toBeGreaterThan(key.getBoundingClientRect().bottom - 1);
    expect(button(s3.el, 'Copy key').getBoundingClientRect().height).toBeGreaterThanOrEqual(40);
    expect(frame.doc.documentElement.scrollWidth).toBeLessThanOrEqual(390);
    s3.fixture.destroy();
    card.remove();

    const s4 = mount(RecoveryCodesComponent);
    s4.fixture.componentRef.setInput('codes', CODES);
    frame.adopt(s4.el);
    await settle(s4.fixture);
    expect(frame.win.getComputedStyle(s4.el.querySelector('.tf-codes')!).gridTemplateColumns.split(' ').length).toBe(1);
    expect(button(s4.el, 'Done').getBoundingClientRect().height).toBeGreaterThanOrEqual(40);
    expect(frame.doc.documentElement.scrollWidth).toBeLessThanOrEqual(390);
    s4.fixture.destroy();

    const http = TestBed.inject(HttpTestingController);
    const page = mount(AccountSecurityComponent);
    frame.adopt(page.el);
    await settle(page.fixture);
    http.expectOne('/api/v1/me/two-factor').flush({ enabled: true, confirmed_at: '2026-11-20T05:12:00Z', required: false, recovery_codes_left: 10,
      setup_pending_until: null });
    await settle(page.fixture);
    page.el.querySelectorAll<HTMLElement>('.tf-actions button').forEach(b => {
      expect(b.getBoundingClientRect().height).toBeGreaterThanOrEqual(40);
      expect(b.getBoundingClientRect().right).toBeLessThanOrEqual(390);
    });
    button(page.el, 'Turn off').click();
    await settle(page.fixture);
    // QA D3: the password field of the dialog is a 40px tap target too.
    expect(page.el.querySelector<HTMLElement>('.tf-dialog input[name=password]')!.getBoundingClientRect().height).toBeGreaterThanOrEqual(40);
    const actions = page.el.querySelectorAll<HTMLElement>('.tf-dialog-actions button');
    expect(text(actions[0])).toBe('Turn off');
    expect(actions[0].getBoundingClientRect().bottom).toBeLessThanOrEqual(actions[1].getBoundingClientRect().top); // stacked, primary on top
    expect(frame.doc.documentElement.scrollWidth).toBeLessThanOrEqual(390);
    page.fixture.destroy();
    frame.remove();
  });
});
