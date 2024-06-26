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

namespace Infection\Tests\Logger;

use Infection\Logger\GitHubAnnotationsLogger;
use Infection\Metrics\ResultsCollector;
use Infection\Tests\EnvVariableManipulation\BacksUpEnvironmentVariables;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('integration')]
#[CoversClass(GitHubAnnotationsLogger::class)]
final class GitHubAnnotationsLoggerTest extends TestCase
{
    use BacksUpEnvironmentVariables;
    use CreateMetricsCalculator;

    protected function setUp(): void
    {
        $this->backupEnvironmentVariables();

        parent::setUp();
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        $this->restoreEnvironmentVariables();
        self::resetOriginalFilePrefix();
    }

    #[DataProvider('metricsProvider')]
    public function test_it_logs_correctly_with_mutations(
        ResultsCollector $resultsCollector,
        array $expectedLines,
    ): void {
        $logger = new GitHubAnnotationsLogger($resultsCollector, null);

        $this->assertSame($expectedLines, $logger->getLogLines());
    }

    public static function metricsProvider(): iterable
    {
        yield 'no mutations' => [
            new ResultsCollector(),
            [],
        ];

        yield 'all mutations' => [
            self::createCompleteResultsCollector(),
            [
                "::warning file=foo/bar,line=9::Escaped Mutant for Mutator \"PregQuote\":%0A%0A--- Original%0A+++ New%0A@@ @@%0A%0A- echo 'original';%0A+ echo 'escaped#1';%0A\n",
                "::warning file=foo/bar,line=10::Escaped Mutant for Mutator \"For_\":%0A%0A--- Original%0A+++ New%0A@@ @@%0A%0A- echo 'original';%0A+ echo 'escaped#0';%0A\n",
            ],
        ];
    }

    public function test_it_logs_correctly_with_ci_github_workspace(): void
    {
        \Safe\putenv('GITHUB_WORKSPACE=/my/project/dir');
        self::setOriginalFilePrefix('/my/project/dir/');

        $resultsCollector = self::createCompleteResultsCollector();

        $logger = new GitHubAnnotationsLogger($resultsCollector, null);

        $this->assertStringContainsString('warning file=foo/bar', $logger->getLogLines()[0]);
    }

    public function test_it_logs_correctly_with_custom_github_workspace(): void
    {
        \Safe\putenv('GITHUB_WORKSPACE=/my/project/dir');
        self::setOriginalFilePrefix('/custom/project/dir/');

        $resultsCollector = self::createCompleteResultsCollector();

        $logger = new GitHubAnnotationsLogger($resultsCollector, '/custom/project/dir/');

        $this->assertStringContainsString('warning file=foo/bar', $logger->getLogLines()[0]);
    }
}
