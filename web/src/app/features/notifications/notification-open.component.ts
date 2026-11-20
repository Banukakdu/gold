import { Component, OnInit, inject } from '@angular/core';
import { ActivatedRoute, Router } from '@angular/router';
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
  template: `<p class="muted">Opening the notification...</p>`,
})
export class NotificationOpenComponent implements OnInit {
  private route = inject(ActivatedRoute);
  private router = inject(Router);
  private svc = inject(NotificationsService);
  private toast = inject(ToastService);

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

  private async run(id: string) {
    let row;
    try {
      row = await firstValueFrom(this.svc.get(id));
    } catch {
      this.toast.show('Notification not found.');
      await this.router.navigateByUrl('/notifications', { replaceUrl: true });
      return;
    }
    if (!(await this.svc.open(row, { replaceUrl: true }))) {
      // No access to the row's branch any more (the toast says so): the list instead of a blank page.
      await this.router.navigateByUrl('/notifications', { replaceUrl: true });
    }
  }
}
