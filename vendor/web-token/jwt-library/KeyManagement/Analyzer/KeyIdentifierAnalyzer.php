<?php

declare (strict_types=1);
namespace PortalPushVendor\Jose\Component\KeyManagement\Analyzer;

use PortalPushVendor\Jose\Component\Core\JWK;
use Override;
final readonly class KeyIdentifierAnalyzer implements KeyAnalyzer
{
    #[Override]
    public function analyze(JWK $jwk, MessageBag $bag): void
    {
        if (!$jwk->has('kid')) {
            $bag->add(Message::medium('The parameter "kid" should be added.'));
        }
    }
}
