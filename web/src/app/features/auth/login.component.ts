import { Component, inject, signal } from '@angular/core';
import { HttpClient, HttpErrorResponse } from '@angular/common/http';
import { FormsModule } from '@angular/forms';
import { ActivatedRoute, Router, RouterLink } from '@angular/router';
import { firstValueFrom } from 'rxjs';
import { AuthService } from '../../core/auth.service';
import { errorText } from '../../core/api.interceptor';
import { safeReturnUrl } from '../../core/guards';
import { Me } from '../../core/models';
import { ToastService } from '../../core/toast.service';
import {
  CODE_ERROR, EXPIRED_SIGN_IN, RECOVERY_ERROR, TwoFactorChallenge, TwoFactorSetup, apiMessage, fieldMessage,
} from '../../core/two-factor';
import { CodeInputComponent } from '../account/code-input.component';
import { RecoveryCodesComponent } from '../account/recovery-codes.component';
import { TwoFactorSetupComponent } from '../account/two-factor-setup.component';

type Step = 'password' | 'code' | 'enrol' | 'codes';

/**
 * /login. AG-005 S1-S4: after the password the API may answer with a challenge (S2, the code step) or an enrol
 * challenge (S3 forced set-up, then S4). The challenge token is kept in this component's memory only: never in
 * localStorage or the URL, so a reload returns to step 1.
 */
@Component({
  standalone: true,
  imports: [FormsModule, RouterLink, CodeInputComponent, RecoveryCodesComponent, TwoFactorSetupComponent],
  template: `
    <div class="login">
      @switch (step()) {
        @case ('password') {
          <form class="card" (ngSubmit)="submit()">
            <div class="brand big">Account<span>gold</span></div>
            <p class="muted">Multi-branch accounting</p>
            @if (passwordSet) { <div class="ok">Your password is set. Sign in to continue.</div> }
            @if (passwordReset) { <div class="ok">Your password has been changed. Sign in with your new password.</div> }
            <label>Email <input type="email" name="email" [(ngModel)]="email" required autocomplete="username" /></label>
            <label>Password <input type="password" name="password" [(ngModel)]="password" required autocomplete="current-password" /></label>
            <a class="tap" routerLink="/forgot-password" [queryParams]="email.trim() ? { email: email.trim() } : {}">Forgot password?</a>
            @if (error()) { <div class="alert">{{ error() }}</div> }
            <button type="submit" [disabled]="busy()">{{ busy() ? 'Signing in…' : 'Sign in' }}</button>
          </form>
        }
        @case ('code') {
          <form class="card" (ngSubmit)="verify()">
            <h2>Two-factor sign-in</h2>
            @if (useRecovery()) {
              <p>Enter one of your recovery codes.</p>
              <label>Recovery code
                <input type="text" name="recovery_code" autocomplete="off" autocapitalize="off" [(ngModel)]="recovery"
                  [disabled]="locked()" required />
              </label>
              @if (fieldError()) { <div class="field-error">{{ fieldError() }}</div> }
            } @else {
              <p>Enter the 6-digit code from your authenticator app.</p>
              <label>Code <app-code-input [(value)]="code" [disabled]="locked()" [autofocus]="true" (complete)="verify()" /></label>
              @if (fieldError()) { <div class="field-error">{{ fieldError() }}</div> }
            }
            @if (error()) { <div class="alert">{{ error() }}</div> }
            <button type="submit" [disabled]="busy() || locked() || !ready()">{{ busy() ? 'Checking…' : 'Verify' }}</button>
            <button type="button" class="link tap" (click)="toggleRecovery()">
              {{ useRecovery() ? 'Use your authenticator app' : 'Use a recovery code instead' }}</button>
            <button type="button" class="link tap" (click)="back()">Back</button>
          </form>
        }
        @case ('enrol') {
          <div class="card tf-login-wide">
            <h2>Set up two-factor sign-in</h2>
            <p>Your organisation requires two-factor sign-in for administrators. Set it up now to continue.</p>
            @if (error()) { <div class="alert">{{ error() }}</div> }
            @if (setup(); as s) {
              <app-two-factor-setup [setup]="s" [email]="email.trim()" [busy]="busy()" [codeError]="fieldError()" [expired]="setupExpired()"
                (confirm)="confirmEnrol($event)" (restart)="back()" />
            } @else if (!error()) { <p class="muted">Loading...</p> }
            <button type="button" class="link tap" (click)="back()">Back</button>
          </div>
        }
        @case ('codes') {
          <div class="card tf-login-wide">
            <app-recovery-codes [codes]="recoveryCodes()" [email]="email.trim()" (done)="finishEnrol()" />
          </div>
        }
      }
    </div>
  `,
})
export class LoginComponent {
  private auth = inject(AuthService);
  private http = inject(HttpClient);
  private router = inject(Router);
  private toast = inject(ToastService);
  private query = inject(ActivatedRoute).snapshot.queryParamMap;
  passwordSet = this.query.has('password_set');
  passwordReset = this.query.has('password_reset');
  email = '';
  password = '';
  busy = signal(false);
  error = signal('');
  step = signal<Step>('password');
  /** In memory only (S1). */
  private challenge: TwoFactorChallenge | null = null;
  code = signal('');
  recovery = '';
  useRecovery = signal(false);
  fieldError = signal('');
  /** 429: the form is disabled. */
  locked = signal(false);
  setup = signal<TwoFactorSetup | null>(null);
  setupExpired = signal(false);
  recoveryCodes = signal<string[]>([]);
  private pending: { token: string; user: Me } | null = null;

  async submit() {
    this.busy.set(true);
    this.error.set('');
    try {
      const challenge = await this.auth.login(this.email, this.password);
      this.password = '';
      if (!challenge) {
        this.router.navigateByUrl(safeReturnUrl(this.query.get('returnUrl')));
        return;
      }
      this.challenge = challenge;
      this.resetSecondStep();
      this.step.set(challenge.two_factor === 'enrol' ? 'enrol' : 'code');
      if (challenge.two_factor === 'enrol') await this.startEnrol();
    } catch (e) {
      this.error.set(errorText(e));
    } finally {
      this.busy.set(false);
    }
  }

  ready(): boolean {
    return this.useRecovery() ? this.recovery.trim() !== '' : this.code().length === 6;
  }

  toggleRecovery() {
    this.useRecovery.set(!this.useRecovery());
    this.fieldError.set('');
    this.code.set('');
    this.recovery = '';
  }

  /** S2 "Back": step 1, and the challenge is forgotten. */
  back(message = '') {
    this.challenge = null;
    this.pending = null;
    this.resetSecondStep();
    this.step.set('password');
    this.error.set(message);
  }

  async verify() {
    if (!this.challenge || this.busy() || this.locked() || !this.ready()) return;
    this.busy.set(true);
    this.error.set('');
    this.fieldError.set('');
    const body = this.useRecovery() ? { recovery_code: this.recovery.trim() } : { code: this.code() };
    try {
      const r = await firstValueFrom(this.http.post<{ token: string; user: Me; recovery_codes_left: number; recovery_code_used: boolean }>(
        '/api/v1/auth/two-factor/verify', { challenge_token: this.challenge.challenge_token, ...body }));
      this.challenge = null;
      this.auth.startSession(r.token, r.user);
      if (r.recovery_code_used) {
        this.toast.show(`Recovery code used. ${r.recovery_codes_left} left.`);
        if (r.recovery_codes_left <= 3) this.toast.then(`Only ${r.recovery_codes_left} recovery codes left. Create new ones.`);
      }
      this.router.navigateByUrl(safeReturnUrl(this.query.get('returnUrl')));
    } catch (e) {
      this.secondStepError(e);
    } finally {
      this.busy.set(false);
    }
  }

  private async startEnrol() {
    if (!this.challenge) return;
    try {
      this.setup.set(await firstValueFrom(this.http.post<TwoFactorSetup>('/api/v1/auth/two-factor/enrol/start',
        { challenge_token: this.challenge.challenge_token })));
    } catch (e) {
      this.secondStepError(e);
    }
  }

  async confirmEnrol(code: string) {
    if (!this.challenge) return;
    this.busy.set(true);
    this.error.set('');
    this.fieldError.set('');
    try {
      const r = await firstValueFrom(this.http.post<{ token: string; user: Me; recovery_codes: string[] }>('/api/v1/auth/two-factor/enrol/confirm',
        { challenge_token: this.challenge.challenge_token, code }));
      this.challenge = null;
      // The session starts after "Done", once the codes are saved (S4).
      this.pending = { token: r.token, user: r.user };
      this.recoveryCodes.set(r.recovery_codes);
      this.setup.set(null);
      this.step.set('codes');
    } catch (e) {
      if (fieldMessage(e, 'setup')) this.setupExpired.set(true);
      else this.secondStepError(e);
    } finally {
      this.busy.set(false);
    }
  }

  finishEnrol() {
    const pending = this.pending;
    this.recoveryCodes.set([]);
    this.pending = null;
    if (!pending) { this.back(); return; }
    this.auth.startSession(pending.token, pending.user);
    this.router.navigateByUrl('/dashboard');
  }

  private secondStepError(e: unknown) {
    const err = e as HttpErrorResponse;
    if (fieldMessage(e, 'challenge_token')) {
      this.back(EXPIRED_SIGN_IN);
    } else if (err?.status === 429) {
      this.error.set(apiMessage(e));
      this.locked.set(true);
    } else if (fieldMessage(e, 'recovery_code')) {
      this.recovery = '';
      this.fieldError.set(RECOVERY_ERROR);
    } else if (fieldMessage(e, 'code')) {
      this.code.set('');
      this.fieldError.set(CODE_ERROR);
    } else {
      this.error.set(apiMessage(e));
    }
  }

  private resetSecondStep() {
    this.code.set('');
    this.recovery = '';
    this.useRecovery.set(false);
    this.fieldError.set('');
    this.locked.set(false);
    this.setup.set(null);
    this.setupExpired.set(false);
    this.recoveryCodes.set([]);
  }
}
