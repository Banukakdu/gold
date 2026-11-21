import { Injectable, signal } from '@angular/core';

/** AG-094: one short message at the bottom of the screen (shown by the shell), e.g. "Preference saved.". */
@Injectable({ providedIn: 'root' })
export class ToastService {
  readonly text = signal('');
  private timer: ReturnType<typeof setTimeout> | null = null;

  show(text: string, ms = 4000) {
    this.text.set(text);
    if (this.timer) clearTimeout(this.timer);
    this.timer = setTimeout(() => this.text.set(''), ms);
  }

  clear() {
    if (this.timer) clearTimeout(this.timer);
    this.text.set('');
  }
}
