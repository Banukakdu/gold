import { Component, effect, input, output, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { CodeInputComponent } from './code-input.component';

export interface StepUpAnswer { password: string; code?: string; recovery_code?: string }

/**
 * AG-005 S5 step-up dialog: the current password and, when the action needs it, the 6-digit code (or, with `either`,
 * a recovery code through "Use a recovery code"). Also used by Settings S6.
 */
@Component({
  selector: 'app-step-up-dialog',
  standalone: true,
  imports: [FormsModule, CodeInputComponent],
  template: `
    <div class="att-backdrop" (click)="cancel.emit()"></div>
    <form class="att-dialog ap-dialog tf-dialog" role="dialog" aria-modal="true" (ngSubmit)="send()">
      <h2>{{ title() }}</h2>
      @if (text()) { <p>{{ text() }}</p> }
      @if (alert()) { <div class="alert">{{ alert() }}</div> }
      <label>Password
        <input class="tf-input" type="password" name="password" autocomplete="current-password" required [(ngModel)]="password" />
      </label>
      @if (errors()['password']) { <div class="field-error">{{ errors()['password'] }}</div> }
      @if (second() !== 'none') {
        @if (useRecovery()) {
          <label>Recovery code
            <input class="tf-input" type="text" name="recovery_code" autocomplete="off" autocapitalize="off" required [(ngModel)]="recovery" />
          </label>
          @if (errors()['recovery_code']) { <div class="field-error">{{ errors()['recovery_code'] }}</div> }
        } @else {
          <label>Code <app-code-input [(value)]="code" name="code" /></label>
          @if (errors()['code']) { <div class="field-error">{{ errors()['code'] }}</div> }
        }
        @if (second() === 'either') {
          <button type="button" class="link tf-switch" (click)="useRecovery.set(!useRecovery())">
            {{ useRecovery() ? 'Use your authenticator app' : 'Use a recovery code' }}</button>
        }
      }
      <div class="tf-dialog-actions">
        <button type="submit" [disabled]="busy() || !ready()">{{ busy() ? 'Checking…' : confirmLabel() }}</button>
        <button type="button" class="ghost" (click)="cancel.emit()">Cancel</button>
      </div>
    </form>
  `,
})
export class StepUpDialogComponent {
  title = input.required<string>();
  text = input('');
  confirmLabel = input('Continue');
  /** none = password only; code = password + code; either = password + code or recovery code. */
  second = input<'none' | 'code' | 'either'>('none');
  busy = input(false);
  errors = input<Record<string, string>>({});
  alert = input('');
  submitted = output<StepUpAnswer>();
  cancel = output<void>();
  password = '';
  recovery = '';
  code = signal('');
  useRecovery = signal(false);

  constructor() {
    // S2: a wrong code or recovery code clears its field.
    effect(() => {
      const e = this.errors();
      if (e['code']) this.code.set('');
      if (e['recovery_code']) this.recovery = '';
    }, { allowSignalWrites: true });
  }

  ready(): boolean {
    if (!this.password) return false;
    if (this.second() === 'none') return true;
    return this.useRecovery() ? this.recovery.trim() !== '' : this.code().length === 6;
  }

  send() {
    if (!this.ready() || this.busy()) return;
    const answer: StepUpAnswer = { password: this.password };
    if (this.second() !== 'none') {
      if (this.useRecovery()) answer.recovery_code = this.recovery.trim();
      else answer.code = this.code();
    }
    this.submitted.emit(answer);
  }
}
