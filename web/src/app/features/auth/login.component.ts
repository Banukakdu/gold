import { Component, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { Router } from '@angular/router';
import { AuthService } from '../../core/auth.service';
import { errorText } from '../../core/api.interceptor';

@Component({
  standalone: true,
  imports: [FormsModule],
  template: `
    <div class="login">
      <form class="card" (ngSubmit)="submit()">
        <div class="brand big">Account<span>gold</span></div>
        <p class="muted">Multi-branch accounting</p>
        <label>Email <input type="email" name="email" [(ngModel)]="email" required autocomplete="username" /></label>
        <label>Password <input type="password" name="password" [(ngModel)]="password" required autocomplete="current-password" /></label>
        @if (error()) { <div class="alert">{{ error() }}</div> }
        <button type="submit" [disabled]="busy()">{{ busy() ? 'Signing in…' : 'Sign in' }}</button>
      </form>
    </div>
  `,
})
export class LoginComponent {
  private auth = inject(AuthService);
  private router = inject(Router);
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
