<?php

$logPath = __DIR__.'/../storage/logs/laravel.log';
$logDirectory = dirname($logPath);

if (is_dir($logDirectory) === false) {
    mkdir($logDirectory, 0775, true);
}

if (file_exists($logPath) === false) {
    touch($logPath);
}

$handle = fopen($logPath, 'rb');

if ($handle === false) {
    fwrite(STDERR, "[logs] Unable to open {$logPath}\n");
    exit(1);
}

fseek($handle, 0, SEEK_END);
$position = ftell($handle) ?: 0;

fwrite(STDOUT, "[logs] Following storage/logs/laravel.log\n");

while (true) {
    clearstatcache(true, $logPath);
    $size = filesize($logPath);

    if ($size === false) {
        usleep(250000);

        continue;
    }

    if ($size < $position) {
        fseek($handle, 0, SEEK_SET);
        $position = 0;
    }

    if ($size > $position) {
        fseek($handle, $position, SEEK_SET);
        $chunk = fread($handle, $size - $position);

        if ($chunk !== false && $chunk !== '') {
            fwrite(STDOUT, $chunk);
            $position += strlen($chunk);
        }
    }

    usleep(250000);
}
