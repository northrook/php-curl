<?php

declare(strict_types=1);

namespace Support\Curl;

use InvalidArgumentException;
use LengthException;
use LogicException;
use RuntimeException;
use Symfony\Component\Filesystem\Exception\{FileNotFoundException};
use const PHP_URL_HOST;

/**
 * @internal
 */
abstract class AbstractCurl
{
    private static ?string $hasError = null;

    private string $tempDirectory;

    /** @var ?callable */
    public mixed $beforeSendCallback = null;

    /** @var ?callable */
    public mixed $afterSendCallback = null;

    /** @var ?callable */
    public mixed $successCallback = null;

    /** @var ?callable */
    public mixed $errorCallback = null;

    /** @var ?callable */
    public mixed $completeCallback = null;

    /** @var ?array<array-key, mixed> */
    protected ?array $options = [];

    /** @var ?array<array-key, mixed> */
    protected ?array $userSetOptions = [];

    /**
     * Before Send
     *
     * @param $callback callable|null
     */
    public function beforeSend( mixed $callback ) : void
    {
        $this->beforeSendCallback = $callback;
    }

    abstract public function close();

    /**
     * Complete
     *
     * @param $callback callable|null
     */
    public function complete( mixed $callback ) : void
    {
        $this->completeCallback = $callback;
    }

    /**
     * Disable Timeout
     */
    public function disableTimeout() : void
    {
        $this->setTimeout( null );
    }

    /**
     * Error
     *
     * @param $callback callable|null
     */
    public function error( mixed $callback ) : void
    {
        $this->errorCallback = $callback;
    }

    /**
     * @param int|string $option
     *
     * @return mixed
     */
    public function getOpt( int|string $option ) : mixed
    {
        return $this->options[$option] ?? null;
    }

    /**
     * Remove Header
     *
     * Remove an internal header from the request.
     * Using `curl -H "Host:" ...' is equivalent to $curl->removeHeader('Host');.
     *
     * @param $key
     */
    public function removeHeader( $key ) : void
    {
        $this->setHeader( $key, '' );
    }

    /**
     * Set auto referer
     *
     * @param bool $auto_referer
     */
    public function setAutoReferer( bool $auto_referer = true ) : void
    {
        $this->setAutoReferrer( $auto_referer );
    }

    /**
     * Set auto referrer
     *
     * @param bool $auto_referrer
     */
    public function setAutoReferrer( bool $auto_referrer = true ) : void
    {
        $this->setOpt( CURLOPT_AUTOREFERER, $auto_referrer );
    }

    /**
     * Set Basic Authentication
     *
     * @param string $username
     * @param string $password
     */
    public function setBasicAuthentication( string $username, string $password = '' ) : void
    {
        $this->setOpt( CURLOPT_HTTPAUTH, CURLAUTH_BASIC );
        $this->setOpt( CURLOPT_USERPWD, $username.':'.$password );
    }

    /**
     * Set Connect Timeout
     *
     * @param $seconds
     */
    public function setConnectTimeout( $seconds ) : void
    {
        $this->setOpt( CURLOPT_CONNECTTIMEOUT, $seconds );
    }

    abstract public function setCookie( string $key, string $value ) : void;

    /**
     * @param resource|string $cookie_file
     */
    abstract public function setCookieFile( mixed $cookie_file ) : self;

    abstract public function setCookieJar( mixed $cookie_jar ) : self;

    abstract public function setCookieString( string $string ) : self;

    /**
     * @param array<string, string> $cookies
     */
    abstract public function setCookies( array $cookies ) : void;

    /**
     * Set Digest Authentication
     *
     * @param string $username
     * @param string $password
     */
    public function setDigestAuthentication( string $username, string $password = '' ) : void
    {
        $this->setOpt( CURLOPT_HTTPAUTH, CURLAUTH_DIGEST );
        $this->setOpt( CURLOPT_USERPWD, $username.':'.$password );
    }

    /**
     * After Send
     *
     * This function is called after the request has been sent.
     *
     * It can be used to override whether or not the request errored. The
     * instance is passed as the first argument to the function, and the instance
     * has attributes like $instance->httpStatusCode and $instance->response to
     * help decide if the request errored. Set $instance->error to true or false
     * within the function.
     *
     * When $instance->error is true indicating a request error, the error
     * callback set by Curl::error() is called. When $instance->error is false,
     * the success callback set by Curl::success() is called.
     *
     * @param $callback null|callable
     */
    public function afterSend( ?callable $callback ) : void
    {
        $this->afterSendCallback = $callback;
    }

    final protected function tempFile( string $filename ) : string
    {
        return $this::normalizePath(
            [
                $this->tempDirectory(),
                \hash( 'xxh32', $filename ).'.tmp',
            ],
        );
    }

    /**
     * Set the `temp` directory used by {@see Curl}.
     *
     * @param null|string $tempDirectory
     * @param bool        $allowTraversal
     * @param bool        $createDirectory
     *
     * @return string
     */
    final public function tempDirectory(
        ?string $tempDirectory = null,
        bool    $allowTraversal = false,
        bool    $createDirectory = true,
    ) : string {
        if ( $tempDirectory ) {
            $this->tempDirectory = static::normalizePath(
                $tempDirectory,
                $allowTraversal,
            );
        }

        $this->tempDirectory ??= static::normalizePath(
            [\sys_get_temp_dir(), \hash( 'xxh32', __FILE__ )],
        );

        if ( ! \is_dir( $this->tempDirectory ) && $createDirectory ) {
            \mkdir( $this->tempDirectory, 0777, true );
        }

        return $this->tempDirectory;
    }

    /**
     * # Normalise a `string` or `string[]`, assuming it is a `path`.
     *
     * - If an array of strings is passed, they will be joined using the directory separator.
     * - Normalises slashes to system separator.
     * - Removes repeated separators.
     * - Will throw a {@see \ValueError} if the resulting string exceeds {@see \PHP_MAXPATHLEN}.
     *
     * ```
     * normalizePath( './assets\\\/scripts///example.js' );
     * // => './assets/scripts/example.js'
     * ```
     *
     * @param null|array<array-key,null|string>|string $path
     * @param bool                                     $traversal
     * @param bool                                     $throwOnFault
     * @param ?string                                  $separator
     *
     * @return string
     */
    final public static function normalizePath(
        null|string|array $path,
        bool              $traversal = false,
        bool              $throwOnFault = false,
        ?string           $separator = null,
    ) : string {
        // Return early on an empty $path
        if ( ! $path ) {
            return $throwOnFault
                    ? throw new InvalidArgumentException(
                        'The provided path is empty: '.\var_export( $path, true ),
                    )
                    : '';
        }

        $separator ??= \defined( 'DIR_SEP' ) ? \constant( 'DIR_SEP' ) : DIRECTORY_SEPARATOR;

        // Resolve provided $path
        $path = \is_array( $path ) ? \implode( $separator, \array_filter( $path ) ) : $path;

        // Normalize separators
        $path = \strtr( $path, '\\', $separator );

        // Check for starting separator
        $relative = match ( true ) {
            $path[0] === $separator                     => $separator,
            $path[0] === '.' && $path[1] === $separator => '.'.$separator,
            default                                     => null,
        };

        if ( $traversal && $relative ) {
            $traversal = $throwOnFault && throw new LogicException(
                'Cannot traverse relative path: '.\var_export( $path, true ),
            );
        }

        $fragments = [];

        // Deduplicate separators and handle traversal
        foreach ( \explode( $separator, $path ) as $fragment ) {
            // Ensure each part does not start or end with illegal characters
            $fragment = \trim( $fragment, " \n\r\t\v\0\\/" );

            if ( ! $fragment ) {
                continue;
            }

            if ( $traversal // if we are allowed to traverse
                 && $fragment === '..' // and this fragment traverses
                 && $fragments // and we have at least one parent
                 && \end( $fragments ) !== '..' // and the parent isn't traversing
            ) {
                \array_pop( $fragments );
            }
            elseif ( $fragment !== '.' ) {
                $fragments[] = $fragment;
            }
        }

        // Implode, preserving intended relative paths
        $path = $relative.\implode( $separator, $fragments );

        if ( ( $length = \mb_strlen( $path ) ) > ( $limit = PHP_MAXPATHLEN ) ) {
            $method  = __METHOD__;
            $length  = (string) $length;
            $limit   = (string) $limit;
            $message = "{$method} resulted in a string of {$length}, exceeding the {$limit} character limit.";
            $result  = 'Operation was halted to prevent overflow.';
            throw new LengthException( $message.PHP_EOL.$result );
        }

        if ( ! $path ) {
            return $throwOnFault
                    ? throw new InvalidArgumentException(
                        'The provided path is empty: '.\var_export( $path, true ),
                    )
                    : '';
        }

        return $path;
    }

    /**
     * Set File
     *
     * @param false|resource $file
     */
    public function setFile( mixed $file ) : void
    {
        $this->setOpt( CURLOPT_FILE, $file );
    }

    /**
     * @param false|resource $file
     *
     * @return void
     */
    protected function setFileInternal( mixed $file ) : void
    {
        $this->setOptInternal( CURLOPT_FILE, $file );
    }

    /**
     * Set follow location
     *
     * @param bool $follow_location
     *
     * @see    Curl::setMaximumRedirects()
     */
    public function setFollowLocation( bool $follow_location = true ) : void
    {
        $this->setOpt( CURLOPT_FOLLOWLOCATION, $follow_location );
    }

    /**
     * Set forbid reuse
     *
     * @param bool $forbid_reuse
     */
    public function setForbidReuse( bool $forbid_reuse = true ) : void
    {
        $this->setOpt( CURLOPT_FORBID_REUSE, $forbid_reuse );
    }

    /**
     * @param string $key
     * @param scalar $value
     */
    abstract public function setHeader( string $key, mixed $value ) : void;

    /**
     * @param array<array-key,mixed> $headers
     */
    abstract public function setHeaders( array $headers ) : void;

    /**
     * Set Interface
     *
     * The name of the outgoing network interface to use.
     * This can be an interface name, an IP address or a host name.
     *
     * @param mixed $interface
     */
    public function setInterface( mixed $interface ) : void
    {
        $this->setOpt( CURLOPT_INTERFACE, $interface );
    }

    /**
     * Set JSON Decoder
     *
     * @param callable|false $decoder
     */
    abstract public function setJsonDecoder( false|callable $decoder ) : void;

    /**
     * Set maximum redirects
     *
     * @param int $maximum_redirects
     *
     * @see    Curl::setFollowLocation()
     */
    public function setMaximumRedirects( int $maximum_redirects ) : void
    {
        $this->setOpt( CURLOPT_MAXREDIRS, $maximum_redirects );
    }

    abstract public function setOpt( int|string $option, mixed $value ) : self;

    abstract public function setOpts( array $options ) : bool;

    protected function setOptInternal( int|string $option, mixed $value ) : bool
    {
        return true;
    }

    /**
     * Set Port
     *
     * @param $port
     */
    public function setPort( $port ) : void
    {
        $this->setOpt( CURLOPT_PORT, (int) $port );
    }

    /**
     * Set Proxy
     *
     * Set an HTTP proxy to tunnel requests through.
     *
     * @param $proxy    - The HTTP proxy to tunnel requests through. May include port number.
     * @param $port     - The port number of the proxy to connect to. This port number can also be set in $proxy.
     * @param $username - The username to use for the connection to the proxy
     * @param $password - The password to use for the connection to the proxy
     */
    public function setProxy( $proxy, $port = null, $username = null, $password = null ) : void
    {
        $this->setOpt( CURLOPT_PROXY, $proxy );
        if ( $port !== null ) {
            $this->setOpt( CURLOPT_PROXYPORT, $port );
        }
        if ( $username !== null && $password !== null ) {
            $this->setOpt( CURLOPT_PROXYUSERPWD, $username.':'.$password );
        }
    }

    /**
     * Set Proxy Auth
     *
     * Set the HTTP authentication method(s) to use for the proxy connection.
     *
     * @param $auth
     */
    public function setProxyAuth( $auth ) : void
    {
        $this->setOpt( CURLOPT_PROXYAUTH, $auth );
    }

    /**
     * Set Proxy Tunnel
     *
     * Set the proxy to tunnel through HTTP proxy.
     *
     * @param bool $tunnel
     */
    public function setProxyTunnel( bool $tunnel = true ) : void
    {
        $this->setOpt( CURLOPT_HTTPPROXYTUNNEL, $tunnel );
    }

    /**
     * Set Proxy Type
     *
     * Set the proxy protocol type.
     *
     * @param $type
     */
    public function setProxyType( $type ) : void
    {
        $this->setOpt( CURLOPT_PROXYTYPE, $type );
    }

    /**
     * Set Range
     *
     * @param $range
     */
    public function setRange( $range ) : void
    {
        $this->setOpt( CURLOPT_RANGE, $range );
    }

    protected function setRangeInternal( $range ) : void
    {
        $this->setOptInternal( CURLOPT_RANGE, $range );
    }

    /**
     * Set Referer
     *
     * @param $referer
     */
    public function setReferer( $referer ) : void
    {
        $this->setReferrer( $referer );
    }

    /**
     * Set Referrer
     *
     * @param $referrer
     */
    public function setReferrer( $referrer ) : void
    {
        $this->setOpt( CURLOPT_REFERER, $referrer );
    }

    /**
     * Set Retry logic.
     *
     * - `int` number of retries to attempt
     * - `callable` the retry decider
     *
     * @param callable|int $retry
     */
    abstract public function setRetry( int|callable $retry ) : void;

    /**
     * Set Timeout
     *
     * @param null|int $seconds
     */
    public function setTimeout( ?int $seconds ) : void
    {
        $this->setOpt( CURLOPT_TIMEOUT, $seconds );
    }

    protected function setTimeoutInternal( int $seconds ) : void
    {
        $this->setOptInternal( CURLOPT_TIMEOUT, $seconds );
    }

    abstract public function setUrl( string $url, mixed $data = '' );

    /**
     * Set User Agent
     *
     * @param $user_agent
     */
    public function setUserAgent( $user_agent ) : void
    {
        $this->setOpt( CURLOPT_USERAGENT, $user_agent );
    }

    /**
     * @param string $user_agent
     *
     * @return void
     */
    protected function setUserAgentInternal( string $user_agent ) : void
    {
        $this->setOptInternal( CURLOPT_USERAGENT, $user_agent );
    }

    abstract public function setXmlDecoder( $mixed );

    /**
     * Stop
     *
     * Attempt to stop the request.
     *
     * Used by {@see MultiCurl::stop()} when making multiple parallel requests.
     */
    abstract public function stop();

    /**
     * Success
     *
     * @param $callback callable|null
     */
    public function success( mixed $callback ) : void
    {
        $this->successCallback = $callback;
    }

    abstract public function unsetHeader( $key );

    /**
     * Unset Proxy
     *
     * Disable use of the proxy.
     */
    public function unsetProxy() : void
    {
        $this->setOpt( CURLOPT_PROXY, null );
    }

    /**
     * Verbose
     *
     * @param bool            $on
     * @param resource|string $output
     */
    public function verbose( bool $on = true, mixed $output = 'STDERR' ) : void
    {
        if ( $output === 'STDERR' ) {
            if ( \defined( 'STDERR' ) ) {
                $output = STDERR;
            }
            else {
                $output = \fopen( 'php://stderr', 'wb' );
            }
        }

        // Turn off CURLINFO_HEADER_OUT for verbose to work. This has the side
        // effect of causing Curl::requestHeaders to be empty.
        if ( $on ) {
            $this->setOpt( CURLINFO_HEADER_OUT, false );
        }
        $this->setOpt( CURLOPT_VERBOSE, $on );
        $this->setOpt( CURLOPT_STDERR, $output );
    }

    /**
     * Copies a file.
     *
     * If the target file is older than the origin file, it's always overwritten.
     * If the target file is newer, it is overwritten only when the
     * $overwriteNewerFiles option is set to true.
     *
     * @throws FileNotFoundException When `$originFile` doesn't exist
     * @throws RuntimeException      When copy fails
     *
     * @param string    $originFile
     * @param string    $targetFile
     * @param bool      $overwriteNewerFiles
     * @param false|int $createDirectory
     */
    final protected function copyFile(
        string    $originFile,
        string    $targetFile,
        bool      $overwriteNewerFiles = false,
        false|int $createDirectory = 0777,
    ) : void {
        $originIsLocal = \stream_is_local( $originFile ) || \stripos( $originFile, 'file://' ) === 0;
        if ( $originIsLocal && ! \is_file( $originFile ) ) {
            throw new FileNotFoundException(
                \sprintf( 'Failed to copy "%s" because file does not exist.', $originFile ),
                0,
                null,
                $originFile,
            );
        }

        $this->makeDirectory( \dirname( $targetFile ) );

        $doCopy = true;

        if ( ! $overwriteNewerFiles && ! \parse_url( $originFile, PHP_URL_HOST ) && \is_file( $targetFile ) ) {
            $doCopy = \filemtime( $originFile ) > \filemtime( $targetFile );
        }

        if ( $doCopy ) {
            // https://bugs.php.net/64634
            if ( ! $source = self::safeCall( 'fopen', $originFile, 'r' ) ) {
                throw new RuntimeException(
                    "Failed to copy '{$originFile}' to '{$targetFile}': ".self::$hasError,
                );
            }

            // Stream context created to allow files to overwrite when using FTP stream wrapper - disabled by default
            if ( ! $target = self::safeCall(
                'fopen',
                $targetFile,
                'w',
                false,
                \stream_context_create( ['ftp' => ['overwrite' => true]] ),
            ) ) {
                throw new RuntimeException(
                    "Failed to copy '{$originFile}' to '{$targetFile}' because target file could not be opened for writing: ".self::$hasError,
                );
            }

            $bytesCopied = \stream_copy_to_stream( $source, $target );
            \fclose( $source );
            \fclose( $target );
            unset( $source, $target );

            if ( ! \is_file( $targetFile ) ) {
                throw new RuntimeException(
                    "Failed to copy '{$originFile}' to '{$targetFile}'.",
                );
            }

            if ( $originIsLocal ) {
                // Like `cp`, preserve executable permission bits
                self::safeCall(
                    'chmod',
                    $targetFile,
                    \fileperms( $targetFile ) | ( \fileperms( $originFile ) & 0111 ),
                );

                // Like `cp`, preserve the file modification time
                self::safeCall( 'touch', $targetFile, \filemtime( $originFile ) );

                if ( $bytesCopied !== $bytesOrigin = \filesize( $originFile ) ) {
                    throw new RuntimeException(
                        \sprintf(
                            "Failed to copy the whole content of '%s' to '%s' (%g of %g bytes copied).",
                            $originFile,
                            $targetFile,
                            $bytesCopied,
                            $bytesOrigin,
                        ),
                    );
                }
            }
        }
    }

    final protected function makeDirectory(
        string    $path,
        false|int $mode = 0777,
    ) : void {
        if ( \is_dir( $path ) || $mode === false ) {
            return;
        }
        if ( ! self::safeCall( 'mkdir', $path, $mode, true ) && ! \is_dir( $path ) ) {
            throw new RuntimeException( "Failed to create '{$path}': ".self::$hasError );
        }
    }

    final protected function removeFile( string $path ) : void
    {
        if ( ! self::safeCall( 'unlink', $path ) && (
            self::hasError( 'Permission denied' ) || \file_exists( $path )
        )
        ) {
            throw new RuntimeException( "Failed to remove file '{$path}': ".self::$hasError );
        }
    }

    final public function hasError( ?string $contains = null ) : bool|string
    {
        if ( self::$hasError === null ) {
            return false;
        }

        if ( $contains && \str_contains( self::$hasError, $contains ) ) {
            return true;
        }

        return self::$hasError;
    }

    final protected static function safeCall( string $callback, mixed ...$args ) : mixed
    {
        \assert(
            \is_callable( $callback ),
            "Unable to perform call: '{$callback}' is not a callable.",
        );

        self::$hasError = null;
        \set_error_handler( self::errorHandler( ... ) );
        try {
            return $callback( ...$args );
        }
        finally {
            \restore_error_handler();
        }
    }

    /**
     * @internal
     *
     * @param int    $type
     * @param string $message
     */
    final public static function errorHandler( int $type, string $message ) : void
    {
        self::$hasError = $message;
    }
}
