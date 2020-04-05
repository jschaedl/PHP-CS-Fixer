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

namespace PhpCsFixer\Tests\Cache;

use PhpCsFixer\Cache\Cache;
use PhpCsFixer\Cache\Signature;
use PhpCsFixer\Cache\SignatureInterface;
use PhpCsFixer\Config;
use PhpCsFixer\Tests\TestCase;
use PhpCsFixer\ToolInfo;

/**
 * @author Andreas Möller <am@localheinz.com>
 *
 * @internal
 *
 * @covers \PhpCsFixer\Cache\Cache
 */
final class CacheTest extends TestCase
{
    public function testIsFinal()
    {
        $reflection = new \ReflectionClass(\PhpCsFixer\Cache\Cache::class);

        static::assertTrue($reflection->isFinal());
    }

    public function testImplementsCacheInterface()
    {
        $reflection = new \ReflectionClass(\PhpCsFixer\Cache\Cache::class);

        static::assertTrue($reflection->implementsInterface(\PhpCsFixer\Cache\CacheInterface::class));
    }

    public function testConstructorSetsValues()
    {
        $signature = $this->getSignatureDouble();

        $cache = new Cache($signature);

        static::assertSame($signature, $cache->getSignature());
    }

    public function testDefaults()
    {
        $signature = $this->getSignatureDouble();

        $cache = new Cache($signature);

        $file = 'test.php';

        static::assertFalse($cache->has($file));
        static::assertNull($cache->get($file));
    }

    public function testCanSetAndGetValue()
    {
        $signature = $this->getSignatureDouble();

        $cache = new Cache($signature);

        $file = 'test.php';
        $hash = crc32('hello');

        $cache->set($file, $hash);

        static::assertTrue($cache->has($file));
        static::assertSame($hash, $cache->get($file));
    }

    public function testCanClearValue()
    {
        $signature = $this->getSignatureDouble();

        $cache = new Cache($signature);

        $file = 'test.php';
        $hash = crc32('hello');

        $cache->set($file, $hash);
        $cache->clear($file);

        static::assertNull($cache->get($file));
    }

    public function testFromJsonThrowsInvalidArgumentExceptionIfJsonIsInvalid()
    {
        $this->expectException(\InvalidArgumentException::class);

        $json = '{"foo';

        Cache::fromJson($json);
    }

    /**
     * @dataProvider provideMissingDataCases
     */
    public function testFromJsonThrowsInvalidArgumentExceptionIfJsonIsMissingKey(array $data)
    {
        $this->expectException(\InvalidArgumentException::class);

        $json = json_encode($data);

        Cache::fromJson($json);
    }

    /**
     * @return array
     */
    public function provideMissingDataCases()
    {
        $data = [
            'php' => '7.1.2',
            'version' => '2.0',
            'rules' => [
                'foo' => true,
                'bar' => false,
            ],
            'hashes' => [],
        ];

        return array_map(static function ($missingKey) use ($data) {
            unset($data[$missingKey]);

            return [
                $data,
            ];
        }, array_keys($data));
    }

    /**
     * @dataProvider provideCanConvertToAndFromJsonCases
     */
    public function testCanConvertToAndFromJson(SignatureInterface $signature)
    {
        $cache = new Cache($signature);

        $file = 'test.php';
        $hash = crc32('hello');

        $cache->set($file, $hash);
        $cached = Cache::fromJson($cache->toJson());

        static::assertTrue($cached->getSignature()->equals($signature));
        static::assertTrue($cached->has($file));
        static::assertSame($hash, $cached->get($file));
    }

    public function provideCanConvertToAndFromJsonCases()
    {
        $toolInfo = new ToolInfo();
        $config = new Config();

        return [
            [new Signature(
                PHP_VERSION,
                '2.0',
                '  ',
                "\r\n",
                [
                    'foo' => true,
                    'bar' => true,
                ]
            )],
            [new Signature(
                PHP_VERSION,
                $toolInfo->getVersion(),
                $config->getIndent(),
                $config->getLineEnding(),
                [
                    // value encoded in ANSI, not UTF
                    'header_comment' => ['header' => 'Dariusz '.base64_decode('UnVtafFza2k=', true)],
                ]
            )],
        ];
    }

    public function testToJsonThrowsExceptionOnInvalid()
    {
        $invalidUtf8Sequence = "\xB1\x31";

        $signature = $this->prophesize(\PhpCsFixer\Cache\SignatureInterface::class);
        $signature->getPhpVersion()->willReturn('7.1.0');
        $signature->getFixerVersion()->willReturn('2.2.0');
        $signature->getIndent()->willReturn('    ');
        $signature->getLineEnding()->willReturn(PHP_EOL);
        $signature->getRules()->willReturn([
            $invalidUtf8Sequence => true,
        ]);

        $cache = new Cache($signature->reveal());

        $this->expectException(
            \UnexpectedValueException::class
        );
        $this->expectExceptionMessage(
            'Can not encode cache signature to JSON, error: "Malformed UTF-8 characters, possibly incorrectly encoded". If you have non-UTF8 chars in your signature, like in license for `header_comment`, consider enabling `ext-mbstring` or install `symfony/polyfill-mbstring`.'
        );

        $cache->toJson();
    }

    /**
     * @return SignatureInterface
     */
    private function getSignatureDouble()
    {
        return $this->prophesize(\PhpCsFixer\Cache\SignatureInterface::class)->reveal();
    }
}
