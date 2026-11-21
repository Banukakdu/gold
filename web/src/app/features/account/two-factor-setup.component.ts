import { Component, effect, input, output, signal } from '@angular/core';
import { TwoFactorSetup, colomboTime, groupKey } from '../../core/two-factor';
import { CodeInputComponent } from './code-input.component';
import { TwoFactorQrComponent } from './two-factor-qr.component';

/**
 * AG-005 S3 setup panel, shared by the forced enrolment on /login (F3), /account/security (F1, F5) and F4.
 * The parent calls the API; this shows the steps, the setup key, the expiry time from the API and the code field.
 */
@Component({
  selector: 'app-two-factor-setup',
  standalone: true,
  imports: [CodeInputComponent, TwoFactorQrComponent],
  template: `
    <div class="tf-setup">
      <h3>1. Install an authenticator app</h3>
      <p class="muted">For example Google Authenticator, Microsoft Authenticator or 1Password.</p>
      <h3>2. Scan this QR code or enter the setup key</h3>
      <app-two-factor-qr [uri]="setup().otpauth_uri" />
      <div class="tf-key" data-testid="setup-key">{{ key() }}</div>
      <button type="button" class="ghost tf-copy" (click)="copyKey()">{{ copied() ? 'Copied' : 'Copy key' }}</button>
      <p class="muted tf-wrap">Account: {{ email() }} · Type: time-based</p>
      <h3>3. Enter the 6-digit code</h3>
      @if (expired()) {
        <div class="alert">This setup has expired. Start again.</div>
        <button type="button" (click)="restart.emit()">Start again</button>
      } @else {
        <app-code-input [(value)]="code" [disabled]="busy()" [autofocus]="true" (complete)="submit()" />
        @if (codeError()) { <div class="field-error">{{ codeError() }}</div> }
        <button type="button" [disabled]="busy() || code().length !== 6" (click)="submit()">{{ busy() ? 'Checking…' : 'Turn on' }}</button>
        <p class="muted">This setup expires at {{ expiresAt() }}.</p>
      }
    </div>
  `,
})
export class TwoFactorSetupComponent {
  setup = input.required<TwoFactorSetup>();
  email = input('');
  busy = input(false);
  /** S2 code message from the parent; the field is cleared whenever it is set. */
  codeError = input('');
  /** 422 `setup`: the pending secret is older than 15 minutes. */
  expired = input(false);
  confirm = output<string>();
  restart = output<void>();
  code = signal('');
  copied = signal(false);

  constructor() {
    effect(() => { if (this.codeError()) this.code.set(''); }, { allowSignalWrites: true });
  }

  key() { return groupKey(this.setup().secret); }
  expiresAt() { return colomboTime(this.setup().expires_at); }

  /** Copies the key without spaces. */
  async copyKey() {
    try {
      await navigator.clipboard.writeText(this.setup().secret.replace(/\s/g, ''));
      this.copied.set(true);
    } catch { /* the key stays on screen to type */ }
  }

  submit() {
    if (this.code().length === 6 && !this.busy()) this.confirm.emit(this.code());
  }
}
