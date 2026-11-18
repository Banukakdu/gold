import { Component, inject, signal } from '@angular/core';
import { HttpClient, HttpErrorResponse } from '@angular/common/http';
import { FormsModule } from '@angular/forms';
import { ActivatedRoute, Router, RouterLink } from '@angular/router';
import { PASSWORD_HINT, fieldError, protectTokenLink } from '../../core/link-safety';

/** AG-002 F2/F3: choose a new password from the emailed link /reset-password?token=..&email=.. (spec 4.3). */
@Component({
  standalone: true,
  imports: [FormsModule, RouterLink],
  template: `
    <div class="login">
      <form class="card" (ngSubmit)="submit()">
        <div class="brand big">Account<span>gold</span></div>
        @if (invalid()) {
          <div class="alert">This reset link is invalid or has expired.</div>
          <a class="tap" routerLink="/forgot-password" [queryParams]="email ? { email } : {}">Request a new link</a>
        } @else {
          <p class="muted wrap">Choose a new password for {{ email }}</p>
          <label>New password
            <input type="password" name="password" [(ngModel)]="password" required minlength="8" autocomplete="new-password" />
          </label>
          @if (passwordError()) { <small class="field-error">{{ passwordError() }}</small> }
          <label>Confirm password
            <input type="password" name="password_confirmation" [(ngModel)]="confirm" required autocomplete="new-password" />
          </label>
          <small class="muted">{{ hint }}</small>
          @if (error()) { <div class="alert">{{ error() }}</div> }
          <button type="submit" [disabled]="busy()">{{ busy() ? 'Saving…' : 'Change password' }}</button>
        }
        <a class="tap" routerLink="/login">Back to sign in</a>
      </form>
    </div>
  `,
})
export class ResetPasswordComponent {
  private http = inject(HttpClient);
  private router = inject(Router);
  private query = inject(ActivatedRoute).snapshot.queryParamMap;
  // Read before protectTokenLink() removes the token from the address bar; kept only in memory.
  private token = this.query.get('token') ?? '';
  email = this.query.get('email') ?? '';
  hint = PASSWORD_HINT;
  password = '';
  confirm = '';
  busy = signal(false);
  error = signal('');
  passwordError = signal('');
  invalid = signal(!this.token || !this.email);

  constructor() {
    protectTokenLink();
  }

  submit() {
    this.error.set('');
    this.passwordError.set('');
    if (this.password !== this.confirm) { this.passwordError.set('The passwords do not match.'); return; }
    this.busy.set(true);
    this.http.post('/api/v1/auth/reset-password', {
      token: this.token, email: this.email, password: this.password, password_confirmation: this.confirm,
    }).subscribe({
      next: () => this.router.navigate(['/login'], { queryParams: { password_reset: 1 } }),
      error: (e: HttpErrorResponse) => {
        this.busy.set(false);
        if (e.status === 422 && fieldError(e, 'token')) this.invalid.set(true);
        else if (e.status === 422 && fieldError(e, 'password')) this.passwordError.set(fieldError(e, 'password'));
        else if (e.status === 429) this.error.set(e.error?.message ?? 'Too many requests. Try again later.');
        else this.error.set('Something went wrong. Try again.');
      },
    });
  }
}
