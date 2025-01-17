<?php

/*
 * This file is part of PHP CS Fixer.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *     Dariusz Rumiński <dariusz.ruminski@gmail.com>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace PhpCsFixer\Tests\Smoke;

use Keradus\CliExecutor\CommandExecutor;
use Keradus\CliExecutor\ScriptExecutor;
use PhpCsFixer\Console\Application;

/**
 * @author Dariusz Rumiński <dariusz.ruminski@gmail.com>
 *
 * @internal
 *
 * @requires OS Linux|Darwin
 * @coversNothing
 * @group covers-nothing
 * @large
 */
final class CiIntegrationTest extends AbstractSmokeTest
{
    public static $fixtureDir;

    public static function doSetUpBeforeClass()
    {
        parent::doSetUpBeforeClass();

        self::$fixtureDir = __DIR__.'/../Fixtures/ci-integration';

        try {
            CommandExecutor::create('composer --version', __DIR__)->getResult();
        } catch (\RuntimeException $e) {
            static::markTestSkippedOrFail('Missing `composer` env script. Details:'."\n".$e->getMessage());
        }

        try {
            CommandExecutor::create('composer check', __DIR__.'/../..')->getResult();
        } catch (\RuntimeException $e) {
            static::markTestSkippedOrFail('Composer check failed. Details:'."\n".$e->getMessage());
        }

        try {
            self::executeScript([
                'rm -rf .git',
                'git init -q',
                'git config user.name test',
                'git config user.email test',
                'git add .',
                'git commit -m "init" -q',
            ]);
        } catch (\RuntimeException $e) {
            static::markTestSkippedOrFail($e->getMessage());
        }
    }

    public static function doTearDownAfterClass()
    {
        parent::doTearDownAfterClass();

        self::executeCommand('rm -rf .git');
    }

    protected function doTearDown()
    {
        parent::doTearDown();

        self::executeScript([
            'git reset . -q',
            'git checkout . -q',
            'git clean -fdq',
            'git checkout master -q',
        ]);
    }

    /**
     * @param string   $branchName
     * @param string[] $caseCommands
     * @param string[] $expectedResult1Lines
     * @param string[] $expectedResult2Lines
     * @param string   $expectedResult3Files
     *
     * @dataProvider provideIntegrationCases
     */
    public function testIntegration(
        $branchName,
        array $caseCommands,
        array $expectedResult1Lines,
        array $expectedResult2Lines,
        $expectedResult3Files
    ) {
        self::executeScript(array_merge(
            [
                "git checkout -b {$branchName} -q",
            ],
            $caseCommands
        ));

        $integrationScript = explode("\n", str_replace('vendor/bin/', './../../../', file_get_contents(__DIR__.'/../../ci-integration.sh')));
        $steps = [
            "COMMIT_RANGE=\"master..{$branchName}\"",
            "{$integrationScript[3]}\n{$integrationScript[4]}",
            $integrationScript[5],
            $integrationScript[6],
            $integrationScript[7],
        ];

        $result1 = self::executeScript([
            $steps[0],
            $steps[1],
            $steps[2],
            'echo "$CHANGED_FILES"',
        ]);

        static::assertSame(implode("\n", $expectedResult1Lines)."\n", $result1->getOutput());

        $result2 = self::executeScript([
            $steps[0],
            $steps[1],
            $steps[2],
            $steps[3],
            'echo "${EXTRA_ARGS}"',
        ]);

        static::assertSame(implode("\n", $expectedResult2Lines), $result2->getOutput());

        $result3 = self::executeScript([
            $steps[0],
            $steps[1],
            $steps[2],
            $steps[3],
            $steps[4],
        ]);

        $optionalIncompatibilityWarning = 'PHP needs to be a minimum version of PHP 5.6.0 and maximum version of PHP 7.4.*.
Current PHP version: '.PHP_VERSION.'.
Ignoring environment requirements because `PHP_CS_FIXER_IGNORE_ENV` is set. Execution may be unstable.
';

        $optionalXdebugWarning = 'You are running PHP CS Fixer with xdebug enabled. This has a major impact on runtime performance.
If you need help while solving warnings, ask at https://gitter.im/PHP-CS-Fixer, we will help you!
';

        /** @phpstan-ignore-next-line to avoid `Ternary operator condition is always true|false.` */
        $aboutSubpattern = Application::VERSION_CODENAME
            ? 'PHP CS Fixer '.preg_quote(Application::VERSION, '/').' '.preg_quote(Application::VERSION_CODENAME, '/').' by Fabien Potencier and Dariusz Ruminski'
            : 'PHP CS Fixer '.preg_quote(Application::VERSION, '/').' by Fabien Potencier and Dariusz Ruminski';

        $pattern = sprintf(
            '/^(?:%s)?(?:%s)?%s\n%s\n%s\n([\.S]{%d})\n%s$/',
            preg_quote($optionalIncompatibilityWarning, '/'),
            preg_quote($optionalXdebugWarning, '/'),
            $aboutSubpattern,
            preg_quote(sprintf('Runtime: PHP %s', PHP_VERSION), '/'),
            preg_quote('Loaded config default from ".php_cs.dist".', '/'),
            \strlen($expectedResult3Files),
            preg_quote('Legend: ?-unknown, I-invalid file syntax (file ignored), S-skipped (cached or empty file), .-no changes, F-fixed, E-error', '/')
        );

        static::assertMatchesRegularExpression($pattern, $result3->getError());

        preg_match($pattern, $result3->getError(), $matches);

        static::assertArrayHasKey(1, $matches);
        static::assertSame(substr_count($expectedResult3Files, '.'), substr_count($matches[1], '.'));
        static::assertSame(substr_count($expectedResult3Files, 'S'), substr_count($matches[1], 'S'));

        static::assertMatchesRegularExpression(
            '/^\s*Checked all files in \d+\.\d+ seconds, \d+\.\d+ MB memory used\s*$/',
            $result3->getOutput()
        );
    }

    public function provideIntegrationCases()
    {
        return [
            'random-changes' => [
                'random-changes',
                [
                    'touch dir\ a/file.php',
                    'rm -r dir\ c',
                    'echo "" >> dir\ b/file\ b.php',
                    'echo "echo 1;" >> dir\ b/file\ b.php',
                    'git add .',
                    'git commit -m "Random changes" -q',
                ],
                [
                    'dir a/file.php',
                    'dir b/file b.php',
                ],
                [
                    '--path-mode=intersection',
                    '--',
                    'dir a/file.php',
                    'dir b/file b.php',
                    '',
                ],
                'S.',
            ],
            'changes-including-dist-config-file' => [
                'changes-including-dist-config-file',
                [
                    'echo "" >> dir\ b/file\ b.php',
                    'echo "echo 1;" >> dir\ b/file\ b.php',
                    // `sed -i ...` is not handled the same on Linux and macOS
                    'sed -e \'s/@Symfony/@PSR2/\' .php_cs.dist > .php_cs.dist.new',
                    'mv .php_cs.dist.new .php_cs.dist',
                    'git add .',
                    'git commit -m "Random changes including config file" -q',
                ],
                [
                    '.php_cs.dist',
                    'dir b/file b.php',
                ],
                [
                    '',
                    '',
                ],
                '...',
            ],
            'changes-including-custom-config-file-creation' => [
                'changes-including-custom-config-file-creation',
                [
                    'echo "" >> dir\ b/file\ b.php',
                    'echo "echo 1;" >> dir\ b/file\ b.php',
                    'sed -e \'s/@Symfony/@PSR2/\' .php_cs.dist > .php_cs',
                    'git add .',
                    'git commit -m "Random changes including custom config file creation" -q',
                ],
                [
                    '.php_cs',
                    'dir b/file b.php',
                ],
                [
                    '',
                    '',
                ],
                '...',
            ],
            'changes-including-composer-lock' => [
                'changes-including-composer-lock',
                [
                    'echo "" >> dir\ b/file\ b.php',
                    'echo "echo 1;" >> dir\ b/file\ b.php',
                    'touch composer.lock',
                    'git add .',
                    'git commit -m "Random changes including composer.lock" -q',
                ],
                [
                    'composer.lock',
                    'dir b/file b.php',
                ],
                [
                    '',
                    '',
                ],
                '...',
            ],
        ];
    }

    private static function executeCommand($command)
    {
        return CommandExecutor::create($command, self::$fixtureDir)->getResult();
    }

    private static function executeScript(array $scriptParts)
    {
        return ScriptExecutor::create($scriptParts, self::$fixtureDir)->getResult();
    }
}
