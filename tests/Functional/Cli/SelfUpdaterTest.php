<?php

declare(strict_types=1);

/*
 * This file is part of the PHPRegex package.
 *
 * (c) Younes ENNAJI <younes.ennaji.pro@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace PHPRegex\Tests\Functional\Cli;

use PHPRegex\Cli\Exception\CliException;
use PHPRegex\Cli\Output;
use PHPRegex\Cli\SelfUpdate\HttpDownloader;
use PHPRegex\Cli\SelfUpdate\SelfUpdater;
use PHPRegex\Tests\Support\LocalReleaseDownloader;
use PHPRegex\Tests\Support\PhpUnitCompat\PhpUnitForwardCompat;
use PHPRegex\Tests\Support\SelfUpdateFunctionOverrides;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SelfUpdaterTest extends TestCase
{
    use PhpUnitForwardCompat;

    protected function tearDown(): void
    {
        SelfUpdateFunctionOverrides::reset();
    }

    #[Test]
    public function test_run_throws_when_not_running_from_phar(): void
    {
        $updater = new SelfUpdater();
        $output = new Output(false, true);

        $this->expectException(CliException::class);
        $this->expectExceptionMessageIsOrContains('Self-update is only supported for phar installs.');

        $updater->run($output);
    }

    #[Test]
    public function test_parse_checksum_accepts_valid_input_and_rejects_invalid(): void
    {
        $updater = new SelfUpdater();
        $method = new \ReflectionMethod(SelfUpdater::class, 'parseChecksum');

        $checksum = str_repeat('A', 64);
        $this->assertSame(strtolower($checksum), $method->invoke($updater, $checksum.'  file.phar'));

        $this->expectException(CliException::class);
        $this->expectExceptionMessageIsOrContains('Invalid checksum format.');
        $method->invoke($updater, 'invalid');
    }

    #[Test]
    public function test_download_and_fetch_remote_string_from_local_file(): void
    {
        $updater = new SelfUpdater();
        $fetch = new \ReflectionMethod(SelfUpdater::class, 'fetchRemoteString');

        $source = tempnam(sys_get_temp_dir(), 'regex-src-');
        if (false === $source) {
            $this->markTestSkipped('Unable to create temp file.');
        }

        $content = "payload\n";
        copy(__DIR__.'/../../Fixtures/Cli/payload.txt', $source);

        $destination = tempnam(sys_get_temp_dir(), 'regex-dest-');
        if (false === $destination) {
            @unlink($source);
            $this->markTestSkipped('Unable to create temp file.');
        }

        @unlink($destination);

        try {
            (new HttpDownloader())->download('file://'.$source, $destination);
            $this->assertSame($content, file_get_contents($destination));

            $fetched = $fetch->invoke($updater, 'file://'.$source);
            $this->assertSame($content, $fetched);
        } finally {
            @unlink($source);
            @unlink($destination);
        }
    }

    #[Test]
    public function test_validate_downloaded_phar_rejects_invalid_file(): void
    {
        $updater = new SelfUpdater();
        $method = new \ReflectionMethod(SelfUpdater::class, 'validateDownloadedPhar');

        $file = tempnam(sys_get_temp_dir(), 'regex-phar-');
        if (false === $file) {
            $this->markTestSkipped('Unable to create temp file.');
        }
        copy(__DIR__.'/../../Fixtures/Cli/not_a_phar.txt', $file);

        try {
            $this->expectException(CliException::class);
            $this->expectExceptionMessageIsOrContains('Downloaded phar is invalid');
            $method->invoke($updater, $file);
        } finally {
            @unlink($file);
        }
    }

    #[Test]
    public function test_validate_downloaded_phar_accepts_valid_phar(): void
    {
        if (\ini_get('phar.readonly')) {
            $this->markTestSkipped('Phar readonly is enabled.');
        }

        $this->expectNotToPerformAssertions();

        $updater = new SelfUpdater();
        $method = new \ReflectionMethod(SelfUpdater::class, 'validateDownloadedPhar');

        $tempBase = tempnam(sys_get_temp_dir(), 'regex-phar-');
        if (false === $tempBase) {
            $this->markTestSkipped('Unable to create temp file.');
        }

        @unlink($tempBase);
        $file = $tempBase.'.phar';

        copy(__DIR__.'/../../Fixtures/Cli/test.phar', $file);

        try {
            $method->invoke($updater, $file);
        } finally {
            @unlink($file);
        }
    }

    #[Test]
    public function test_download_file_with_invalid_url_throws_and_tries_exec(): void
    {
        $downloader = new HttpDownloader();

        $destination = tempnam(sys_get_temp_dir(), 'regex-dest-');
        if (false === $destination) {
            $this->markTestSkipped('Unable to create temp file.');
        }

        @unlink($destination);

        try {
            $this->expectException(CliException::class);
            $this->expectExceptionMessageIsOrContains('Unable to download invalid://url.');
            $downloader->download('invalid://url', $destination);
        } finally {
            @unlink($destination);
        }
    }

    #[Test]
    public function test_run_updates_phar_successfully(): void
    {
        if (\ini_get('phar.readonly')) {
            $this->markTestSkipped('Phar readonly is enabled.');
        }

        // Create a temp phar to update
        $tempBase = tempnam(sys_get_temp_dir(), 'original-phar-');
        if (false === $tempBase) {
            $this->markTestSkipped('Unable to create temp file.');
        }

        @unlink($tempBase);
        $originalPhar = $tempBase.'.phar';

        copy(__DIR__.'/../../Fixtures/Cli/original.phar', $originalPhar);

        // Create a new phar to download
        $tempBase2 = tempnam(sys_get_temp_dir(), 'new-phar-');
        if (false === $tempBase2) {
            @unlink($originalPhar);
            $this->markTestSkipped('Unable to create temp file.');
        }

        @unlink($tempBase2);
        $newPhar = $tempBase2.'.phar';

        copy(__DIR__.'/../../Fixtures/Cli/updated.phar', $newPhar);

        // Create checksum file
        $checksumFile = tempnam(sys_get_temp_dir(), 'checksum-');
        if (false === $checksumFile) {
            @unlink($originalPhar);
            @unlink($newPhar);
            $this->markTestSkipped('Unable to create temp file.');
        }

        copy(__DIR__.'/../../Fixtures/Cli/checksum.txt', $checksumFile);

        $output = new Output(false, true);

        $updater = self::updater($originalPhar, 'file://'.$newPhar, 'file://'.$checksumFile);

        try {
            $updater->run($output);

            // Check that the original phar now has the new content
            $updatedPhar = new \Phar($originalPhar);
            $content = $updatedPhar['test.php']->getContent();
            $this->assertSame('<?php echo "updated";', $content);
        } finally {
            @unlink($originalPhar);
            @unlink($newPhar);
            @unlink($checksumFile);
        }
    }

    #[Test]
    public function test_run_throws_on_checksum_mismatch(): void
    {
        if (\ini_get('phar.readonly')) {
            $this->markTestSkipped('Phar readonly is enabled.');
        }

        // Create a temp phar to update
        $tempBase = tempnam(sys_get_temp_dir(), 'original-phar-');
        if (false === $tempBase) {
            $this->markTestSkipped('Unable to create temp file.');
        }

        @unlink($tempBase);
        $originalPhar = $tempBase.'.phar';

        copy(__DIR__.'/../../Fixtures/Cli/original.phar', $originalPhar);

        // Create a new phar to download
        $tempBase2 = tempnam(sys_get_temp_dir(), 'new-phar-');
        if (false === $tempBase2) {
            @unlink($originalPhar);
            $this->markTestSkipped('Unable to create temp file.');
        }

        @unlink($tempBase2);
        $newPhar = $tempBase2.'.phar';

        copy(__DIR__.'/../../Fixtures/Cli/updated.phar', $newPhar);

        // Create wrong checksum file
        $checksumFile = tempnam(sys_get_temp_dir(), 'checksum-');
        if (false === $checksumFile) {
            @unlink($originalPhar);
            @unlink($newPhar);
            $this->markTestSkipped('Unable to create temp file.');
        }

        copy(__DIR__.'/../../Fixtures/Cli/wrong_checksum.txt', $checksumFile);

        $output = new Output(false, true);

        $updater = self::updater($originalPhar, 'file://'.$newPhar, 'file://'.$checksumFile);

        try {
            $this->expectException(CliException::class);
            $this->expectExceptionMessageIsOrContains('Checksum verification failed.');
            $updater->run($output);
        } finally {
            @unlink($originalPhar);
            @unlink($newPhar);
            @unlink($checksumFile);
        }
    }

    #[Test]
    public function test_run_throws_on_invalid_downloaded_phar(): void
    {
        if (\ini_get('phar.readonly')) {
            $this->markTestSkipped('Phar readonly is enabled.');
        }

        // Create a temp phar to update
        $tempBase = tempnam(sys_get_temp_dir(), 'original-phar-');
        if (false === $tempBase) {
            $this->markTestSkipped('Unable to create temp file.');
        }

        @unlink($tempBase);
        $originalPhar = $tempBase.'.phar';

        copy(__DIR__.'/../../Fixtures/Cli/original.phar', $originalPhar);

        // Create a invalid 'phar' to download
        $invalidPhar = tempnam(sys_get_temp_dir(), 'invalid-phar-');
        if (false === $invalidPhar) {
            @unlink($originalPhar);
            $this->markTestSkipped('Unable to create temp file.');
        }

        copy(__DIR__.'/../../Fixtures/Cli/not_a_phar.txt', $invalidPhar);

        $hash = hash_file('sha256', $invalidPhar);
        if (false === $hash) {
            @unlink($originalPhar);
            @unlink($invalidPhar);
            $this->markTestSkipped('Unable to hash file.');
        }

        // Create checksum file
        $checksumFile = tempnam(sys_get_temp_dir(), 'checksum-');
        if (false === $checksumFile) {
            @unlink($originalPhar);
            @unlink($invalidPhar);
            $this->markTestSkipped('Unable to create temp file.');
        }

        file_put_contents($checksumFile, $hash.'  regex.phar');

        $output = new Output(false, true);

        $updater = self::updater($originalPhar, 'file://'.$invalidPhar, 'file://'.$checksumFile);

        try {
            $this->expectException(CliException::class);
            $this->expectExceptionMessageIsOrContains('Downloaded phar is invalid');
            $updater->run($output);
        } finally {
            @unlink($originalPhar);
            @unlink($invalidPhar);
            @unlink($checksumFile);
        }
    }

    #[Test]
    public function test_run_throws_when_phar_path_does_not_exist(): void
    {
        $nonExistentPath = sys_get_temp_dir().'/non-existent-phar.phar';

        $output = new Output(false, true);

        $updater = self::updater($nonExistentPath, 'file://'.$nonExistentPath, 'file://'.$nonExistentPath);

        $this->expectException(CliException::class);
        $this->expectExceptionMessageIsOrContains('Unable to locate the running phar.');
        $updater->run($output);
    }

    #[Test]
    public function test_run_throws_when_phar_path_is_not_writable(): void
    {
        if (\ini_get('phar.readonly')) {
            $this->markTestSkipped('Phar readonly is enabled.');
        }

        // Create a temp phar
        $tempBase = tempnam(sys_get_temp_dir(), 'readonly-phar-');
        if (false === $tempBase) {
            $this->markTestSkipped('Unable to create temp file.');
        }

        @unlink($tempBase);
        $readonlyPhar = $tempBase.'.phar';

        copy(__DIR__.'/../../Fixtures/Cli/test.phar', $readonlyPhar);

        // Make it read-only
        @chmod($readonlyPhar, 0o444);

        $output = new Output(false, true);

        $updater = self::updater($readonlyPhar, 'file://'.$readonlyPhar, 'file://'.$readonlyPhar);

        try {
            $this->expectException(CliException::class);
            $this->expectExceptionMessageIsOrContains('The phar file is not writable');
            $updater->run($output);
        } finally {
            @chmod($readonlyPhar, 0o644); // Restore permissions for cleanup
            @unlink($readonlyPhar);
        }
    }

    #[Test]
    public function test_get_update_url_returns_expected_url(): void
    {
        $pharPath = $this->copyTemplatePhar();
        $checksumFile = $this->writeChecksumFile($pharPath);
        // The checksum is served, the phar is not.
        $downloader = new LocalReleaseDownloader(['https://github.com/php-regex/php-regex/releases/latest/download/regex.phar.sha256' => $checksumFile]);

        try {
            (new SelfUpdater($downloader, $pharPath))->run(new Output(false, true));
            $this->fail('The phar was downloaded from an address nobody serves.');
        } catch (CliException $e) {
            $this->assertStringContainsString('Unable to download invalid://https://github.com/php-regex/php-regex/releases/latest/download/regex.phar.', $e->getMessage());
        } finally {
            @unlink($pharPath);
            @unlink($checksumFile);
        }

        $this->assertSame('https://github.com/php-regex/php-regex/releases/latest/download/regex.phar', $downloader->requested[1] ?? null);
    }

    #[Test]
    public function test_get_checksum_url_returns_expected_url(): void
    {
        $pharPath = $this->copyTemplatePhar();
        $downloader = new LocalReleaseDownloader([]);

        try {
            (new SelfUpdater($downloader, $pharPath))->run(new Output(false, true));
            $this->fail('The checksum was downloaded from an address nobody serves.');
        } catch (CliException) {
            $this->assertSame(['https://github.com/php-regex/php-regex/releases/latest/download/regex.phar.sha256'], $downloader->requested);
        } finally {
            @unlink($pharPath);
        }
    }

    #[Test]
    public function test_run_downloads_the_checksum_then_the_phar_of_the_latest_release(): void
    {
        $pharPath = $this->copyTemplatePhar();
        $checksumFile = $this->writeChecksumFile($pharPath);
        $downloader = new LocalReleaseDownloader([SelfUpdater::UPDATE_URL => $pharPath, SelfUpdater::CHECKSUM_URL => $checksumFile]);

        try {
            (new SelfUpdater($downloader, $pharPath))->run(new Output(false, true));
        } finally {
            @unlink($pharPath);
            @unlink($checksumFile);
        }

        $this->assertSame([SelfUpdater::CHECKSUM_URL, SelfUpdater::UPDATE_URL], $downloader->requested);
    }

    #[Test]
    public function test_run_throws_when_phar_not_writable_via_override(): void
    {
        $pharPath = $this->copyTemplatePhar();
        $output = new Output(false, true);

        SelfUpdateFunctionOverrides::queueIsWritable(false);

        $updater = self::updater($pharPath, $pharPath, $pharPath);

        try {
            $this->expectException(CliException::class);
            $this->expectExceptionMessageIsOrContains('The phar file is not writable');
            $updater->run($output);
        } finally {
            @unlink($pharPath);
        }
    }

    #[Test]
    public function test_run_throws_when_tempnam_fails(): void
    {
        $pharPath = $this->copyTemplatePhar();
        $checksumFile = $this->writeChecksumFile($pharPath);
        $output = new Output(false, true);

        $checksumTemp = sys_get_temp_dir().'/regex-update-'.bin2hex(random_bytes(4));
        SelfUpdateFunctionOverrides::queueTempnam($checksumTemp);
        SelfUpdateFunctionOverrides::queueTempnam(false);

        $updater = self::updater($pharPath, $pharPath, $checksumFile);

        try {
            $this->expectException(CliException::class);
            $this->expectExceptionMessageIsOrContains('Unable to create a temporary file.');
            $updater->run($output);
        } finally {
            @unlink($pharPath);
            @unlink($checksumFile);
        }
    }

    #[Test]
    public function test_run_throws_when_hash_file_fails(): void
    {
        $pharPath = $this->copyTemplatePhar();
        $checksumFile = $this->writeChecksumFile($pharPath);
        $output = new Output(false, true);

        SelfUpdateFunctionOverrides::queueHashFile(false);

        $updater = self::updater($pharPath, $pharPath, $checksumFile);

        try {
            $this->expectException(CliException::class);
            $this->expectExceptionMessageIsOrContains('Unable to hash the downloaded phar.');
            $updater->run($output);
        } finally {
            @unlink($pharPath);
            @unlink($checksumFile);
        }
    }

    #[Test]
    public function test_run_throws_when_checksum_mismatch_without_phar_writes(): void
    {
        $pharPath = $this->copyTemplatePhar();
        $checksumFile = $this->writeChecksumFile($pharPath, str_repeat('a', 64));
        $output = new Output(false, true);

        $updater = self::updater($pharPath, $pharPath, $checksumFile);

        try {
            $this->expectException(CliException::class);
            $this->expectExceptionMessageIsOrContains('Checksum verification failed.');
            $updater->run($output);
        } finally {
            @unlink($pharPath);
            @unlink($checksumFile);
        }
    }

    #[Test]
    public function test_run_throws_when_copy_fails_after_rename_failure(): void
    {
        $pharPath = $this->copyTemplatePhar();
        $checksumFile = $this->writeChecksumFile($pharPath);
        $output = new Output(false, true);

        SelfUpdateFunctionOverrides::queueRename(false);
        SelfUpdateFunctionOverrides::queueCopy(false);

        $updater = self::updater($pharPath, $pharPath, $checksumFile);

        try {
            $this->expectException(CliException::class);
            $this->expectExceptionMessageIsOrContains('Unable to replace the existing binary.');
            $updater->run($output);
        } finally {
            @unlink($pharPath);
            @unlink($checksumFile);
        }
    }

    #[Test]
    public function test_run_uses_copy_when_rename_fails(): void
    {
        $pharPath = $this->copyTemplatePhar();
        $checksumFile = $this->writeChecksumFile($pharPath);
        $output = new Output(false, true);

        SelfUpdateFunctionOverrides::queueRename(false);
        SelfUpdateFunctionOverrides::queueCopy(true);

        $updater = self::updater($pharPath, $pharPath, $checksumFile);

        try {
            $updater->run($output);
            $this->assertFileExists($pharPath);
        } finally {
            @unlink($pharPath);
            @unlink($checksumFile);
        }
    }

    #[Test]
    public function test_download_file_throws_when_write_handle_fails(): void
    {
        $downloader = new HttpDownloader();

        $source = tempnam(sys_get_temp_dir(), 'regex-src-');
        if (false === $source) {
            $this->markTestSkipped('Unable to create temp file.');
        }

        copy(__DIR__.'/../../Fixtures/Cli/payload_no_nl.txt', $source);

        $destinationDir = sys_get_temp_dir().'/regex-unwritable-'.bin2hex(random_bytes(4));
        @mkdir($destinationDir, 0o444, true);
        $destination = $destinationDir.'/file.phar';

        SelfUpdateFunctionOverrides::$forceFopenWriteFail = true;

        try {
            $this->expectException(CliException::class);
            $this->expectExceptionMessageIsOrContains('Unable to write to '.$destination.'.');
            $downloader->download('file://'.$source, $destination);
        } finally {
            SelfUpdateFunctionOverrides::$forceFopenWriteFail = null;
            @chmod($destinationDir, 0o755);
            @unlink($destination);
            @rmdir($destinationDir);
            @unlink($source);
        }
    }

    #[Test]
    public function test_download_file_throws_when_downloaded_file_empty(): void
    {
        $downloader = new HttpDownloader();

        $source = tempnam(sys_get_temp_dir(), 'regex-src-');
        if (false === $source) {
            $this->markTestSkipped('Unable to create temp file.');
        }

        copy(__DIR__.'/../../Fixtures/empty.txt', $source);

        $destination = tempnam(sys_get_temp_dir(), 'regex-dest-');
        if (false === $destination) {
            @unlink($source);
            $this->markTestSkipped('Unable to create temp file.');
        }

        @unlink($destination);

        try {
            $this->expectException(CliException::class);
            $this->expectExceptionMessageIsOrContains('Downloaded file is empty.');
            $downloader->download('file://'.$source, $destination);
        } finally {
            @unlink($source);
            @unlink($destination);
        }
    }

    #[Test]
    public function test_download_file_exec_fallback_uses_curl_when_successful(): void
    {
        $downloader = new HttpDownloader();

        $destination = tempnam(sys_get_temp_dir(), 'regex-dest-');
        if (false === $destination) {
            $this->markTestSkipped('Unable to create temp file.');
        }

        @unlink($destination);

        SelfUpdateFunctionOverrides::$forceFopenReadFail = true;
        SelfUpdateFunctionOverrides::queueExecResult(0, $destination, 'payload');

        try {
            $downloader->download('https://example.com/file', $destination);
            $this->assertSame('payload', file_get_contents($destination));
        } finally {
            SelfUpdateFunctionOverrides::$forceFopenReadFail = null;
            @unlink($destination);
        }
    }

    #[Test]
    public function test_download_file_exec_fallback_curl_empty_file_throws(): void
    {
        $downloader = new HttpDownloader();

        $destination = tempnam(sys_get_temp_dir(), 'regex-dest-');
        if (false === $destination) {
            $this->markTestSkipped('Unable to create temp file.');
        }

        @unlink($destination);

        SelfUpdateFunctionOverrides::$forceFopenReadFail = true;
        SelfUpdateFunctionOverrides::queueExecResult(0, $destination, '');

        try {
            $this->expectException(CliException::class);
            $this->expectExceptionMessageIsOrContains('Downloaded file is empty.');
            $downloader->download('https://example.com/file', $destination);
        } finally {
            SelfUpdateFunctionOverrides::$forceFopenReadFail = null;
            @unlink($destination);
        }
    }

    #[Test]
    public function test_download_file_exec_fallback_uses_wget_when_successful(): void
    {
        $downloader = new HttpDownloader();

        $destination = tempnam(sys_get_temp_dir(), 'regex-dest-');
        if (false === $destination) {
            $this->markTestSkipped('Unable to create temp file.');
        }

        @unlink($destination);

        SelfUpdateFunctionOverrides::$forceFopenReadFail = true;
        SelfUpdateFunctionOverrides::queueExecResult(1);
        SelfUpdateFunctionOverrides::queueExecResult(0, $destination, 'payload');

        try {
            $downloader->download('https://example.com/file', $destination);
            $this->assertSame('payload', file_get_contents($destination));
        } finally {
            SelfUpdateFunctionOverrides::$forceFopenReadFail = null;
            @unlink($destination);
        }
    }

    #[Test]
    public function test_download_file_exec_fallback_wget_empty_file_throws(): void
    {
        $downloader = new HttpDownloader();

        $destination = tempnam(sys_get_temp_dir(), 'regex-dest-');
        if (false === $destination) {
            $this->markTestSkipped('Unable to create temp file.');
        }

        @unlink($destination);

        SelfUpdateFunctionOverrides::$forceFopenReadFail = true;
        SelfUpdateFunctionOverrides::queueExecResult(1);
        SelfUpdateFunctionOverrides::queueExecResult(0, $destination, '');

        try {
            $this->expectException(CliException::class);
            $this->expectExceptionMessageIsOrContains('Downloaded file is empty.');
            $downloader->download('https://example.com/file', $destination);
        } finally {
            SelfUpdateFunctionOverrides::$forceFopenReadFail = null;
            @unlink($destination);
        }
    }

    #[Test]
    public function test_fetch_remote_string_throws_when_tempnam_fails(): void
    {
        $updater = new SelfUpdater();
        $method = new \ReflectionMethod(SelfUpdater::class, 'fetchRemoteString');

        SelfUpdateFunctionOverrides::queueTempnam(false);

        $this->expectException(CliException::class);
        $this->expectExceptionMessageIsOrContains('Unable to create a temporary file.');
        $method->invoke($updater, 'file://'.__FILE__);
    }

    #[Test]
    public function test_fetch_remote_string_throws_when_file_get_contents_fails(): void
    {
        $updater = new SelfUpdater();
        $method = new \ReflectionMethod(SelfUpdater::class, 'fetchRemoteString');

        $source = tempnam(sys_get_temp_dir(), 'regex-src-');
        if (false === $source) {
            $this->markTestSkipped('Unable to create temp file.');
        }

        copy(__DIR__.'/../../Fixtures/Cli/payload_no_nl.txt', $source);
        SelfUpdateFunctionOverrides::queueFileGetContents(false);

        try {
            $this->expectException(CliException::class);
            $this->expectExceptionMessageIsOrContains('Unable to read downloaded data.');
            $method->invoke($updater, 'file://'.$source);
        } finally {
            @unlink($source);
        }
    }

    /**
     * An updater replacing $pharPath with the phar at $update, checked
     * against the checksum at $checksum.
     */
    private static function updater(string $pharPath, string $update, string $checksum): SelfUpdater
    {
        return new SelfUpdater(new LocalReleaseDownloader([SelfUpdater::UPDATE_URL => $update, SelfUpdater::CHECKSUM_URL => $checksum]), $pharPath);
    }

    private function copyTemplatePhar(): string
    {
        $source = __DIR__.'/../../Fixtures/Cli/template.phar';
        $target = sys_get_temp_dir().'/regex-parser-phar-'.bin2hex(random_bytes(4)).'.phar';

        copy($source, $target);

        return $target;
    }

    private function writeChecksumFile(string $updatePath, ?string $overrideHash = null): string
    {
        $checksum = $overrideHash ?? hash_file('sha256', $updatePath);
        $checksumFile = sys_get_temp_dir().'/regex-checksum-'.bin2hex(random_bytes(4)).'.txt';
        file_put_contents($checksumFile, $checksum.'  regex.phar');

        return $checksumFile;
    }
}
