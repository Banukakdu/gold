import { AfterViewInit, Component, ElementRef, input, model, output, viewChild } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { cleanCode } from '../../core/two-factor';

/**
 * AG-005 S2 code field (also in S3 and the step-up dialogs): 6 digits, spaces removed, numeric keyboard and one-time
 * code autofill. `complete` fires when the 6th digit is typed or pasted (auto-submit).
 */
@Component({
  selector: 'app-code-input',
  standalone: true,
  imports: [FormsModule],
  template: `
    <input #field class="tf-code" type="text" inputmode="numeric" autocomplete="one-time-code" maxlength="6" [attr.name]="name()"
      [attr.aria-label]="label()" [disabled]="disabled()" [ngModel]="value()" (ngModelChange)="changed($event)" (paste)="paste($event)" />
  `,
})
export class CodeInputComponent implements AfterViewInit {
  value = model('');
  name = input('code');
  label = input('6-digit code');
  disabled = input(false);
  autofocus = input(false);
  complete = output<string>();
  private field = viewChild.required<ElementRef<HTMLInputElement>>('field');

  ngAfterViewInit() {
    if (this.autofocus()) this.field().nativeElement.focus();
  }

  changed(v: string) {
    const code = cleanCode(v);
    this.value.set(code);
    // Keep what is shown in step with the cleaned value (maxlength counts the spaces of a pasted "123 456").
    if (this.field().nativeElement.value !== code) this.field().nativeElement.value = code;
    if (code.length === 6) this.complete.emit(code);
  }

  paste(e: ClipboardEvent) {
    const text = e.clipboardData?.getData('text');
    if (text === undefined) return;
    e.preventDefault();
    this.changed(text);
  }
}
