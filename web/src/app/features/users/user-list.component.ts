import { Component, OnInit, inject, signal } from '@angular/core';
import { HttpClient, HttpParams } from '@angular/common/http';
import { AppDateTimePipe } from '../../core/app-date.pipe';
import { FormsModule } from '@angular/forms';
import { Router, RouterLink } from '@angular/router';
import { AuthService } from '../../core/auth.service';
import { Membership, Page, UserRow } from '../../core/models';
import { errorText } from '../../core/api.interceptor';

/** Users list /users (spec 4.1). Branch mode: members of the active branch; All branches: everyone. */
@Component({
  standalone: true,
  imports: [FormsModule, AppDateTimePipe, RouterLink],
  template: `
    <h1>Users</h1>
    <form class="filters" (ngSubmit)="load(1)">
      <input name="q" placeholder="Search name or email" [(ngModel)]="f.q" />
      <select name="status" [(ngModel)]="f.status">
        <option value="">All</option><option value="active">Active</option><option value="inactive">Inactive</option>
      </select>
      <button type="submit">Filter</button>
      @if (auth.can('user.create')) {
        @if (auth.isAllBranches()) {
          <button type="button" disabled title="Select a branch to add a user">New user</button>
          <span class="muted">Select a branch to add a user</span>
        } @else {
          <a routerLink="/users/new"><button type="button">New user</button></a>
        }
      }
    </form>
    @if (error()) { <div class="alert">{{ error() }}</div> }
    <div class="card">
      <table class="list">
        <thead><tr>
          <th>Name</th><th>Email</th><th>Phone</th><th>{{ auth.isAllBranches() ? 'Branches' : 'Role' }}</th><th>Status</th><th>Last login</th>
          @if (auth.isSuperAdmin()) { <th>2FA</th> }
        </tr></thead>
        <tbody>
          @for (u of page()?.data; track u.id) {
            <tr (click)="open(u)" style="cursor: pointer">
              <td><a [routerLink]="['/users', u.id]">{{ u.name }}</a></td>
              <td>{{ u.email }}</td>
              <td>{{ u.phone }}</td>
              <td>
                @if (u.is_super_admin) { <span class="tag">Super Admin</span> }
                @else if (auth.isAllBranches()) { {{ branchesText(u.memberships) }} }
                @else {
                  @if (current(u); as m) {
                    {{ m.role_name ?? '—' }} @if (m.role_status === 'inactive') { <span class="tag" data-s="inactive">Role inactive</span> }
                  }
                }
              </td>
              <td><span class="tag" [attr.data-s]="u.status">{{ u.status }}</span></td>
              <td>{{ u.last_login_at ? (u.last_login_at | appDateTime) : 'Invitation pending' }}</td>
              <!-- AG-005 S8: Super Admin only -->
              @if (auth.isSuperAdmin()) {
                <td>@if (u.two_factor; as t) {
                  @if (t.enabled) { On } @else if (t.required) { <span class="tf-2fa-off">Required, off</span> } @else { Off }
                }</td>
              }
            </tr>
          } @empty { <tr><td [attr.colspan]="auth.isSuperAdmin() ? 7 : 6" class="muted">{{ auth.isAllBranches() ? 'No users match.' : 'No users in this branch yet.' }}</td></tr> }
        </tbody>
      </table>
      @if (page(); as p) {
        <div class="pager">
          <button class="ghost" [disabled]="p.current_page <= 1" (click)="load(p.current_page - 1)">Previous</button>
          <span>Page {{ p.current_page }} of {{ p.last_page }} · {{ p.total }} users</span>
          <button class="ghost" [disabled]="p.current_page >= p.last_page" (click)="load(p.current_page + 1)">Next</button>
        </div>
      }
    </div>
  `,
})
export class UserListComponent implements OnInit {
  auth = inject(AuthService);
  private http = inject(HttpClient);
  private router = inject(Router);
  page = signal<Page<UserRow> | null>(null);
  error = signal('');
  f = { q: '', status: '' };

  ngOnInit() { this.load(1); }

  load(p: number) {
    let params = new HttpParams().set('page', p);
    for (const [k, v] of Object.entries(this.f)) if (v) params = params.set(k, v);
    this.http.get<Page<UserRow>>('/api/v1/users', { params }).subscribe({
      next: r => { this.page.set(r); this.error.set(''); },
      error: e => this.error.set(errorText(e)),
    });
  }

  current(u: UserRow): Membership | undefined {
    return u.memberships.find(m => String(m.branch_id) === this.auth.activeBranchId());
  }

  branchesText(ms: Membership[]) {
    return ms.map(m => `${m.branch_code} · ${m.role_name ?? '—'}${m.role_status === 'inactive' ? ' (inactive)' : ''}`).join(', ');
  }

  open(u: UserRow) { this.router.navigate(['/users', u.id]); }
}
