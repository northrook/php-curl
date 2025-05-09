<?php

namespace Support\cURL;

use InvalidArgumentException;
use Support\cURL;

final class CurlOptionException extends InvalidArgumentException
{
    public function __construct(
        int|string $option,
        mixed      $value,
        ?string    $message = null,
    ) {
        if ( \is_int( $option ) ) {
            $option = cURL::getOptionsArray( true )[$option] ?? "Unknown[{$option}]";
        }

        $message ??= "Unable to set cURL option '{$option}' with value '".\var_export( $value, true )."'.";
        parent::__construct( $message );
    }
}
