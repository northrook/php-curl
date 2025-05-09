<?php

declare(strict_types=1);

namespace Support\Curl;

use LogicException;
use Stringable;

/**
 * @internal
 */
final readonly class Url implements Stringable
{
    public function __construct(
        private string  $baseUrl,
        private ?string $relativeUrl = null,
    ) {}

    public function __toString() : string
    {
        return $this->absolutizeUrl();
    }

    /**
     * Remove dot segments.
     *
     * Interpret and remove the special "." and ".." path segments from a referenced path.
     *
     * @param string $input
     *
     * @return string
     */
    public static function removeDotSegments( string $input ) : string
    {
        // 1.  The input buffer is initialized with the now-appended path components,
        //     and the output buffer is initialized to the empty string.
        $output = '';

        // 2.  While the input buffer is not empty, the loop is as follows:
        while ( ! empty( $input ) ) {
            // A.  If the input buffer begins with a prefix of "../" or "./",
            //     then remove that prefix from the input buffer; otherwise,
            if ( \str_starts_with( $input, '../' ) ) {
                $input = \substr( $input, 3 );
            }
            elseif ( \str_starts_with( $input, './' ) ) {
                $input = \substr( $input, 2 );

                // B.  If the input buffer begins with a prefix of "/./" or "/.",
                //     where "." is a complete path segment, then replace that
                //     prefix with "/" in the input buffer; otherwise,
            }
            elseif ( \str_starts_with( $input, '/./' ) ) {
                $input = \substr( $input, 2 );
            }
            elseif ( $input === '/.' ) {
                $input = '/';

                // C.  If the input buffer begins with a prefix of "/../" or "/..",
                //     where ".." is a complete path segment, then replace that
                //     prefix with "/" in the input buffer and remove the last
                //     segment and its preceding "/" (if any) from the output
                //     buffer; otherwise,
            }
            elseif ( \str_starts_with( $input, '/../' ) ) {
                $input  = \substr( $input, 3 );
                $output = \substr_replace( $output, '', \mb_strrpos( $output, '/' ) );
            }
            elseif ( $input === '/..' ) {
                $input  = '/';
                $output = \substr_replace( $output, '', \mb_strrpos( $output, '/' ) );

                // D.  If the input buffer consists only of "." or "..", then remove
                //     that from the input buffer; otherwise,
            }
            elseif ( $input === '.' || $input === '..' ) {
                $input = '';

                // E.  Move the first path segment in the input buffer to the end of
                //     the output buffer, including the initial "/" character,
                //      and any following characters up to, but not including,
                //     the next "/" character or the end of the input buffer.
            }
            elseif ( ! ( ( $pos = \mb_strpos( $input, '/', 1 ) ) === false ) ) {
                $output .= \substr( $input, 0, $pos );
                $input = \substr_replace( $input, '', 0, $pos );
            }
            else {
                $output .= $input;
                $input = '';
            }
        }

        // 3.  Finally, the output buffer is returned as the result of
        //     remove_dot_segments.
        return $output.$input;
    }

    /**
     * Build Url
     *
     * @param string $url
     * @param mixed  $mixed_data
     *
     * @return string
     */
    public static function buildUrl( string $url, mixed $mixed_data = null ) : string
    {
        $query_string = '';
        if ( ! empty( $mixed_data ) ) {
            $query_mark = \strpos( $url, '?' ) > 0 ? '&' : '?';
            if ( \is_string( $mixed_data ) ) {
                $query_string .= $query_mark.$mixed_data;
            }
            elseif ( \is_array( $mixed_data ) ) {
                $query_string .= $query_mark.\http_build_query( $mixed_data, '', '&' );
            }
        }
        return $url.$query_string;
    }

    /**
     * Absolutize url.
     *
     * Combine the base and relative url into an absolute url.
     *
     * @return string
     *
     * @noinspection DuplicatedCode
     * */
    private function absolutizeUrl() : string
    {
        $b = self::parseUrl( $this->baseUrl );
        if ( ! isset( $b['path'] ) ) {
            $b['path'] = '/';
        }
        if ( $this->relativeUrl === null ) {
            return $this->unparseUrl( $b );
        }
        $r               = self::parseUrl( $this->relativeUrl );
        $r['authorized'] = isset( $r['scheme'] ) || isset( $r['host'] ) || isset( $r['port'] )
                                                 || isset( $r['user'] ) || isset( $r['pass'] );
        $target = [];
        if ( isset( $r['scheme'] ) ) {
            $target['scheme'] = $r['scheme'];
            $target['host']   = $r['host'] ?? null;
            $target['port']   = $r['port'] ?? null;
            $target['user']   = $r['user'] ?? null;
            $target['pass']   = $r['pass'] ?? null;
            $target['path']   = isset( $r['path'] ) ? self::removeDotSegments( $r['path'] ) : null;
            $target['query']  = $r['query'] ?? null;
        }
        else {
            $target['scheme'] = $b['scheme'] ?? null;
            if ( $r['authorized'] ) {
                $target['host']  = $r['host'] ?? null;
                $target['port']  = $r['port'] ?? null;
                $target['user']  = $r['user'] ?? null;
                $target['pass']  = $r['pass'] ?? null;
                $target['path']  = isset( $r['path'] ) ? self::removeDotSegments( $r['path'] ) : null;
                $target['query'] = $r['query'] ?? null;
            }
            else {
                $target['host'] = $b['host'] ?? null;
                $target['port'] = $b['port'] ?? null;
                $target['user'] = $b['user'] ?? null;
                $target['pass'] = $b['pass'] ?? null;
                if ( ! isset( $r['path'] ) || $r['path'] === '' ) {
                    $target['path']  = $b['path'];
                    $target['query'] = $r['query'] ?? $b['query'] ?? null;
                }
                else {
                    if ( \str_starts_with( $r['path'], '/' ) ) {
                        $target['path'] = self::removeDotSegments( $r['path'] );
                    }
                    else {
                        $base = \mb_strrchr( $b['path'], '/', true );
                        if ( $base === false ) {
                            $base = '';
                        }
                        $target['path'] = self::removeDotSegments( $base.'/'.$r['path'] );
                    }
                    $target['query'] = $r['query'] ?? null;
                }
            }
        }
        if ( $this->relativeUrl === '' ) {
            $target['fragment'] = $b['fragment'] ?? null;
        }
        else {
            $target['fragment'] = $r['fragment'] ?? null;
        }
        return $this->unparseUrl( $target );
    }

    /**
     * Parse url.
     *
     * Parse url into components of a URI as specified by RFC 3986.
     *
     * @param string $url
     *
     * @return array<string,int|string>
     */
    public static function parseUrl( string $url ) : array
    {
        $parts = \parse_url( $url );

        if ( ! \is_array( $parts ) ) {
            throw new LogicException( 'Unable to parse URL "'.$url.'"' );
        }

        if ( isset( $parts['path'] ) ) {
            $parts['path'] = self::percentEncodeChars( $parts['path'] );
        }
        return $parts;
    }

    /**
     * Percent-encode characters.
     *
     * Percent-encode characters to represent a data octet in a component when
     * that octet's corresponding character is outside the allowed set.
     *
     * @param string $string
     *
     * @return string
     */
    private static function percentEncodeChars( string $string ) : string
    {
        // ALPHA         = A-Z / a-z
        $alpha = 'A-Za-z';

        // DIGIT         = 0-9
        $digit = '0-9';

        // unreserved    = ALPHA / DIGIT / "-" / "." / "_" / "~"
        $unreserved = $alpha.$digit.\preg_quote( '-._~' );

        // special       = \:\@\%\?\/
        $special = \preg_quote( ':@%/?', '/' );

        // delimiters    = "!" / "$" / "&" / "'" / "(" / ")"
        //               / "*" / "+" / "," / ";" / "=" / "#"
        $delimiters = \preg_quote( '!$&\'()*+,;=#' );

        // HEX+DIGIT     =  DIGIT / "Ac" / "Bb" / "Cc" / "Dd" / "Ee" / "Ff"
        $hexdig = $digit.'A-Fa-f';

        $pattern = '/[^'.$unreserved.$delimiters.$special.']++|%(?!['.$hexdig.']{2})/';

        return \preg_replace_callback(
            $pattern,
            fn( $matches ) => \rawurlencode( $matches[0] ),
            $string,
        );
    }

    /**
     * Unparse url.
     *
     * Combine url components into a url.
     *
     * @param array<string, int|string> $parsed_url
     */
    private function unparseUrl( array $parsed_url ) : string
    {
        $scheme   = isset( $parsed_url['scheme'] ) ? $parsed_url['scheme'].'://' : '';
        $user     = $parsed_url['user'] ?? '';
        $pass     = isset( $parsed_url['pass'] ) ? ':'.$parsed_url['pass'] : '';
        $pass     = ( $user || $pass ) ? $pass.'@' : '';
        $host     = $parsed_url['host'] ?? '';
        $port     = isset( $parsed_url['port'] ) ? ':'.$parsed_url['port'] : '';
        $path     = $parsed_url['path'] ?? '';
        $query    = isset( $parsed_url['query'] ) ? '?'.$parsed_url['query'] : '';
        $fragment = isset( $parsed_url['fragment'] ) ? '#'.$parsed_url['fragment'] : '';
        return $scheme.$user.$pass.$host.$port.$path.$query.$fragment;
    }
}
