<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class GenerateLocalRepositoryTest extends TestCase
{
    /** @var list<string> */
    private $tempBases = array();

    protected function tearDown(): void
    {
        foreach ($this->tempBases as $base) {
            removeTempTree($base);
        }
        $this->tempBases = array();

        parent::tearDown();
    }

    public function testRealRepositoryMatrix(): void
    {
        $outputDir = sys_get_temp_dir() . '/conflicts-out-' . bin2hex(random_bytes(6));
        $this->tempBases[] = $outputDir;

        $result = runGeneratorScript(REPO_ROOT, array('--output=' . $outputDir));
        $this->assertSame(0, $result['exitCode'], $result['stderr']);
        $this->assertSame('', $result['stderr']);

        $outputFile = $outputDir . '/packages.json';
        $this->assertFileExists($outputFile);

        $expectedVersions = $this->expectedVersionKeysFromRepo();
        $this->assertNotEmpty($expectedVersions);

        $decoded = json_decode((string) file_get_contents($outputFile), true);
        $this->assertIsArray($decoded);
        $this->assertArrayHasKey('packages', $decoded);
        $this->assertArrayHasKey('shopware/conflicts', $decoded['packages']);

        $versions = array_keys($decoded['packages']['shopware/conflicts']);
        $this->assertSame($expectedVersions, $versions);

        $sorted = $expectedVersions;
        usort($sorted, static function (string $a, string $b): int {
            return version_compare($b, $a);
        });
        $this->assertSame($sorted, $versions);

        $versionCount = count($versions);
        $this->assertStringContainsString(
            sprintf('Generated %d package version(s) for 1 package(s) in %s', $versionCount, $outputFile),
            $result['stdout']
        );

        $raw = (string) file_get_contents($outputFile);
        $this->assertStringEndsWith("\n", $raw);
        $this->assertStringNotContainsString('\\/', $raw);

        foreach ($versions as $version) {
            $sourceFile = REPO_ROOT . '/composer.' . $version . '.json';
            $source = json_decode((string) file_get_contents($sourceFile), true);
            $this->assertIsArray($source);

            $entry = $decoded['packages']['shopware/conflicts'][$version];
            $this->assertSame('shopware/conflicts', $entry['name']);
            $this->assertSame($version, $entry['version']);
            $this->assertSame('metapackage', $entry['type']);
            $this->assertSame($source['require'] ?? null, $entry['require'] ?? null);
            $this->assertSame($source['conflict'] ?? null, $entry['conflict'] ?? null);
        }
    }

    public function testDefaultOutputPath(): void
    {
        $temp = createTempRepo();
        $this->tempBases[] = tempRepoBaseFromRepoRoot($temp['repoRoot']);
        $this->writeMinimalVersionFile($temp['repoRoot']);

        $result = runGeneratorScript($temp['repoRoot'], array(), $temp['scratchCwd']);
        $this->assertSame(0, $result['exitCode'], $result['stderr']);
        $this->assertSame('', $result['stderr']);
        $this->assertFileExists($temp['repoRoot'] . '/build/local-repository/packages.json');
        $this->assertFileDoesNotExist($temp['repoRoot'] . '/packages.json');
    }

    public function testPositionalRelativeOutput(): void
    {
        $temp = createTempRepo();
        $this->tempBases[] = tempRepoBaseFromRepoRoot($temp['repoRoot']);
        $this->writeMinimalVersionFile($temp['repoRoot']);

        $result = runGeneratorScript($temp['repoRoot'], array('out/repo'), $temp['scratchCwd']);
        $this->assertSame(0, $result['exitCode'], $result['stderr']);
        $this->assertSame('', $result['stderr']);
        $this->assertFileExists($temp['repoRoot'] . '/out/repo/packages.json');
    }

    public function testLongOptionEqualsOutput(): void
    {
        $temp = createTempRepo();
        $this->tempBases[] = tempRepoBaseFromRepoRoot($temp['repoRoot']);
        $this->writeMinimalVersionFile($temp['repoRoot']);

        $result = runGeneratorScript($temp['repoRoot'], array('--output=out/repo'), $temp['scratchCwd']);
        $this->assertSame(0, $result['exitCode'], $result['stderr']);
        $this->assertSame('', $result['stderr']);
        $this->assertFileExists($temp['repoRoot'] . '/out/repo/packages.json');
    }

    public function testLongOptionSpaceSeparatedOutput(): void
    {
        $temp = createTempRepo();
        $this->tempBases[] = tempRepoBaseFromRepoRoot($temp['repoRoot']);
        $this->writeMinimalVersionFile($temp['repoRoot']);

        $result = runGeneratorScript($temp['repoRoot'], array('--output', 'out/repo'), $temp['scratchCwd']);
        $this->assertSame(0, $result['exitCode'], $result['stderr']);
        $this->assertSame('', $result['stderr']);
        $this->assertFileExists($temp['repoRoot'] . '/out/repo/packages.json');
    }

    public function testMissingOutputValueExitsCleanly(): void
    {
        $temp = createTempRepo();
        $this->tempBases[] = tempRepoBaseFromRepoRoot($temp['repoRoot']);

        $result = runGeneratorScript($temp['repoRoot'], array('--output'), $temp['scratchCwd']);
        $this->assertSame(1, $result['exitCode']);
        $this->assertStringContainsString('Usage:', $result['stderr']);
        $this->assertStringNotContainsString('TypeError', $result['stderr']);
        $this->assertEmpty(findPackagesJsonFiles($temp['repoRoot']));
    }

    public function testEmptyOutputValueExitsCleanly(): void
    {
        $temp = createTempRepo();
        $this->tempBases[] = tempRepoBaseFromRepoRoot($temp['repoRoot']);
        $this->writeMinimalVersionFile($temp['repoRoot']);

        $result = runGeneratorScript($temp['repoRoot'], array('--output='), $temp['scratchCwd']);
        $this->assertSame(1, $result['exitCode']);
        $this->assertStringContainsString('Usage:', $result['stderr']);
        $this->assertEmpty(findPackagesJsonFiles($temp['repoRoot']));
    }

    public function testHelpFlagExitsCleanly(): void
    {
        $temp = createTempRepo();
        $this->tempBases[] = tempRepoBaseFromRepoRoot($temp['repoRoot']);
        $this->writeMinimalVersionFile($temp['repoRoot']);

        $result = runGeneratorScript($temp['repoRoot'], array('--help'), $temp['scratchCwd']);
        $this->assertSame(1, $result['exitCode']);
        $this->assertStringContainsString('Usage:', $result['stderr']);
        $this->assertEmpty(findPackagesJsonFiles($temp['repoRoot']));
        $this->assertFalse($this->hasDashPrefixedOutputDirectory($temp['repoRoot']));
    }

    public function testAbsoluteOutputIsNotPrefixedWithRepoRoot(): void
    {
        $outputDir = sys_get_temp_dir() . '/conflicts-abs-' . bin2hex(random_bytes(6));
        $this->tempBases[] = $outputDir;

        $result = runGeneratorScript(REPO_ROOT, array('--output=' . $outputDir));
        $this->assertSame(0, $result['exitCode'], $result['stderr']);
        $this->assertSame('', $result['stderr']);
        $this->assertFileExists($outputDir . '/packages.json');
        $this->assertStringContainsString($outputDir, $result['stdout']);
    }

    public function testNoVersionFiles(): void
    {
        $temp = createTempRepo();
        $this->tempBases[] = tempRepoBaseFromRepoRoot($temp['repoRoot']);

        $result = runGeneratorScript($temp['repoRoot'], array(), $temp['scratchCwd']);
        $this->assertSame(1, $result['exitCode']);
        $this->assertSame("No composer.*.json files found.\n", $result['stderr']);
        $this->assertEmpty(findPackagesJsonFiles($temp['repoRoot']));
    }

    public function testRootComposerJsonIsIgnored(): void
    {
        $temp = createTempRepo();
        $this->tempBases[] = tempRepoBaseFromRepoRoot($temp['repoRoot']);

        file_put_contents(
            $temp['repoRoot'] . '/composer.json',
            json_encode(
                array(
                    'name' => 'shopware/conflicts',
                    'conflict' => array('example/only-root' => '*'),
                )
            )
        );
        $this->writeMinimalVersionFile($temp['repoRoot']);

        $result = runGeneratorScript($temp['repoRoot'], array(), $temp['scratchCwd']);
        $this->assertSame(0, $result['exitCode'], $result['stderr']);
        $this->assertSame('', $result['stderr']);

        $decoded = json_decode(
            (string) file_get_contents($temp['repoRoot'] . '/build/local-repository/packages.json'),
            true
        );
        $entry = $decoded['packages']['shopware/conflicts']['9.9.9'];
        $this->assertArrayNotHasKey('example/only-root', $entry['conflict'] ?? array());
    }

    public function testFilenameVersionOverridesEmbeddedVersion(): void
    {
        $temp = createTempRepo();
        $this->tempBases[] = tempRepoBaseFromRepoRoot($temp['repoRoot']);

        file_put_contents(
            $temp['repoRoot'] . '/composer.1.2.3.json',
            json_encode(
                array(
                    'name' => 'shopware/conflicts',
                    'version' => '9.9.9',
                    'require' => array('shopware/core' => '>=1.0.0'),
                )
            )
        );

        $result = runGeneratorScript($temp['repoRoot'], array(), $temp['scratchCwd']);
        $this->assertSame(0, $result['exitCode'], $result['stderr']);
        $this->assertSame('', $result['stderr']);

        $decoded = json_decode(
            (string) file_get_contents($temp['repoRoot'] . '/build/local-repository/packages.json'),
            true
        );
        $this->assertSame('1.2.3', $decoded['packages']['shopware/conflicts']['1.2.3']['version']);
    }

    public function testMissingTypeDefaultsToMetapackage(): void
    {
        $temp = createTempRepo();
        $this->tempBases[] = tempRepoBaseFromRepoRoot($temp['repoRoot']);

        file_put_contents(
            $temp['repoRoot'] . '/composer.2.0.0.json',
            json_encode(
                array(
                    'name' => 'shopware/conflicts',
                    'require' => array('shopware/core' => '>=1.0.0'),
                )
            )
        );

        $result = runGeneratorScript($temp['repoRoot'], array(), $temp['scratchCwd']);
        $this->assertSame(0, $result['exitCode'], $result['stderr']);
        $this->assertSame('', $result['stderr']);

        $decoded = json_decode(
            (string) file_get_contents($temp['repoRoot'] . '/build/local-repository/packages.json'),
            true
        );
        $this->assertSame('metapackage', $decoded['packages']['shopware/conflicts']['2.0.0']['type']);
    }

    public function testExplicitTypeIsPreserved(): void
    {
        $temp = createTempRepo();
        $this->tempBases[] = tempRepoBaseFromRepoRoot($temp['repoRoot']);

        file_put_contents(
            $temp['repoRoot'] . '/composer.2.0.0.json',
            json_encode(
                array(
                    'name' => 'shopware/conflicts',
                    'type' => 'library',
                    'require' => array('shopware/core' => '>=1.0.0'),
                )
            )
        );

        $result = runGeneratorScript($temp['repoRoot'], array(), $temp['scratchCwd']);
        $this->assertSame(0, $result['exitCode'], $result['stderr']);
        $this->assertSame('', $result['stderr']);

        $decoded = json_decode(
            (string) file_get_contents($temp['repoRoot'] . '/build/local-repository/packages.json'),
            true
        );
        $this->assertSame('library', $decoded['packages']['shopware/conflicts']['2.0.0']['type']);
    }

    public function testMissingNameIsSkipped(): void
    {
        $temp = createTempRepo();
        $this->tempBases[] = tempRepoBaseFromRepoRoot($temp['repoRoot']);

        file_put_contents($temp['repoRoot'] . '/composer.bad.json', '{"require":{"shopware/core":"*"}}');
        $this->writeMinimalVersionFile($temp['repoRoot'], '1.0.0');

        $result = runGeneratorScript($temp['repoRoot'], array(), $temp['scratchCwd']);
        $this->assertSame(0, $result['exitCode'], $result['stderr']);
        $this->assertStringContainsString("Missing 'name'", $result['stderr']);
        $this->assertStringContainsString('composer.bad.json', $result['stderr']);

        $decoded = json_decode(
            (string) file_get_contents($temp['repoRoot'] . '/build/local-repository/packages.json'),
            true
        );
        $this->assertCount(1, $decoded['packages']['shopware/conflicts']);
    }

    public function testEmptyNameIsSkipped(): void
    {
        $temp = createTempRepo();
        $this->tempBases[] = tempRepoBaseFromRepoRoot($temp['repoRoot']);

        file_put_contents($temp['repoRoot'] . '/composer.bad.json', '{"name":""}');
        $this->writeMinimalVersionFile($temp['repoRoot'], '1.0.0');

        $result = runGeneratorScript($temp['repoRoot'], array(), $temp['scratchCwd']);
        $this->assertSame(0, $result['exitCode'], $result['stderr']);
        $this->assertStringContainsString("Missing 'name'", $result['stderr']);
    }

    public function testInvalidJsonExitsWithError(): void
    {
        $temp = createTempRepo();
        $this->tempBases[] = tempRepoBaseFromRepoRoot($temp['repoRoot']);

        file_put_contents($temp['repoRoot'] . '/composer.broken.json', '{');
        $this->writeMinimalVersionFile($temp['repoRoot'], '1.0.0');

        $result = runGeneratorScript($temp['repoRoot'], array(), $temp['scratchCwd']);
        $this->assertSame(1, $result['exitCode']);
        $this->assertStringContainsString('Invalid JSON in composer.broken.json', $result['stderr']);
        $this->assertEmpty(findPackagesJsonFiles($temp['repoRoot']));
    }

    public function testEmptyJsonFileExitsWithError(): void
    {
        $temp = createTempRepo();
        $this->tempBases[] = tempRepoBaseFromRepoRoot($temp['repoRoot']);

        file_put_contents($temp['repoRoot'] . '/composer.empty.json', '');
        $this->writeMinimalVersionFile($temp['repoRoot'], '1.0.0');

        $result = runGeneratorScript($temp['repoRoot'], array(), $temp['scratchCwd']);
        $this->assertSame(1, $result['exitCode']);
        $this->assertStringContainsString('Invalid JSON in composer.empty.json', $result['stderr']);
    }

    public function testNonArrayJsonIsSkipped(): void
    {
        $temp = createTempRepo();
        $this->tempBases[] = tempRepoBaseFromRepoRoot($temp['repoRoot']);

        file_put_contents($temp['repoRoot'] . '/composer.null.json', 'null');
        file_put_contents($temp['repoRoot'] . '/composer.string.json', '"not-an-object"');
        $this->writeMinimalVersionFile($temp['repoRoot'], '1.0.0');

        $result = runGeneratorScript($temp['repoRoot'], array(), $temp['scratchCwd']);
        $this->assertSame(0, $result['exitCode'], $result['stderr']);
        $this->assertStringContainsString("Missing 'name'", $result['stderr']);

        $decoded = json_decode(
            (string) file_get_contents($temp['repoRoot'] . '/build/local-repository/packages.json'),
            true
        );
        $this->assertCount(1, $decoded['packages']['shopware/conflicts']);
    }

    public function testUnreadableFileIsSkipped(): void
    {
        $temp = createTempRepo();
        $this->tempBases[] = tempRepoBaseFromRepoRoot($temp['repoRoot']);

        symlink('/nonexistent/composer-missing.json', $temp['repoRoot'] . '/composer.9.9.9.json');
        $this->writeMinimalVersionFile($temp['repoRoot'], '1.0.0');

        $result = runGeneratorScript($temp['repoRoot'], array(), $temp['scratchCwd']);
        $this->assertSame(0, $result['exitCode'], $result['stderr']);
        $this->assertStringContainsString('Unable to read', $result['stderr']);
        $this->assertStringContainsString('composer.9.9.9.json', $result['stderr']);
    }

    public function testOutputPathExistsAsFile(): void
    {
        $temp = createTempRepo();
        $this->tempBases[] = tempRepoBaseFromRepoRoot($temp['repoRoot']);
        $this->writeMinimalVersionFile($temp['repoRoot']);

        $filePath = $temp['repoRoot'] . '/not-a-dir';
        file_put_contents($filePath, 'blocker');

        $result = runGeneratorScript($temp['repoRoot'], array('--output=' . $filePath), $temp['scratchCwd']);
        $this->assertSame(1, $result['exitCode']);
        $this->assertStringContainsString('Failed to create output directory', $result['stderr']);
        $this->assertStringNotContainsString('Generated', $result['stdout']);
    }

    public function testWriteFailureIsNotReportedAsSuccess(): void
    {
        $temp = createTempRepo();
        $this->tempBases[] = tempRepoBaseFromRepoRoot($temp['repoRoot']);
        $this->writeMinimalVersionFile($temp['repoRoot']);

        $outDir = $temp['repoRoot'] . '/blocked';
        mkdir($outDir, 0777, true);
        mkdir($outDir . '/packages.json', 0777, true);

        $result = runGeneratorScript($temp['repoRoot'], array('--output=' . $outDir), $temp['scratchCwd']);
        $this->assertSame(1, $result['exitCode']);
        $this->assertStringContainsString('Failed to write', $result['stderr']);
        $this->assertStringNotContainsString('Generated', $result['stdout']);
    }

    public function testVersionSortDescendingAcrossNumericBoundaries(): void
    {
        $temp = createTempRepo();
        $this->tempBases[] = tempRepoBaseFromRepoRoot($temp['repoRoot']);

        foreach (array('0.1.9', '0.1.10', '0.2.0') as $version) {
            file_put_contents(
                $temp['repoRoot'] . '/composer.' . $version . '.json',
                json_encode(
                    array(
                        'name' => 'shopware/conflicts',
                        'require' => array('shopware/core' => '>=1.0.0'),
                    )
                )
            );
        }

        $result = runGeneratorScript($temp['repoRoot'], array(), $temp['scratchCwd']);
        $this->assertSame(0, $result['exitCode'], $result['stderr']);
        $this->assertSame('', $result['stderr']);

        $decoded = json_decode(
            (string) file_get_contents($temp['repoRoot'] . '/build/local-repository/packages.json'),
            true
        );
        $this->assertSame(array('0.2.0', '0.1.10', '0.1.9'), array_keys($decoded['packages']['shopware/conflicts']));
    }

    public function testMultiplePackageNames(): void
    {
        $temp = createTempRepo();
        $this->tempBases[] = tempRepoBaseFromRepoRoot($temp['repoRoot']);

        file_put_contents(
            $temp['repoRoot'] . '/composer.1.0.0.json',
            json_encode(
                array(
                    'name' => 'shopware/conflicts',
                    'require' => array('shopware/core' => '>=1.0.0'),
                )
            )
        );
        file_put_contents(
            $temp['repoRoot'] . '/composer.2.0.0.json',
            json_encode(
                array(
                    'name' => 'other/package',
                    'require' => array('shopware/core' => '>=1.0.0'),
                )
            )
        );

        $result = runGeneratorScript($temp['repoRoot'], array(), $temp['scratchCwd']);
        $this->assertSame(0, $result['exitCode'], $result['stderr']);
        $this->assertSame('', $result['stderr']);
        $this->assertStringContainsString('Generated 2 package version(s) for 2 package(s)', $result['stdout']);

        $decoded = json_decode(
            (string) file_get_contents($temp['repoRoot'] . '/build/local-repository/packages.json'),
            true
        );
        $this->assertArrayHasKey('shopware/conflicts', $decoded['packages']);
        $this->assertArrayHasKey('other/package', $decoded['packages']);
    }

    /**
     * @return list<string>
     */
    private function expectedVersionKeysFromRepo(): array
    {
        $versions = array();
        foreach (glob(REPO_ROOT . '/composer.*.json') as $file) {
            $basename = basename($file);
            if ($basename === 'composer.json') {
                continue;
            }
            if (preg_match('/^composer\.(.+)\.json$/', $basename, $matches)) {
                $versions[] = $matches[1];
            }
        }

        usort($versions, static function (string $a, string $b): int {
            return version_compare($b, $a);
        });

        return $versions;
    }

    private function writeMinimalVersionFile(string $repoRoot, string $version = '9.9.9'): void
    {
        file_put_contents(
            $repoRoot . '/composer.' . $version . '.json',
            json_encode(
                array(
                    'name' => 'shopware/conflicts',
                    'require' => array('shopware/core' => '>=1.0.0'),
                )
            )
        );
    }

    private function hasDashPrefixedOutputDirectory(string $repoRoot): bool
    {
        foreach (scandir($repoRoot) ?: array() as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            if ($entry[0] === '-' && is_dir($repoRoot . '/' . $entry)) {
                return true;
            }
        }

        return false;
    }
}
