<?php namespace Renick\TailorCompanion\Tests\Classes\Logs;

use PluginTestCase;
use Renick\TailorCompanion\Classes\Logs\LogReader;

class LogReaderTest extends PluginTestCase
{
    protected LogReader $reader;
    protected string $tmp;

    public function setUp(): void
    {
        parent::setUp();
        $this->reader = new LogReader;
        $this->tmp = tempnam(sys_get_temp_dir(), 'logreader_');
    }

    public function tearDown(): void
    {
        @unlink($this->tmp);
        parent::tearDown();
    }

    protected function writeLines(int $count): void
    {
        $lines = [];
        for ($i = 1; $i <= $count; $i++) {
            $lines[] = "line {$i}";
        }
        file_put_contents($this->tmp, implode("\n", $lines) . "\n");
    }

    public function testReturnsLastNLinesInChronologicalOrder()
    {
        $this->writeLines(100);

        $result = $this->reader->tail($this->tmp, 10);

        $this->assertCount(10, $result['lines']);
        $this->assertSame('line 91', $result['lines'][0]);
        $this->assertSame('line 100', $result['lines'][9]);
        $this->assertTrue($result['truncated'], 'More lines exist above the returned window');
        $this->assertSame(10, $result['returned']);
        $this->assertTrue($result['exists']);
    }

    public function testReturnsAllWhenFileHasFewerLines()
    {
        $this->writeLines(5);

        $result = $this->reader->tail($this->tmp, 100);

        $this->assertCount(5, $result['lines']);
        $this->assertSame('line 1', $result['lines'][0]);
        $this->assertSame('line 5', $result['lines'][4]);
        $this->assertFalse($result['truncated']);
    }

    public function testPreservesMultilineStackTraces()
    {
        $entry = "[2026-07-07 10:00:00] local.ERROR: Boom\n"
            . "#0 /app/foo.php(1): bar()\n"
            . "#1 {main}\n";
        file_put_contents($this->tmp, $entry);

        $result = $this->reader->tail($this->tmp, 100);

        $this->assertSame(
            ['[2026-07-07 10:00:00] local.ERROR: Boom', '#0 /app/foo.php(1): bar()', '#1 {main}'],
            $result['lines']
        );
    }

    public function testMissingFileReturnsEmpty()
    {
        $result = $this->reader->tail('/no/such/file.log', 100);

        $this->assertSame([], $result['lines']);
        $this->assertFalse($result['exists']);
    }

    public function testEmptyFileReturnsEmptyButExists()
    {
        file_put_contents($this->tmp, '');

        $result = $this->reader->tail($this->tmp, 100);

        $this->assertSame([], $result['lines']);
        $this->assertTrue($result['exists']);
        $this->assertFalse($result['truncated']);
    }

    public function testLargeFileReadsOnlyTailCorrectly()
    {
        // 60k lines spanning multiple backward chunks — the last window must
        // still be exact, proving the reverse read reconstructs boundaries.
        $handle = fopen($this->tmp, 'wb');
        for ($i = 1; $i <= 60000; $i++) {
            fwrite($handle, "log entry number {$i} with some padding text\n");
        }
        fclose($handle);

        $result = $this->reader->tail($this->tmp, 50);

        $this->assertCount(50, $result['lines']);
        $this->assertSame('log entry number 59951 with some padding text', $result['lines'][0]);
        $this->assertSame('log entry number 60000 with some padding text', $result['lines'][49]);
        $this->assertTrue($result['truncated']);
    }
}
