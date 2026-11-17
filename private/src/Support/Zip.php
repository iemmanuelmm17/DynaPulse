<?php

declare(strict_types=1);

namespace DynaPulse\Support;

use PharData;
use RuntimeException;
use Throwable;
use ZipArchive;

/**
 * Reads zip archives without a hard dependency on ext-zip.
 *
 * XAMPP installs routinely ship without the `zip` extension (it is optional in
 * php.ini), which broke `console.php update` with "Class ZipArchive not found".
 * The order is: ext-zip, PharData (normally present), then a small pure-PHP
 * reader that only needs zlib.
 */
final class Zip
{
    /** @return int number of files written */
    public static function extract(string $zipPath, string $destination): int
    {
        if (!is_file($zipPath)) {
            throw new RuntimeException('No such archive: ' . $zipPath);
        }

        self::ensureDirectory($destination);

        if (class_exists(ZipArchive::class)) {
            $zip = new ZipArchive();

            if ($zip->open($zipPath) === true) {
                $zip->extractTo($destination);
                $zip->close();

                return self::countFiles($destination);
            }
        }

        if (class_exists(PharData::class)) {
            try {
                (new PharData($zipPath))->extractTo($destination, null, true);

                return self::countFiles($destination);
            } catch (Throwable) {
                // Phar cannot read every zip flavour; fall through.
            }
        }

        return self::extractManually($zipPath, $destination);
    }

    /**
     * Minimal zip reader (stored + deflate entries), used when neither ext-zip
     * nor Phar is available. Returns the number of files written.
     */
    public static function extractManually(string $zipPath, string $destination): int
    {
        $data = (string) @file_get_contents($zipPath);

        if ($data === '') {
            throw new RuntimeException('Cannot read ' . $zipPath);
        }

        $eocd = strrpos($data, "PK\x05\x06");

        if ($eocd === false) {
            throw new RuntimeException('Not a zip archive: ' . $zipPath);
        }

        $entries = (int) (unpack('v', substr($data, $eocd + 10, 2))[1] ?? 0);
        $offset = (int) (unpack('V', substr($data, $eocd + 16, 4))[1] ?? 0);
        $written = 0;

        self::ensureDirectory($destination);

        for ($i = 0; $i < $entries; $i++) {
            if (substr($data, $offset, 4) !== "PK\x01\x02") {
                break;
            }

            $header = unpack(
                'vversionMade/vversionNeeded/vflags/vmethod/vmtime/vmdate/Vcrc/Vcompressed/Vuncompressed'
                . '/vnameLen/vextraLen/vcommentLen/vdisk/vinternal/Vexternal/VlocalOffset',
                substr($data, $offset + 4, 42),
            );

            if ($header === false) {
                break;
            }

            $name = str_replace('\\', '/', substr($data, $offset + 46, (int) $header['nameLen']));
            $offset += 46 + (int) $header['nameLen'] + (int) $header['extraLen'] + (int) $header['commentLen'];

            $target = self::safePath($destination, $name);

            if ($target === null) {
                continue;
            }

            if (str_ends_with($name, '/')) {
                self::ensureDirectory($target);

                continue;
            }

            $local = (int) $header['localOffset'];

            if (substr($data, $local, 4) !== "PK\x03\x04") {
                continue;
            }

            $localHeader = unpack('vnameLen/vextraLen', substr($data, $local + 26, 4));

            if ($localHeader === false) {
                continue;
            }

            $start = $local + 30 + (int) $localHeader['nameLen'] + (int) $localHeader['extraLen'];
            $raw = substr($data, $start, (int) $header['compressed']);
            $method = (int) $header['method'];

            if ($method === 8) {
                $content = @gzinflate($raw);

                if ($content === false) {
                    throw new RuntimeException('Cannot inflate ' . $name . ' (corrupt archive?)');
                }
            } elseif ($method === 0) {
                $content = $raw;
            } else {
                throw new RuntimeException('Unsupported compression for ' . $name . ' (method ' . $method . ')');
            }

            self::ensureDirectory(dirname($target));

            if (@file_put_contents($target, $content) === false) {
                throw new RuntimeException('Cannot write ' . $target);
            }

            $written++;
        }

        if ($written === 0) {
            throw new RuntimeException('The archive contained no files.');
        }

        return $written;
    }

    private static function safePath(string $destination, string $name): ?string
    {
        $name = ltrim($name, '/');

        if ($name === '' || str_contains($name, "\0")) {
            return null;
        }

        foreach (explode('/', $name) as $segment) {
            if ($segment === '..') {
                return null;
            }
        }

        return rtrim($destination, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR
            . str_replace('/', DIRECTORY_SEPARATOR, $name);
    }

    private static function ensureDirectory(string $path): void
    {
        if ($path !== '' && !is_dir($path) && !@mkdir($path, 0775, true) && !is_dir($path)) {
            throw new RuntimeException('Cannot create ' . $path);
        }
    }

    private static function countFiles(string $directory): int
    {
        $count = 0;

        foreach (new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
        ) as $file) {
            if ($file->isFile()) {
                $count++;
            }
        }

        return $count;
    }
}
