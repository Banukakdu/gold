<?php

namespace Tests\Feature\Settings;

use App\Models\AuditLog;
use App\Models\Setting;
use App\Services\ReportExporter;
use App\Services\ReportService;
use App\Support\Settings;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\IOFactory;

/** Settings spec ACs 10-15 (R3 logo) and AC 16 (date format in PDFs and Excel). */
class LogoTest extends SettingsTestCase
{
    private function logo(): ?array
    {
        app(Settings::class)->flush();

        return app(Settings::class)->logo();
    }

    public function test_ac10_png_and_jpeg_are_stored_read_only_without_url(): void
    {
        $bytes = $this->png(600, 200);
        $res = $this->upload($this->file('logo.png', $bytes))->assertCreated()
            ->assertJsonPath('width', 600)->assertJsonPath('height', 200)->assertJsonPath('mime', 'image/png')->assertJsonPath('original_name', 'logo.png');
        $logo = $this->logo();
        $this->assertMatchesRegularExpression('#^logo/[0-9a-f-]{36}\.png$#', $logo['path']);
        $disk = Storage::disk(Settings::DISK);
        $this->assertSame($bytes, $disk->get($logo['path']));
        $this->assertSame('0440', substr(sprintf('%o', fileperms($disk->path($logo['path']))), -4));
        $this->assertSame(hash('sha256', $bytes), $logo['sha256']);
        $raw = Setting::find('company_logo')->value;
        $this->assertStringNotContainsString('data:', $raw);
        $this->assertStringNotContainsString('http', $raw);
        $this->assertSame($logo['sha256'], $res->json('sha256'));
        $row = AuditLog::where('event', 'settings.logo_uploaded')->sole();
        $this->assertNull($row->old_values);
        // JSON column: MySQL does not keep the key order.
        $this->assertEquals(['original_name' => 'logo.png', 'mime' => 'image/png', 'size_bytes' => strlen($bytes), 'width' => 600, 'height' => 200, 'sha256' => $logo['sha256']], $row->new_values);

        $this->upload($this->file('logo.jpg', $this->jpeg(), 'image/jpeg'))->assertCreated()->assertJsonPath('mime', 'image/jpeg');
        $this->assertStringEndsWith('.jpg', $this->logo()['path']);
    }

    public function test_ac11_refused_files_store_nothing(): void
    {
        $cases = [
            ['big.png', $this->png(20, 20).str_repeat("\0", 1_048_577 - strlen($this->png(20, 20))), 'The logo may be at most 1 MB.'],
            ['empty.png', '', 'The file is empty.'],
            ['x.svg', '<svg xmlns="http://www.w3.org/2000/svg"></svg>', 'The logo must be a PNG or JPG image.'],
            ['evil.png', "<?php echo 'x';", 'The logo must be a PNG or JPG image.'],
            ['fake.jpg', $this->png(100, 40), 'fake.jpg is a PNG image, not a JPG. Rename it or choose the right file.'],
            ['huge.png', $this->png(3000, 100), 'The logo must be between 16 and 2000 pixels wide and high.'],
        ];
        $this->assertSame(1_048_577, strlen($cases[0][1]));
        foreach ($cases as [$name, $bytes, $message]) {
            $this->upload($this->file($name, $bytes, 'image/png'))->assertStatus(422)->assertJsonPath('message', $message);
        }
        $this->assertSame([], Storage::disk(Settings::DISK)->allFiles());
        $this->assertNull($this->logo());
        $this->assertSame(0, AuditLog::where('event', 'like', 'settings.%')->count());
    }

    public function test_ac12_logo_on_every_pdf_page_from_the_stored_file(): void
    {
        $bytes = $this->png(600, 200);
        $this->upload($this->file('logo.png', $bytes))->assertCreated();
        $p = app(ReportService::class)->params('transfers', ['from' => '2026-10-01', 'to' => '2026-10-31']);
        $head = app(ReportExporter::class)->header('transfers', $p, ['provisional' => false], $this->admin);
        $this->assertSame('data:image/png;base64,'.base64_encode($bytes), $head['logo']);

        $pdf = $this->as($this->acc, $this->kdy)->postJson('/api/v1/reports/income-expense/export', ['from' => '2026-10-01', 'to' => '2026-10-31', 'format' => 'pdf'])->assertOk()->getContent();
        $this->assertStringContainsString('/Subtype /Image', $pdf);

        $this->sa()->postJson('/api/v1/settings/logo/remove')->assertOk();
        $head = app(ReportExporter::class)->header('transfers', $p, ['provisional' => false], $this->admin);
        $this->assertNull($head['logo']);
        $this->assertSame('Account-gold', $head['company']);
    }

    public function test_ac13_any_user_gets_the_logo_bytes(): void
    {
        $bytes = $this->png(600, 200);
        $this->upload($this->file('logo.png', $bytes))->assertCreated();
        $res = $this->as($this->csh, $this->kdy)->get('/api/v1/settings/logo')->assertOk()
            ->assertHeader('Content-Type', 'image/png')->assertHeader('X-Content-Type-Options', 'nosniff')->assertHeader('ETag', '"'.hash('sha256', $bytes).'"');
        $this->assertSame($bytes, $res->getContent());
        $this->assertStringContainsString('max-age=300', $res->headers->get('Cache-Control'));
        $this->as($this->csh, $this->kdy)->getJson('/api/v1/settings/public')->assertJsonPath('logo.sha256', hash('sha256', $bytes));
    }

    public function test_ac14_changed_or_missing_file(): void
    {
        $this->upload($this->file('logo.png', $this->png()))->assertCreated();
        $logo = $this->logo();
        $disk = Storage::disk(Settings::DISK);
        @chmod($disk->path($logo['path']), 0640);
        $disk->put($logo['path'], $this->png(50, 50));

        Log::spy();
        $p = app(ReportService::class)->params('income-expense', ['from' => '2026-10-01', 'to' => '2026-10-31']);
        $this->assertNull(app(ReportExporter::class)->header('income-expense', $p, ['provisional' => false], $this->admin)['logo']);
        Log::shouldHaveReceived('error')->withArgs(fn ($msg, $ctx = []) => str_contains($msg, 'logo') && ($ctx['path'] ?? null) === $logo['path']);
        $this->as($this->acc, $this->kdy)->postJson('/api/v1/reports/income-expense/export', ['from' => '2026-10-01', 'to' => '2026-10-31', 'format' => 'pdf'])->assertOk();
        $this->as($this->csh, $this->kdy)->getJson('/api/v1/settings/logo')->assertStatus(409);
        $this->sa()->getJson('/api/v1/settings')->assertJsonPath('logo.file_problem', 'changed');

        $disk->delete($logo['path']);
        $this->as($this->csh, $this->kdy)->getJson('/api/v1/settings/logo')->assertStatus(410)
            ->assertJsonPath('message', 'The logo file is missing from storage. Upload it again.');
        $this->sa()->getJson('/api/v1/settings')->assertJsonPath('logo.file_problem', 'missing');
    }

    public function test_ac15_replace_keeps_the_old_file_and_remove_keeps_the_file(): void
    {
        $this->upload($this->file('logo.png', $this->png()))->assertCreated();
        $first = $this->logo();
        $this->upload($this->file('new.png', $this->png(300, 100)))->assertCreated();
        $disk = Storage::disk(Settings::DISK);
        $this->assertTrue($disk->exists($first['path']));
        $row = AuditLog::where('event', 'settings.logo_uploaded')->latest('id')->first();
        $this->assertSame(['sha256' => $first['sha256'], 'original_name' => 'logo.png'], $row->old_values);

        $second = $this->logo();
        $this->sa()->postJson('/api/v1/settings/logo/remove')->assertOk()->assertJsonPath('logo', null);
        $this->assertNull($this->logo());
        $this->assertTrue($disk->exists($second['path']));
        $removed = AuditLog::where('event', 'settings.logo_removed')->sole();
        $this->assertSame(['sha256' => $second['sha256'], 'original_name' => 'new.png'], $removed->old_values);
        $this->sa()->postJson('/api/v1/settings/logo/remove')->assertStatus(422)->assertJsonPath('message', 'There is no logo to remove.');
        $this->as($this->csh, $this->kdy)->getJson('/api/v1/settings/logo')->assertNotFound()->assertJsonPath('message', 'No logo.');

        $this->assertFalse(collect(Route::getRoutes())->contains(fn ($r) => str_starts_with($r->uri(), 'api/v1/settings') && in_array('DELETE', $r->methods(), true)));
    }

    public function test_ac16_date_format_in_pdf_and_excel_api_unchanged(): void
    {
        $this->save(['date_format' => 'DD/MM/YYYY'])->assertOk();
        $q = ['from' => '2026-10-01', 'to' => '2026-10-31'];
        $this->sa()->getJson('/api/v1/reports/transfers?'.http_build_query($q))->assertJsonPath('movements.data.0.txn_date', '2026-10-01');

        $pdf = $this->sa()->postJson('/api/v1/reports/transfers/export', $q + ['format' => 'pdf'])->assertOk();
        $this->assertSame('attachment; filename="transfers_2026-10-01_2026-10-31.pdf"', $pdf->headers->get('Content-Disposition'));
        $text = implode(' ', $this->pdfText($pdf->getContent()));
        $this->assertStringContainsString('Period: 01/10/2026 to 31/10/2026', $text);
        $this->assertStringContainsString('01/10/2026', str_replace('Period: 01/10/2026', '', $text));

        $xlsx = $this->sa()->postJson('/api/v1/reports/transfers/export', $q + ['format' => 'xlsx'])->assertOk();
        $book = IOFactory::createReader('Xlsx')->load($xlsx->getFile()->getPathname());
        $ws = $book->getSheet(0);
        $row = collect($ws->toArray(null, false, false))->search(fn ($r) => ($r[0] ?? null) === 'Date') + 2;
        $cell = $ws->getCell([1, $row]);
        $this->assertSame('n', $cell->getDataType());
        $this->assertSame('dd/mm/yyyy', $cell->getStyle()->getNumberFormat()->getFormatCode());
        $this->assertSame('01/10/2026', $cell->getFormattedValue());
    }

    /** dompdf core-font text, as ReportTestCase::pdfPages. */
    private function pdfText(string $pdf): array
    {
        $pages = [];
        preg_match_all('/\/Length (\d+)[^>]*>>\s*stream\r?\n/', $pdf, $m, PREG_OFFSET_CAPTURE | PREG_SET_ORDER);
        foreach ($m as $hit) {
            $content = @gzuncompress(substr($pdf, $hit[0][1] + strlen($hit[0][0]), (int) $hit[1][0]));
            if ($content === false || str_contains($content, "\0") || ! str_contains($content, ' Tf')) {
                continue;
            }
            preg_match_all('/\((?:[^()\\\\]|\\\\.)*\)/s', $content, $s);
            $pages[] = implode(' ', array_map(fn ($x) => stripcslashes(substr($x, 1, -1)), $s[0]));
        }

        return $pages;
    }
}
