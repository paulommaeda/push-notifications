<?php

declare (strict_types=1);
namespace PortalPushVendor\Jose\Component\KeyManagement\Analyzer;

use PortalPushVendor\Jose\Component\Core\JWKSet;
interface KeysetAnalyzer
{
    /**
     * This method will analyse the key set and add messages to the message bag if needed.
     */
    public function analyze(JWKSet $JWKSet, MessageBag $bag): void;
}
