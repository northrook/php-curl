<?php

namespace Support\Curl;

use Support\Curl;
use InvalidArgumentException;

final class CurlOptionException extends InvalidArgumentException
{
    public function __construct(
        int|string $option,
        mixed      $value,
        ?string    $message = null,
    ) {
        if ( \is_int( $option ) ) {
            $option = Curl::getOptionsArray()[$option] ?? "Unknown[{$option}]";
        }

        $message ??= "Unable to set cURL option '{$option}' with value '".\var_export( $value, true )."'.";
        parent::__construct( $message );
    }
}
