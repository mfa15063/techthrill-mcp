<?php
namespace TechThrill\MCP\Tests\Unit;

use PHPUnit\Framework\TestCase;

class AutoloaderTest extends TestCase {
    public function test_autoloads_plugin_class(): void {
        $this->assertTrue(class_exists(\TechThrill\MCP\Plugin::class));
        $this->assertSame('1.2.0', TECHTHRILL_MCP_VERSION);
    }
}
