<?php

declare (strict_types=1);
namespace PortalPushVendor\Jose\Component\Encryption;

use PortalPushVendor\Jose\Component\Checker\HeaderCheckerManagerFactory;
use PortalPushVendor\Jose\Component\Encryption\Serializer\JWESerializerManagerFactory;
readonly class JWELoaderFactory
{
    public function __construct(private JWESerializerManagerFactory $jweSerializerManagerFactory, private JWEDecrypterFactory $jweDecrypterFactory, private ?HeaderCheckerManagerFactory $headerCheckerManagerFactory)
    {
    }
    public function create(array $serializers, array $encryptionAlgorithms, array $headerCheckers = []): JWELoader
    {
        $serializerManager = $this->jweSerializerManagerFactory->create($serializers);
        $jweDecrypter = $this->jweDecrypterFactory->create($encryptionAlgorithms);
        if ($this->headerCheckerManagerFactory !== null) {
            $headerCheckerManager = $this->headerCheckerManagerFactory->create($headerCheckers);
        } else {
            $headerCheckerManager = null;
        }
        return new JWELoader($serializerManager, $jweDecrypter, $headerCheckerManager);
    }
}
