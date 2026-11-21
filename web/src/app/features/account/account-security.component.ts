import { Component, OnInit, inject, signal } from '@angular/core';
import { HttpClient, HttpErrorResponse } from '@angular/common/http';
import { ActivatedRoute, Router } from '@angular/router';
import { firstValueFrom } from 'rxjs';
import { AuthService } from '../../core/auth.service';
import { AppDateTimePipe } from '../../core/app-date.pipe';
import { TwoFactorSetup, TwoFactorStatus, apiMessage, fieldMessage } from '../../core/two-factor';
import { RecoveryCodesComponent } from './recovery-codes.component';
import { StepUpAnswer, StepUpDialogComponent } from './step-up-dialog.component';
import { TwoFactorSetupComponent } from './two-factor-setup.component';

type Action = 'enable' | 'move' | 'codes' | 'disable';

const DIALOGS: Record<Action, { title: string; label: string; second: 'none' | 'code' | 'either'; text: string }> = {
  enable: { title: 'Turn on two-factor sign-in', label: 'Continue', second: 'none', text: 'Enter your password to continue.' },
  move: { title: 'Move to a new phone', label: 'Continue', second: 'either',
    text: 'Enter your password and a code from your current app, or a recovery code.' },
  codes: { title: 'Create new recovery codes', label: 'Create', second: 'code', text: 'Your old codes will stop working.' },
  disable: { title: 'Turn off two-factor sign-in', label: 'Turn off', second: 'either',
    text: 'You will sign in with your password only. Your recovery codes will stop working.' },
};

/**
 * AG-005 S5 Account security /account/security: the signed-in user's own two-factor sign-in. Any signed-in user, any
 * switcher mode. With ?required=1 (F4) the shell hides its menu and this page shows the "required" banner.
 */
@Component({
  standalone: true,
  imports: [AppDateTimePipe, RecoveryCodesComponent, StepUpDialogComponent, TwoFactorSetupComponent],
  template: `
    <h1>Security</h1>
    @if (requiredMode()) {
      <div class="banner amber">Two-factor sign-in is required for your account. Set it up to continue.</div>
    }
    @if (ok()) { <div class="ok">{{ ok() }}</div> }
    @if (loadError()) {
      <div class="alert">{{ loadError() }}</div>
      <button type="button" (click)="load()">Retry</button>
    }
    @if (codes(); as list) {
      <div class="card tf-card"><app-recovery-codes [codes]="list" [email]="auth.me()?.email ?? ''" (done)="codesDone()" /></div>
    } @else if (setup()) {
      <div class="card tf-card">
        <h2>{{ action() === 'move' ? 'Set up your new phone' : 'Set up two-factor sign-in' }}</h2>
         <app-two-factor-setup [setup]="setup()!" [email]="auth.me()?.email ?? ''" [busy]="busy()" [codeError]="codeError()" [expired]="setupExpired()"
          (confirm)="confirm($event)" (restart)="startAgain()" />
        @if (alert()) { <div class="alert">{{ alert() }}</div> }
        <button type="button" class="link" (click)="cancelSetup()">Cancel</button>
      </div>
    } @else {
      @if (status(); as st) {
      <div class="card tf-card">
        <h2>Two-factor sign-in</h2>
        @if (alert()) { <div class="alert">{{ alert() }}</div> }
        @if (st.enabled) {
          <p>On since {{ st.confirmed_at | appDateTime }} @if (st.required) { <span class="tag">Required for your account</span> }</p>
          <p [class.tf-low]="st.recovery_codes_left <= 3">Recovery codes left: {{ st.recovery_codes_left }}</p>
          @if (st.recovery_codes_left <= 3) {
            <div class="banner amber">Only {{ st.recovery_codes_left }} recovery codes left. Create new ones.</div>
          }
          <div class="tf-actions">
            <button type="button" class="ghost" (click)="open('move')">Move to a new phone</button>
            <button type="button" class="ghost" (click)="open('codes')">Create new recovery codes</button>
            @if (!st.required) { <button type="button" class="ghost" data-testid="turn-off" (click)="open('disable')">Turn off</button> }
          </div>
        } @else {
          <p>Off. Add a code from your phone when you sign in.</p>
          @if (st.required) { <div class="banner amber">Required for your account.</div> }
          <div class="tf-actions"><button type="button" (click)="open('enable')">Turn on</button></div>
        }
      </div>
      } @else if (!loadError()) { <p class="muted">Loading...</p> }
    }

    @if (dialog(); as d) {
      <app-step-up-dialog [title]="d.title" [text]="d.text" [confirmLabel]="d.label" [second]="d.second" [busy]="busy()"
        [errors]="errors()" [alert]="dialogAlert()" (submitted)="stepUp($event)" (cancel)="closeDialog()" />
    }
  `,
})
export class AccountSecurityComponent implements OnInit {
  auth = inject(AuthService);
  private http = inject(HttpClient);
  private router = inject(Router);
  requiredMode = signal(inject(ActivatedRoute).snapshot.queryParamMap.get('required') === '1');
  status = signal<TwoFactorStatus | null>(null);
  loadError = signal('');
  ok = signal('');
  alert = signal('');
  action = signal<Action | null>(null);
  dialog = signal<(typeof DIALOGS)[Action] | null>(null);
  errors = signal<Record<string, string>>({});
  dialogAlert = signal('');
  busy = signal(false);
  setup = signal<TwoFactorSetup | null>(null);
  setupExpired = signal(false);
  codeError = signal('');
  /** S4: in memory only, cleared on Done or when the page is left. */
  codes = signal<string[] | null>(null);
  private afterCodes = '';

  ngOnInit() { this.load(); }

  async load() {
    this.loadError.set('');
    try {
      this.status.set(await firstValueFrom(this.http.get<TwoFactorStatus>('/api/v1/me/two-factor')));
    } catch (e) { this.loadError.set(apiMessage(e)); }
  }

  open(action: Action) {
    this.ok.set(''); this.alert.set(''); this.errors.set({}); this.dialogAlert.set('');
    this.action.set(action);
    this.dialog.set(DIALOGS[action]);
  }

  closeDialog() { this.dialog.set(null); }

  async stepUp(answer: StepUpAnswer) {
    const action = this.action();
    if (!action || this.busy()) return; // QA D1: one request per submit
    if (action === 'disable' && !confirm('Turn off two-factor sign-in?')) return;
    this.busy.set(true);
    this.errors.set({}); this.dialogAlert.set('');
    try {
      if (action === 'enable' || action === 'move') {
        this.setup.set(await firstValueFrom(this.http.post<TwoFactorSetup>('/api/v1/me/two-factor/setup', answer)));
        this.setupExpired.set(false);
        this.codeError.set('');
      } else if (action === 'codes') {
        const r = await firstValueFrom(this.http.post<{ recovery_codes: string[] }>('/api/v1/me/two-factor/recovery-codes', answer));
        this.afterCodes = 'New recovery codes created. Your old codes no longer work.';
        this.codes.set(r.recovery_codes);
      } else {
        await firstValueFrom(this.http.post('/api/v1/me/two-factor/disable', answer));
        this.ok.set('Two-factor sign-in is off.');
        await this.refresh();
      }
      this.dialog.set(null);
    } catch (e) {
      this.showDialogError(e);
    } finally { this.busy.set(false); }
  }

  async confirm(code: string) {
    if (this.busy()) return;
    this.busy.set(true);
    this.codeError.set(''); this.alert.set('');
    try {
      const r = await firstValueFrom(this.http.post<{ recovery_codes: string[] | null; tokens_revoked: number }>('/api/v1/me/two-factor/confirm', { code }));
      this.setup.set(null);
      if (r.recovery_codes) {
        this.afterCodes = 'Two-factor sign-in is on. Other devices have been signed out.';
        this.codes.set(r.recovery_codes);
      } else {
        this.ok.set('Your new phone is set up.');
        await this.refresh();
      }
    } catch (e) {
      const err = e as HttpErrorResponse;
      if (fieldMessage(e, 'setup')) this.setupExpired.set(true);
      else if (fieldMessage(e, 'code')) this.codeError.set(fieldMessage(e, 'code'));
      else this.alert.set(apiMessage(err));
    } finally { this.busy.set(false); }
  }

  startAgain() {
    const action = this.action();
    this.setup.set(null);
    this.setupExpired.set(false);
    if (action) this.open(action);
  }

  cancelSetup() {
    this.setup.set(null);
    this.action.set(null);
  }

  async codesDone() {
    this.codes.set(null);
    this.ok.set(this.afterCodes);
    if (this.requiredMode()) {
      // F4: back to work once two-factor sign-in is set up.
      await this.auth.refreshMe();
      this.router.navigateByUrl('/dashboard');
      return;
    }
    await this.refresh();
  }

  private async refresh() {
    await this.load();
    await this.auth.refreshMe();
  }

  private showDialogError(e: unknown) {
    const err = e as HttpErrorResponse;
    const fields: Record<string, string> = {};
    for (const f of ['password', 'code', 'recovery_code']) {
      const m = fieldMessage(e, f);
      if (m) fields[f] = m;
    }
    this.errors.set(fields);
    if (!Object.keys(fields).length) this.dialogAlert.set(fieldMessage(e, 'two_factor') || apiMessage(err));
  }
}
