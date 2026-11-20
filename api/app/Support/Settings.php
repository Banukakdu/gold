<?php

namespace App\Support;

use App\Models\Setting;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Typed, memoised reader of the system settings (docs/specs/settings.md R1). One query per request; a saved Setting
 * flushes the memo (Setting::booted). Not branch data: every branch sees the same values.
 * Writes go through SettingsController only (LedgerService reads `expense_negative_balance` through here too).
 */
final class Settings
{
    public const DISK = 'branding';

    /** Display formats (S1 / R4) => [PHP date format, Excel number format]. Display only, never input or storage. */
    public const DATE_FORMATS = [
        'D MMM YYYY' => ['j M Y', 'd mmm yyyy'],
        'DD/MM/YYYY' => ['d/m/Y', 'dd/mm/yyyy'],
        'DD-MM-YYYY' => ['d-m-Y', 'dd-mm-yyyy'],
        'YYYY-MM-DD' => ['Y-m-d', 'yyyy-mm-dd'],
    ];

    public const DEFAULT_DATE_FORMAT = 'D MMM YYYY';

    public const DEFAULT_FISCAL_START = 4;

    /** Rows inserted by the settings migration where missing (R1 / section 5); currency_decimals is a constant. */
    public static function defaults(): array
    {
        return [
            'company_name' => (string) config('app.name'),
            'currency' => Currencies::DEFAULT,
            'date_format' => self::DEFAULT_DATE_FORMAT,
            'fiscal_year_start_month' => (string) self::DEFAULT_FISCAL_START,
        ];
    }

    private ?array $values = null;

    public function flush(): void
    {
        $this->values = null;
    }

    private function raw(string $key): ?string
    {
        $this->values ??= Setting::query()->pluck('value', 'key')->all();
        $v = $this->values[$key] ?? null;

        return $v === null ? null : (string) $v;
    }

    public function companyName(): string
    {
        $v = $this->raw('company_name');

        return $v === null || trim($v) === '' ? (string) config('app.name') : $v;
    }

    public function currencyCode(): string
    {
        $v = $this->raw('currency');

        return $v !== null && in_array($v, Currencies::CODES, true) ? $v : Currencies::DEFAULT;
    }

    public function dateFormat(): string
    {
        $v = $this->raw('date_format');

        return $v !== null && isset(self::DATE_FORMATS[$v]) ? $v : self::DEFAULT_DATE_FORMAT;
    }

    public function fiscalYearStartMonth(): int
    {
        $v = (int) ($this->raw('fiscal_year_start_month') ?? self::DEFAULT_FISCAL_START);

        return $v >= 1 && $v <= 12 ? $v : self::DEFAULT_FISCAL_START;
    }

    public function expenseNegativeBalance(): string
    {
        return $this->raw('expense_negative_balance') ?? 'block';
    }

    /** AG-052 R3: new between-branch transfers start as "Awaiting receipt". Default off (Q2). */
    public function transferReceiptRequired(): bool
    {
        return $this->raw('transfer_receipt_required') === '1';
    }

    /**
     * AG-051 R2: transfers above this many cents need Main's approval. Null = never (default); 0 = every branch
     * transfer and fund return. Stored as integer cents text; anything else reads as never.
     */
    public function transferApprovalThresholdCents(): ?int
    {
        $v = $this->raw('transfer_approval_threshold');
        if ($v !== null && $v !== '' && ! ctype_digit($v)) {
            // Review m9: never silent. A hand-edited value turns the control off; the Settings screen warns too.
            if (! $this->thresholdLogged) {
                Log::warning('Setting transfer_approval_threshold is not integer cents; transfers are not held for approval.', ['value' => $v]);
                $this->thresholdLogged = true;
            }

            return null;
        }

        return $v !== null && $v !== '' ? (int) $v : null;
    }

    private bool $thresholdLogged = false;

    /** Review m9: the stored threshold is set but not integer cents (it reads as never). */
    public function transferApprovalThresholdInvalid(): bool
    {
        $v = $this->raw('transfer_approval_threshold');

        return $v !== null && $v !== '' && ! ctype_digit($v);
    }

    /** The stored logo metadata (R3) or null. A legacy data: URI is not a logo any more (the migration converted it). */
    public function logo(): ?array
    {
        $v = $this->raw('company_logo');
        if ($v === null || $v === '') {
            return null;
        }
        $d = json_decode($v, true);

        return is_array($d) && isset($d['path'], $d['sha256'], $d['mime']) ? $d : null;
    }

    public function fiscalYear(string $date): FiscalYear
    {
        return FiscalYear::of($date, $this->fiscalYearStartMonth());
    }

    public function fiscalYearStarting(int $startYear): FiscalYear
    {
        return new FiscalYear($startYear, $this->fiscalYearStartMonth());
    }

    public function currentFiscalYear(): FiscalYear
    {
        return $this->fiscalYear(BusinessDate::today());
    }

    /** A Y-m-d date in the date format setting (S3). Anything else is returned unchanged. */
    public function formatDate(?string $date): string
    {
        $date = (string) $date;
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', substr($date, 0, 10));
        if ($d === false || strlen($date) < 10) {
            return $date;
        }

        return $d->format(self::DATE_FORMATS[$this->dateFormat()][0]);
    }

    public function excelDateFormat(): string
    {
        return self::DATE_FORMATS[$this->dateFormat()][1];
    }

    /**
     * R3 "Use": the stored logo as a PNG/JPEG data URI for dompdf, after checking its sha256. On a missing file or a
     * mismatch, null (the PDF is produced without the logo) and an error is logged with the path.
     */
    public function logoDataUri(): ?string
    {
        $logo = $this->logo();
        if (! $logo) {
            return null;
        }
        [$bytes, $problem] = self::readLogo($logo);
        if ($problem !== null) {
            Log::error("Company logo not printed: {$problem}.", ['path' => $logo['path']]);

            return null;
        }

        return 'data:'.$logo['mime'].';base64,'.base64_encode($bytes);
    }

    /** @return array{0: ?string, 1: ?string} [bytes, problem] problem = 'missing' | 'changed' | null */
    public static function readLogo(array $logo): array
    {
        try {
            $disk = Storage::disk(self::DISK);
            $bytes = $disk->exists($logo['path']) ? $disk->get($logo['path']) : null;
        } catch (Throwable) {
            $bytes = null;
        }
        if ($bytes === null) {
            return [null, 'missing'];
        }
        if (! hash_equals((string) $logo['sha256'], hash('sha256', $bytes))) {
            return [null, 'changed'];
        }

        return [$bytes, null];
    }
}
