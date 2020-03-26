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

namespace Infection\Command;

use Exception;
use Infection\Configuration\Configuration;
use Infection\Configuration\Schema\SchemaConfigurationLoader;
use Infection\Console\ConsoleOutput;
use Infection\Console\Exception\ConfigurationException;
use Infection\Console\Input\MsiParser;
use Infection\Console\LogVerbosity;
use Infection\Container;
use Infection\Engine;
use Infection\Event\ApplicationExecutionWasStarted;
use Infection\FileSystem\Locator\FileOrDirectoryNotFound;
use Infection\FileSystem\Locator\Locator;
use Infection\Metrics\MinMsiCheckFailed;
use Infection\Process\Runner\InitialTestsFailed;
use Infection\TestFramework\TestFrameworkTypes;
use function Safe\sprintf;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use function trim;
use Webmozart\Assert\Assert;

/**
 * @internal
 */
final class RunCommand extends BaseCommand
{
    /**
     * @var ConsoleOutput
     */
    private $consoleOutput;

    /**
     * @var Container
     */
    private $container;

    protected function configure(): void
    {
        $this
            ->setName('run')
            ->setDescription('Runs the mutation testing.')
            ->addOption(
                'test-framework',
                null,
                InputOption::VALUE_REQUIRED,
                'Name of the Test framework to use (' . implode(', ', TestFrameworkTypes::TYPES) . ')',
                ''
            )
            ->addOption(
                'test-framework-options',
                null,
                InputOption::VALUE_REQUIRED,
                'Options to be passed to the test framework'
            )
            ->addOption(
                'threads',
                'j',
                InputOption::VALUE_REQUIRED,
                'Threads count',
                '1'
            )
            ->addOption(
                'only-covered',
                null,
                InputOption::VALUE_NONE,
                'Mutate only covered by tests lines of code'
            )
            ->addOption(
                'show-mutations',
                's',
                InputOption::VALUE_NONE,
                'Show mutations to the console'
            )
            ->addOption(
                'no-progress',
                null,
                InputOption::VALUE_NONE,
                'Do not output progress bars'
            )
            ->addOption(
                'configuration',
                'c',
                InputOption::VALUE_REQUIRED,
                'Custom configuration file path'
            )
            ->addOption(
                'coverage',
                null,
                InputOption::VALUE_REQUIRED,
                'Path to existing coverage (`xml` and `junit` reports are required)'
            )
            ->addOption(
                'mutators',
                null,
                InputOption::VALUE_REQUIRED,
                'Specify particular mutators. Example: --mutators=Plus,PublicVisibility'
            )
            ->addOption(
                'filter',
                null,
                InputOption::VALUE_REQUIRED,
                'Filter which files to mutate',
                ''
            )
            ->addOption(
                'formatter',
                null,
                InputOption::VALUE_REQUIRED,
                'Output formatter. Possible values: dot, progress',
                'dot'
            )
            ->addOption(
                'min-msi',
                null,
                InputOption::VALUE_REQUIRED,
                'Minimum Mutation Score Indicator (MSI) percentage value. Should be used in CI server.'
            )
            ->addOption(
                'min-covered-msi',
                null,
                InputOption::VALUE_REQUIRED,
                'Minimum Covered Code Mutation Score Indicator (MSI) percentage value. Should be used in CI server.'
            )
            ->addOption(
                'log-verbosity',
                null,
                InputOption::VALUE_REQUIRED,
                'Log verbosity level. \'all\' - full logs format, \'default\' - short logs format, \'none\' - no logs.',
                LogVerbosity::NORMAL
            )
            ->addOption(
                'initial-tests-php-options',
                null,
                InputOption::VALUE_REQUIRED,
                'Extra php options for the initial test runner. Will be ignored if --coverage option presented.'
            )
            ->addOption(
                'skip-initial-tests',
                null,
                InputOption::VALUE_NONE,
                'Skips the initial test runs - requires the coverage to be provided via the --coverage option.'
            )
            ->addOption(
                'ignore-msi-with-no-mutations',
                null,
                InputOption::VALUE_NONE,
                'Ignore MSI violations with zero mutations'
            )
            ->addOption(
                'debug',
                null,
                InputOption::VALUE_NONE,
                'Debug mode. Will not clean up Infection temporary folder.'
            )
            ->addOption(
                'dry-run',
                null,
                InputOption::VALUE_NONE,
                'Dry run. Will not clean up Infection temporary folder.'
            )
        ;
    }

    protected function initialize(InputInterface $input, OutputInterface $output): void
    {
        parent::initialize($input, $output);

        $this->initContainer($input);

        $locator = $this->container->getRootsFileOrDirectoryLocator();

        if ($customConfigPath = (string) $input->getOption('configuration')) {
            $locator->locate($customConfigPath);
        } else {
            $this->runConfigurationCommand($locator);
        }

        $this->installTestFrameworkIfNeeded($input, $output);

        $this->consoleOutput = $this->getApplication()->getConsoleOutput();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $this->startUp();

        $engine = new Engine(
            $this->container->getConfiguration(),
            $this->container->getTestFrameworkAdapter(),
            $this->container->getCoverageChecker(),
            $this->container->getEventDispatcher(),
            $this->container->getInitialTestsRunner(),
            $this->container->getMemoryLimiter(),
            $this->container->getMutationGenerator(),
            $this->container->getMutationTestingRunner(),
            $this->container->getMinMsiChecker(),
            $this->consoleOutput,
            $this->container->getMetricsCalculator(),
            $this->container->getTestFrameworkExtraOptionsFilter()
        );

        try {
            $engine->execute();
        } catch (InitialTestsFailed | MinMsiCheckFailed $exception) {
            // TODO: we can move that in a dedicated logger later and handle those cases in the
            // Engine instead
            $io->error($exception->getMessage());
        }

        return 0;
    }

    private function initContainer(InputInterface $input): void
    {
        // Currently the configuration is mandatory hence there is no way to
        // say "do not use a config". If this becomes possible in the future
        // though, it will likely be a `--no-config` option rather than relying
        // on this value to be set to an empty string.
        $configFile = trim((string) $input->getOption('configuration'));

        $coverage = trim((string) $input->getOption('coverage'));
        $testFramework = trim((string) $this->input->getOption('test-framework'));
        $testFrameworkExtraOptions = trim((string) $this->input->getOption('test-framework-options'));
        $initialTestsPhpOptions = trim((string) $input->getOption('initial-tests-php-options'));

        /** @var string|null $minMsi */
        $minMsi = $input->getOption('min-msi');
        /** @var string|null $minCoveredMsi */
        $minCoveredMsi = $input->getOption('min-covered-msi');

        $msiPrecision = MsiParser::detectPrecision($minMsi, $minCoveredMsi);

        $this->container = $this->getApplication()->getContainer()->withDynamicParameters(
            $configFile === '' ? null : $configFile,
            trim((string) $input->getOption('mutators')),
            $input->getOption('show-mutations'),
            trim((string) $input->getOption('log-verbosity')),
            $input->getOption('debug'),
            $input->getOption('only-covered'),
            trim((string) $input->getOption('formatter')),
            $input->getOption('no-progress'),
            $coverage === '' ? null : $coverage,
            $initialTestsPhpOptions === '' ? null : $initialTestsPhpOptions,
            (bool) $input->getOption('skip-initial-tests'),
            $input->getOption('ignore-msi-with-no-mutations'),
            MsiParser::parse($minMsi, $msiPrecision, 'min-msi'),
            MsiParser::parse($minCoveredMsi, $msiPrecision, 'min-covered-msi'),
            $msiPrecision,
            $testFramework === '' ? null : $testFramework,
            $testFrameworkExtraOptions === '' ? null : $testFrameworkExtraOptions,
            trim((string) $input->getOption('filter')),
            (int) $this->input->getOption('threads'),
            (bool) $this->input->getOption('dry-run')
        );
    }

    private function installTestFrameworkIfNeeded(InputInterface $input, OutputInterface $output): void
    {
        $installationDecider = $this->container->getAdapterInstallationDecider();
        $configTestFramework = $this->container->getConfiguration()->getTestFramework();

        $adapterName = trim((string) $this->input->getOption('test-framework')) ?: $configTestFramework;

        if (!$installationDecider->shouldBeInstalled($adapterName, $input, $output)) {
            return;
        }

        $output->writeln([
            '',
            sprintf('Installing <comment>infection/%s-adapter</comment>...', $adapterName),
        ]);

        $this->container->getAdapterInstaller()->install($adapterName);
    }

    private function startUp(): void
    {
        Assert::notNull($this->container);

        $this->container->getCoverageChecker()->checkCoverageRequirements();

        $config = $this->container->getConfiguration();

        $this->includeUserBootstrap($config);

        $this->container->getFileSystem()->mkdir($config->getTmpDir());

        LogVerbosity::convertVerbosityLevel($this->input, $this->consoleOutput);

        $this->container->getSubscriberBuilder()->registerSubscribers(
            $this->container->getTestFrameworkAdapter(),
            $this->output
        );

        $this->container->getEventDispatcher()->dispatch(new ApplicationExecutionWasStarted());
    }

    private function runConfigurationCommand(Locator $locator): void
    {
        try {
            $locator->locateOneOf([
                SchemaConfigurationLoader::DEFAULT_CONFIG_FILE,
                SchemaConfigurationLoader::DEFAULT_DIST_CONFIG_FILE,
            ]);
        } catch (Exception $exception) {
            $configureCommand = $this->getApplication()->find('configure');

            $args = [
                '--test-framework' => $this->input->getOption('test-framework') ?: TestFrameworkTypes::PHPUNIT,
            ];

            $newInput = new ArrayInput($args);
            $newInput->setInteractive($this->input->isInteractive());
            $result = $configureCommand->run($newInput, $this->output);

            if ($result !== 0) {
                throw ConfigurationException::configurationAborted();
            }
        }
    }

    private function includeUserBootstrap(Configuration $config): void
    {
        $bootstrap = $config->getBootstrap();

        if ($bootstrap === null) {
            return;
        }

        if (!file_exists($bootstrap)) {
            throw FileOrDirectoryNotFound::fromFileName($bootstrap, [__DIR__]);
        }

        (static function (string $infectionBootstrapFile): void {
            require_once $infectionBootstrapFile;
        })($bootstrap);
    }
}