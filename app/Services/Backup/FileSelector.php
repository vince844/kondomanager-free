<?php

namespace App\Services\Backup;

use FilesystemIterator;
use RecursiveCallbackFilterIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Enumera i file da includere nel backup secondo config('backup.include') /
 * config('backup.exclude'), con percorsi relativi a base_path().
 *
 * I symlink non vengono seguiti (public/storage punta dentro storage/app:
 * seguirlo duplicherebbe i file). I dotfile (.env) sono inclusi.
 */
class FileSelector
{
    /**
     * @return array{files: array<int, array{0: string, 1: int}>, total_bytes: int}
     *                                                                              files: coppie [percorso relativo, dimensione in byte], ordinate.
     */
    public function collect(): array
    {
        $basePath = rtrim(base_path(), DIRECTORY_SEPARATOR);
        $excludedPrefixes = array_map(
            fn (string $path) => $basePath.DIRECTORY_SEPARATOR.trim($path, '/').DIRECTORY_SEPARATOR,
            config('backup.exclude', [])
        );

        $excludedFilenames = config('backup.exclude_filenames', []);

        $files = [];
        $totalBytes = 0;

        foreach (config('backup.include', []) as $relative) {
            $absolute = $basePath.DIRECTORY_SEPARATOR.trim($relative, '/');

            if (is_file($absolute)) {
                $size = (int) filesize($absolute);
                $files[] = [trim($relative, '/'), $size];
                $totalBytes += $size;

                continue;
            }

            if (! is_dir($absolute)) {
                continue;
            }

            $iterator = new RecursiveIteratorIterator(
                new RecursiveCallbackFilterIterator(
                    new RecursiveDirectoryIterator($absolute, FilesystemIterator::SKIP_DOTS),
                    function (SplFileInfo $file) use ($excludedPrefixes): bool {
                        if ($file->isLink()) {
                            return false;
                        }

                        $path = $file->getPathname().DIRECTORY_SEPARATOR;

                        foreach ($excludedPrefixes as $prefix) {
                            if (str_starts_with($path, $prefix)) {
                                return false;
                            }
                        }

                        return true;
                    }
                )
            );

            /** @var SplFileInfo $file */
            foreach ($iterator as $file) {
                if (! $file->isFile()) {
                    continue;
                }

                if (in_array($file->getFilename(), $excludedFilenames, true)) {
                    continue;
                }

                $size = (int) $file->getSize();
                $files[] = [substr($file->getPathname(), strlen($basePath) + 1), $size];
                $totalBytes += $size;
            }
        }

        usort($files, fn (array $a, array $b) => strcmp($a[0], $b[0]));

        return ['files' => $files, 'total_bytes' => $totalBytes];
    }
}
