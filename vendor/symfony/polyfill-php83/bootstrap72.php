<?php

namespace PortalPushVendor;

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
use PortalPushVendor\Symfony\Polyfill\Php83 as p;
if (!\class_exists('ValueError', \false)) {
    class ValueError extends \Error
    {
    }
    \class_alias('PortalPushVendor\ValueError', 'ValueError', \false);
}
if (\extension_loaded('mbstring')) {
    if (!\function_exists('mb_str_pad') && !\function_exists('PortalPushVendor\mb_str_pad')) {
        /** @return string|false */
        function mb_str_pad(?string $string, ?int $length, ?string $pad_string = ' ', ?int $pad_type = \STR_PAD_RIGHT, ?string $encoding = null)
        {
            return p\Php83::mb_str_pad((string) $string, (int) $length, (string) $pad_string, (int) $pad_type, $encoding);
        }
    }
}
