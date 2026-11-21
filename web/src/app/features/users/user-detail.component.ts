import { Component, OnInit, inject, input, signal } from '@angular/core';
import { HttpClient, HttpHeaders } from '@angular/common/http';
import { AppDateTimePipe } from '../../core/app-date.pipe';
import { FormsModule } from '@angular/forms';
import { RouterLink } from '@angular/router';
import { AuthService } from '../../core/auth.service';
import { Membership, RoleRow, UserRow, fieldErrors } from '../../core/models';
import { errorText } from '../../core/api.interceptor';
import { CodeInputComponent } from '../account/code-input.component';

/** User detail /users/:id (spec 4.3): profile, status, invitation and branch access. */
@Component({
  standalone: true,
  imports: [FormsModule, AppDateTimePipe, RouterLink, CodeInputComponent],
  template: `
    <p><a routerLink="/users">← Users</a></p>
    @if (notFound()) {
      <div class="alert">User not found.</div>
    }
    @if (user(); as u) {
      <h1>{{ u.name }} @if (u.is_super_admin) { <span class="tag">Super Admin</span> }</h1>
      @if (ok()) { <div class="ok">{{ ok() }}</div> }
      @if (error()) { <div class="alert">{{ error() }}</div> }

      <h2>Profile</h2>
      <form class="card form" (ngSubmit)="saveProfile()">
        <label>Name <input name="name" [(ngModel)]="p.name" [disabled]="!canEdit(u)" required maxlength="255" />
          @if (errors()['name']) { <small class="neg">{{ errors()['name'] }}</small> }</label>
        <label>Email <input type="email" name="email" [(ngModel)]="p.email" [disabled]="!canEdit(u)" required />
          @if (errors()['email']) { <small class="neg">{{ errors()['email'] }}</small> }</label>
        <label>Phone <input name="phone" [(ngModel)]="p.phone" [disabled]="!canEdit(u)" maxlength="30" /></label>
        <label>Status <span><span class="tag" [attr.data-s]="u.status">{{ u.status }}</span></span></label>
        <label>Last login <span>{{ u.last_login_at ? (u.last_login_at | appDateTime) : 'Invitation pending' }}</span></label>
        <!-- AG-005 S8: Super Admin only -->
        @if (u.two_factor; as t) {
          <label>Two-factor sign-in
            <span data-testid="two-factor-row">
              @if (t.enabled) { On since {{ t.confirmed_at | appDateTime }} · recovery codes left {{ t.recovery_codes_left }} }
              @else if (t.required) { <span class="tf-2fa-off">Off (required: will be asked at next sign-in)</span> }
              @else { Off }
            </span>
          </label>
          @if (t.enabled && u.id !== auth.me()?.id) {
            <div class="wide"><button type="button" class="ghost" data-testid="reset-two-factor" (click)="openReset()">Reset two-factor</button></div>
          }
        }
        @if (auth.can('user.edit') && u.profile_locked_reason) { <p class="muted wide">{{ u.profile_locked_reason }}</p> }
        @if (canEdit(u)) {
          <div class="wide actions" style="gap: 8px">
            @if (!u.last_login_at && u.status === 'active') { <button type="button" class="ghost" (click)="resend(u)">Resend invitation</button> }
            @if (u.id !== auth.me()?.id) {
              <button type="button" class="ghost" (click)="toggleStatus(u)">{{ u.status === 'active' ? 'Deactivate' : 'Activate' }}</button>
            }
            <button type="submit">Save profile</button>
          </div>
        }
      </form>

      <h2>Branch access</h2>
      <div class="card">
        <table class="list">
          <thead><tr><th>Branch</th><th>Role</th><th>Role status</th><th></th></tr></thead>
          <tbody>
            @for (m of u.memberships; track m.branch_id) {
              <tr>
                <td>{{ m.branch_code }} · {{ m.branch_name }}</td>
                <td>
                  @if (editing() === m.branch_id) {
                    <select [(ngModel)]="changeRoleId" name="r{{ m.branch_id }}">
                      @for (r of changeRoles(); track r.id) { <option [ngValue]="r.id">{{ r.name }}</option> }
                    </select>
                  } @else { {{ m.role_name ?? '—' }} }
                </td>
                <td>@if (m.role_status) { <span class="tag" [attr.data-s]="m.role_status">{{ m.role_status === 'inactive' ? 'Role inactive' : 'active' }}</span> }</td>
                <td>
                  @if (canManage(u, m)) {
                    @if (editing() === m.branch_id) {
                      <button class="link" (click)="saveRole(u, m.branch_id)">Save</button>
                      <button class="link" (click)="editing.set(null)">Cancel</button>
                    } @else {
                      <button class="link" (click)="startChange(m.branch_id)">Change role</button>
                      <button class="link" (click)="remove(u, m)">Remove from branch</button>
                    }
                  }
                </td>
              </tr>
            } @empty { <tr><td colspan="4" class="muted">No branch access. The user cannot use the app until added to a branch.</td></tr> }
          </tbody>
        </table>
        @if (auth.isSuperAdmin() && !u.is_super_admin) {
          <h2>Add to branch</h2>
          <form class="filters" (ngSubmit)="addToBranch(u)">
            <select name="add_branch" [(ngModel)]="addBranchId" (ngModelChange)="loadAddRoles($event)">
              <option [ngValue]="null">Pick a branch</option>
              @for (b of otherBranches(u); track b.id) { <option [ngValue]="b.id">{{ b.code }} · {{ b.name }}</option> }
            </select>
            <select name="add_role" [(ngModel)]="addRoleId" [disabled]="!addBranchId">
              @for (r of addRoles(); track r.id) { <option [ngValue]="r.id">{{ r.name }}</option> }
            </select>
            <button type="submit" [disabled]="!addBranchId || !addRoleId">Add to branch</button>
          </form>
        }
      </div>

      @if (resetOpen()) {
        <div class="att-backdrop" (click)="resetOpen.set(false)"></div>
        <form class="att-dialog ap-dialog tf-dialog" role="dialog" aria-modal="true" (ngSubmit)="reset(u)">
          <h2>Reset two-factor for {{ u.name }}</h2>
          @if (u.two_factor?.reset_blocked_since; as since) {
            <div class="banner amber">Someone entered this user's password without the code on {{ since | appDateTime }}. They must reset their
              password before two-factor can be reset.</div>
          }
          <p>This turns off their two-factor sign-in, removes their recovery codes and signs them out on every device. If they are an admin and
            two-factor is required, they must set it up again at their next sign-in.</p>
          @if (resetAlert()) { <div class="alert">{{ resetAlert() }}</div> }
          <label>Reason
            <textarea name="reason" rows="3" maxlength="500" required [(ngModel)]="resetForm.reason"></textarea>
            <small class="muted">Say how you checked it was really them (for example: in person, phone call to their known number).</small>
          </label>
          @if (resetErrors()['reason']) { <div class="field-error">{{ resetErrors()['reason'] }}</div> }
          <label>Your password <input type="password" name="password" autocomplete="current-password" required [(ngModel)]="resetForm.password" /></label>
          @if (resetErrors()['password']) { <div class="field-error">{{ resetErrors()['password'] }}</div> }
          @if (auth.me()?.two_factor?.enabled) {
            <label>Your code <app-code-input [(value)]="resetCode" name="code" /></label>
            @if (resetErrors()['code']) { <div class="field-error">{{ resetErrors()['code'] }}</div> }
          }
          <div class="tf-dialog-actions">
            <button type="submit" [disabled]="resetBusy()">{{ resetBusy() ? 'Resetting…' : 'Reset' }}</button>
            <button type="button" class="ghost" (click)="resetOpen.set(false)">Cancel</button>
          </div>
        </form>
      }
    }
  `,
})
export class UserDetailComponent implements OnInit {
  id = input.required<string>();
  auth = inject(AuthService);
  private http = inject(HttpClient);
  user = signal<UserRow | null>(null);
  notFound = signal(false);
  error = signal('');
  ok = signal('');
  errors = signal<Record<string, string>>({});
  editing = signal<number | null>(null);
  /** Each form has its own branch-specific role options. */
  changeRoles = signal<RoleRow[]>([]);
  addRoles = signal<RoleRow[]>([]);
  changeRoleId: number | null = null;
  addRoleId: number | null = null;
  addBranchId: number | null = null;
  p = { name: '', email: '', phone: '' };
  /** AG-005 S8 reset dialog. */
  resetOpen = signal(false);
  resetBusy = signal(false);
  resetErrors = signal<Record<string, string>>({});
  resetAlert = signal('');
  resetForm = { reason: '', password: '' };
  resetCode = signal('');

  ngOnInit() { this.load(); }

  load() {
    this.http.get<UserRow>(`/api/v1/users/${this.id()}`).subscribe({
      next: u => { this.user.set(u); this.p = { name: u.name, email: u.email, phone: u.phone ?? '' }; },
      error: e => e.status === 404 ? this.notFound.set(true) : this.error.set(errorText(e)),
    });
  }

  /** Profile edits: the API also enforces B5 (shared users) and B6 (Super Admin). */
  canEdit(u: UserRow) { return this.auth.can('user.edit') && !u.profile_locked_reason; }

  canManage(u: UserRow, m: Membership) {
    if (u.id === this.auth.me()?.id || (u.is_super_admin && !this.auth.isSuperAdmin())) return false;
    return this.auth.isSuperAdmin() || (this.auth.can('user.edit') && String(m.branch_id) === this.auth.activeBranchId());
  }

  otherBranches(u: UserRow) {
    return (this.auth.me()?.branches ?? []).filter(b => !u.memberships.some(m => m.branch_id === b.id));
  }

  /** Requests about one membership run in that branch's context. */
  private h(branchId: number) { return { headers: new HttpHeaders({ 'X-Branch-Id': String(branchId) }) }; }

  /** Assignable roles of one branch: active, not templates, nothing the actor lacks. */
  private rolesOf(branchId: number, into: (rs: RoleRow[]) => void) {
    this.http.get<RoleRow[]>('/api/v1/roles', this.h(branchId)).subscribe({
      next: rs => into(rs.filter(r => !r.is_template && r.status === 'active' && r.permissions.every(p => this.auth.can(p)))),
      error: e => this.error.set(errorText(e)),
    });
  }

  loadAddRoles(branchId: number | null) {
    this.addRoles.set([]);
    this.addRoleId = null;
    if (branchId) this.rolesOf(branchId, rs => this.addRoles.set(rs));
  }

  startChange(branchId: number) {
    this.editing.set(branchId);
    this.changeRoles.set([]);
    this.changeRoleId = null;
    this.rolesOf(branchId, rs => { if (this.editing() === branchId) this.changeRoles.set(rs); });
  }

  saveRole(u: UserRow, branchId: number) {
    if (!this.changeRoleId) return;
    this.http.post(`/api/v1/users/${u.id}/role`, { role_id: this.changeRoleId }, this.h(branchId)).subscribe({
      next: () => { this.editing.set(null); this.done('Role changed.'); }, error: e => this.fail(e) });
  }

  addToBranch(u: UserRow) {
    if (!this.addBranchId || !this.addRoleId) return;
    this.http.post(`/api/v1/users/${u.id}/role`, { role_id: this.addRoleId }, this.h(this.addBranchId)).subscribe({
      next: () => { this.loadAddRoles(null); this.addBranchId = null; this.done('Added to branch.'); }, error: e => this.fail(e) });
  }

  remove(u: UserRow, m: Membership) {
    if (!confirm(`Remove ${u.name} from ${m.branch_name}? Their entries stay; they lose access to this branch.`)) return;
    this.http.post(`/api/v1/users/${u.id}/remove-from-branch`, {}, this.h(m.branch_id)).subscribe({
      next: () => this.done('Removed from branch.'), error: e => this.fail(e) });
  }

  toggleStatus(u: UserRow) {
    const deactivate = u.status === 'active';
    if (deactivate && !confirm(`Deactivate ${u.name}? They are signed out at once and cannot log in. Their entries and branch access are kept.`)) return;
    this.http.put(`/api/v1/users/${u.id}`, { status: deactivate ? 'inactive' : 'active' }).subscribe({
      next: () => this.done(deactivate ? 'User deactivated.' : 'User activated.'), error: e => this.fail(e) });
  }

  resend(u: UserRow) {
    this.http.post(`/api/v1/users/${u.id}/resend-invite`, {}).subscribe({
      next: () => this.done('Invitation sent again.'), error: e => this.fail(e) });
  }

  saveProfile() {
    const u = this.user();
    if (!u) return;
    this.errors.set({});
    this.http.put(`/api/v1/users/${u.id}`, { name: this.p.name, email: this.p.email, phone: this.p.phone || null }).subscribe({
      next: () => this.done('Profile saved.'), error: e => { this.errors.set(fieldErrors(e)); this.fail(e); } });
  }

  openReset() {
    this.resetForm = { reason: '', password: '' };
    this.resetCode.set('');
    this.resetErrors.set({});
    this.resetAlert.set('');
    this.resetOpen.set(true);
  }

  /** A1: Super Admin only; the API checks R16 and the actor's password and code. */
  reset(u: UserRow) {
    if (this.resetBusy()) return;
    this.resetBusy.set(true);
    this.resetErrors.set({});
    this.resetAlert.set('');
    const body: Record<string, string> = { reason: this.resetForm.reason, password: this.resetForm.password };
    if (this.auth.me()?.two_factor?.enabled) body['code'] = this.resetCode();
    this.http.post(`/api/v1/users/${u.id}/two-factor/reset`, body).subscribe({
      next: () => { this.resetBusy.set(false); this.resetOpen.set(false); this.done('Two-factor sign-in was reset.'); },
      error: e => {
        this.resetBusy.set(false);
        const fields = fieldErrors(e);
        const shown = Object.fromEntries(Object.entries(fields).filter(([k]) => ['reason', 'password', 'code'].includes(k)));
        this.resetErrors.set(shown);
        if (shown['code']) this.resetCode.set('');
        if (!Object.keys(shown).length) this.resetAlert.set(errorText(e));
      },
    });
  }

  private done(msg: string) { this.error.set(''); this.ok.set(msg); this.load(); }
  private fail(e: unknown) { this.ok.set(''); this.error.set(errorText(e)); }
}
