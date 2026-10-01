<?php

use App\Filesystem\ResilientFilesystem;
use ErrorException;

test('retries when the compiled view disappears during rename', function () {
    $directory = sys_get_temp_dir().'/gpsh-views-'.bin2hex(random_bytes(4));
    mkdir($directory);
    $path = $directory.'/compiled.php';

    $filesystem = new class extends ResilientFilesystem
    {
        public int $attempts = 0;

        protected function writeAtomically(string $path, string $content, ?int $mode): void
        {
            $this->attempts++;
            if ($this->attempts === 1) {
                throw new ErrorException('rename('.$path.'.tmp,'.$path.'): No such file or directory');
            }

            parent::writeAtomically($path, $content, $mode);
        }
    };

    try {
        $filesystem->replace($path, 'ok');

        expect($filesystem->attempts)->toBe(2)
            ->and(file_get_contents($path))->toBe('ok');
    } finally {
        @unlink($path);
        @rmdir($directory);
    }
});

test('does not hide other file write errors', function () {
    $filesystem = new class extends ResilientFilesystem
    {
        protected function writeAtomically(string $path, string $content, ?int $mode): void
        {
            throw new ErrorException('Permission denied');
        }
    };

    expect(fn () => $filesystem->replace(sys_get_temp_dir().'/gpsh-views-denied.php', 'ok'))
        ->toThrow(ErrorException::class, 'Permission denied');
});
