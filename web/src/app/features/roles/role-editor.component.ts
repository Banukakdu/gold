import { Component, OnInit, computed, inject, input, signal } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { FormsModule } from '@angular/forms';
import { Router, RouterLink } from '@angular/router';
import { forkJoin } from 'rxjs';
import { AuthService } from '../../core/auth.service';
import { PERMISSION_MODULES, RoleRow, fieldErrors } from '../../core/models';
import { errorText } from '../../core/api.interceptor';

const RECORD_AND_CANCEL: [string, string][] = [['income.create', 'income.void'], ['expense.create', 'expense.void'], ['transfer.create', 'transfer.reverse']];
const ADMIN_KEYS = ['user.create', 'user.edit', 'role.manage'];
const MONEY_KEYS = ['income.create', 'expense.create', 'transfer.create', 'income.void', 'expense.void', 'transfer.reverse', 'account.manage'];

/** Role editor /roles/new, /roles/:id (spec 4.5). Read-only for users with role.view only. */
@Component({
  standalone: true,
  imports: [FormsModule, RouterLink],
  template: `
    <p><a routerLink="/roles">← Roles</a></p>
    @if (notFound()) {
      <div class="alert">Role not found.</div>
    } @else {
      <h1>
        {{ isNew() ? (auth.isAllBranches() ? 'New template' : 'New role') : name }}
        @if (role()?.is_template || (isNew() && auth.isAllBranches())) { <span class="tag">Template</span> }
        @if (role()?.status === 'inactive') { <span class="tag" data-s="inactive">Role inactive</span> }
      </h1>
      <form class="card" (ngSubmit)="save()">
        <div class="form">
          <label>Name <input name="name" [(ngModel)]="name" [disabled]="!editable()" required maxlength="60" />
            @if (errors()['name']) { <small class="neg">{{ errors()['name'] }}</small> }</label>
          @if (role(); as r) { <label>Usage <span>Held by {{ r.users_count }} {{ r.users_count === 1 ? 'user' : 'users' }}</span></label> }
        </div>

        <h2>Permissions</h2>
        <table class="list">
          <tbody>
            @for (g of groups(); track g.module) {
              <tr>
                <th style="width: 160px">
                  <label title="Select all in module">
                    <input type="checkbox" style="width: auto" [checked]="allIn(g.keys)" [disabled]="!editable() || !grantable(g.keys).length"
                      (change)="toggleModule(g.keys, $any($event.target).checked)" /> {{ g.module }}
                  </label>
                </th>
                <td>
                  @for (k of g.keys; track k) {
                    <label style="display: inline-flex; gap: 4px; margin-right: 16px; align-items: center"
                      [title]="auth.can(k) ? k : 'You don\\'t have this permission'">
                      <input type="checkbox" style="width: auto" [checked]="selected().has(k)" [disabled]="!editable() || !auth.can(k)"
                        (change)="toggle(k, $any($event.target).checked)" /> {{ k.split('.')[1] }}
                    </label>
                  }
                </td>
              </tr>
            }
          </tbody>
        </table>

        @for (w of warnings(); track w) { <div class="alert" style="margin-top: 8px">{{ w }}</div> }
        @if (error()) { <div class="alert" style="margin-top: 8px">{{ error() }}</div> }
        @if (ok()) { <div class="ok" style="margin-top: 8px">{{ ok() }}</div> }
        @if (!editable() && !isNew()) { <p class="muted">You can view this role but not change it.</p> }

        @if (editable()) {
          <div class="actions" style="gap: 8px; margin-top: 12px">
            @if (role(); as r) {
              <button type="button" class="ghost" (click)="toggleStatus(r)">{{ r.status === 'active' ? 'Deactivate' : 'Activate' }}</button>
            }
            <button type="submit" [disabled]="busy()">Save</button>
          </div>
        }
      </form>
    }
  `,
})
export class RoleEditorComponent implements OnInit {
  id = input<string>();
  auth = inject(AuthService);
  private http = inject(HttpClient);
  private router = inject(Router);
  catalogue = signal<Record<string, string[]>>({});
  role = signal<RoleRow | null>(null);
  selected = signal<Set<string>>(new Set());
  notFound = signal(false);
  error = signal('');
  ok = signal('');
  errors = signal<Record<string, string>>({});
  busy = signal(false);
  name = '';

  isNew = computed(() => !this.id());

  /** Templates: Super Admin only. Branch roles: role.manage, and not a role with permissions the actor lacks. */
  editable = computed(() => {
    const r = this.role();
    if (this.isNew()) return this.auth.isAllBranches() ? this.auth.isSuperAdmin() : this.auth.can('role.manage');
    if (!r) return false;
    if (r.is_template) return this.auth.isSuperAdmin();
    return this.auth.can('role.manage') && r.permissions.every(p => this.auth.can(p));
  });

  groups = computed(() => {
    const cat = this.catalogue();
    const order = [...PERMISSION_MODULES, ...Object.keys(cat).filter(m => !PERMISSION_MODULES.includes(m))];
    return order.filter(m => cat[m]?.length).map(module => ({ module, keys: cat[module] }));
  });

  /** Accounting review finding 5 / AC 44: warn, never block. */
  warnings = computed(() => {
    const s = this.selected();
    const out: string[] = [];
    if (RECORD_AND_CANCEL.some(([a, b]) => s.has(a) && s.has(b))) {
      out.push('This role can record and cancel the same entries. Make sure someone else reviews the audit log.');
    }
    if (ADMIN_KEYS.some(k => s.has(k)) && MONEY_KEYS.some(k => s.has(k))) {
      out.push('This role can manage users and move money.');
    }
    return out;
  });

  ngOnInit() {
    forkJoin({
      cat: this.http.get<Record<string, string[]>>('/api/v1/permissions'),
      roles: this.http.get<RoleRow[]>('/api/v1/roles'),
    }).subscribe({
      next: ({ cat, roles }) => {
        this.catalogue.set(cat);
        if (this.isNew()) return;
        const r = roles.find(x => String(x.id) === this.id());
        if (!r) { this.notFound.set(true); return; }
        this.role.set(r);
        this.name = r.name;
        this.selected.set(new Set(r.permissions));
      },
      error: e => this.error.set(errorText(e)),
    });
  }

  grantable(keys: string[]) { return keys.filter(k => this.auth.can(k)); }
  /** "Select all" is checked only when every key the actor may grant in that module is ticked. */
  allIn(keys: string[]) {
    const g = this.grantable(keys);
    return g.length > 0 && g.every(k => this.selected().has(k));
  }

  toggle(k: string, on: boolean) {
    const s = new Set(this.selected());
    on ? s.add(k) : s.delete(k);
    this.selected.set(s);
  }

  toggleModule(keys: string[], on: boolean) {
    const s = new Set(this.selected());
    for (const k of this.grantable(keys)) on ? s.add(k) : s.delete(k);
    this.selected.set(s);
  }

  save() {
    this.busy.set(true);
    this.errors.set({});
    this.error.set('');
    this.ok.set('');
    const body = { name: this.name, permissions: [...this.selected()].sort() };
    const r = this.role();
    const req = r ? this.http.put<RoleRow>(`/api/v1/roles/${r.id}`, body) : this.http.post<RoleRow>('/api/v1/roles', body);
    req.subscribe({
      next: saved => {
        this.busy.set(false);
        if (!r) { this.router.navigate(['/roles', saved.id]); return; }
        this.role.set(saved);
        this.ok.set('Role saved.');
      },
      error: e => { this.busy.set(false); this.errors.set(fieldErrors(e)); if (!this.errors()['name']) this.error.set(errorText(e)); },
    });
  }

  toggleStatus(r: RoleRow) {
    const deactivate = r.status === 'active';
    if (deactivate && !confirm(`${r.users_count} users hold this role; they lose its permissions until given another role.`)) return;
    this.http.put<RoleRow>(`/api/v1/roles/${r.id}`, { status: deactivate ? 'inactive' : 'active' }).subscribe({
      next: saved => { this.role.set(saved); this.error.set(''); this.ok.set(deactivate ? 'Role deactivated.' : 'Role activated.'); },
      error: e => this.error.set(errorText(e)),
    });
  }
}
