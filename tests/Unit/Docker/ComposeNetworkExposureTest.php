<?php

namespace Tests\Unit\Docker;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

class ComposeNetworkExposureTest extends TestCase
{
    #[DataProvider('composeFileProvider')]
    public function test_web_port_is_bound_to_ipv4_loopback(string $composeFile): void
    {
        $configuration = Yaml::parseFile(dirname(__DIR__, 3) . '/' . $composeFile);

        $this->assertSame(
            ['127.0.0.1:8080:80'],
            $configuration['services']['web']['ports'] ?? null,
        );
    }

    public static function composeFileProvider(): iterable
    {
        yield 'published installation' => ['compose.yaml'];
        yield 'local source build' => ['compose.dev.yaml'];
    }
}
