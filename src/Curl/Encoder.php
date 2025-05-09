<?php

declare(strict_types=1);

namespace Support\Curl;

use RuntimeException;

/**
 * @internal
 */
final class Encoder
{
    /**
     * Encode JSON
     *
     * Wrap `json_encode()` to throw an error when the value being encoded fails.
     *
     * @param mixed $value
     * @param int   $flags
     * @param int   $depth
     *
     * @return string
     */
    public static function encodeJson( mixed $value, int $flags = 0, int $depth = 512 ) : string
    {
        $args  = \func_get_args();
        $value = \call_user_func_array( 'json_encode', $args );
        if ( \json_last_error() !== JSON_ERROR_NONE ) {
            $error_message = 'json_encode error: '.\json_last_error_msg();
            throw new RuntimeException( $error_message );
        }
        return $value;
    }
}
