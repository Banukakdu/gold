<?php

namespace App\Support;

use App\Models\Attachment;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** AG-062 upload checks (R4) and filename handling (R7). Pure functions, no storage or database access. */
final class AttachmentFile
{
    public const TOO_BIG = 'Each file may be at most 5 MB.';

    public const WRONG_TYPE = 'Only JPG, PNG or PDF files can be attached.';

    /** Magic bytes at offset 0 (R4 step 3). */
    private const SIGNATURES = [
        'jpeg' => "\xFF\xD8\xFF",
        'png' => "\x89PNG\r\n\x1A\n",
        'pdf' => '%PDF-',
    ];

    /** Accepted extensions of the original name => detected type. */
    private const EXTENSIONS = ['jpg' => 'jpeg', 'jpeg' => 'jpeg', 'png' => 'png', 'pdf' => 'pdf'];

    /**
     * R4 in order; the first failure is the message (422 on the `file` field).
     *
     * @return array{0: UploadedFile, 1: string, 2: string} [file, detected type key of Attachment::TYPES, clean original name]
     *
     * @throws ValidationException
     */
    public static function inspect(mixed $file, int $contentLength = 0): array
    {
        // 1. Present and uploaded without a PHP error.
        if (! $file instanceof UploadedFile) {
            // PHP drops the whole body when it is larger than post_max_size: the file was too big, not missing.
            self::fail($contentLength > 0 && $contentLength > self::iniBytes((string) ini_get('post_max_size')) ? self::TOO_BIG : 'Choose a file to attach.');
        }
        if (! $file->isValid()) {
            self::fail(in_array($file->getError(), [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)
                ? self::TOO_BIG : 'The file could not be uploaded. Please try again.');
        }

        // 2. Size.
        $size = (int) $file->getSize();
        if ($size === 0) {
            self::fail('The file is empty.');
        }
        if ($size > Attachment::MAX_BYTES) {
            self::fail(self::TOO_BIG);
        }

        // 3. Content by magic bytes: finfo AND the signature must agree. The client's type and name are ignored here.
        $type = self::detect($file->getPathname());
        if ($type === null) {
            self::fail(self::WRONG_TYPE);
        }

        // 4. The original name's extension must be accepted and match the content.
        $base = self::basename((string) $file->getClientOriginalName());
        $ext = self::extensionOf($base);
        if ($ext === null || (self::EXTENSIONS[$ext] ?? null) !== $type) {
            [, $canonical, $label] = Attachment::TYPES[$type];
            $what = $type === 'pdf' ? 'a PDF' : "a {$label} image";
            $claimed = $ext !== null && isset(self::EXTENSIONS[$ext]) ? Attachment::TYPES[self::EXTENSIONS[$ext]][2] : null;
            self::fail($claimed
                ? "{$base} is {$what}, not a {$claimed}. Rename it or choose the right file."
                : "{$base} is {$what}, but its name does not end in .{$canonical}. Rename it or choose the right file.");
        }

        return [$file, $type, self::cleanName($base, $type)];
    }

    /** Detected type (key of Attachment::TYPES) or null. Both finfo and the first bytes must say the same thing. */
    public static function detect(string $path): ?string
    {
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            return null;
        }
        $head = (string) fread($handle, 8);
        fclose($handle);

        $bySignature = null;
        foreach (self::SIGNATURES as $type => $magic) {
            if (str_starts_with($head, $magic)) {
                $bySignature = $type;
                break;
            }
        }
        if ($bySignature === null) {
            return null;
        }
        $finfo = (new \finfo(FILEINFO_MIME_TYPE))->file($path);

        return $finfo === Attachment::TYPES[$bySignature][0] ? $bySignature : null;
    }

    /**
     * R7: display/download name. Path part dropped, extension replaced by the canonical one of the detected type,
     * control/format characters removed, reserved characters replaced, whitespace collapsed, stem cut to 120 characters.
     */
    public static function cleanName(string $original, string $type): string
    {
        $base = self::basename($original);
        $dot = mb_strrpos($base, '.');
        $stem = $dot === false ? $base : mb_substr($base, 0, $dot);

        $stem = (string) preg_replace('/\p{C}+/u', '', $stem);
        $stem = (string) preg_replace('#[/\\\\:*?"<>|]#u', '_', $stem);
        $stem = (string) preg_replace('/\s+/u', ' ', $stem);
        $stem = trim($stem, ' .');
        $stem = mb_substr($stem, 0, 120);
        if ($stem === '') {
            $stem = 'attachment';
        }

        return $stem.'.'.Attachment::TYPES[$type][1];
    }

    /** ASCII-only `filename=` fallback for Content-Disposition (RFC 6266); the real name goes in `filename*`. */
    public static function asciiFallback(string $name): string
    {
        $dot = mb_strrpos($name, '.');
        $stem = $dot === false ? $name : mb_substr($name, 0, $dot);
        $ext = $dot === false ? '' : '.'.mb_substr($name, $dot + 1);
        $clean = fn (string $v) => trim(str_replace(['%', '/', '\\', '"'], '_', (string) preg_replace('/[^\x20-\x7E]/', '_', Str::ascii($v))));
        $stem = $clean($stem);
        // A name in a non-Latin script can leave nothing readable (reviewer N1): never a bare ".pdf".
        if (! preg_match('/[A-Za-z0-9]/', $stem)) {
            $stem = 'attachment';
        }

        return $stem.$clean($ext);
    }

    private static function basename(string $name): string
    {
        $name = mb_scrub($name, 'UTF-8');
        $parts = preg_split('#[/\\\\]#', $name);

        return (string) end($parts);
    }

    private static function extensionOf(string $base): ?string
    {
        $dot = mb_strrpos($base, '.');

        return $dot === false ? null : mb_strtolower(mb_substr($base, $dot + 1));
    }

    private static function iniBytes(string $v): int
    {
        $v = trim($v);
        $n = (int) $v;

        return match (strtolower(substr($v, -1))) {
            'g' => $n * 1024 ** 3,
            'm' => $n * 1024 ** 2,
            'k' => $n * 1024,
            default => $n,
        } ?: PHP_INT_MAX;
    }

    /** @throws ValidationException */
    private static function fail(string $message): never
    {
        throw ValidationException::withMessages(['file' => $message]);
    }
}
