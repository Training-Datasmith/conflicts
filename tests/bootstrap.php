<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

define('REPO_ROOT', dirname(__DIR__));

/**
 * @return array{exitCode: int, stdout: string, stderr: string}
 */
function runGeneratorScript(string $repoRoot, array $args = array(), ?string $scratchCwd = null): array
{
    $script = $repoRoot . '/bin/generate-local-repository.php';
    if (!is_file($script)) {
        throw new RuntimeException('Generator script not found at ' . $script);
    }

    $command = array_merge(
        array(
            PHP_BINARY,
            '-d',
            'error_reporting=-1',
            '-d',
            'display_errors=stderr',
            '-d',
            'log_errors=0',
            $script,
        ),
        $args
    );

    $cwd = $scratchCwd !== null ? $scratchCwd : sys_get_temp_dir();
    $descriptors = array(
        0 => array('pipe', 'r'),
        1 => array('pipe', 'w'),
        2 => array('pipe', 'w'),
    );

    $process = proc_open($command, $descriptors, $pipes, $cwd);
    if (!is_resource($process)) {
        throw new RuntimeException('Failed to start generator process');
    }

    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    $exitCode = proc_close($process);

    return array(
        'exitCode' => $exitCode,
        'stdout' => $stdout !== false ? $stdout : '',
        'stderr' => $stderr !== false ? $stderr : '',
    );
}

/**
 * @return array{repoRoot: string, scratchCwd: string, binScript: string}
 */
function createTempRepo(): array
{
    $base = sys_get_temp_dir() . '/conflicts-test-' . bin2hex(random_bytes(8));
    $repoRoot = $base . '/repo';
    $scratchCwd = $base . '/scratch';
    $binDir = $repoRoot . '/bin';

    if (!mkdir($binDir, 0777, true) && !is_dir($binDir)) {
        throw new RuntimeException('Failed to create temp bin directory');
    }
    if (!mkdir($scratchCwd, 0777, true) && !is_dir($scratchCwd)) {
        throw new RuntimeException('Failed to create scratch cwd');
    }

    $sourceScript = REPO_ROOT . '/bin/generate-local-repository.php';
    $binScript = $binDir . '/generate-local-repository.php';
    if (!copy($sourceScript, $binScript)) {
        throw new RuntimeException('Failed to copy generator script');
    }

    return array(
        'repoRoot' => $repoRoot,
        'scratchCwd' => $scratchCwd,
        'binScript' => $binScript,
    );
}

function removeTempTree(string $base): void
{
    if (!is_dir($base)) {
        return;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($base, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );

    foreach ($iterator as $item) {
        if ($item->isDir()) {
            rmdir($item->getPathname());
        } else {
            unlink($item->getPathname());
        }
    }

    rmdir($base);
}

function tempRepoBaseFromRepoRoot(string $repoRoot): string
{
    return dirname($repoRoot);
}

/**
 * @return list<string>
 */
function findPackagesJsonFiles(string $directory): array
{
    $found = array();
    if (!is_dir($directory)) {
        return $found;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS)
    );

    foreach ($iterator as $file) {
        if ($file->isFile() && $file->getFilename() === 'packages.json') {
            $found[] = $file->getPathname();
        }
    }

    return $found;
}
