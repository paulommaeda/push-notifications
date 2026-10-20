<?php

declare (strict_types=1);
namespace PortalPushVendor\SpomkyLabs\Pki\CryptoTypes\Asymmetric;

use PortalPushVendor\SpomkyLabs\Pki\ASN1\Type\Primitive\BitString;
use PortalPushVendor\SpomkyLabs\Pki\CryptoTypes\AlgorithmIdentifier\Feature\AlgorithmIdentifierType;
use PortalPushVendor\SpomkyLabs\Pki\CryptoTypes\Asymmetric\Attribute\OneAsymmetricKeyAttributes;
/**
 * PrivateKeyInfo was first introduced in RFC 5208, but later refined as OneAsymmetricKey in RFC 5958 with backwards
 * compatibility.
 *
 * Thus `PrivateKeyInfo ::= OneAsymmetricKey`
 *
 * @see https://tools.ietf.org/html/rfc5208#section-5
 * @see https://tools.ietf.org/html/rfc5958#section-2
 */
final class PrivateKeyInfo extends OneAsymmetricKey
{
    // PrivateKeyInfo has version 1 by default
    public static function create(AlgorithmIdentifierType $algo, string $key, ?OneAsymmetricKeyAttributes $attributes = null, ?BitString $public_key = null): self
    {
        return new self($algo, $key, $attributes, $public_key, parent::VERSION_1);
    }
}
