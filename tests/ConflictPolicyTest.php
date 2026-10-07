<?php

declare(strict_types=1);

use Composer\Semver\Semver;
use Composer\Semver\VersionParser;
use PHPUnit\Framework\TestCase;

final class ConflictPolicyTest extends TestCase
{
    public function testVersionedManifestStructure(): void
    {
        $parser = new VersionParser();
        $files = glob(REPO_ROOT . '/composer.*.json');
        $this->assertNotFalse($files);
        $this->assertNotEmpty($files);

        foreach ($files as $file) {
            $basename = basename($file);
            if (!preg_match('/^composer\.(.+)\.json$/', $basename, $matches)) {
                continue;
            }

            $fileVersion = $matches[1];
            $contents = file_get_contents($file);
            $this->assertNotFalse($contents, $basename);

            $data = json_decode($contents, true);
            $this->assertIsArray($data, $basename);
            $this->assertSame('shopware/conflicts', $data['name'] ?? null, $basename);
            $this->assertArrayHasKey('type', $data, $basename);
            $this->assertSame('metapackage', $data['type'], $basename);
            $this->assertArrayHasKey('require', $data, $basename);
            $this->assertIsArray($data['require'], $basename);
            $this->assertArrayHasKey('shopware/core', $data['require'], $basename);

            $this->assertFilenameVersionNormalizes($parser, $fileVersion, $basename);

            if (array_key_exists('version', $data)) {
                $this->assertSame($fileVersion, $data['version'], $basename);
            }

            foreach ($data['require'] as $package => $constraint) {
                $this->parseConstraint($parser, (string) $constraint, $basename . ' require ' . $package);
            }

            if (isset($data['conflict']) && is_array($data['conflict'])) {
                foreach ($data['conflict'] as $package => $constraint) {
                    $this->parseConstraint($parser, (string) $constraint, $basename . ' conflict ' . $package);
                }
            }
        }
    }

    public function testRootAndVersionedManifestsConflictWithSymfonySymfony(): void
    {
        $this->assertManifestConflictsWithSymfony(REPO_ROOT . '/composer.json');

        foreach (glob(REPO_ROOT . '/composer.*.json') ?: array() as $file) {
            if (basename($file) === 'composer.json') {
                continue;
            }
            $this->assertManifestConflictsWithSymfony($file);
        }
    }

    public function testUsagesCrossCheck(): void
    {
        $manifests = $this->loadVersionedManifests();
        $rows = $this->parseUsagesRows();
        $this->assertNotEmpty($rows);

        $failures = array();
        foreach ($rows as $row) {
            $shopwareVersion = $row['shopware'];
            $conflictsConstraint = $row['conflicts'];

            $matchingManifestVersions = array();
            foreach ($manifests as $version => $manifest) {
                if (Semver::satisfies($version, $conflictsConstraint)) {
                    $matchingManifestVersions[] = $version;
                }
            }

            if ($matchingManifestVersions === array()) {
                $failures[] = sprintf(
                    '%s requires conflicts %s but no manifest version satisfies that constraint',
                    $shopwareVersion,
                    $conflictsConstraint
                );
                continue;
            }

            $supported = false;
            foreach ($matchingManifestVersions as $manifestVersion) {
                if ($this->shopwareVersionSupportedByManifest($shopwareVersion, $manifests[$manifestVersion])) {
                    $supported = true;
                    break;
                }
            }

            if (!$supported) {
                $failures[] = sprintf(
                    '%s requires conflicts %s but no matching manifest accepts that Shopware version',
                    $shopwareVersion,
                    $conflictsConstraint
                );
            }
        }

        $this->assertSame(array(), $failures, "USAGES.md cross-check failures:\n" . implode("\n", $failures));
    }

    public function testK8sMetaConstraintAllowsSupportedVersion(): void
    {
        $files = array_merge(
            array(REPO_ROOT . '/composer.json'),
            glob(REPO_ROOT . '/composer.*.json') ?: array()
        );

        foreach ($files as $file) {
            $data = json_decode((string) file_get_contents($file), true);
            if (!is_array($data) || !isset($data['conflict']['shopware/k8s-meta'])) {
                continue;
            }

            $constraint = (string) $data['conflict']['shopware/k8s-meta'];
            $label = basename($file);
            $this->assertFalse(
                Semver::satisfies('1.0.4', $constraint),
                $label . ' should allow shopware/k8s-meta 1.0.4 (not covered by conflict)'
            );
            $this->assertTrue(
                Semver::satisfies('1.0.3', $constraint),
                $label . ' should block shopware/k8s-meta 1.0.3'
            );
        }
    }

    private function assertManifestConflictsWithSymfony(string $file): void
    {
        $data = json_decode((string) file_get_contents($file), true);
        $this->assertIsArray($data, $file);
        $this->assertArrayHasKey('conflict', $data, $file);
        $this->assertArrayHasKey('symfony/symfony', $data['conflict'], $file);
        $this->assertSame('*', $data['conflict']['symfony/symfony'], $file);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function loadVersionedManifests(): array
    {
        $manifests = array();
        foreach (glob(REPO_ROOT . '/composer.*.json') ?: array() as $file) {
            if (!preg_match('/^composer\.(.+)\.json$/', basename($file), $matches)) {
                continue;
            }
            $data = json_decode((string) file_get_contents($file), true);
            $this->assertIsArray($data, $file);
            $manifests[$matches[1]] = $data;
        }

        return $manifests;
    }

    /**
     * @return list<array{shopware: string, conflicts: string}>
     */
    private function parseUsagesRows(): array
    {
        $content = (string) file_get_contents(REPO_ROOT . '/USAGES.md');
        $rows = array();
        foreach (explode("\n", $content) as $line) {
            if (preg_match('/^\|\s*([^|]+?)\s*\|\s*([^|]+?)\s*\|$/', $line, $matches)) {
                $shopware = trim($matches[1]);
                $conflicts = trim($matches[2]);
                if ($shopware === 'Shopware Version' || $shopware === '-----------------') {
                    continue;
                }
                $rows[] = array(
                    'shopware' => $shopware,
                    'conflicts' => $conflicts,
                );
            }
        }

        return $rows;
    }

    /**
     * @param array<string, mixed> $manifest
     */
    private function shopwareVersionSupportedByManifest(string $shopwareVersion, array $manifest): bool
    {
        $require = $manifest['require']['shopware/core'] ?? null;
        if (!is_string($require)) {
            return false;
        }

        if (!Semver::satisfies($shopwareVersion, $require)) {
            return false;
        }

        if (isset($manifest['conflict']['shopware/core'])) {
            $conflict = (string) $manifest['conflict']['shopware/core'];
            if (Semver::satisfies($shopwareVersion, $conflict)) {
                return false;
            }
        }

        return true;
    }

    private function parseConstraint(VersionParser $parser, string $constraint, string $label): void
    {
        try {
            $parser->parseConstraints($constraint);
        } catch (\Exception $e) {
            $this->fail('Invalid constraint for ' . $label . ': ' . $e->getMessage());
        }
    }

    private function assertFilenameVersionNormalizes(VersionParser $parser, string $fileVersion, string $basename): void
    {
        try {
            $parser->normalize($fileVersion);
        } catch (\Exception $e) {
            $this->fail('Filename version does not normalize for ' . $basename . ': ' . $e->getMessage());
        }
    }
}
