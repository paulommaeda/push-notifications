<?php

declare (strict_types=1);
namespace PortalPushVendor\Jose\Component\Console;

use InvalidArgumentException;
use PortalPushVendor\Jose\Component\Core\JWKSet;
use PortalPushVendor\Jose\Component\KeyManagement\JWKFactory;
use Override;
use PortalPushVendor\Symfony\Component\Console\Attribute\AsCommand;
use PortalPushVendor\Symfony\Component\Console\Input\InputArgument;
use PortalPushVendor\Symfony\Component\Console\Input\InputInterface;
use PortalPushVendor\Symfony\Component\Console\Output\OutputInterface;
use function is_string;
#[AsCommand(name: 'keyset:generate:ec', description: 'Generate an EC key set (JWKSet format)')]
final class EcKeysetGeneratorCommand extends GeneratorCommand
{
    #[Override]
    protected function configure(): void
    {
        parent::configure();
        $this->addArgument('quantity', InputArgument::REQUIRED, 'Quantity of keys in the key set.')->addArgument('curve', InputArgument::REQUIRED, 'Curve of the keys.');
    }
    #[Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $quantity = (int) $input->getArgument('quantity');
        if ($quantity < 1) {
            throw new InvalidArgumentException('Invalid quantity');
        }
        $curve = $input->getArgument('curve');
        if (!is_string($curve)) {
            throw new InvalidArgumentException('Invalid curve');
        }
        $keyset = new JWKSet([]);
        for ($i = 0; $i < $quantity; ++$i) {
            $args = $this->getOptions($input);
            $keyset = $keyset->with(JWKFactory::createECKey($curve, $args));
        }
        $this->prepareJsonOutput($input, $output, $keyset);
        return self::SUCCESS;
    }
}
