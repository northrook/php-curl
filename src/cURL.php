<?php

/** @noinspection SpellCheckingInspection */

declare(strict_types=1);

namespace Support;

use ArrayAccess;
use CURLFile;
use CurlHandle;
use CURLStringFile;
use ErrorException;
use JsonSerializable;
use Random\RandomException;
use stdClass;
use BadMethodCallException;
use LogicException;

use Support\cURL\{BaseCurl, ArrayData, CurlException, CurlOptionException, Encoder, MultiCurl, Url};

class cURL extends BaseCurl
{
    public const DEFAULT_TIMEOUT = 30;

    /** @var array<string,bool> */
    private static array $probeCache = [];

    /** @var string[] */
    public static array $RFC2616 = [
        // RFC 2616: "any CHAR except CTLs or separators".
        // CHAR           = <any US-ASCII character (octets 0 - 127)>
        // CTL            = <any US-ASCII control character
        //                  (octets 0 - 31) and DEL (127)>
        // separators     = "(" | ")" | "<" | ">" | "@"
        //                | "," | ";" | ":" | "\" | <">
        //                | "/" | "[" | "]" | "?" | "="
        //                | "{" | "}" | SP | HT
        // SP             = <US-ASCII SP, space (32)>
        // HT             = <US-ASCII HT, horizontal-tab (9)>
        // <">            = <US-ASCII double-quote mark (34)>
        '!',
        '#',
        '$',
        '%',
        '&',
        "'",
        '*',
        '+',
        '-',
        '.',
        '0',
        '1',
        '2',
        '3',
        '4',
        '5',
        '6',
        '7',
        '8',
        '9',
        'A',
        'B',
        'C',
        'D',
        'E',
        'F',
        'G',
        'H',
        'I',
        'J',
        'K',
        'L',
        'M',
        'N',
        'O',
        'P',
        'Q',
        'R',
        'S',
        'T',
        'U',
        'V',
        'W',
        'X',
        'Y',
        'Z',
        '^',
        '_',
        '`',
        'a',
        'b',
        'c',
        'd',
        'e',
        'f',
        'g',
        'h',
        'i',
        'j',
        'k',
        'l',
        'm',
        'n',
        'o',
        'p',
        'q',
        'r',
        's',
        't',
        'u',
        'v',
        'w',
        'x',
        'y',
        'z',
        '|',
        '~',
    ];

    /** @var string[] */
    public static array $RFC6265 = [
        // RFC 6265: "US-ASCII characters excluding CTLs, whitespace DQUOTE, comma, semicolon, and backslash".
        // %x21
        '!',
        // %x23-2B
        '#',
        '$',
        '%',
        '&',
        "'",
        '(',
        ')',
        '*',
        '+',
        // %x2D-3A
        '-',
        '.',
        '/',
        '0',
        '1',
        '2',
        '3',
        '4',
        '5',
        '6',
        '7',
        '8',
        '9',
        ':',
        // %x3C-5B
        '<',
        '=',
        '>',
        '?',
        '@',
        'A',
        'B',
        'C',
        'D',
        'E',
        'F',
        'G',
        'H',
        'I',
        'J',
        'K',
        'L',
        'M',
        'N',
        'O',
        'P',
        'Q',
        'R',
        'S',
        'T',
        'U',
        'V',
        'W',
        'X',
        'Y',
        'Z',
        '[',
        // %x5D-7E
        ']',
        '^',
        '_',
        '`',
        'a',
        'b',
        'c',
        'd',
        'e',
        'f',
        'g',
        'h',
        'i',
        'j',
        'k',
        'l',
        'm',
        'n',
        'o',
        'p',
        'q',
        'r',
        's',
        't',
        'u',
        'v',
        'w',
        'x',
        'y',
        'z',
        '{',
        '|',
        '}',
        '~',
    ];

    /** @var string[] */
    private static array $deferredProperties = [
        'curlErrorCodeConstant',
        'curlErrorCodeConstants',
        'curlOptionCodeConstants',
        'effectiveUrl',
        'rfc2616',
        'rfc6265',
        'totalTime',
    ];

    public null|false|CurlHandle $curl;

    public string $id;

    public bool $error = false;

    public int $errorCode = 0;

    public ?string $errorMessage;

    public bool $curlError = false;

    public int $curlErrorCode = 0;

    public ?string $curlErrorMessage;

    public bool $httpError = false;

    public int $httpStatusCode = 0;

    public ?string $httpErrorMessage;

    public ?string $url = null;

    /** @var null|array<array-key,mixed>|ArrayAccess<array-key,mixed> */
    public null|array|ArrayAccess $requestHeaders = [];

    /** @var null|array<array-key,mixed>|ArrayAccess<array-key,mixed> */
    public null|array|ArrayAccess $responseHeaders;

    public string $rawResponseHeaders = '';

    /** @var array<array-key,mixed> */
    public array $responseCookies = [];

    public mixed $response;

    public mixed $rawResponse;

    /** @var ?callable */
    public mixed $downloadCompleteCallback;

    /** @var null|false|resource */
    public mixed $fileHandle = null;

    public ?string $downloadFileName;

    public int $attempts = 0;

    public int $retries = 0;

    public bool $childOfMultiCurl = false;

    public int $remainingRetries = 0;

    /** @var ?callable */
    public mixed $retryDecider = null;

    /** @var ?callable */
    public mixed $jsonDecoder = null;

    /** @var ?callable */
    public mixed $xmlDecoder = null;

    /** @var null|callable|false */
    private mixed $defaultDecoder = null;

    private ?stdClass $headerCallbackData;

    /** @var array<array-key,mixed> */
    private array $cookies = [];

    /** @var array<array-key,mixed>|ArrayAccess<array-key,mixed> */
    private array|ArrayAccess $headers = [];

    /** @var ?array<array-key,mixed> */
    private ?array $jsonDecoderArgs = [];

    /** @var ?array<array-key,mixed> */
    private ?array $xmlDecoderArgs = [];

    private string $jsonPattern = '/^(?:application|text)\/(?:[a-z]+(?:[.-][0-9a-z]+)*[+.]|x-)?json(?:-[a-z]+)?/i';

    private string $xmlPattern = '~^(?:text/|application/(?:atom\+|rss\+|soap\+)?)xml~i';

    public ?string $curlErrorCodeConstant = null;

    /** @var array<array-key, ?string> */
    public array $curlErrorCodeConstants;

    /** @var array<array-key,mixed> */
    private array $deferredValues = [];

    /**
     * Construct
     *
     * @param ?string $baseUrl
     * @param mixed   $options
     * @param ?string $tempDirectory
     */
    public function __construct(
        ?string $baseUrl = null,
        mixed   $options = [],
        ?string $tempDirectory = null,
    ) {
        if ( ! \extension_loaded( 'curl' ) ) {
            throw new LogicException( 'cURL library is not loaded' );
        }

        unset( $this->deferredValues['curlErrorCodeConstant'], $this->deferredValues['curlErrorCodeConstants'], $this->deferredValues['curlOptionCodeConstants'], $this->deferredValues['effectiveUrl'], $this->deferredValues['rfc2616'], $this->deferredValues['rfc6265'], $this->deferredValues['totalTime'] );

        $this->curl = \curl_init();
        $this->tempDirectory( $tempDirectory );
        $this->initialize( $baseUrl, $options );
    }

    public static function from(
        ?string $baseUrl = null,
        mixed   $options = [],
        ?string $tempDirectory = null,
    ) : cURL {
        return new cURL( $baseUrl, $options, $tempDirectory );
    }

    /**
     * Initialize
     *
     * @param ?string $baseUrl
     * @param mixed   $options
     */
    private function initialize(
        ?string $baseUrl = null,
        mixed   $options = [],
    ) : void {
        $this->setProtocolsInternal( CURLPROTO_HTTPS | CURLPROTO_HTTP );
        $this->setRedirectProtocolsInternal( CURLPROTO_HTTPS | CURLPROTO_HTTP );

        if ( ! empty( $options ) ) {
            $this->setOpts( $options );
        }

        try {
            $key = \random_bytes( 7 );
        }
        catch ( RandomException ) {
            $key = (string) \rand( 0, PHP_INT_MAX );
        }

        $this->id = \hash( 'xxh64', $key );

        // Only set the default user agent if not already set.
        if ( ! \array_key_exists( CURLOPT_USERAGENT, $this->options ) ) {
            $this->setDefaultUserAgentInternal();
        }

        // Only set the default timeout if not already set.
        if ( ! \array_key_exists( CURLOPT_TIMEOUT, $this->options ) ) {
            $this->setDefaultTimeoutInternal();
        }

        if ( ! \array_key_exists( CURLINFO_HEADER_OUT, $this->options ) ) {
            $this->setDefaultHeaderOutInternal();
        }

        // Create a placeholder to temporarily store the header callback data.
        $header_callback_data                     = new stdClass();
        $header_callback_data->rawResponseHeaders = '';
        $header_callback_data->responseCookies    = [];
        $header_callback_data->stopRequestDecider = null;
        $header_callback_data->stopRequest        = false;
        $this->headerCallbackData                 = $header_callback_data;
        $this->setStopInternal();
        $this->setOptInternal( CURLOPT_HEADERFUNCTION, createHeaderCallback( $header_callback_data ) );

        $this->setOptInternal( CURLOPT_RETURNTRANSFER, true );
        $this->headers = new ArrayData();

        if ( $baseUrl !== null ) {
            $this->setUrl( $baseUrl );
        }
    }

    /**
     * Build Post-Data
     *
     * @param mixed       $data
     * @param bool|string $prefix
     *
     * @return mixed
     */
    public function buildPostData(
        mixed       $data,
        bool|string $prefix = false,
    ) : mixed {
        $binary_data = false;

        // Return JSON-encoded string when the request's content-type is JSON and the data is serializable.
        if (
            isset( $this->headers['Content-Type'] )
            && \preg_match( $this->jsonPattern, $this->headers['Content-Type'] )
            && (
                \is_array( $data )
                    || (
                        \is_object( $data )
                            && \interface_exists( 'JsonSerializable', false )
                            && $data instanceof JsonSerializable
                    )
            )
        ) {
            $data = Encoder::encodeJson( $data );
        }
        elseif ( \is_array( $data ) ) {
            /**
             * Manually build a single-dimensional array from a multidimensional array as using
             * {@see curl_setopt}`($ch, CURLOPT_POSTFIELDS, $data)` doesn't correctly handle
             * multidimensional arrays when files are referenced. */
            if ( $this->multidimensionalArray( $data ) ) {
                $data = $this->flattenDataArray( $data );
            }

            // Modify array values to ensure any referenced files are properly handled depending on the support of
            // the @filename API or CURLFile usage. This also fixes the warning "curl_setopt(): The usage of the
            // @filename API for file uploading is deprecated. Please use the CURLFile class instead". Ignore
            // non-file values prefixed with the @ character.
            foreach ( $data as $key => $value ) {
                if ( \is_string( $value ) && \str_starts_with( $value, '@' ) && \is_file( \substr( $value, 1 ) ) ) {
                    $binary_data = true;
                    if ( \class_exists( 'CURLFile' ) ) {
                        $data[$key] = new CURLFile( \substr( $value, 1 ) );
                    }
                }
                elseif ( $value instanceof CURLFile ) {
                    $binary_data = true;
                }
            }
        }

        if (
            ! $binary_data
            && ( \is_array( $data ) || \is_object( $data ) )
            && (
                ! isset( $this->headers['Content-Type'] )
                    || ! \preg_match( '/^multipart\/form-data/', $this->headers['Content-Type'] )
            )
        ) {
            // Avoid using http_build_query() as keys with null values are
            // unexpectedly excluded from the resulting string.
            //
            // $ php -a
            // php > echo http_build_query(['a' => '1', 'b' => null, 'c' => '3']);
            // a=1&c=3
            // php > echo http_build_query(['a' => '1', 'b' => '',   'c' => '3']);
            // a=1&b=&c=3
            //
            // $data = http_build_query($data, '', '&');
            $data = \implode(
                '&',
                \array_map(
                    function( $k, $v ) {
                        // Encode keys and values using urlencode() to match the default
                        // behavior http_build_query() where $encoding_type is
                        // PHP_QUERY_RFC1738.
                        //
                        // Use strval() as urlencode() expects a string parameter:
                        //   TypeError: urlencode() expects parameter 1 to be string, integer given
                        //   TypeError: urlencode() expects parameter 1 to be string, null given
                        //
                        // php_raw_url_encode()
                        // php_url_encode()
                        // https://github.com/php/php-src/blob/master/ext/standard/http.c
                        return \urlencode( \strval( $k ) ).'='.\urlencode( \strval( $v ) );
                    },
                    \array_keys( (array) $data ),
                    \array_values( (array) $data ),
                ),
            );
        }

        return $data;
    }

    /**
     * Call
     */
    public function call() : void
    {
        $args     = \func_get_args();
        $function = \array_shift( $args );
        if ( \is_callable( $function ) ) {
            \array_unshift( $args, $this );
            \call_user_func_array( $function, $args );
        }
    }

    /**
     * Close
     */
    public function close() : void
    {
        if ( \is_resource( $this->curl ) || $this->curl instanceof CurlHandle ) {
            \curl_close( $this->curl );
        }
        $this->curl               = null;
        $this->options            = null;
        $this->userSetOptions     = null;
        $this->jsonDecoder        = null;
        $this->jsonDecoderArgs    = null;
        $this->xmlDecoder         = null;
        $this->xmlDecoderArgs     = null;
        $this->headerCallbackData = null;
        $this->defaultDecoder     = null;
    }

    /**
     * Progress
     *
     * @param $callback callable|null
     */
    public function progress( mixed $callback ) : void
    {
        $this->setOpt( CURLOPT_PROGRESSFUNCTION, $callback );
        $this->setOpt( CURLOPT_NOPROGRESS, false );
    }

    /**
     * Progress
     *
     * @internal
     *
     * @param $callback callable|null
     */
    private function progressInternal( mixed $callback ) : void
    {
        $this->setOptInternal( CURLOPT_PROGRESSFUNCTION, $callback );
        $this->setOptInternal( CURLOPT_NOPROGRESS, false );
    }

    /**
     * @param string $url
     * @param int    $timeout
     * @param bool   $throwOnError
     * @param bool   $cached
     *
     * @return bool
     * @throws CurlException
     */
    public static function probe(
        string $url,
        int    $timeout = 5,
        bool   $throwOnError = false,
        bool   $cached = true,
    ) : bool {
        if ( $cached && ( self::$probeCache[$url] ?? false ) ) {
            return true;
        }

        $curl = new cURL( $url );

        $curl->setOptions(
            CURLOPT_NOBODY         : true,
            CURLOPT_TIMEOUT        : $timeout,
            CURLOPT_FOLLOWLOCATION : true,
            CURLOPT_FAILONERROR    : true,
            CURLOPT_RETURNTRANSFER : true,
        );

        $curl->exec();

        if ( $curl->error ) {
            if ( $throwOnError ) {
                throw new CurlException(
                    $curl->httpStatusCode,
                    $curl->errorMessage,
                );
            }
            self::$probeCache[$url] = false;
            return false;
        }

        $success = $curl->httpStatusCode >= 200 && $curl->httpStatusCode < 400;

        self::$probeCache[$url] = $success;

        return $success;
    }

    /**
     * Delete
     *
     * @param string|string[] $url
     * @param mixed           $query_parameters
     * @param mixed           $data
     *
     * @return mixed returns the value provided by exec
     */
    public function delete(
        string|array $url,
        mixed        $query_parameters = [],
        mixed        $data = [],
    ) : mixed {
        $this->setDelete( $url, $query_parameters, $data );
        return $this->exec();
    }

    public function setDelete(
        string|array $url,
        mixed        $query_parameters = [],
        mixed        $data = [],
    ) : void {
        if ( \is_array( $url ) ) {
            $data             = $query_parameters;
            $query_parameters = $url;
            $url              = (string) $this->url;
        }

        $this->setUrl( $url, $query_parameters );
        $this->setOpt( CURLOPT_CUSTOMREQUEST, 'DELETE' );

        // Avoid including a content-length header in DELETE requests unless there is a message body. The following
        // would include "Content-Length: 0" in the request header:
        //   curl_setopt($ch, CURLOPT_POSTFIELDS, []);
        // RFC 2616 4.3 Message Body:
        //   The presence of a message-body in a request is signaled by the
        //   inclusion of a Content-Length or Transfer-Encoding header field in
        //   the request's message-headers.
        if ( ! empty( $data ) ) {
            $this->setOpt( CURLOPT_POSTFIELDS, $this->buildPostData( $data ) );
        }
    }

    /**
     * Download
     *
     * @param string          $url
     * @param callable|string $location
     *
     * @return bool
     */
    public function download(
        string          $url,
        string|callable $location,
    ) : bool {
        /** Use {@see tmpfile()} or php://temp to avoid "Too many open files" error. */
        if ( \is_callable( $location ) ) {
            $this->downloadCompleteCallback = $location;
            $this->downloadFileName         = null;
            $this->fileHandle               = \tmpfile();
        }
        else {
            $filename = $location;

            /**
             * Use a temporary file when downloading.
             *
             * This prevents atomicity erros.
             *
             * The download request will include the header "Range: bytes=$filesize-" to facilitate resuming partial downloads.
             */
            $tempFile = $this->tempFile( $location );

            $this->downloadFileName = $tempFile;

            // Attempt to resume download only when a temporary download file exists and is not empty.
            if ( \is_file( $tempFile ) && $filesize = \filesize( $tempFile ) ) {
                $first_byte_position = $filesize;
                $range               = $first_byte_position.'-';
                $this->setRange( $range );
                $this->fileHandle = \fopen( $tempFile, 'ab' );
            }
            else {
                $this->fileHandle = \fopen( $tempFile, 'wb' );
            }

            // Move the downloaded temporary file to the destination save-path.
            $this->downloadCompleteCallback = function( $instance, $fh ) use ( $tempFile, $filename ) {
                // Close the open file handle before renaming the file.
                if ( \is_resource( $fh ) ) {
                    \fclose( $fh );
                }

                $this->copyFile( $tempFile, $filename );
                $this->removeFile( $tempFile );
            };
        }

        $this->setFile( $this->fileHandle );
        $this->get( $url );

        return ! $this->error;
    }

    /**
     * Fast download
     *
     * @param     $url
     * @param     $filename
     * @param int $connections
     *
     * @return bool
     * @throws ErrorException
     */
    public function fastDownload(
            $url,
            $filename,
        int $connections = 4,
    ) : bool {
        // Retrieve content length from the "Content-Length" header from the url
        // to download. Use an HTTP GET request without a body instead of a HEAD
        // request because not all hosts support HEAD requests.
        $curl = new cURL();
        $curl->setOptInternal( CURLOPT_NOBODY, true );

        // Pass user-specified options to the instance checking for content-length.
        $curl->setOpts( $this->userSetOptions );
        $curl->get( $url );

        // Exit early when an error occurred.
        if ( $curl->error ) {
            return false;
        }

        $content_length = $curl->responseHeaders['Content-Length'] ?? null;

        // Use a regular download when content length could not be determined.
        if ( ! $content_length ) {
            return $this->download( $url, $filename );
        }

        // Divide chunk_size across the number of connections.
        $chunk_size = (int) \ceil( $content_length / $connections );

        // Keep track of file name parts.
        $part_file_names = [];

        $multi_curl = new MultiCurl();
        $multi_curl->setConcurrency( $connections );

        for ( $part_number = 1; $part_number <= $connections; $part_number++ ) {
            $range_start = ( $part_number - 1 ) * $chunk_size;
            $range_end   = $range_start + $chunk_size - 1;
            if ( $part_number === $connections ) {
                $range_end = '';
            }
            $range = $range_start.'-'.$range_end;

            $part_file_name = $filename.'.part'.$part_number;

            // Save the file name of this part.
            $part_file_names[] = $part_file_name;

            // Remove any existing file part.
            if ( \is_file( $part_file_name ) ) {
                \unlink( $part_file_name );
            }

            // Create a file part.
            $file_handle = \tmpfile();

            // Set up the instance downloading a part.
            $curl = new cURL();
            $curl->setUrl( $url );

            // Pass user-specified options to the instance downloading a part.
            $curl->setOpts( $this->userSetOptions );

            $curl->setOptInternal( CURLOPT_CUSTOMREQUEST, 'GET' );
            $curl->setOptInternal( CURLOPT_HTTPGET, true );
            $curl->setRangeInternal( $range );
            $curl->setFileInternal( $file_handle );
            $curl->fileHandle = $file_handle;

            $curl->downloadCompleteCallback = function( $instance, $tmpfile ) use ( $part_file_name ) {
                $fh = \fopen( $part_file_name, 'wb' );
                if ( $fh !== false ) {
                    \stream_copy_to_stream( $tmpfile, $fh );
                    \fclose( $fh );
                }
            };

            $multi_curl->addCurl( $curl );
        }

        // Start the simultaneous downloads for each of the ranges in parallel.
        $multi_curl->start();

        // Remove the existing download file name at destination.
        if ( \is_file( $filename ) ) {
            \unlink( $filename );
        }

        // Combine downloaded chunks into a single file.
        $main_file_handle = \fopen( $filename, 'w' );
        if ( $main_file_handle === false ) {
            return false;
        }

        foreach ( $part_file_names as $part_file_name ) {
            if ( ! \is_file( $part_file_name ) ) {
                return false;
            }

            $file_handle = \fopen( $part_file_name, 'r' );
            if ( $file_handle === false ) {
                return false;
            }

            \stream_copy_to_stream( $file_handle, $main_file_handle );
            \fclose( $file_handle );
            \unlink( $part_file_name );
        }

        \fclose( $main_file_handle );

        return true;
    }

    /**
     * Exec
     *
     * @param mixed $ch
     *
     * @return mixed returns the value provided by parseResponse
     */
    public function exec( mixed $ch = null ) : mixed
    {
        $this->attempts++;

        if ( $this->jsonDecoder === null ) {
            $this->setDefaultJsonDecoder();
        }
        if ( $this->xmlDecoder === null ) {
            $this->setDefaultXmlDecoder();
        }

        if ( $ch === null ) {
            $this->responseCookies = [];
            $this->call( $this->beforeSendCallback );
            $this->rawResponse      = \curl_exec( $this->curl );
            $this->curlErrorCode    = \curl_errno( $this->curl );
            $this->curlErrorMessage = \curl_error( $this->curl );
        }
        else {
            $this->rawResponse      = \curl_multi_getcontent( $ch );
            $this->curlErrorMessage = \curl_error( $ch );
        }
        $this->curlError = $this->curlErrorCode !== 0;

        // Ensure Curl::rawResponse is a string as curl_exec() can return false.
        // Without this, calling strlen($curl->rawResponse) will error when the
        // strict types setting is enabled.
        if ( ! \is_string( $this->rawResponse ) ) {
            $this->rawResponse = '';
        }

        // Transfer the header callback data and release the temporary store to avoid memory leak.
        $this->rawResponseHeaders                     = $this->headerCallbackData->rawResponseHeaders;
        $this->responseCookies                        = $this->headerCallbackData->responseCookies;
        $this->headerCallbackData->rawResponseHeaders = '';
        $this->headerCallbackData->responseCookies    = [];
        $this->headerCallbackData->stopRequestDecider = null;
        $this->headerCallbackData->stopRequest        = false;

        // Include additional error code information in an error message when possible.
        if ( $this->curlError ) {
            $curl_error_message = \curl_strerror( $this->curlErrorCode );

            if ( isset( $this->curlErrorCodeConstant ) ) {
                $curl_error_message .= ' ('.$this->curlErrorCodeConstant.')';
            }

            if ( ! empty( $this->curlErrorMessage ) ) {
                $curl_error_message .= ': '.$this->curlErrorMessage;
            }

            $this->curlErrorMessage = $curl_error_message;
        }

        // NOTE: CURLINFO_HEADER_OUT set to true is required for requestHeaders
        // to not be empty (e.g., $curl->setOpt(CURLINFO_HEADER_OUT, true);).
        if ( $this->getOpt( CURLINFO_HEADER_OUT ) === true ) {
            $this->requestHeaders = $this->parseRequestHeaders( $this->getInfo( CURLINFO_HEADER_OUT ) );
        }
        $this->responseHeaders = $this->parseResponseHeaders( $this->rawResponseHeaders );
        $this->response        = $this->parseResponse( $this->responseHeaders, $this->rawResponse );

        $this->httpStatusCode = $this->getInfo( CURLINFO_HTTP_CODE );
        $this->httpError      = \in_array( (int) \floor( $this->httpStatusCode / 100 ), [4, 5], true );
        $this->error          = $this->curlError || $this->httpError;

        $this->call( $this->afterSendCallback );

        if ( ! \in_array( $this->error, [true, false], true ) ) {
            \trigger_error( '$instance->error MUST be set to true or false', E_USER_WARNING );
        }

        $this->errorCode = $this->error ? ( $this->curlError ? $this->curlErrorCode : $this->httpStatusCode ) : 0;

        $this->httpErrorMessage = '';
        if ( $this->error ) {
            if ( isset( $this->responseHeaders['Status-Line'] ) ) {
                $this->httpErrorMessage = $this->responseHeaders['Status-Line'];
            }
        }
        $this->errorMessage = $this->curlError ? $this->curlErrorMessage : $this->httpErrorMessage;

        // Reset select deferred properties so that they may be recalculated.
        unset( $this->deferredValues['curlErrorCodeConstant'], $this->deferredValues['effectiveUrl'], $this->deferredValues['totalTime'] );

        // Reset content-length header possibly set from a PUT or SEARCH request.
        $this->unsetHeader( 'Content-Length' );

        // Reset nobody setting possibly set from a HEAD request.
        $this->setOptInternal( CURLOPT_NOBODY, false );

        /** Allow {@see MultiCurl} to attempt retry as needed. */
        if ( $this->isChildOfMultiCurl() ) {
            return true;
        }

        if ( $this->attemptRetry() ) {
            return $this->exec( $ch );
        }

        $this->execDone();

        return $this->response;
    }

    public function execDone() : void
    {
        if ( $this->error ) {
            $this->call( $this->errorCallback );
        }
        else {
            $this->call( $this->successCallback );
        }

        $this->call( $this->completeCallback );

        // Close open file handles and reset the curl instance.
        if ( $this->fileHandle !== null ) {
            $this->downloadComplete( $this->fileHandle );
        }
    }

    /**
     * Get
     *
     * @param string|string[] $url
     * @param mixed           $data
     *
     * @return mixed returns the value provided by exec
     */
    public function get(
        string|array $url,
        mixed        $data = [],
    ) : mixed {
        $this->setGet( $url, $data );
        return $this->exec();
    }

    /**
     * @param string|string[] $url
     * @param mixed           $data
     *
     * @return void
     */
    public function setGet(
        string|array $url,
        mixed        $data = [],
    ) : void {
        if ( \is_array( $url ) ) {
            $data = $url;
            $url  = (string) $this->url;
        }
        $this->setUrl( $url, $data );
        $this->setOptInternal( CURLOPT_CUSTOMREQUEST, 'GET' );
        $this->setOptInternal( CURLOPT_HTTPGET, true );
    }

    /**
     * Get Info
     *
     * @param mixed $opt
     *
     * @return mixed
     */
    public function getInfo( mixed $opt = null ) : mixed
    {
        $args   = [];
        $args[] = $this->curl;

        if ( \func_num_args() ) {
            $args[] = $opt;
        }

        return \call_user_func_array( 'curl_getinfo', $args );
    }

    /**
     * Head
     *
     * @param string|string[] $url
     * @param mixed           $data
     *
     * @return mixed returns the value provided by exec
     */
    public function head(
        string|array $url,
        mixed        $data = [],
    ) : mixed {
        $this->setHead( $url, $data );
        return $this->exec();
    }

    public function setHead(
        string|array $url,
        mixed        $data = [],
    ) : void {
        if ( \is_array( $url ) ) {
            $data = $url;
            $url  = (string) $this->url;
        }
        $this->setUrl( $url, $data );
        $this->setOpt( CURLOPT_CUSTOMREQUEST, 'HEAD' );
        $this->setOpt( CURLOPT_NOBODY, true );
    }

    /**
     * Options
     *
     * @param string|string[] $url
     * @param mixed           $data
     *
     * @return mixed returns the value provided by exec
     */
    public function execOptions(
        string|array $url,
        mixed        $data = [],
    ) : mixed {
        $this->setOptions( $url, $data );
        return $this->exec();
    }

    public function setUrlOptions(
        string|array $url,
        mixed        $data = [],
    ) : void {
        if ( \is_array( $url ) ) {
            $data = $url;
            $url  = (string) $this->url;
        }
        $this->setUrl( $url, $data );
        $this->setOpt( CURLOPT_CUSTOMREQUEST, 'OPTIONS' );
    }

    /**
     * Patch
     *
     * @param string|string[] $url
     * @param mixed           $data
     *
     * @return mixed returns the value provided by exec
     */
    public function patch(
        string|array $url,
        mixed        $data = [],
    ) : mixed {
        $this->setPatch( $url, $data );
        return $this->exec();
    }

    public function setPatch(
        string|array $url,
        mixed        $data = [],
    ) : void {
        if ( \is_array( $url ) ) {
            $data = $url;
            $url  = (string) $this->url;
        }

        if ( \is_array( $data ) && empty( $data ) ) {
            $this->removeHeader( 'Content-Length' );
        }

        $this->setUrl( $url );
        $this->setOpt( CURLOPT_CUSTOMREQUEST, 'PATCH' );
        $this->setOpt( CURLOPT_POSTFIELDS, $this->buildPostData( $data ) );
    }

    /**
     * Post
     *
     * @param string|string[] $url
     * @param mixed           $data
     * @param bool            $follow_303_with_post
     *                                              If true will cause 303 redirections to be followed using a POST request
     *                                              (default: false).
     *                                              Notes:
     *                                              - Redirections are only followed if the CURLOPT_FOLLOWLOCATION option is set
     *                                              to true.
     *                                              - According to the HTTP specs (see [1]), a 303 redirection should be followed
     *                                              using the GET method. 301 and 302 must not.
     *                                              - In order to force a 303 redirection to be performed using the same method,
     *                                              the underlying cURL object must be set in a special state (the
     *                                              CURLOPT_CUSTOMREQUEST option must be set to the method to use after the
     *                                              redirection). Due to a limitation of the cURL extension of PHP < 5.5.11 ([2],
     *                                              [3]), it is not possible to reset this option. Using these PHP engines, it is
     *                                              therefore impossible to restore this behavior to an existing php-curl-class
     *                                              Curl object.
     *
     * @return mixed Returns the value provided by exec.
     *
     * [1] https://www.w3.org/Protocols/rfc2616/rfc2616-sec10.html#sec10.3.2
     * [2] https://github.com/php/php-src/pull/531
     * [3] http://php.net/ChangeLog-5.php#5.5.11
     */
    public function post(
        string|array $url,
        mixed        $data = '',
        bool         $follow_303_with_post = false,
    ) : mixed {
        $this->setPost( $url, $data, $follow_303_with_post );
        return $this->exec();
    }

    /**
     * @param array|string $url
     * @param mixed        $data
     * @param bool         $follow_303_with_post
     *
     * @return void
     */
    public function setPost(
        string|array $url,
        mixed        $data = '',
        bool         $follow_303_with_post = false,
    ) : void {
        if ( \is_array( $url ) ) {
            $follow_303_with_post = (bool) $data;
            $data                 = $url;
            $url                  = (string) $this->url;
        }

        $this->setUrl( $url );

        // Set the request method to "POST" when following a 303 redirect with
        // an additional POST request is desired. This is equivalent to setting
        // the -X, --request command line option where curl won't change the
        // request method according to the HTTP 30x response code.
        if ( $follow_303_with_post ) {
            $this->setOpt( CURLOPT_CUSTOMREQUEST, 'POST' );
        }
        elseif ( isset( $this->options[CURLOPT_CUSTOMREQUEST] ) ) {
            // Unset the CURLOPT_CUSTOMREQUEST option so that curl does not use
            // a POST request after a post/redirect/get redirection. Without
            // this, curl will use the method string specified for all requests.
            $this->setOpt( CURLOPT_CUSTOMREQUEST, null );
        }

        $this->setOpt( CURLOPT_POST, true );
        $this->setOpt( CURLOPT_POSTFIELDS, $this->buildPostData( $data ) );
    }

    /**
     * Put
     *
     * @param string|string[] $url
     * @param mixed           $data
     *
     * @return mixed returns the value provided by exec
     */
    public function put(
        string|array $url,
        mixed        $data = [],
    ) : mixed {
        $this->setPut( $url, $data );
        return $this->exec();
    }

    public function setPut(
        string|array $url,
        mixed        $data = [],
    ) : void {
        if ( \is_array( $url ) ) {
            $data = $url;
            $url  = (string) $this->url;
        }
        $this->setUrl( $url );
        $this->setOpt( CURLOPT_CUSTOMREQUEST, 'PUT' );
        $put_data = $this->buildPostData( $data );
        if ( empty( $this->options[CURLOPT_INFILE] ) && empty( $this->options[CURLOPT_INFILESIZE] ) ) {
            if ( \is_string( $put_data ) ) {
                $this->setHeader( 'Content-Length', \strlen( $put_data ) );
            }
        }
        if ( ! empty( $put_data ) ) {
            $this->setOpt( CURLOPT_POSTFIELDS, $put_data );
        }
    }

    /**
     * Search
     *
     * @param string|string[] $url
     * @param mixed           $data
     *
     * @return mixed returns the value provided by exec
     */
    public function search(
        string|array $url,
        mixed        $data = [],
    ) : mixed {
        $this->setSearch( $url, $data );
        return $this->exec();
    }

    public function setSearch(
        string|array $url,
        mixed        $data = [],
    ) : void {
        if ( \is_array( $url ) ) {
            $data = $url;
            $url  = (string) $this->url;
        }
        $this->setUrl( $url );
        $this->setOpt( CURLOPT_CUSTOMREQUEST, 'SEARCH' );
        $put_data = $this->buildPostData( $data );
        if ( empty( $this->options[CURLOPT_INFILE] ) && empty( $this->options[CURLOPT_INFILESIZE] ) ) {
            if ( \is_string( $put_data ) ) {
                $this->setHeader( 'Content-Length', \strlen( $put_data ) );
            }
        }
        if ( ! empty( $put_data ) ) {
            $this->setOpt( CURLOPT_POSTFIELDS, $put_data );
        }
    }

    /**
     * Set Cookie
     *
     * @param string $key
     * @param string $value
     */
    public function setCookie( string $key, string $value ) : void
    {
        $this->setEncodedCookie( $key, $value );
        $this->buildCookies();
    }

    /**
     * Set Cookies
     *
     * @param array<string, string> $cookies
     */
    public function setCookies( array $cookies ) : void
    {
        foreach ( $cookies as $key => $value ) {
            $this->setEncodedCookie( $key, $value );
        }
        $this->buildCookies();
    }

    /**
     * Get Cookie
     *
     * @param $key
     *
     * @return mixed
     */
    public function getCookie( $key ) : mixed
    {
        return $this->getResponseCookie( $key );
    }

    /**
     * Get Response Cookie
     *
     * @param $key
     *
     * @return mixed
     */
    public function getResponseCookie( $key ) : mixed
    {
        return $this->responseCookies[$key] ?? null;
    }

    /**
     * Set Max Filesize
     *
     * @param $bytes
     */
    public function setMaxFilesize( $bytes ) : void
    {
        $callback = function( $resource, $download_size, $downloaded, $upload_size, $uploaded ) use ( $bytes ) {
            // Abort the transfer when $downloaded bytes exceeds maximum $bytes by returning a non-zero value.
            return $downloaded > $bytes ? 1 : 0;
        };
        $this->progress( $callback );
    }

    /**
     * Set Cookie String
     *
     * @param string $string
     *
     * @return self
     */
    public function setCookieString( string $string ) : self
    {
        return $this->setOpt( CURLOPT_COOKIE, $string );
    }

    /**
     * Set Cookie File
     *
     * @param $cookie_file
     *
     * @return self
     */
    public function setCookieFile( $cookie_file ) : self
    {
        return $this->setOpt( CURLOPT_COOKIEFILE, $cookie_file );
    }

    /**
     * Set Cookie Jar
     *
     * @param mixed $cookie_jar
     *
     * @return self
     */
    public function setCookieJar( mixed $cookie_jar ) : self
    {
        return $this->setOpt( CURLOPT_COOKIEJAR, $cookie_jar );
    }

    /**
     * Set Default JSON Decoder
     */
    public function setDefaultJsonDecoder() : void
    {
        $this->jsonDecoder     = '\Support\cURL\Decoder::decodeJson';
        $this->jsonDecoderArgs = \func_get_args();
    }

    /**
     * Set Default XML Decoder
     */
    public function setDefaultXmlDecoder() : void
    {
        $this->xmlDecoder     = '\Support\cURL\Decoder::decodeXml';
        $this->xmlDecoderArgs = \func_get_args();
    }

    /**
     * Set Default Decoder
     *
     * @param $mixed callable|boolean|string
     */
    public function setDefaultDecoder( callable|bool|string $mixed = 'json' ) : void
    {
        if ( $mixed === false ) {
            $this->defaultDecoder = false;
        }
        elseif ( $mixed === 'json' ) {
            $this->defaultDecoder = '\Support\cURL\Decoder::decodeJson';
        }
        elseif ( $mixed === 'xml' ) {
            $this->defaultDecoder = '\Support\cURL\Decoder::decodeXml';
        }
        elseif ( \is_callable( $mixed ) ) {
            $this->defaultDecoder = $mixed;
        }
    }

    /**
     * Set the Default Header Out
     */
    public function setDefaultHeaderOut() : void
    {
        $this->setOpt( CURLINFO_HEADER_OUT, true );
    }

    /**
     * Set the Default Header Out
     */
    private function setDefaultHeaderOutInternal() : void
    {
        $this->setOptInternal( CURLINFO_HEADER_OUT, true );
    }

    /**
     * Set Default Timeout
     */
    public function setDefaultTimeout() : void
    {
        $this->setTimeout( self::DEFAULT_TIMEOUT );
    }

    private function setDefaultTimeoutInternal() : void
    {
        $this->setTimeoutInternal( self::DEFAULT_TIMEOUT );
    }

    /**
     * Set Default User Agent
     */
    public function setDefaultUserAgent() : void
    {
        $this->setUserAgent( $this->getDefaultUserAgent() );
    }

    private function setDefaultUserAgentInternal() : void
    {
        $this->setUserAgentInternal( $this->getDefaultUserAgent() );
    }

    private function getDefaultUserAgent() : string
    {
        // (+https://github.com/northrook/php-curl)

        // $user_agent = 'php-curl/ ';
        // $curl_version = \curl_version();
        // $user_agent .= ' curl/'.$curl_version['version'];

        $info       = $this::getVersion();
        $version    = $info['version'];
        $user_agent = 'php-curl/'.\substr( $version, 0, \strrpos( $version, '-' ) );
        $user_agent .= ' (+https://github.com/northrook/php-curl)';
        $user_agent .= ' PHP/'.PHP_VERSION;

        // dump( \get_defined_vars() );
        return $user_agent;
    }

    /**
     * Set Header
     *
     * Add an extra header to include in the request.
     *
     * @param string $key
     * @param scalar $value
     */
    public function setHeader( string $key, mixed $value ) : void
    {
        $this->headers[$key] = $value;
        $headers             = [];

        foreach ( $this->headers as $key => $value ) {
            $headers[] = $key.': '.$value;
        }
        $this->setOpt( CURLOPT_HTTPHEADER, $headers );
    }

    /**
     * Set Headers
     *
     * Add extra headers to include in the request.
     *
     * @param array<array-key,mixed> $headers
     */
    public function setHeaders( array $headers ) : void
    {
        if ( ArrayData::isAssociative( $headers ) ) {
            foreach ( $headers as $key => $value ) {
                $key                 = \trim( $key );
                $value               = \trim( $value );
                $this->headers[$key] = $value;
            }
        }
        else {
            foreach ( $headers as $header ) {
                [$key, $value]       = \array_pad( \explode( ':', $header, 2 ), 2, '' );
                $key                 = \trim( $key );
                $value               = \trim( $value );
                $this->headers[$key] = $value;
            }
        }

        $headers = [];

        foreach ( $this->headers as $key => $value ) {
            $headers[] = $key.': '.$value;
        }

        $this->setOpt( CURLOPT_HTTPHEADER, $headers );
    }

    /**
     * Set JSON Decoder
     *
     * @param callable|false $decoder
     */
    public function setJsonDecoder( false|callable $decoder ) : void
    {
        $this->jsonDecoder     = $decoder;
        $this->jsonDecoderArgs = [];
    }

    /**
     * Set XML Decoder
     *
     * @param $mixed boolean|callable
     */
    public function setXmlDecoder( $mixed ) : void
    {
        if ( $mixed === false || \is_callable( $mixed ) ) {
            $this->xmlDecoder     = $mixed;
            $this->xmlDecoderArgs = [];
        }
    }

    /**
     * Set Opt
     *
     * @param int|string $option
     * @param mixed      $value
     *
     * @return self
     */
    public function setOpt( int|string $option, mixed $value ) : self
    {
        $required_options = [
            CURLOPT_RETURNTRANSFER => 'CURLOPT_RETURNTRANSFER',
        ];

        if ( \in_array( $option, \array_keys( $required_options ), true ) && $value !== true ) {
            \trigger_error( $required_options[$option].' is a required option', E_USER_WARNING );
        }

        if ( \curl_setopt( $this->curl, $option, $value ) ) {
            $this->options[$option]        = $value;
            $this->userSetOptions[$option] = $value;
        }
        else {
            throw new CurlOptionException( $option, $value );
        }
        return $this;
    }

    /**
     * Set Opt Internal
     *
     * @param int|string $option
     * @param mixed      $value
     *
     * @return bool
     */
    protected function setOptInternal( int|string $option, mixed $value ) : bool
    {
        $success = \curl_setopt( $this->curl, $option, $value );
        if ( $success ) {
            $this->options[$option] = $value;
        }
        return $success;
    }

    public function setOptions( mixed ...$options ) : self
    {
        if ( ! \count( $options ) ) {
            return $this;
        }

        foreach ( $options as $option => $value ) {
            if ( \is_string( $option ) ) {
                $option = \constant( $option );
                dump( $option );
            }

            $this->setOpt( $option, $value );
        }
        return $this;
    }

    /**
     * Set Opts
     *
     * @param array<array-key,mixed> $options
     *
     * @return bool
     *              Returns true if all options were successfully set. If an
     *              option could not be successfully set, false is immediately
     *              returned, ignoring any future options in the option array.
     *              Similar to curl_setopt_array().
     */
    public function setOpts( array $options ) : bool
    {
        if ( ! \count( $options ) ) {
            return true;
        }

        foreach ( $options as $option => $value ) {
            if ( ! $this->setOpt( $option, $value ) ) {
                return false;
            }
        }
        return true;
    }

    /**
     * Set Protocols
     *
     * Limit what protocols libcurl will accept for a request.
     *
     * @param $protocols
     *
     * @see    cURL::setRedirectProtocols()
     */
    public function setProtocols( $protocols ) : void
    {
        $this->setOpt( CURLOPT_PROTOCOLS, $protocols );
    }

    private function setProtocolsInternal( $protocols ) : void
    {
        $this->setOptInternal( CURLOPT_PROTOCOLS, $protocols );
    }

    /**
     * Set Retry logic.
     *
     * - `int` number of retries to attempt
     * - `callable` the retry decider
     *
     * When using a number of retries to attempt, the maximum number of attempts
     * for the request is $maximum_number_of_retries + 1.
     *
     * When using a callable decider, the request will be retried until the
     * function returns a value which evaluates to false.
     *
     * @param callable|int $retry
     */
    public function setRetry( int|callable $retry ) : void
    {
        if ( \is_callable( $retry ) ) {
            $this->retryDecider = $retry;
        }
        elseif ( \is_int( $retry ) ) {
            $maximum_number_of_retries = $retry;
            $this->remainingRetries    = $maximum_number_of_retries;
        }
    }

    /**
     * Set Redirect Protocols
     *
     * Limit what protocols libcurl will accept when following a redirect.
     *
     * @param $redirect_protocols
     *
     * @see    cURL::setProtocols()
     */
    public function setRedirectProtocols( $redirect_protocols ) : void
    {
        $this->setOpt( CURLOPT_REDIR_PROTOCOLS, $redirect_protocols );
    }

    private function setRedirectProtocolsInternal( $redirect_protocols ) : void
    {
        $this->setOptInternal( CURLOPT_REDIR_PROTOCOLS, $redirect_protocols );
    }

    /**
     * Set Url
     *
     * @param string $url
     * @param mixed  $data
     */
    public function setUrl( string $url, mixed $data = '' ) : void
    {
        $built_url = Url::buildUrl( $url, $data );

        if ( $this->url === null ) {
            $this->url = (string) new Url( $built_url );
        }
        else {
            $this->url = (string) new Url( $this->url, $built_url );
        }

        $this->setOpt( CURLOPT_URL, $this->url );
    }

    /**
     * Attempt Retry
     *
     * @return bool
     */
    public function attemptRetry() : bool
    {
        $attempt_retry = false;
        if ( $this->error ) {
            if ( $this->retryDecider === null ) {
                $attempt_retry = $this->remainingRetries >= 1;
            }
            else {
                $attempt_retry = \call_user_func( $this->retryDecider, $this );
            }
            if ( $attempt_retry ) {
                $this->retries++;
                if ( $this->remainingRetries ) {
                    $this->remainingRetries--;
                }
            }
        }
        return $attempt_retry;
    }

    /**
     * Unset Header
     *
     * Remove extra header previously set using Curl::setHeader().
     *
     * @param $key
     */
    public function unsetHeader( $key ) : void
    {
        unset( $this->headers[$key] );
        $headers = [];

        foreach ( $this->headers as $key => $value ) {
            $headers[] = $key.': '.$value;
        }
        $this->setOpt( CURLOPT_HTTPHEADER, $headers );
    }

    /**
     * Diagnose
     *
     * @param bool $return
     *
     * @return bool|string
     */
    public function diagnose( bool $return = false ) : bool|string
    {
        //  @return ($return is true ? string | bool)
        if ( $return ) {
            \ob_start();
        }

        echo "\n";
        echo '--- Begin PHP Curl Class diagnostic output ---'."\n";
        echo self::class."\n";
        echo 'PHP version: '.PHP_VERSION."\n";

        $curl_version = \curl_version();
        echo 'Curl version: '.$curl_version['version']."\n";

        if ( $this->attempts === 0 ) {
            echo 'No HTTP requests have been made.'."\n";
        }
        else {
            $request_types = [
                'DELETE'  => $this->getOpt( CURLOPT_CUSTOMREQUEST ) === 'DELETE',
                'GET'     => $this->getOpt( CURLOPT_CUSTOMREQUEST ) === 'GET' || $this->getOpt( CURLOPT_HTTPGET ),
                'HEAD'    => $this->getOpt( CURLOPT_CUSTOMREQUEST ) === 'HEAD',
                'OPTIONS' => $this->getOpt( CURLOPT_CUSTOMREQUEST ) === 'OPTIONS',
                'PATCH'   => $this->getOpt( CURLOPT_CUSTOMREQUEST ) === 'PATCH',
                'POST'    => $this->getOpt( CURLOPT_CUSTOMREQUEST ) === 'POST' || $this->getOpt( CURLOPT_POST ),
                'PUT'     => $this->getOpt( CURLOPT_CUSTOMREQUEST ) === 'PUT',
                'SEARCH'  => $this->getOpt( CURLOPT_CUSTOMREQUEST ) === 'SEARCH',
            ];
            $request_method = '';

            foreach ( $request_types as $http_method_name => $http_method_used ) {
                if ( $http_method_used ) {
                    $request_method = $http_method_name;

                    break;
                }
            }
            $request_url           = $this->getOpt( CURLOPT_URL );
            $request_options_count = \count( $this->options );
            $request_headers_count = \count( $this->requestHeaders );
            $request_body_empty    = empty( $this->getOpt( CURLOPT_POSTFIELDS ) );
            $response_header_length
                                        = $this->responseHeaders['Content-Length'] ?? '(not specified in response header)';
            $response_calculated_length = \is_string( $this->rawResponse )
                    ? \strlen( $this->rawResponse ) : '('.\var_export( $this->rawResponse, true ).')';
            $response_headers_count = \count( $this->responseHeaders );

            echo 'Request contained '.$request_options_count.' '.(
                $request_options_count === 1 ? 'option:' : 'options:'
            )."\n";
            if ( $request_options_count ) {
                $i = 1;

                foreach ( $this->options as $option => $value ) {
                    echo '    '.$i.' ';
                    $this->displayCurlOptionValue( $option, $value );
                    $i++;
                }
            }

            echo 'Sent an HTTP '.$request_method.' request to "'.$request_url.'".'."\n"
                 .'Request contained '.$request_headers_count.' '.(
                     $request_headers_count === 1 ? 'header:' : 'headers:'
                 )."\n";
            if ( $request_headers_count ) {
                $i = 1;

                foreach ( $this->requestHeaders as $key => $value ) {
                    echo '    '.$i.' '.$key.': '.$value."\n";
                    $i++;
                }
            }

            echo 'Request contained '.( $request_body_empty ? 'no body' : 'a body' ).'.'."\n";

            if (
                $request_headers_count === 0 && (
                    $this->getOpt( CURLOPT_VERBOSE )
                        || ! $this->getOpt( CURLINFO_HEADER_OUT )
                )
            ) {
                echo 'Warning: Request headers (Curl::requestHeaders) are expected to be empty '
                     .'(CURLOPT_VERBOSE was enabled or CURLINFO_HEADER_OUT was disabled).'."\n";
            }

            if ( isset( $this->responseHeaders['allow'] ) ) {
                $allowed_request_types = \array_map(
                    function( $v ) {
                        return \trim( $v );
                    },
                    \explode( ',', \strtoupper( $this->responseHeaders['allow'] ) ),
                );

                foreach ( $request_types as $http_method_name => $http_method_used ) {
                    if ( $http_method_used && ! \in_array( $http_method_name, $allowed_request_types, true ) ) {
                        echo 'Warning: An HTTP '.$http_method_name.' request was made, but only the following '
                             .'request types are allowed: '.\implode( ', ', $allowed_request_types )."\n";
                    }
                }
            }

            echo 'Response contains '.$response_headers_count.' '.(
                $response_headers_count === 1 ? 'header:' : 'headers:'
            )."\n";
            if ( $this->responseHeaders !== null ) {
                $i = 1;

                foreach ( $this->responseHeaders as $key => $value ) {
                    echo '    '.$i.' '.$key.': '.$value."\n";
                    $i++;
                }
            }

            if ( ! isset( $this->responseHeaders['Content-Type'] ) ) {
                echo 'Response did not set a content type.'."\n";
            }
            elseif ( \preg_match( $this->jsonPattern, $this->responseHeaders['Content-Type'] ) ) {
                echo 'Response appears to be JSON.'."\n";
            }
            elseif ( \preg_match( $this->xmlPattern, $this->responseHeaders['Content-Type'] ) ) {
                echo 'Response appears to be XML.'."\n";
            }

            if ( $this->curlError ) {
                echo 'A curl error ('.$this->curlErrorCode.') occurred '
                     .'with message "'.$this->curlErrorMessage.'".'."\n";
            }
            if ( ! empty( $this->httpStatusCode ) ) {
                echo 'Received an HTTP status code of '.$this->httpStatusCode.'.'."\n";
            }
            if ( $this->httpError ) {
                echo 'Received an HTTP '.$this->httpStatusCode.' error response '
                     .'with message "'.$this->httpErrorMessage.'".'."\n";
            }

            if ( $this->rawResponse === null ) {
                echo 'Received no response body (response=null).'."\n";
            }
            elseif ( $this->rawResponse === '' ) {
                echo 'Received an empty response body (response="").'."\n";
            }
            else {
                echo 'Received a non-empty response body.'."\n";
                if ( isset( $this->responseHeaders['Content-Length'] ) ) {
                    echo 'Response content length (from content-length header): '.$response_header_length."\n";
                }
                else {
                    echo 'Response content length (calculated): '.$response_calculated_length."\n";
                }

                if (
                    isset( $this->responseHeaders['Content-Type'] )
                    && \preg_match( $this->jsonPattern, $this->responseHeaders['Content-Type'] )
                ) {
                    $parsed_response = \json_decode( $this->rawResponse, true );
                    if ( $parsed_response !== null ) {
                        $messages = [];
                        \array_walk_recursive(
                            $parsed_response,
                            function( $value, $key ) use ( &$messages ) {
                                if ( \in_array( $key, ['code', 'error', 'message'], true ) ) {
                                    $message    = $key.': '.$value;
                                    $messages[] = $message;
                                }
                            },
                        );
                        $messages = \array_unique( $messages );

                        $messages_count = \count( $messages );
                        if ( $messages_count ) {
                            echo 'Found '.$messages_count.' '
                                 .( $messages_count === 1 ? 'message' : 'messages' )
                                 .' in response:'."\n";

                            $i = 1;

                            foreach ( $messages as $message ) {
                                echo '    '.$i.' '.$message."\n";
                                $i++;
                            }
                        }
                    }
                }
            }
        }

        echo '--- End PHP Curl Class diagnostic output ---'."\n";
        echo "\n";

        if ( $return ) {
            $output = \ob_get_contents();
            \ob_end_clean();
            return $output;
        }
        return false;
    }

    /**
     * Reset
     */
    public function reset() : void
    {
        if ( \is_resource( $this->curl ) || $this->curl instanceof CurlHandle ) {
            \curl_reset( $this->curl );
        }
        else {
            $this->curl = \curl_init();
        }

        $this->setDefaultUserAgentInternal();
        $this->setDefaultTimeoutInternal();
        $this->setDefaultHeaderOutInternal();

        $this->initialize();
    }

    public function getCurl() : CurlHandle|bool
    {
        return $this->curl;
    }

    public function getId() : string
    {
        return $this->id;
    }

    public function isError() : bool
    {
        return $this->error;
    }

    public function getErrorCode() : int
    {
        return $this->errorCode;
    }

    public function getErrorMessage() : ?string
    {
        return $this->errorMessage;
    }

    public function isCurlError() : bool
    {
        return $this->curlError;
    }

    public function getCurlErrorCode() : int
    {
        return $this->curlErrorCode;
    }

    public function getCurlErrorMessage() : ?string
    {
        return $this->curlErrorMessage;
    }

    public function isHttpError() : bool
    {
        return $this->httpError;
    }

    public function getHttpStatusCode() : int
    {
        return $this->httpStatusCode;
    }

    public function getHttpErrorMessage() : ?string
    {
        return $this->httpErrorMessage;
    }

    public function getUrl() : ?string
    {
        return $this->url;
    }

    public function getOptions() : array
    {
        return $this->options;
    }

    public function getUserSetOptions() : array
    {
        return $this->userSetOptions;
    }

    public function getRequestHeaders() : array|ArrayAccess
    {
        return $this->requestHeaders;
    }

    public function getResponseHeaders() : array|ArrayAccess
    {
        return $this->responseHeaders;
    }

    public function getRawResponseHeaders() : string
    {
        return $this->rawResponseHeaders;
    }

    public function getResponseCookies() : array
    {
        return $this->responseCookies;
    }

    public function getResponse()
    {
        return $this->response;
    }

    public function getRawResponse()
    {
        return $this->rawResponse;
    }

    public function getBeforeSendCallback()
    {
        return $this->beforeSendCallback;
    }

    public function getDownloadCompleteCallback()
    {
        return $this->downloadCompleteCallback;
    }

    public function getDownloadFileName() : ?string
    {
        return $this->downloadFileName;
    }

    public function getSuccessCallback()
    {
        return $this->successCallback;
    }

    public function getErrorCallback()
    {
        return $this->errorCallback;
    }

    public function getCompleteCallback()
    {
        return $this->completeCallback;
    }

    public function getFileHandle()
    {
        return $this->fileHandle;
    }

    public function getAttempts() : int
    {
        return $this->attempts;
    }

    public function getRetries() : int
    {
        return $this->retries;
    }

    public function isChildOfMultiCurl() : bool
    {
        return $this->childOfMultiCurl;
    }

    public function getRemainingRetries() : int
    {
        return $this->remainingRetries;
    }

    public function getRetryDecider()
    {
        return $this->retryDecider;
    }

    public function getJsonDecoder()
    {
        return $this->jsonDecoder;
    }

    public function getXmlDecoder()
    {
        return $this->xmlDecoder;
    }

    /**
     * Destruct
     */
    public function __destruct()
    {
        $this->close();
    }

    public function __get( $name )
    {
        if ( \in_array( $name, self::$deferredProperties, true ) ) {
            if ( isset( $this->deferredValues[$name] ) ) {
                return $this->deferredValues[$name];
            }
            if ( \is_callable( [$this, $getter = 'get'.\ucfirst( $name )] ) ) {
                $this->deferredValues[$name] = $this->{$getter}();
                return $this->deferredValues[$name];
            }
        }

        throw new BadMethodCallException( "Undefined property via __get(): {$name}()" );
    }

    public function __isset( $name )
    {
        if ( \in_array( $name, self::$deferredProperties, true ) ) {
            if ( isset( $this->deferredValues[$name] ) ) {
                return true;
            }
            if ( \is_callable( [$this, $getter = 'get'.\ucfirst( $name )] ) ) {
                $this->deferredValues[$name] = $this->{$getter}();
                return true;
            }

            return false;
        }

        return isset( $this->{$name} );
    }

    // /**
    //  * Get Curl Error Code Constants
    //  */
    // private function getCurlErrorCodeConstants() : array
    // {
    //     $constants      = \get_defined_constants( true );
    //     $filtered_array = \array_filter(
    //         $constants['curl'],
    //         function( $key ) {
    //             return \str_contains( $key, 'CURLE_' );
    //         },
    //         ARRAY_FILTER_USE_KEY,
    //     );
    //     return \array_flip( $filtered_array );
    // }

    /**
     * Get Curl Error Code Constant
     *
     * @param mixed      $option
     * @param null|mixed $value
     */
    // private function getCurlErrorCodeConstant() : string
    // {
    //     $curl_const_by_code = $this->curlErrorCodeConstants ?? [];
    //     if ( isset( $curl_const_by_code[$this->curlErrorCode] ) ) {
    //         return $curl_const_by_code[$this->curlErrorCode];
    //     }
    //     return '';
    // }

    /**
     * Get Curl Option Code Constants
     */
    // private function getCurlOptionCodeConstants() : array
    // {
    //     $constants      = \get_defined_constants( true );
    //     $filtered_array = \array_filter(
    //         $constants['curl'],
    //         function( $key ) {
    //             return str_contains( $key, 'CURLOPT_' );
    //         },
    //         ARRAY_FILTER_USE_KEY,
    //     );
    //     $curl_const_by_code = \array_flip( $filtered_array );
    //
    //     if ( ! isset( $curl_const_by_code[CURLINFO_HEADER_OUT] ) ) {
    //         $curl_const_by_code[CURLINFO_HEADER_OUT] = 'CURLINFO_HEADER_OUT';
    //     }
    //
    //     return $curl_const_by_code;
    // }

    /**
     * Display Curl Option Value.
     *
     * @param $option
     * @param $value
     */
    public function displayCurlOptionValue( $option, $value = null ) : void
    {
        if ( $value === null ) {
            $value = $this->getOpt( $option );
        }

        if ( isset( $this->curlOptionCodeConstants[$option] ) ) {
            echo $this->curlOptionCodeConstants[$option].':';
        }
        else {
            echo $option.':';
        }

        if ( \is_string( $value ) ) {
            echo ' "'.$value.'"'."\n";
        }
        elseif ( \is_int( $value ) ) {
            echo ' '.$value;

            $bit_flag_lookups = [
                'CURLOPT_HTTPAUTH'          => 'CURLAUTH_',
                'CURLOPT_PROTOCOLS'         => 'CURLPROTO_',
                'CURLOPT_PROXYAUTH'         => 'CURLAUTH_',
                'CURLOPT_PROXY_SSL_OPTIONS' => 'CURLSSLOPT_',
                'CURLOPT_REDIR_PROTOCOLS'   => 'CURLPROTO_',
                'CURLOPT_SSH_AUTH_TYPES'    => 'CURLSSH_AUTH_',
                'CURLOPT_SSL_OPTIONS'       => 'CURLSSLOPT_',
            ];
            if ( isset( $this->curlOptionCodeConstants[$option] ) ) {
                $option_name = $this->curlOptionCodeConstants[$option];
                if ( \in_array( $option_name, \array_keys( $bit_flag_lookups ), true ) ) {
                    $curl_const_prefix = $bit_flag_lookups[$option_name];
                    $constants         = \get_defined_constants( true );
                    $curl_constants    = \array_filter(
                        $constants['curl'],
                        function( $key ) use ( $curl_const_prefix ) {
                            return \str_contains( $key, $curl_const_prefix );
                        },
                        ARRAY_FILTER_USE_KEY,
                    );

                    $bit_flags = [];

                    foreach ( $curl_constants as $const_name => $const_value ) {
                        // Attempt to detect bit flags in use that use constants with negative values (e.g.,
                        // CURLAUTH_ANY, CURLAUTH_ANYSAFE, CURLPROTO_ALL, CURLSSH_AUTH_ANY,
                        // CURLSSH_AUTH_DEFAULT, etc.)
                        if ( $value < 0 && $value === $const_value ) {
                            $bit_flags[] = $const_name;

                            break;
                        }
                        if ( $value >= 0 && $const_value >= 0 && ( $value & $const_value ) ) {
                            $bit_flags[] = $const_name;
                        }
                    }

                    if ( \count( $bit_flags ) ) {
                        \asort( $bit_flags );
                        echo ' ('.\implode( ' | ', $bit_flags ).')';
                    }
                }
            }

            echo "\n";
        }
        elseif ( \is_bool( $value ) ) {
            echo ' '.( $value ? 'true' : 'false' )."\n";
        }
        elseif ( \is_array( $value ) ) {
            echo ' ';
            \var_dump( $value );
        }
        elseif ( \is_callable( $value ) ) {
            echo ' (callable)'."\n";
        }
        else {
            echo ' '.\gettype( $value ).':'."\n";
            \var_dump( $value );
        }
    }

    /**
     * Get Effective Url
     */
    // private function getEffectiveUrl()
    // {
    //     return $this->getInfo( CURLINFO_EFFECTIVE_URL );
    // }

    /**
     * Get RFC 2616
     */
    // private function getRfc2616() : array
    // {
    //     return \array_fill_keys( self::$RFC2616, true );
    // }

    /**
     * Get RFC 6265
     */
    // private function getRfc6265() : array
    // {
    //     return \array_fill_keys( self::$RFC6265, true );
    // }

    /**
     * Get Total Time
     */
    // private function getTotalTime()
    // {
    //     return $this->getInfo( CURLINFO_TOTAL_TIME );
    // }

    /**
     * Build Cookies
     */
    private function buildCookies() : void
    {
        // Avoid changing CURLOPT_COOKIE if there are no cookies' set.
        if ( \count( $this->cookies ) ) {
            /**
             * Avoid using {@see http_build_query} as unnecessary encoding is performed.
             * {@see http_build_query}($this->cookies, '', '; ')
             * */
            $cookies = [];

            foreach ( $this->cookies as $key => $value ) {
                $cookies[] = $key.'='.$value;
            }
            $this->setOpt( CURLOPT_COOKIE, \implode( '; ', $cookies ) );
        }
    }

    /**
     * Download Complete
     *
     * @param $fh
     */
    private function downloadComplete( $fh ) : void
    {
        if ( $this->error && \is_file( (string) $this->downloadFileName ) ) {
            @\unlink( $this->downloadFileName );
        }
        elseif ( ! $this->error && $this->downloadCompleteCallback ) {
            \rewind( $fh );
            $this->call( $this->downloadCompleteCallback, $fh );
            $this->downloadCompleteCallback = null;
        }

        if ( \is_resource( $fh ) ) {
            \fclose( $fh );
        }

        // Fix "PHP Notice: Use of undefined constant STDOUT" when reading the
        // PHP script from stdin. Using null causes "Warning: curl_setopt():
        // supplied argument is not a valid File-Handle resource".
        if ( \defined( 'STDOUT' ) ) {
            $output = STDOUT;
        }
        else {
            $output = \fopen( 'php://stdout', 'w' );
        }

        // Reset CURLOPT_FILE with STDOUT to avoid: "curl_exec(): CURLOPT_FILE
        // resource has gone away, resetting to default".
        $this->setFile( $output );

        // Reset CURLOPT_RETURNTRANSFER to tell cURL to return subsequent
        // responses as the return value of curl_exec(). Without this,
        // curl_exec() will revert to returning boolean values.
        $this->setOpt( CURLOPT_RETURNTRANSFER, true );
    }

    /**
     * Parse Headers
     *
     * @param $raw_headers
     *
     * @return array
     */
    private function parseHeaders( $raw_headers ) : array
    {
        $http_headers = new ArrayData();
        $raw_headers  = \preg_split( '/\r\n/', (string) $raw_headers, -1, PREG_SPLIT_NO_EMPTY );
        if ( $raw_headers === false ) {
            return ['', $http_headers];
        }

        $raw_headers_count = \count( $raw_headers );
        for ( $i = 1; $i < $raw_headers_count; $i++ ) {
            if ( \str_contains( $raw_headers[$i], ':' ) ) {
                [$key, $value] = \array_pad( \explode( ':', $raw_headers[$i], 2 ), 2, '' );
                $key           = \trim( $key );
                $value         = \trim( $value );
                // Use isset() as array_key_exists() and ArrayAccess are not compatible.
                if ( isset( $http_headers[$key] ) ) {
                    $http_headers[$key] .= ','.$value;
                }
                else {
                    $http_headers[$key] = $value;
                }
            }
        }

        return [$raw_headers[0] ?? '', $http_headers];
    }

    /**
     * Parse Request Headers
     *
     * @param $raw_headers
     *
     * @return ArrayData
     */
    private function parseRequestHeaders( $raw_headers ) : ArrayData
    {
        $request_headers                 = new ArrayData();
        [$first_line, $headers]          = $this->parseHeaders( $raw_headers );
        $request_headers['Request-Line'] = $first_line;

        foreach ( $headers as $key => $value ) {
            $request_headers[$key] = $value;
        }
        return $request_headers;
    }

    /**
     * Parse Response
     *
     * @param $response_headers
     * @param $raw_response
     *
     * @return mixed
     *               If the response content-type is JSON: Returns the JSON decoder's return value: A stdClass object
     *               when the default JSON decoder is used.
     *
     *               If the response content-type is XML: Returns the XML decoder's return value: A SimpleXMLElement
     *               object when the default XML decoder is used.
     *
     *               If the response content-type is something else: Returns the original raw response unless a default
     *               decoder has been set.
     *
     *               If the response content-type cannot be determined: Returns the original raw response.
     *
     *               If the response content-encoding is gzip: Returns the response gzip-decoded.
     */
    private function parseResponse( $response_headers, $raw_response ) : mixed
    {
        $response = $raw_response;
        if ( isset( $response_headers['Content-Type'] ) ) {
            if ( \preg_match( $this->jsonPattern, $response_headers['Content-Type'] ) ) {
                if ( $this->jsonDecoder ) {
                    $args = $this->jsonDecoderArgs;
                    \array_unshift( $args, $response );
                    $response = \call_user_func_array( $this->jsonDecoder, $args );
                }
            }
            elseif ( \preg_match( $this->xmlPattern, $response_headers['Content-Type'] ) ) {
                if ( $this->xmlDecoder ) {
                    $args = $this->xmlDecoderArgs;
                    \array_unshift( $args, $response );
                    $response = \call_user_func_array( $this->xmlDecoder, $args );
                }
            }
            else {
                if ( $this->defaultDecoder ) {
                    $response = \call_user_func( $this->defaultDecoder, $response );
                }
            }
        }

        if (
            (
                // Ensure that the server says the response is compressed with
                // gzip and the response has not yet been decoded.
                // Use is_string() to ensure that $response is a string being passed
                // to mb_strpos() and gzdecode(). Use extension_loaded() to
                // ensure that mb_strpos() uses the mbstring extension and not a
                // polyfill.
                isset( $response_headers['Content-Encoding'] )
                && $response_headers['Content-Encoding'] === 'gzip'
                && \is_string( $response )
                && (
                    (
                        \extension_loaded( 'mbstring' )
                                && \mb_strpos( $response, "\x1f\x8b\x08", 0, 'US-ASCII' ) === 0
                    )
                        || ! \extension_loaded( 'mbstring' )
                )
            ) || (
                // Or ensure that the response looks like it is compressed with
                // gzip. Use is_string() to ensure that $response is a string
                // being passed to mb_strpos() and gzdecode(). Use
                // extension_loaded() to ensure that mb_strpos() uses the
                // mbstring extension and not a polyfill.
                \is_string( $response )
                && \extension_loaded( 'mbstring' )
                && \mb_strpos( $response, "\x1f\x8b\x08", 0, 'US-ASCII' ) === 0
            )
        ) {
            // Use @ to suppress message "Warning gzdecode(): data error".
            $decoded_response = @\gzdecode( $response );
            if ( $decoded_response !== false ) {
                $response = $decoded_response;
            }
        }

        return $response;
    }

    /**
     * Parse Response Headers
     *
     * @param $raw_response_headers
     *
     * @return ArrayData
     */
    private function parseResponseHeaders( $raw_response_headers ) : ArrayData
    {
        $response_header_array = \explode( "\r\n\r\n", $raw_response_headers );
        $response_header       = '';
        for ( $i = \count( $response_header_array ) - 1; $i >= 0; $i-- ) {
            if ( isset( $response_header_array[$i] ) && \stripos( $response_header_array[$i], 'HTTP/' ) === 0 ) {
                $response_header = $response_header_array[$i];

                break;
            }
        }

        $response_headers                = new ArrayData();
        [$first_line, $headers]          = $this->parseHeaders( $response_header );
        $response_headers['Status-Line'] = $first_line;

        foreach ( $headers as $key => $value ) {
            $response_headers[$key] = $value;
        }
        return $response_headers;
    }

    /**
     * Set Encoded Cookie
     *
     * @param string $key
     * @param string $value
     */
    private function setEncodedCookie( string $key, string $value ) : void
    {
        $name_chars = [];

        foreach ( \str_split( $key ) as $name_char ) {
            if ( isset( $this->rfc2616[$name_char] ) ) {
                $name_chars[] = $name_char;
            }
            else {
                $name_chars[] = \rawurlencode( $name_char );
            }
        }

        $value_chars = [];

        foreach ( \str_split( $value ) as $value_char ) {
            if ( isset( $this->rfc6265[$value_char] ) ) {
                $value_chars[] = $value_char;
            }
            else {
                $value_chars[] = \rawurlencode( $value_char );
            }
        }

        $this->cookies[\implode( '', $name_chars )] = \implode( '', $value_chars );
    }

    /**
     * Set Stop
     *
     * Specify a callable decider to stop the request early without waiting for
     * the full response to be received.
     *
     * The callable is passed two parameters. The first is the cURL resource,
     * the second is a string with header data. Both parameters match the
     * parameters in the CURLOPT_HEADERFUNCTION callback.
     *
     * The callable must return a truthy value for the request to be stopped
     * early.
     *
     * The callable may be set to null to avoid calling the stop request decider
     * callback and instead just check the value of stopRequest for attempting
     * to stop the request as used by {@see Curl::stop()}.
     *
     * @param null|callable $callback
     */
    public function setStop( ?callable $callback = null ) : void
    {
        $this->headerCallbackData->stopRequestDecider = $callback;
        $this->headerCallbackData->stopRequest        = false;

        $header_callback_data = $this->headerCallbackData;
        $this->progress( createStopRequestFunction( $header_callback_data ) );
    }

    /**
     * @return void
     */
    private function setStopInternal() : void
    {
        $this->headerCallbackData->stopRequestDecider = null;
        $this->headerCallbackData->stopRequest        = false;

        $header_callback_data = $this->headerCallbackData;
        $this->progressInternal( createStopRequestFunction( $header_callback_data ) );
    }

    public function stop() : void
    {
        $this->headerCallbackData->stopRequest = true;
    }

    /**
     * Array Flatten Multidimensional
     *
     * @param mixed       $array
     * @param bool|string $prefix
     *
     * @return array<array-key,mixed>
     */
    private function flattenDataArray( mixed $array, bool|string $prefix = false ) : array
    {
        $return = [];
        if ( \is_array( $array ) || \is_object( $array ) ) {
            if ( empty( $array ) ) {
                $return[$prefix] = '';
            }
            else {
                $arrays_to_merge = [];

                foreach ( $array as $key => $value ) {
                    if ( \is_scalar( $value ) ) {
                        if ( $prefix ) {
                            $arrays_to_merge[] = [
                                $prefix.'['.$key.']' => $value,
                            ];
                        }
                        else {
                            $arrays_to_merge[] = [
                                $key => $value,
                            ];
                        }
                    }
                    elseif ( $value instanceof CURLStringFile ) {
                        $arrays_to_merge[] = [
                            $key => $value,
                        ];
                    }
                    elseif ( $value instanceof CURLFile ) {
                        $arrays_to_merge[] = [
                            $key => $value,
                        ];
                    }
                    else {
                        $arrays_to_merge[] = $this->flattenDataArray(
                            $value,
                            $prefix ? $prefix.'['.$key.']' : $key,
                        );
                    }
                }

                $return = \array_merge( $return, ...$arrays_to_merge );
            }
        }
        elseif ( $array === null ) {
            $return[$prefix] = $array;
        }
        return $return;
    }

    private function multidimensionalArray( mixed $data ) : bool
    {
        if ( ! \is_array( $data ) ) {
            return false;
        }

        return (bool) \count( \array_filter( $data, 'is_array' ) );
    }

    public static function getVersion() : array
    {
        return \curl_version() ?: [];
    }

    /**
     * ⚠️ Some options have duplicate names.
     * - Example: `119` - `CURLOPT_FTP_SSL`, `CURLOPT_USE_SSL`
     *
     * `$flagKeys` the flag integer is used as the key, removing duplicate entries.
     *
     * @param bool $flagKeys
     *
     * @return array
     */
    public static function getOptionsArray( bool $flagKeys = false ) : array
    {
        $constants = \array_filter(
            \get_defined_constants(),
            fn( $key ) => \str_starts_with( $key, 'CURLOPT' ),
            ARRAY_FILTER_USE_KEY,
        );

        return $flagKeys
                ? \array_flip( $constants )
                : $constants;
    }
}

/**
 * Create Header Callback
 *
 * Gather headers and parse cookies as response headers are received. Keep this function separate from the class so that
 * unset($curl) automatically calls __destruct() as expected. Otherwise, manually calling $curl->close() will be
 * necessary to prevent a memory leak.
 *
 * @param $header_callback_data
 *
 * @return callable
 */
function createHeaderCallback( $header_callback_data ) : callable
{
    return function( $ch, $header ) use ( $header_callback_data ) {
        if ( \preg_match( '/^Set-Cookie:\s*([^=]+)=([^;]+)/mi', $header, $cookie ) === 1 ) {
            $header_callback_data->responseCookies[$cookie[1]] = \trim( $cookie[2], " \n\r\t\0\x0B" );
        }

        if ( $header_callback_data->stopRequestDecider !== null ) {
            $stop_request_decider = $header_callback_data->stopRequestDecider;
            if ( $stop_request_decider( $ch, $header ) ) {
                $header_callback_data->stopRequest = true;
            }
        }

        $header_callback_data->rawResponseHeaders .= $header;
        return \strlen( $header );
    };
}

/**
 * Create Stop Request Function
 *
 * Create a function for Curl::progress() that stops a request early when the
 * stopRequest flag is on. Keep this function separate from the class to prevent
 * a memory leak.
 *
 * @param $header_callback_data
 *
 * @return callable
 */
function createStopRequestFunction( $header_callback_data ) : callable
{
    return function(
        $resource,
        $download_size,
        $downloaded,
        $upload_size,
        $uploaded,
    ) use (
        $header_callback_data,
    ) {
        // Abort the transfer when the stop request flag has been set by returning a non-zero value.
        return $header_callback_data->stopRequest ? 1 : 0;
    };
}
