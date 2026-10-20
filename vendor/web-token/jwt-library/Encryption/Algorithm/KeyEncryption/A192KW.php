<?php

declare (strict_types=1);
namespace PortalPushVendor\Jose\Component\Encryption\Algorithm\KeyEncryption;

use PortalPushVendor\AESKW\A192KW as Wrapper;
use PortalPushVendor\AESKW\Wrapper as WrapperInterface;
use Override;
final readonly class A192KW extends AESKW
{
    #[Override]
    public function name(): string
    {
        return 'A192KW';
    }
    #[Override]
    protected function getWrapper(): WrapperInterface
    {
        return new Wrapper();
    }
}
