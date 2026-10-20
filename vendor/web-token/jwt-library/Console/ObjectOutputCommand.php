<?php

declare (strict_types=1);
namespace PortalPushVendor\Jose\Component\Console;

use PortalPushVendor\Jose\Component\Core\Util\JsonConverter;
use JsonSerializable;
use PortalPushVendor\Symfony\Component\Console\Command\Command;
use PortalPushVendor\Symfony\Component\Console\Input\InputInterface;
use PortalPushVendor\Symfony\Component\Console\Output\OutputInterface;
abstract class ObjectOutputCommand extends Command
{
    protected function prepareJsonOutput(InputInterface $input, OutputInterface $output, JsonSerializable $json): void
    {
        $data = JsonConverter::encode($json);
        $output->write($data);
    }
}
