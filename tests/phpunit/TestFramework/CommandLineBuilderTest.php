<?php
/**
 * This code is licensed under the BSD 3-Clause License.
 *
 * Copyright (c) 2017, Maks Rafalko
 * All rights reserved.
 *
 * Redistribution and use in source and binary forms, with or without
 * modification, are permitted provided that the following conditions are met:
 *
 * * Redistributions of source code must retain the above copyright notice, this
 *   list of conditions and the following disclaimer.
 *
 * * Redistributions in binary form must reproduce the above copyright notice,
 *   this list of conditions and the following disclaimer in the documentation
 *   and/or other materials provided with the distribution.
 *
 * * Neither the name of the copyright holder nor the names of its
 *   contributors may be used to endorse or promote products derived from
 *   this software without specific prior written permission.
 *
 * THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS "AS IS"
 * AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE
 * IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE ARE
 * DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT HOLDER OR CONTRIBUTORS BE LIABLE
 * FOR ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL
 * DAMAGES (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR
 * SERVICES; LOSS OF USE, DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER
 * CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY,
 * OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE
 * OF THIS SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.
 */

declare(strict_types=1);

namespace Infection\Tests\TestFramework;

use Infection\TestFramework\CommandLineBuilder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('integration')]
#[CoversClass(CommandLineBuilder::class)]
final class CommandLineBuilderTest extends TestCase
{
    private const PHP_EXTRA_ARGS = ['-d zend_extension=xdebug.so'];

    private const TEST_FRAMEWORK_ARGS = ['--filter XYZ', '--exclude-group=integration'];

    /**
     * @var CommandLineBuilder
     */
    private $commandLineBuilder;

    protected function setUp(): void
    {
        parent::setUp();

        $this->commandLineBuilder = new CommandLineBuilder();
    }

    public function test_it_builds_command_line_for_batch_file(): void
    {
        $commandLine = $this->commandLineBuilder->build('phpunit.bat', self::PHP_EXTRA_ARGS, self::TEST_FRAMEWORK_ARGS);

        $this->assertContains('phpunit.bat', $commandLine);
        $this->assertContains('--filter XYZ', $commandLine);
        $this->assertContains('--exclude-group=integration', $commandLine);
    }

    public function test_it_builds_command_line_with_empty_php_args(): void
    {
        $commandLine = $this->commandLineBuilder->build('vendor/bin/phpunit', [], self::TEST_FRAMEWORK_ARGS);

        $this->assertContains('vendor/bin/phpunit', $commandLine);
        $this->assertContains('--filter XYZ', $commandLine);
        $this->assertContains('--exclude-group=integration', $commandLine);
    }
}
