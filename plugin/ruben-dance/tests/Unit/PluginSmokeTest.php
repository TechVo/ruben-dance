<?php
/**
 * Smoke test proving the Composer autoloader and PHPUnit wiring work.
 *
 * @package RubenDance
 */

declare( strict_types = 1 );

namespace RubenDance\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RubenDance\Cli\Seed_Command;

/**
 * Class PluginSmokeTest.
 */
class PluginSmokeTest extends TestCase {

	/**
	 * The skeleton wp-cli command class autoloads and is invokable.
	 *
	 * Later milestones replace this with real coverage once there is real
	 * logic to test; M01 only proves the toolchain is wired up.
	 */
	public function test_seed_command_class_autoloads(): void {
		$this->assertTrue( class_exists( Seed_Command::class ) );
		$this->assertTrue( method_exists( Seed_Command::class, '__invoke' ) );
	}
}
