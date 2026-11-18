import { Component, OnInit, inject, signal } from '@angular/core';
import { HttpClient, HttpErrorResponse } from '@angular/common/http';
import { FormsModule } from '@angular/forms';
import { Router, RouterLink } from '@angular/router';
import { AuthService } from '../../core/auth.service';
import { RoleRow, fieldErrors } from '../../core/models';
import { errorText } from '../../core/api.interceptor';

/** New user /users/new (spec 4.2): joins the active branch with a role; an invitation email is sent. */
@Component({
  standalone: true,
  imports: [FormsModule, RouterLink],
  template: `
    <h1>New user in {{ auth.activeBranch()?.name }}</h1>
    <form class="card form" (ngSubmit)="save()">
      <label>Name <input name="name" [(ngModel)]="m.name" required maxlength="255" />
        @if (errors()['name']) { <small class="neg">{{ errors()['name'] }}</small> }</label>
      <label>Email <input type="email" name="email" [(ngModel)]="m.email" required maxlength="255" />
        @if (errors()['email']) { <small class="neg">{{ errors()['email'] }}</small> }</label>
      <label>Phone <input name="phone" [(ngModel)]="m.phone" maxlength="30" />
        @if (errors()['phone']) { <small class="neg">{{ errors()['phone'] }}</small> }</label>
      <label>Role
        <select name="role_id" [(ngModel)]="m.role_id" required>
          @for (r of roles(); track r.id) { <option [ngValue]="r.id">{{ r.name }}</option> }
        </select>
        @if (errors()['role_id']) { <small class="neg">{{ errors()['role_id'] }}</small> }
        @if (loaded() && !roles().length) { <small class="muted">This branch has no roles you can assign. Create or copy roles first.</small> }
      </label>
      @if (existingId() !== null) {
        <div class="alert wide">
          @if (auth.isSuperAdmin()) {
            This email already has an account - <a [routerLink]="['/users', existingId()]">open it to add branch access</a>.
          } @else {
            This email already has an account. Ask a Super Admin to add them to your branch.
          }
        </div>
      }
      @if (error()) { <div class="alert wide">{{ error() }}</div> }
      <p class="muted wide">An invitation email is sent so the user can set their password.</p>
      <div class="wide actions"><button type="submit" [disabled]="busy()">Save</button></div>
    </form>
  `,
})
export class UserFormComponent implements OnInit {
  auth = inject(AuthService);
  private http = inject(HttpClient);
  private router = inject(Router);
  roles = signal<RoleRow[]>([]);
  loaded = signal(false);
  errors = signal<Record<string, string>>({});
  error = signal('');
  existingId = signal<number | null>(null);
  busy = signal(false);
  m: { name: string; email: string; phone: string; role_id: number | null } = { name: '', email: '', phone: '', role_id: null };

  ngOnInit() {
    // Active roles of this branch only, no templates, nothing with permissions the actor lacks (spec 4.2).
    this.http.get<RoleRow[]>('/api/v1/roles').subscribe(rs => {
      this.roles.set(rs.filter(r => !r.is_template && r.status === 'active' && r.permissions.every(p => this.auth.can(p))));
      this.loaded.set(true);
    });
  }

  save() {
    this.busy.set(true);
    this.errors.set({});
    this.error.set('');
    this.existingId.set(null);
    this.http.post<{ id: number }>('/api/v1/users', this.m).subscribe({
      next: u => this.router.navigate(['/users', u.id]),
      error: (e: HttpErrorResponse) => {
        this.busy.set(false);
        if (e.status === 422) {
          this.errors.set(fieldErrors(e));
          if (e.error?.errors?.email && e.error?.existing_user_id !== undefined && e.error.message === 'This email already has an account.') {
            this.existingId.set(e.error.existing_user_id ?? 0);
          }
          if (!Object.keys(this.errors()).length) this.error.set(errorText(e));
        } else {
          this.error.set(errorText(e));
        }
      },
    });
  }
}
