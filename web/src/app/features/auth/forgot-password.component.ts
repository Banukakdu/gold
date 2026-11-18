import { Component, DestroyRef, inject, signal } from '@angular/core';
import { HttpClient, HttpErrorResponse } from '@angular/common/http';
import { FormsModule } from '@angular/forms';
import { ActivatedRoute, RouterLink } from '@angular/router';
import { fieldError } from '../../core/link-safety';

/** AG-002 F1: request a reset link (password reset spec 4.2). The confirmation never says whether the account exists. */
@Component({
  standalone: true,
  imports: [FormsModule, RouterLink],
  template: `
    <div class="login">
      <form class="card" (ngSubmit)="send()">
        <div class="brand big">Account<span>gold</span></div>
        <p class="muted">Reset your password</p>
        @if (sentTo()) {
          <div class="ok wrap">If an account exists for {{ sentTo() }}, we have sent a link to reset your password.
            The link works for 60 minutes. Check your spam folder if it does not arrive.</div>
          @if (error()) { <div class="alert">{{ error() }}</div> }
          <button type="button" (click)="send()" [disabled]="busy() || wait() > 0">
            {{ busy() ? 'Sending…' : wait() > 0 ? 'Send again (' + wait() + ' s)' : 'Send again' }}
          </button>
        } @else {
          <label>Email
            <input type="email" name="email" [(ngModel)]="email" required maxlength="255" autocomplete="username" />
          </label>
          @if (emailError()) { <small class="field-error">{{ emailError() }}</small> }
          @if (error()) { <div class="alert">{{ error() }}</div> }
          <button type="submit" [disabled]="busy()">{{ busy() ? 'Sending…' : 'Send reset link' }}</button>
        }
        <a class="tap" routerLink="/login">Back to sign in</a>
      </form>
    </div>
  `,
})
export class ForgotPasswordComponent {
  private http = inject(HttpClient);
  email = inject(ActivatedRoute).snapshot.queryParamMap.get('email') ?? '';
  busy = signal(false);
  error = signal('');
  emailError = signal('');
  sentTo = signal('');
  wait = signal(0);
  private timer?: ReturnType<typeof setInterval>;

  constructor() {
    inject(DestroyRef).onDestroy(() => clearInterval(this.timer));
  }

  send() {
    if (this.busy()) return; // QA Q1: a double click sends one request
    const email = this.sentTo() || this.email.trim();
    this.busy.set(true);
    this.error.set('');
    this.emailError.set('');
    this.http.post('/api/v1/auth/forgot-password', { email }).subscribe({
      next: () => { this.busy.set(false); this.sentTo.set(email); this.startWait(); },
      error: (e: HttpErrorResponse) => {
        this.busy.set(false);
        if (e.status === 422 && fieldError(e, 'email')) this.emailError.set(fieldError(e, 'email'));
        else if (e.status === 429) this.error.set(e.error?.message ?? 'Too many requests. Try again later.');
        else this.error.set('Something went wrong. Try again.');
      },
    });
  }

  /** "Send again" stays disabled for 60 seconds after each send. */
  private startWait() {
    clearInterval(this.timer);
    this.wait.set(60);
    this.timer = setInterval(() => {
      this.wait.update(s => s - 1);
      if (this.wait() <= 0) clearInterval(this.timer);
    }, 1000);
  }
}
