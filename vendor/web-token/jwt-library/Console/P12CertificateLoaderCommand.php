<?php

declare (strict_types=1);
namespace PortalPushVendor\Jose\Component\Console;

use InvalidArgumentException;
use PortalPushVendor\Jose\Component\KeyManagement\JWKFactory;
use Override;
use PortalPushVendor\Symfony\Component\Console\Attribute\AsCommand;
use PortalPushVendor\Symfony\Component\Console\Input\InputArgument;
use PortalPushVendor\Symfony\Component\Console\Input\InputInterface;
use PortalPushVendor\Symfony\Component\Console\Input\InputOption;
use PortalPushVendor\Symfony\Component\Console\Output\OutputInterface;
use function is_string;
#[AsCommand(name: 'key:load:p12', description: 'Load a key from a P12 certificate file.')]
final class P12CertificateLoaderCommand extends GeneratorCommand
{
    #[Override]
    protected function configure(): void
    {
        parent::configure();
        $this->addArgument('file', InputArgument::REQUIRED, 'Filename of the P12 certificate.')->addOption('secret', 's', InputOption::VALUE_OPTIONAL, 'Secret if the key is encrypted.');
    }
    #[Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $file = $input->getArgument('file');
        $password = $input->getOption('secret');
        if (!is_string($file)) {
            throw new InvalidArgumentException('Invalid file');
        }
        if (!is_string($password)) {
            throw new InvalidArgumentException('Invalid secret');
        }
        $args = $this->getOptions($input);
        $jwk = JWKFactory::createFromPKCS12CertificateFile($file, $password, $args);
        $this->prepareJsonOutput($input, $output, $jwk);
        return self::SUCCESS;
    }
}
