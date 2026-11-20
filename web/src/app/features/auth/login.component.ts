import { Component, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { ActivatedRoute, Router, RouterLink } from '@angular/router';
import { AuthService } from '../../core/auth.service';
import { errorText } from '../../core/api.interceptor';

@Component({
  standalone: true,
  imports: [FormsModule, RouterLink],
  template: `
    <div class="login">
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
    </div>
  `,
})
export class LoginComponent {
  private auth = inject(AuthService);
  private router = inject(Router);
  private query = inject(ActivatedRoute).snapshot.queryParamMap;
  passwordSet = this.query.has('password_set');
  passwordReset = this.query.has('password_reset');
  email = '';
  password = '';
  busy = signal(false);
  error = signal('');

  async submit() {
    this.busy.set(true);
    this.error.set('');
    try {
      await this.auth.login(this.email, this.password);
      this.router.navigateByUrl('/dashboard');
    } catch (e) {
      this.error.set(errorText(e));
    } finally {
      this.busy.set(false);
    }
  }
}
