<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$failed = false;
foreach (['src', 'tests', 'tools'] as $directory) {
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $directory, FilesystemIterator::SKIP_DOTS));
    foreach ($files as $file) {
        if (!$file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }
        $output = [];
        exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($file->getPathname()) . ' 2>&1', $output, $status);
        if ($status !== 0) {
            fwrite(STDERR, implode(PHP_EOL, $output) . PHP_EOL);
            $failed = true;
        }
    }
}
if ($failed) {
    exit(1);
}
echo "PHP syntax checks passed.\n";
