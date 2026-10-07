#!/usr/bin/env php
<?php

declare(strict_types=1);

$repoRoot = dirname(__DIR__);
$defaultOutputDir = $repoRoot . '/build/local-repository';

$options = getopt('', ['output:']);
if ($options === false) {
    printUsage();
    exit(1);
}

$outputArgument = null;

if (array_key_exists('output', $options)) {
    $outputArgument = $options['output'];
    if ($outputArgument === false || $outputArgument === '') {
        printUsage();
        exit(1);
    }
} elseif (isset($argv[1])) {
    if ($argv[1] === '' || $argv[1][0] === '-') {
        printUsage();
        exit(1);
    }
    $outputArgument = $argv[1];
}

$outputDir = $outputArgument !== null
    ? normalizePath($outputArgument, $repoRoot)
    : $defaultOutputDir;

$packages = buildPackageMatrix($repoRoot);
if ($packages === []) {
    fwrite(STDERR, "No composer.*.json files found.\n");
    exit(1);
}

ksort($packages);
foreach ($packages as &$versions) {
    uksort($versions, static fn (string $a, string $b): int => version_compare($b, $a));
}
unset($versions);

if (!is_dir($outputDir) && !mkdir($outputDir, 0777, true) && !is_dir($outputDir)) {
    fwrite(STDERR, sprintf("Failed to create output directory '%s'.\n", $outputDir));
    exit(1);
}

$outputFile = rtrim($outputDir, DIRECTORY_SEPARATOR) . '/packages.json';
$json = json_encode(
    ['packages' => $packages],
    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
);

if (file_put_contents($outputFile, $json . PHP_EOL) === false) {
    fwrite(STDERR, sprintf("Failed to write '%s'.\n", $outputFile));
    exit(1);
}

$versionCount = array_reduce(
    $packages,
    static fn (int $carry, array $versions): int => $carry + count($versions),
    0
);

fwrite(
    STDOUT,
    sprintf(
        "Generated %d package version(s) for %d package(s) in %s\n",
        $versionCount,
        count($packages),
        $outputFile
    )
);

function printUsage(): void
{
    fwrite(STDERR, "Usage: generate-local-repository.php [--output=<dir>|--output <dir>|<dir>]\n");
}

/**
 * @return array<string, array<string, array<string, mixed>>>
 */
function buildPackageMatrix(string $repoRoot): array
{
    $matrix = [];
    $files = glob($repoRoot . '/composer.*.json');
    if ($files === false) {
        return [];
    }

    sort($files, SORT_NATURAL);

    foreach ($files as $file) {
        $basename = basename($file);
        if ($basename === 'composer.json') {
            continue;
        }

        if (!preg_match('/^composer\.(.+)\.json$/', $basename, $matches)) {
            continue;
        }

        $version = $matches[1];
        $contents = file_get_contents($file);

        if ($contents === false) {
            fwrite(STDERR, sprintf("Unable to read %s, skipping.\n", $basename));
            continue;
        }

        try {
            $packageData = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            fwrite(STDERR, sprintf("Invalid JSON in %s: %s\n", $basename, $e->getMessage()));
            exit(1);
        }

        if (!is_array($packageData)) {
            fwrite(STDERR, sprintf("Missing 'name' in %s, skipping.\n", $basename));
            continue;
        }

        $name = $packageData['name'] ?? null;

        if (!is_string($name) || $name === '') {
            fwrite(STDERR, sprintf("Missing 'name' in %s, skipping.\n", $basename));
            continue;
        }

        $packageData['version'] = $version;
        $packageData['type'] ??= 'metapackage';

        $matrix[$name][$version] = $packageData;
    }

    return $matrix;
}

function normalizePath(string $path, string $repoRoot): string
{
    if (isAbsolutePath($path)) {
        return $path;
    }

    return $repoRoot . '/' . ltrim($path, DIRECTORY_SEPARATOR);
}

function isAbsolutePath(string $path): bool
{
    if ($path === '') {
        return false;
    }

    if ($path[0] === '/' || $path[0] === '\\') {
        return true;
    }

    return (bool) preg_match('/^[A-Z]:[\\\\\\/]/i', $path);
}
