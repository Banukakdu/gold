import { Component, inject, signal } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { FormsModule } from '@angular/forms';
import { ActivatedRoute, Router, RouterLink } from '@angular/router';
import { errorText } from '../../core/api.interceptor';

/** F9: invitee sets a password from the emailed link /set-password?token=..&email=.. (public). */
@Component({
  standalone: true,
  imports: [FormsModule, RouterLink],
  template: `
    <div class="login">
      <form class="card" (ngSubmit)="submit()">
        <div class="brand big">Account<span>gold</span></div>
        <p class="muted">Set your password for {{ email }}</p>
        @if (!token || !email) {
          <div class="alert">This link has expired. Ask your administrator to resend the invitation.</div>
        } @else {
          <label>Password <input type="password" name="password" [(ngModel)]="password" required minlength="8" autocomplete="new-password" /></label>
          <label>Confirm password <input type="password" name="password_confirmation" [(ngModel)]="confirm" required autocomplete="new-password" /></label>
          <small class="muted">At least 8 characters, with a letter and a digit.</small>
          @if (error()) { <div class="alert">{{ error() }}</div> }
          <button type="submit" [disabled]="busy()">{{ busy() ? 'Saving…' : 'Set password' }}</button>
        }
        <a routerLink="/login">Back to sign in</a>
      </form>
    </div>
  `,
})
export class SetPasswordComponent {
  private http = inject(HttpClient);
  private router = inject(Router);
  private query = inject(ActivatedRoute).snapshot.queryParamMap;
  token = this.query.get('token') ?? '';
  email = this.query.get('email') ?? '';
  password = '';
  confirm = '';
  busy = signal(false);
  error = signal('');

  submit() {
    if (this.password !== this.confirm) { this.error.set('The passwords do not match.'); return; }
    this.busy.set(true);
    this.error.set('');
    this.http.post('/api/v1/auth/set-password', {
      token: this.token, email: this.email, password: this.password, password_confirmation: this.confirm,
    }).subscribe({
      next: () => this.router.navigate(['/login'], { queryParams: { password_set: 1 } }),
      error: e => { this.error.set(errorText(e)); this.busy.set(false); },
    });
  }
}
