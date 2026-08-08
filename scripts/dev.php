<?php

/**
 * Cross-platform local development launcher.
 *
 * Windows cannot run Laravel Horizon because Horizon depends on pcntl/posix,
 * so local Windows development uses queue:work instead. Linux/macOS keep
 * Horizon for parity with production-like queue supervision.
 */

function fail(string $message): never
{
    fwrite(STDERR, "\n[dev] ERROR: {$message}\n\n");
    exit(1);
}

function commandOutput(string $command): string
{
    $output = [];
    $code = 0;
    exec($command.' 2>&1', $output, $code);

    return $code === 0 ? trim(implode("\n", $output)) : '';
}

function supportedNodeVersion(string $version): bool
{
    $version = ltrim(trim($version), 'vV');
    if ($version === '') {
        return false;
    }

    // Vite 8: ^20.19.0 || >=22.12.0
    return (version_compare($version, '20.19.0', '>=') && version_compare($version, '21.0.0', '<'))
        || version_compare($version, '22.12.0', '>=');
}

if (! file_exists(__DIR__.'/../artisan')) {
    fail('Run this command from the project root.');
}

if (! file_exists(__DIR__.'/../.env')) {
    fail('Missing .env file. Copy .env.example to .env first.');
}

if (! file_exists(__DIR__.'/../vendor/autoload.php')) {
    fail('PHP dependencies are missing. Run: composer install');
}

$nodeVersion = commandOutput('node --version');
if ($nodeVersion === '') {
    fail('Node.js was not found in PATH. Install Node.js 20.19+ or 22.12+.');
}

if (! supportedNodeVersion($nodeVersion)) {
    fail("Unsupported Node.js {$nodeVersion}. Vite 8 requires Node.js 20.19+ (20.x) or 22.12+. Update Node.js, reopen the terminal, then run composer dev again.");
}

$npmVersion = commandOutput('npm --version');
if ($npmVersion === '') {
    fail('npm was not found in PATH. Reinstall/repair Node.js.');
}

if (! is_dir(__DIR__.'/../node_modules')) {
    fail('Frontend dependencies are missing. Run: npm install');
}

$isWindows = PHP_OS_FAMILY === 'Windows';
$queueCommand = $isWindows
    ? 'php artisan queue:work --tries=1 --timeout=0'
    : 'php artisan horizon';
$queueName = $isWindows ? 'queue' : 'horizon';

fwrite(STDOUT, sprintf(
    "\n[dev] Starting WhatsFlow local stack on %s\n[dev] Node %s | npm %s | queue: %s\n\n",
    PHP_OS_FAMILY,
    $nodeVersion,
    $npmVersion,
    $queueName,
));

$commands = [
    'php artisan serve',
    $queueCommand,
    'php artisan schedule:work',
    'php artisan pail --timeout=0',
    'npm run dev',
    'php artisan reverb:start',
];

$names = ['server', $queueName, 'scheduler', 'logs', 'vite', 'reverb'];
$colors = ['#93c5fd', '#c4b5fd', '#fb7185', '#fdba74', '#6ee7b7', '#67e8f9'];

$quotedCommands = array_map(
    static fn (string $command): string => '"'.str_replace('"', '\\"', $command).'"',
    $commands,
);

$command = sprintf(
    'npx concurrently -c "%s" %s --names=%s --kill-others-on-fail',
    implode(',', $colors),
    implode(' ', $quotedCommands),
    implode(',', $names),
);

passthru($command, $exitCode);
exit($exitCode);
