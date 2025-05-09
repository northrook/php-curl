<?php

declare(strict_types=1);

namespace Support\cURL;

/**
 * @internal
 */
final class Decoder
{
    /**
     * Decode `JSON`.
     *
     * @param string    $json
     * @param null|bool $associative
     * @param int       $depth
     * @param int       $flags
     *
     * @return mixed
     */
    public static function decodeJson(
        string $json,
        ?bool  $associative = null,
        int    $depth = 512,
        int    $flags = 0,
    ) : mixed {
        $args     = \func_get_args();
        $response = \call_user_func_array( 'json_decode', $args );
        if ( $response === null && isset( $args[0] ) ) {
            $response = $args[0];
        }
        return $response;
    }

    /**
     * Decode `XML`.
     *
     * @template XMLObject of object
     *
     * @param string                       $data
     * @param null|class-string<XMLObject> $class_name
     * @param int                          $options
     * @param string                       $namespace_or_prefix
     * @param bool                         $is_prefix
     *
     * @return false|XMLObject
     */
    public static function decodeXml(
        string  $data,
        ?string $class_name = 'SimpleXMLElement',
        int     $options = 0,
        string  $namespace_or_prefix = '',
        bool    $is_prefix = false,
    ) : mixed {
        $args     = \func_get_args();
        $response = @\call_user_func_array( 'simplexml_load_string', $args );
        if ( $response === false && \array_key_exists( 0, $args ) ) {
            $response = $args[0];
        }
        return $response;
    }
}
