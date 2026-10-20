<?php

declare (strict_types=1);
namespace PortalPushVendor\Jose\Component\Console;

use InvalidArgumentException;
use PortalPushVendor\Jose\Component\KeyManagement\JWKFactory;
use Override;
use PortalPushVendor\Symfony\Component\Console\Attribute\AsCommand;
use PortalPushVendor\Symfony\Component\Console\Input\InputArgument;
use PortalPushVendor\Symfony\Component\Console\Input\InputInterface;
use PortalPushVendor\Symfony\Component\Console\Output\OutputInterface;
#[AsCommand(name: 'key:generate:oct', description: 'Generate an octet key (JWK format)')]
final class OctKeyGeneratorCommand extends GeneratorCommand
{
    #[Override]
    protected function configure(): void
    {
        parent::configure();
        $this->addArgument('size', InputArgument::REQUIRED, 'Key size.');
    }
    #[Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $size = (int) $input->getArgument('size');
        if ($size < 1) {
            throw new InvalidArgumentException('Invalid size');
        }
        $args = $this->getOptions($input);
        $jwk = JWKFactory::createOctKey($size, $args);
        $this->prepareJsonOutput($input, $output, $jwk);
        return self::SUCCESS;
    }
}
