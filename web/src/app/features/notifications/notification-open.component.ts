import { Component, OnInit, inject, signal } from '@angular/core';
import { HttpErrorResponse } from '@angular/common/http';
import { ActivatedRoute, Router, RouterLink } from '@angular/router';
import { errorText } from '../../core/api.interceptor';
import { firstValueFrom } from 'rxjs';
import { NotificationsService } from '../../core/notifications.service';
import { ToastService } from '../../core/toast.service';

/** The id being opened: a branch switch re-creates this page (the shell re-renders the outlet), which must not re-run. */
let opening: string | null = null;

/**
 * AG-094 S3 route /notifications/:id (the approval email's link): runs the open flow on load and replaces itself in
 * history, so Back does not return here and the link works whatever branch is active. Someone else's (or an unknown)
 * id goes to /notifications with "Notification not found.".
 */
@Component({
  standalone: true,
  template: `
    @if (error()) {
      <div class="alert" role="alert">{{ error() }}</div>
      <p><button type="button" class="ghost" (click)="retry()">Try again</button> <a routerLink="/notifications">All notifications</a></p>
    } @else {
      <p class="muted">Opening the notification...</p>
    }
  `,
  imports: [RouterLink],
})
export class NotificationOpenComponent implements OnInit {
  private route = inject(ActivatedRoute);
  private router = inject(Router);
  private svc = inject(NotificationsService);
  private toast = inject(ToastService);
  error = signal('');

  async ngOnInit() {
    const id = this.route.snapshot.paramMap.get('id') ?? '';
    if (opening === id) return;
    opening = id;
    try {
      await this.run(id);
    } finally {
      opening = null;
    }
  }

  retry() {
    const id = this.route.snapshot.paramMap.get('id') ?? '';
    if (opening === id) return;
    opening = id;
    this.run(id).finally(() => { opening = null; });
  }

  private async run(id: string) {
    let row;
    this.error.set('');
    try {
      row = await firstValueFrom(this.svc.get(id));
    } catch (e) {
      // Only a 404 means "not yours or gone" (review finding 5). An outage or an inactive account stays here and says so.
      if ((e as HttpErrorResponse)?.status === 404) {
        this.toast.show('Notification not found.');
        await this.router.navigateByUrl('/notifications', { replaceUrl: true });
      } else {
        this.error.set((e as HttpErrorResponse)?.status === 403 ? errorText(e) : 'Could not open the notification. Try again.');
      }
      return;
    }
    if (!(await this.svc.open(row, { replaceUrl: true }))) {
      // No access to the row's branch any more (the toast says so): the list instead of a blank page.
      await this.router.navigateByUrl('/notifications', { replaceUrl: true });
    }
  }
}
