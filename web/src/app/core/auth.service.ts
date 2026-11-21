import { Injectable, computed, inject, signal } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { Router } from '@angular/router';
import { firstValueFrom } from 'rxjs';
import { BranchAccess, Me } from './models';
import { TwoFactorChallenge } from './two-factor';

const TOKEN = 'ag.token';
const BRANCH = 'ag.branch';

function read(key: string): string | null {
  try { return localStorage.getItem(key); } catch { return null; }
}
function write(key: string, v: string | null) {
  try { v === null ? localStorage.removeItem(key) : localStorage.setItem(key, v); } catch { /* ignore */ }
}

/**
 * Holds the session: token, current user and the active branch.
 * activeBranchId === 'all' is the Super Admin consolidated view.
 */
@Injectable({ providedIn: 'root' })
export class AuthService {
  private http = inject(HttpClient);
  private router = inject(Router);

  readonly token = signal<string | null>(read(TOKEN));
  readonly me = signal<Me | null>(null);
  readonly activeBranchId = signal<string>(read(BRANCH) ?? '');
  /** AG-094 S3: when /me was last loaded (ms), so the notification open flow can tell whether it is fresh. */
  meAt = 0;

  readonly activeBranch = computed<BranchAccess | null>(() =>
    this.me()?.branches.find(b => String(b.id) === this.activeBranchId()) ?? null);
  readonly isSuperAdmin = computed(() => !!this.me()?.is_super_admin);
  readonly isAllBranches = computed(() => this.isSuperAdmin() && this.activeBranchId() === 'all');

  /**
   * Step 1 of sign-in. AG-005: when 2FA is on or required, the API answers with a challenge instead of a token; it is
   * returned to the login screen (kept in its memory only) and nothing is stored.
   */
  async login(email: string, password: string): Promise<TwoFactorChallenge | null> {
    const res = await firstValueFrom(this.http.post<{ token: string; user: Me } | TwoFactorChallenge>('/api/v1/auth/login', { email, password }));
    if ('two_factor' in res) return res;
    this.startSession(res.token, res.user);
    return null;
  }

  /** AG-005 P1 / P3: the token after the second step. */
  startSession(token: string, user: Me) {
    this.token.set(token);
    write(TOKEN, token);
    this.setUser(user);
  }

  async loadMe(): Promise<boolean> {
    if (!this.token()) return false;
    try {
      this.setUser(await firstValueFrom(this.http.get<Me>('/api/v1/me')));
      return true;
    } catch {
      this.clear();
      return false;
    }
  }

  /** AG-094 S3: reload /me (branches and permissions) without signing out when the request fails. */
  async refreshMe(): Promise<void> {
    if (!this.token()) return;
    try { this.setUser(await firstValueFrom(this.http.get<Me>('/api/v1/me'))); } catch { /* keep the current profile */ }
  }

  async logout() {
    try { await firstValueFrom(this.http.post('/api/v1/auth/logout', {})); } catch { /* token may already be gone */ }
    this.clear();
    this.router.navigateByUrl('/login');
  }

  switchBranch(id: string) {
    this.activeBranchId.set(id);
    write(BRANCH, id);
  }

  /** Permission check in the active branch. */
  can(permission: string): boolean {
    if (this.isSuperAdmin()) return true;
    const p = this.activeBranch()?.permissions ?? [];
    return p.includes('*') || p.includes(permission);
  }

  clear() {
    this.token.set(null);
    this.me.set(null);
    write(TOKEN, null);
  }

  private setUser(user: Me) {
    this.me.set(user);
    this.meAt = Date.now();
    const current = this.activeBranchId();
    const valid = (user.is_super_admin && current === 'all') || user.branches.some(b => String(b.id) === current);
    if (!valid) {
      this.switchBranch(user.is_super_admin ? 'all' : String(user.branches[0]?.id ?? ''));
    }
  }
}
