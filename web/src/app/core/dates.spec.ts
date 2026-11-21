import { colomboToday } from './dates';
import { currentMonth } from '../features/reports/report-common';

/** Default dates are Asia/Colombo business days (API BusinessDate), not the UTC or browser date. */
describe('business date defaults', () => {
  beforeEach(() => jasmine.clock().install());
  afterEach(() => jasmine.clock().uninstall());

  it('colomboToday is the next day after 18:30 UTC', () => {
    jasmine.clock().mockDate(new Date('2026-11-20T20:00:00Z')); // 2026-11-21 01:30 Asia/Colombo
    expect(colomboToday()).toBe('2026-11-21');
    jasmine.clock().mockDate(new Date('2026-11-20T18:29:00Z')); // 23:59 Asia/Colombo
    expect(colomboToday()).toBe('2026-11-20');
  });

  it('currentMonth is the Colombo month at a month boundary', () => {
    jasmine.clock().mockDate(new Date('2026-11-30T20:00:00Z')); // 2026-12-01 01:30 Asia/Colombo
    expect(currentMonth()).toEqual({ from: '2026-12-01', to: '2026-12-31' });
    expect(currentMonth('2028-02-10')).toEqual({ from: '2028-02-01', to: '2028-02-29' });
  });
});
