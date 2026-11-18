import { Pipe, PipeTransform } from '@angular/core';

/** 1234567.5 -> "1,234,567.50" (currency shown separately). */
@Pipe({ name: 'money', standalone: true })
export class MoneyPipe implements PipeTransform {
  transform(v: string | number | null | undefined): string {
    const n = Number(v ?? 0);
    return n.toLocaleString('en-LK', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  }
}

export const TYPE_LABELS: Record<string, string> = {
  capital: 'Capital',
  opening_allocation: 'Opening balance',
  fund_allocation: 'Fund allocation',
  fund_return: 'Fund return',
  branch_transfer: 'Branch transfer',
  internal_transfer: 'Internal transfer',
  income: 'Income',
  expense: 'Expense',
  reversal: 'Reversal',
  year_end_close: 'Year-end close',
};
