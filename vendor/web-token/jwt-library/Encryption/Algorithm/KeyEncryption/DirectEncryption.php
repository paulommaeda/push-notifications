<?php

declare (strict_types=1);
namespace PortalPushVendor\Jose\Component\Encryption\Algorithm\KeyEncryption;

use PortalPushVendor\Jose\Component\Core\JWK;
use PortalPushVendor\Jose\Component\Encryption\Algorithm\KeyEncryptionAlgorithm;
interface DirectEncryption extends KeyEncryptionAlgorithm
{
    /**
     * Returns the CEK.
     *
     * @param JWK $key The key used to get the CEK
     */
    public function getCEK(JWK $key): string;
}
