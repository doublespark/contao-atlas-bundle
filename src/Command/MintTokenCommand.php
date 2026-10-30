<?php

declare(strict_types=1);

namespace Doublespark\ContaoAtlasBundle\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Yaml\Yaml;

/**
 * Generates the site's API token, stores only its sha256 in config/atlas_api.yaml, and prints the token once to stdout.
 * Everything else this command says goes to stderr, so a caller capturing stdout gets the token and nothing else.
 */
#[AsCommand(name: 'atlas:mint-token', description: 'Generate the Atlas API token for this site and print it once')]
class MintTokenCommand extends Command
{
    public function __construct(private readonly string $projectDir)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('rotate', null, InputOption::VALUE_NONE, 'Replace a token that already exists');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $err = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
        $file = $this->projectDir.'/config/atlas_api.yaml';

        if (is_file($file) && !$input->getOption('rotate')) {
            $err->writeln('A token already exists for this site. Use --rotate to replace it; the old one stops working.');

            return Command::FAILURE;
        }

        $token = bin2hex(random_bytes(32));
        $yaml = Yaml::dump(['doublespark_contao_atlas' => ['token_hash' => hash('sha256', $token)]], 4);
        (new Filesystem())->dumpFile($file, $yaml);
        @chmod($file, 0600);

        $err->writeln('Token stored (hash only) in config/atlas_api.yaml. Clear the cache so the site picks it up.');
        $output->write($token."\n");

        return Command::SUCCESS;
    }
}
