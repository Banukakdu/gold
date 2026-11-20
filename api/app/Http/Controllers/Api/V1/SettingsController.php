<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Setting;
use App\Models\User;
use App\Models\YearEndClose;
use App\Support\Currencies;
use App\Support\LogoFile;
use App\Support\LogoStore;
use App\Support\Settings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * AG-092 system settings (docs/specs/settings.md S1, section 5, R1-R4, R12). The only writer of the settings table.
 * Public read for every signed-in user; the Settings screen and every write are Super Admin only (`super`).
 */
class SettingsController extends Controller
{
    /** Editable keys: API name => settings row key. Anything else is "Unknown setting". */
    public const EDITABLE = [
        'company_name' => 'company_name',
        'currency_code' => 'currency',
        'date_format' => 'date_format',
        'fiscal_year_start_month' => 'fiscal_year_start_month',
    ];

    public const CURRENCY_LOCKED = 'The currency cannot be changed after entries have been posted.';

    public const FISCAL_LOCKED = 'The fiscal year start cannot be changed after a year-end close.';

    public const DECIMALS = 'Only 2 decimal places are supported.';

    public function __construct(private Settings $settings) {}

    /** GET /settings/public: what every screen needs (header, dates, amounts, report presets). */
    public function publicSettings()
    {
        return $this->publicBody();
    }

    /** GET /settings/logo: the image bytes, after the sha256 check (R3). */
    public function logo()
    {
        $logo = $this->settings->logo();
        abort_unless($logo, 404, 'No logo.');
        [$bytes, $problem] = Settings::readLogo($logo);
        if ($problem === 'missing') {
            Log::error('Company logo file is missing from storage.', ['path' => $logo['path']]);

            return response()->json(['message' => 'The logo file is missing from storage. Upload it again.'], 410);
        }
        if ($problem === 'changed') {
            Log::error('Company logo file does not match its sha256.', ['path' => $logo['path']]);

            return response()->json(['message' => 'The logo file was changed outside the app. Upload it again.'], 409);
        }

        return response($bytes, 200, [
            'Content-Type' => $logo['mime'],
            'Content-Length' => (string) strlen($bytes),
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, max-age=300',
            'ETag' => '"'.$logo['sha256'].'"',
        ]);
    }

    /** GET /settings (Super Admin): the Settings screen. */
    public function show()
    {
        return $this->fullBody();
    }

    /** PUT /settings: only changed keys are written, in one DB transaction, with one `settings.updated` row (R1). */
    public function update(Request $request)
    {
        $input = $request->json()->all() ?: $request->all();
        foreach (array_keys($input) as $key) {
            if ($key !== 'currency_decimals' && ! isset(self::EDITABLE[$key])) {
                throw ValidationException::withMessages([$key => "Unknown setting: {$key}."]);
            }
        }
        $d = validator($input, [
            'company_name' => 'sometimes|required|string|min:2|max:120',
            'currency_code' => ['sometimes', 'required', 'string', Rule::in(Currencies::CODES)],
            'date_format' => ['sometimes', 'required', Rule::in(array_keys(Settings::DATE_FORMATS))],
            'fiscal_year_start_month' => 'sometimes|required|integer|between:1,12',
            // Review N3: an array or text is a 422, never a string cast of an array.
            'currency_decimals' => 'sometimes|required|integer',
        ], [
            'currency_decimals.required' => self::DECIMALS,
            'currency_decimals.integer' => self::DECIMALS,
            'currency_code.in' => 'Choose a currency code from the list (3 capital letters, ISO 4217).',
            'date_format.in' => 'Choose one of the date formats offered.',
        ])->validate();
        if (array_key_exists('currency_decimals', $d) && (int) $d['currency_decimals'] !== Currencies::DECIMALS) {
            throw ValidationException::withMessages(['currency_decimals' => self::DECIMALS]);
        }
        unset($d['currency_decimals']);
        if (isset($d['company_name'])) {
            $d['company_name'] = trim((string) $d['company_name']);
        }
        if (isset($d['fiscal_year_start_month'])) {
            $d['fiscal_year_start_month'] = (int) $d['fiscal_year_start_month'];
        }

        DB::transaction(function () use ($d) {
            // Review N4: the rows FOR UPDATE before the old values are read, so two saves cannot both audit the same
            // old value. Ordered by key, one lock order.
            Setting::whereIn('key', array_values(self::EDITABLE))->orderBy('key')->lockForUpdate()->get();
            $current = $this->current();
            $changed = array_filter($d, fn ($v, $k) => (string) $v !== (string) $current[$k], ARRAY_FILTER_USE_BOTH);
            if (! $changed) {
                return;
            }
            if (isset($changed['currency_code']) && DB::table('ledger_entries')->sharedLock()->exists()) {
                throw ValidationException::withMessages(['currency_code' => self::CURRENCY_LOCKED]);
            }
            if (isset($changed['fiscal_year_start_month']) && YearEndClose::query()->sharedLock()->exists()) {
                throw ValidationException::withMessages(['fiscal_year_start_month' => self::FISCAL_LOCKED]);
            }
            foreach ($changed as $k => $v) {
                Setting::put(self::EDITABLE[$k], (string) $v);
            }
            // R2: accounts.currency follows the setting (amounts are never converted).
            if (isset($changed['currency_code'])) {
                DB::table('accounts')->update(['currency' => $changed['currency_code']]);
            }
            AuditLog::record('settings.updated', null, array_intersect_key($current, $changed), $changed);
        });
        $this->settings->flush();

        return $this->fullBody();
    }

    /** POST /settings/logo: R3 checks, then the file is stored as received (0440); the old file stays. */
    public function uploadLogo(Request $request)
    {
        $info = LogoFile::inspect($request->file('file'));
        $file = $request->file('file');
        $stored = null;
        try {
            $stored = LogoStore::put($file->getPathname(), $info, $info['name'], $request->user()->id);
            DB::transaction(function () use ($stored) {
                $previous = $this->settings->logo();
                Setting::put('company_logo', json_encode($stored));
                AuditLog::record('settings.logo_uploaded', null,
                    $previous ? ['sha256' => $previous['sha256'], 'original_name' => $previous['original_name']] : null,
                    array_intersect_key($stored, array_flip(['original_name', 'mime', 'size_bytes', 'width', 'height', 'sha256'])));
            });
        } catch (Throwable $e) {
            if ($stored) {
                LogoStore::discard($stored['path']);
            }
            throw $e;
        }
        $this->settings->flush();

        return response()->json($this->logoBody(), 201);
    }

    /** POST /settings/logo/remove: clears the setting; the file stays on disk (nothing is hard-deleted). */
    public function removeLogo(Request $request)
    {
        $reason = $request->validate(['reason' => 'nullable|string|max:500'])['reason'] ?? null;
        DB::transaction(function () use ($reason) {
            $logo = $this->settings->logo();
            abort_unless($logo, 422, 'There is no logo to remove.');
            Setting::put('company_logo', null);
            AuditLog::record('settings.logo_removed', null, ['sha256' => $logo['sha256'], 'original_name' => $logo['original_name']],
                $reason !== null ? ['reason' => $reason] : null);
        });
        $this->settings->flush();

        return $this->fullBody();
    }

    // ------------------------------------------------------------------ bodies

    /** Current values by API name. */
    private function current(): array
    {
        $this->settings->flush();

        return [
            'company_name' => $this->settings->companyName(),
            'currency_code' => $this->settings->currencyCode(),
            'date_format' => $this->settings->dateFormat(),
            'fiscal_year_start_month' => $this->settings->fiscalYearStartMonth(),
        ];
    }

    private function publicBody(): array
    {
        $logo = $this->settings->logo();

        return $this->current() + [
            'logo' => $logo ? ['sha256' => $logo['sha256'], 'updated_at' => $logo['uploaded_at'] ?? null] : null,
            'currency_decimals' => Currencies::DECIMALS,
            'current_fiscal_year' => $this->settings->currentFiscalYear()->toArray(),
        ];
    }

    private function logoBody(): ?array
    {
        $logo = $this->settings->logo();
        if (! $logo) {
            return null;
        }
        $by = isset($logo['uploaded_by']) ? User::find($logo['uploaded_by']) : null;

        return array_intersect_key($logo, array_flip(['original_name', 'mime', 'size_bytes', 'width', 'height', 'sha256'])) + [
            'uploaded_by' => $by ? ['id' => $by->id, 'name' => $by->name] : null,
            'uploaded_at' => $logo['uploaded_at'] ?? null,
            // S1: "The logo file is missing or was changed outside the app. Upload it again."
            'file_problem' => Settings::readLogo($logo)[1],
        ];
    }

    private function fullBody(): array
    {
        $out = $this->publicBody();
        $out['logo'] = $this->logoBody();
        $out['currency_locked'] = DB::table('ledger_entries')->exists();
        $out['fiscal_year_start_locked'] = YearEndClose::query()->exists();
        $out['date_formats'] = array_keys(Settings::DATE_FORMATS);
        $out['currencies'] = Currencies::CODES;

        // "Last changed by <name> on <date>" per field, from the audit (newest first).
        $last = [];
        $rows = AuditLog::with('user:id,name')->where('event', 'like', 'settings.%')->orderByDesc('id')->get();
        foreach ($rows as $r) {
            $keys = $r->event === 'settings.updated' ? array_keys($r->new_values ?? []) : ['logo'];
            foreach ($keys as $k) {
                $last[$k] ??= ['by' => $r->user ? ['id' => $r->user->id, 'name' => $r->user->name] : null, 'at' => $r->created_at?->toIso8601String()];
            }
        }
        $out['last_changed'] = $last;

        return $out;
    }
}
