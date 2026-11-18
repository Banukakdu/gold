import { Component, OnInit, computed, inject, signal } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { RouterLink } from '@angular/router';
import { AuthService } from '../../core/auth.service';
import { RoleRow } from '../../core/models';
import { errorText } from '../../core/api.interceptor';

/** Roles /roles (spec 4.4): branch roles + read-only templates, or templates only in All branches mode. */
@Component({
  standalone: true,
  imports: [RouterLink],
  template: `
    <h1>Roles</h1>
    @if (ok()) { <div class="ok">{{ ok() }}</div> }
    @if (error()) { <div class="alert">{{ error() }}</div> }

    @if (!auth.isAllBranches()) {
      <div class="filters">
        <h2 style="margin: 0; flex: 1">Roles in {{ auth.activeBranch()?.name }}</h2>
        @if (auth.can('role.manage')) { <a routerLink="/roles/new"><button type="button">New role</button></a> }
      </div>
      <div class="card">
        <table class="list">
          <thead><tr><th>Name</th><th class="num">Permissions</th><th class="num">Users</th><th>Status</th><th></th></tr></thead>
          <tbody>
            @for (r of branchRoles(); track r.id) {
              <tr>
                <td><a [routerLink]="['/roles', r.id]">{{ r.name }}</a></td>
                <td class="num">{{ r.permissions.length }}</td>
                <td class="num">{{ r.users_count }}</td>
                <td><span class="tag" [attr.data-s]="r.status">{{ r.status === 'inactive' ? 'Role inactive' : 'active' }}</span></td>
                <td><a [routerLink]="['/roles', r.id]">{{ auth.can('role.manage') ? 'Edit' : 'View' }}</a></td>
              </tr>
            } @empty {
              <tr><td colspan="5" class="muted">
                This branch has no roles yet.
                @if (!auth.isSuperAdmin()) { Ask a Super Admin to copy the role templates. }
              </td></tr>
            }
          </tbody>
        </table>
      </div>
    }

    <div class="filters" style="margin-top: 16px">
      <h2 style="margin: 0; flex: 1">Templates</h2>
      @if (auth.isAllBranches()) { <a routerLink="/roles/new"><button type="button">New template</button></a> }
      @if (auth.isSuperAdmin() && !auth.isAllBranches() && missingTemplates()) {
        <button type="button" (click)="copy()">Copy templates into this branch</button>
      }
    </div>
    <div class="card">
      <table class="list">
        <thead><tr><th>Name</th><th class="num">Permissions</th><th>Status</th><th></th></tr></thead>
        <tbody>
          @for (r of templates(); track r.id) {
            <tr>
              <td><a [routerLink]="['/roles', r.id]">{{ r.name }}</a></td>
              <td class="num">{{ r.permissions.length }}</td>
              <td><span class="tag" [attr.data-s]="r.status">{{ r.status }}</span></td>
              <td><a [routerLink]="['/roles', r.id]">{{ auth.isSuperAdmin() ? 'Edit' : 'View' }}</a></td>
            </tr>
          }
        </tbody>
      </table>
      @if (!auth.isAllBranches()) { <p class="muted">Templates are copied into a branch; copies are independent of the template afterwards.</p> }
    </div>
  `,
})
export class RoleListComponent implements OnInit {
  auth = inject(AuthService);
  private http = inject(HttpClient);
  roles = signal<RoleRow[]>([]);
  error = signal('');
  ok = signal('');
  branchRoles = computed(() => this.roles().filter(r => !r.is_template));
  templates = computed(() => this.roles().filter(r => r.is_template));
  /** True when at least one template name is not yet in this branch. */
  missingTemplates = computed(() => {
    const names = new Set(this.branchRoles().map(r => r.name.toLowerCase()));
    return this.templates().some(t => !names.has(t.name.toLowerCase()));
  });

  ngOnInit() { this.load(); }

  load() {
    this.http.get<RoleRow[]>('/api/v1/roles').subscribe({ next: r => this.roles.set(r), error: e => this.error.set(errorText(e)) });
  }

  copy() {
    this.http.post<{ created: string[]; skipped: string[] }>('/api/v1/roles/copy-templates', {}).subscribe({
      next: r => {
        this.ok.set(`Created: ${r.created.join(', ') || 'none'}.` + (r.skipped.length ? ` Skipped (already exist): ${r.skipped.join(', ')}.` : ''));
        this.load();
      },
      error: e => this.error.set(errorText(e)),
    });
  }
}
