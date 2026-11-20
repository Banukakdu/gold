import { Pipe, PipeTransform, inject } from '@angular/core';
import { SettingsService } from './settings.service';

/** S3: a business date or timestamp in the date format setting. Impure, so a changed setting shows at once. */
@Pipe({ name: 'appDate', standalone: true, pure: false })
export class AppDatePipe implements PipeTransform {
  private settings = inject(SettingsService);
  transform(v: string | null | undefined): string { return this.settings.date(v); }
}

/** A timestamp as "<date> HH:mm" (Asia/Colombo, 24 h) in the date format setting. */
@Pipe({ name: 'appDateTime', standalone: true, pure: false })
export class AppDateTimePipe implements PipeTransform {
  private settings = inject(SettingsService);
  transform(v: string | null | undefined): string { return this.settings.dateTime(v); }
}
