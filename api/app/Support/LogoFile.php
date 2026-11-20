<?php

namespace App\Support;

use App\Models\Attachment;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

/**
 * AG-092 company logo checks (docs/specs/settings.md R3; the attachments rules R2/R6 and accountant finding 2).
 * Pure checks, no storage or database access. The first failure is the message (422 on the `file` field).
 */
final class LogoFile
{
    public const MAX_BYTES = 1_048_576;

    public const MIN_PX = 16;

    public const MAX_PX = 2000;

    public const EMPTY = 'The file is empty.';

    public const TOO_BIG = 'The logo may be at most 1 MB.';

    public const WRONG_TYPE = 'The logo must be a PNG or JPG image.';

    public const PIXELS = 'The logo must be between 16 and 2000 pixels wide and high.';

    private const EXTENSIONS = ['jpg' => 'jpeg', 'jpeg' => 'jpeg', 'png' => 'png'];

    /**
     * @return array{type: string, mime: string, ext: string, name: string, size: int, width: int, height: int}
     *
     * @throws ValidationException
     */
    public static function inspect(mixed $file): array
    {
        if (! $file instanceof UploadedFile) {
            self::fail('Choose an image to upload.');
        }
        if (! $file->isValid()) {
            self::fail(in_array($file->getError(), [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true) ? self::TOO_BIG : 'The file could not be uploaded. Please try again.');
        }
        $name = self::basename((string) $file->getClientOriginalName());
        $error = self::check($file->getPathname(), (int) $file->getSize(), $name, $out);
        if ($error !== null) {
            self::fail($error);
        }

        return $out + ['name' => AttachmentFile::cleanName($name, $out['type'])];
    }

    /**
     * The R3 checks on a file on disk. $name null = no extension check (migration of a data: URI).
     *
     * @param-out array{type: string, mime: string, ext: string, size: int, width: int, height: int} $out
     *
     * @return string|null the message of the first failed check
     */
    public static function check(string $path, int $size, ?string $name, ?array &$out = null): ?string
    {
        if ($size === 0) {
            return self::EMPTY;
        }
        if ($size > self::MAX_BYTES) {
            return self::TOO_BIG;
        }
        $type = AttachmentFile::detect($path);
        if (! in_array($type, ['jpeg', 'png'], true)) {
            return self::WRONG_TYPE;
        }
        [$mime, $ext, $label] = Attachment::TYPES[$type];
        if ($name !== null) {
            $dot = mb_strrpos($name, '.');
            $given = $dot === false ? null : mb_strtolower(mb_substr($name, $dot + 1));
            if ($given === null || (self::EXTENSIONS[$given] ?? null) !== $type) {
                $claimed = $given !== null && isset(self::EXTENSIONS[$given]) ? Attachment::TYPES[self::EXTENSIONS[$given]][2] : null;

                return $claimed
                    ? "{$name} is a {$label} image, not a {$claimed}. Rename it or choose the right file."
                    : "{$name} is a {$label} image, but its name does not end in .{$ext}. Rename it or choose the right file.";
            }
        }
        // getimagesize is read before any other use of the image.
        $info = @getimagesize($path);
        if (! is_array($info) || ! $info[0] || ! $info[1]) {
            return self::WRONG_TYPE;
        }
        [$w, $h] = [(int) $info[0], (int) $info[1]];
        if ($w < self::MIN_PX || $h < self::MIN_PX || $w > self::MAX_PX || $h > self::MAX_PX) {
            return self::PIXELS;
        }
        $out = ['type' => $type, 'mime' => $mime, 'ext' => $ext, 'size' => $size, 'width' => $w, 'height' => $h];

        return null;
    }

    private static function basename(string $name): string
    {
        $parts = preg_split('#[/\\\\]#', mb_scrub($name, 'UTF-8'));

        return (string) end($parts);
    }

    /** @throws ValidationException */
    private static function fail(string $message): never
    {
        throw ValidationException::withMessages(['file' => $message]);
    }
}
