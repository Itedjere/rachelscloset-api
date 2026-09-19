<?php

namespace App\Support;

/**
 * The real ceiling on an upload is PHP's, not the validation rule's.
 *
 * `upload_max_filesize` and `post_max_size` are PHP_INI_PERDIR, so they cannot
 * be raised from application code -- exceed either and PHP discards the file
 * before Laravel sees it, which surfaces as a bare "failed to upload". Deriving
 * the validation limit from the actual ini values keeps the rule and the error
 * message honest on any machine, including whatever the shared host allows.
 */
class UploadLimits
{
    /** The largest upload PHP will actually accept, in kilobytes. */
    public static function maxKilobytes(): int
    {
        $uploadMax = self::toBytes(ini_get('upload_max_filesize'));
        $postMax = self::toBytes(ini_get('post_max_size'));

        // post_max_size covers the whole request body, so it binds too. A value
        // of 0 means unlimited and should not win the min().
        $limits = array_filter([$uploadMax, $postMax], fn (int $bytes) => $bytes > 0);

        $bytes = $limits === [] ? 10 * 1024 * 1024 : min($limits);

        return (int) floor($bytes / 1024);
    }

    public static function maxMegabytesLabel(): string
    {
        $megabytes = self::maxKilobytes() / 1024;

        return rtrim(rtrim(number_format($megabytes, 1, '.', ''), '0'), '.').'MB';
    }

    /** Parses a shorthand ini value such as "2M" or "512K" into bytes. */
    private static function toBytes(string|false $value): int
    {
        $value = trim((string) $value);

        if ($value === '') {
            return 0;
        }

        $number = (int) $value;
        $suffix = strtolower(substr($value, -1));

        return match ($suffix) {
            'g' => $number * 1024 * 1024 * 1024,
            'm' => $number * 1024 * 1024,
            'k' => $number * 1024,
            default => $number,
        };
    }
}
