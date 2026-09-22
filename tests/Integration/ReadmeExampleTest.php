<?php

declare(strict_types=1);

namespace mrstroz\querymonitoring\tests\Integration;

use mrstroz\querymonitoring\QueryMonitor;
use mrstroz\querymonitoring\tests\app\Schema;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * YQM-11: the SQL configuration example in README.md runs as written, and its option table matches the
 * component. Only the example's `adapter`, a class of the reader's application, is left to the test
 * application's capturing adapter.
 */
final class ReadmeExampleTest extends IntegrationTestCase
{
    private const MARKER = '<!-- example:sql-config -->';

    #[DataProvider('databases')]
    public function testConfigurationExampleRunsInTheTestApplication(string $db): void
    {
        $example = $this->example();
        self::assertIsArray($example['bootstrap'] ?? null);
        self::assertContains('queryMonitor', $example['bootstrap']);
        $component = $example['components']['queryMonitor'] ?? null;
        self::assertIsArray($component);
        self::assertSame(QueryMonitor::class, $component['class']);
        self::assertIsString($component['adapter'], 'the example names an adapter explicitly');
        self::assertStringStartsNotWith('mrstroz\querymonitoring\\', ltrim($component['adapter'], '\\'), 'the adapter is the reader\'s own class');
        unset($component['adapter']);

        $this->requireDatabase($db);
        Schema::create(Schema::connection($db));
        $result = $this->request($db, 'order/index', $component);

        $this->assertProcessOk($result);
        $this->assertNoErrors($result);
        $batch = $this->singleBatch($result);
        self::assertSame($component['app'], $batch['app']);
        self::assertNotEmpty($batch['queries']);
        self::assertSame($component['connections'], array_values(array_unique(array_column($batch['queries'], 'conn'))));
    }

    public function testOptionTableListsEveryOptionWithItsDefault(): void
    {
        preg_match_all('/^\| `(\w+)` \| ([^|]+?) \|/m', $this->readme(), $rows, PREG_SET_ORDER);
        $table = [];
        foreach ($rows as [, $name, $default]) {
            $table[$name] = trim($default, " `");
        }

        $defaults = (new \ReflectionClass(QueryMonitor::class))->getDefaultProperties();
        $options = [];
        foreach ((new \ReflectionClass(QueryMonitor::class))->getProperties(\ReflectionProperty::IS_PUBLIC) as $property) {
            if ($property->getDeclaringClass()->getName() === QueryMonitor::class && !$property->isStatic()) {
                $options[$property->getName()] = $defaults[$property->getName()];
            }
        }
        ksort($options);
        ksort($table);
        self::assertSame(array_keys($options), array_keys($table), 'README option table and the public properties of QueryMonitor');

        foreach ($options as $name => $default) {
            if ($table[$name] === 'required') {
                self::assertContains($default, ['', null], "{$name} is required, so it has no usable default");
                continue;
            }
            self::assertSame(json_encode($default), $table[$name], "default of {$name}");
        }
    }

    /**
     * The configuration returned by the README block.
     *
     * @return array<mixed>
     */
    private function example(): array
    {
        $readme = $this->readme();
        $at = strpos($readme, self::MARKER);
        self::assertNotFalse($at, 'README has the example marker');
        $code = preg_match('/\G\s*```php\n(.*?)\n```/s', $readme, $block, 0, $at + strlen(self::MARKER)) === 1 ? $block[1] : null;
        self::assertNotNull($code, 'a php block right after the marker');

        $file = (string) tempnam(sys_get_temp_dir(), 'qm-readme-');
        try {
            file_put_contents($file, $code);
            $config = require $file;
        } finally {
            unlink($file);
        }
        self::assertIsArray($config);

        return $config;
    }

    private function readme(): string
    {
        return (string) file_get_contents(__DIR__ . '/../../README.md');
    }
}
