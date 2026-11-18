<?php

namespace Tests\Feature\Settings;

use App\Support\Settings;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Controls\ControlsTestCase;

/**
 * docs/specs/settings.md section 8 setup: MAIN, KDY, GAL; SA, BA (Branch Admin), ACC, CSH; "today" 2026-11-18;
 * `Storage::fake('branding')` and real image bytes built with GD.
 */
abstract class SettingsTestCase extends ControlsTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(Settings::DISK);
        $this->ba = $this->userIn($this->kdy, 'Branch Admin');
    }

    protected function png(int $w = 600, int $h = 200): string
    {
        $im = imagecreatetruecolor($w, $h);
        imagefill($im, 0, 0, imagecolorallocate($im, 200, 160, 40));
        ob_start();
        imagepng($im);

        return (string) ob_get_clean();
    }

    protected function jpeg(int $w = 300, int $h = 100): string
    {
        $im = imagecreatetruecolor($w, $h);
        ob_start();
        imagejpeg($im);

        return (string) ob_get_clean();
    }

    protected function file(string $name, string $bytes, string $mime = 'image/png'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, $bytes)->mimeType($mime);
    }

    protected function upload(UploadedFile $file)
    {
        return $this->sa()->post('/api/v1/settings/logo', ['file' => $file], ['Accept' => 'application/json']);
    }

    protected function save(array $body, $as = null)
    {
        return ($as ?? $this->sa())->putJson('/api/v1/settings', $body);
    }
}
