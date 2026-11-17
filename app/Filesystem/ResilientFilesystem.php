<?php

namespace App\Filesystem;

use ErrorException;
use Illuminate\Filesystem\Filesystem;

class ResilientFilesystem extends Filesystem
{
    public function replace($path, $content, $mode = null)
    {
        $last = null;

        for ($attempt = 0; $attempt < 3; $attempt++) {
            try {
                $this->writeAtomically($path, $content, $mode);

                return;
            } catch (ErrorException $exception) {
                $last = $exception;
                if ($attempt === 2 || ! str_contains($exception->getMessage(), 'No such file or directory')) {
                    throw $exception;
                }
                $directory = dirname($path);
                if (! is_dir($directory)) {
                    @mkdir($directory, 0775, true);
                }
                usleep(20000);
            }
        }

        throw $last ?? new ErrorException('Unable to write file at path '.$path);
    }

    protected function writeAtomically(string $path, string $content, ?int $mode): void
    {
        parent::replace($path, $content, $mode);
    }
}
