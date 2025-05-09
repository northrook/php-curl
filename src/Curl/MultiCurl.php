<?php

declare(strict_types=1);

namespace Support\Curl;

use CurlMultiHandle;
use ErrorException;
use Support\Curl;
use UnexpectedValueException;

final class MultiCurl extends AbstractCurl
{
    public ?string $baseUrl;

    /** @var null|CurlMultiHandle|resource */
    public mixed $multiCurl;

    public float $startTime;

    public float $stopTime;

    private array $queuedCurls = [];

    private array $activeCurls = [];

    private bool $isStarted = false;

    private float $currentStartTime;

    private int $currentRequestCount = 0;

    private int $concurrency = 25;

    private int $nextCurlId = 0;

    private bool $preferRequestTimeAccuracy = false;

    // private $rateLimit;

    private bool $rateLimitEnabled = false;

    private bool $rateLimitReached = false;

    private int $maxRequests;

    // private int $interval;

    private ?float $intervalSeconds;

    // private string $unit;

    /** @var callable|int */
    private mixed $retry;

    private array $cookies = [];

    /** @var ArrayData */
    private ArrayData $headers;

    private array $instanceSpecificOptions = [];

    private array $proxies;

    /** @var callable|false */
    private mixed $jsonDecoder;

    /** @var callable|false */
    private mixed $xmlDecoder;

    /**
     * Construct
     *
     * @param null|string $base_url
     */
    public function __construct( ?string $base_url = null )
    {
        $this->multiCurl = \curl_multi_init();
        $this->headers   = new ArrayData();

        if ( $base_url !== null ) {
            $this->setUrl( $base_url );
        }
    }

    /**
     * Add Delete
     *
     * @param string|string[] $url
     * @param mixed           $query_parameters
     * @param mixed           $data
     *
     * @return Curl
     */
    public function addDelete(
        string|array $url,
        mixed        $query_parameters = [],
        mixed        $data = [],
    ) : Curl {
        if ( \is_array( $url ) ) {
            $data             = $query_parameters;
            $query_parameters = $url;
            $url              = $this->baseUrl;
        }

        $curl = new Curl( $this->baseUrl, $this->options );
        $this->queueHandle( $curl );
        $this->setUrl( $url, $query_parameters );
        $curl->setUrl( $url, $query_parameters );
        $curl->setOpt( CURLOPT_CUSTOMREQUEST, 'DELETE' );
        $curl->setOpt( CURLOPT_POSTFIELDS, $curl->buildPostData( $data ) );
        return $curl;
    }

    /**
     * Add Download
     *
     * @param $url
     * @param $mixed_filename
     *
     * @return Curl
     */
    public function addDownload( $url, $mixed_filename ) : Curl
    {
        $curl = new Curl( $this->baseUrl, $this->options );
        $this->queueHandle( $curl );
        $this->setUrl( $url );
        $curl->setUrl( $url );

        /** Use {@see tmpfile()} or php://temp to avoid "Too many open files" error. */
        if ( \is_callable( $mixed_filename ) ) {
            $curl->downloadCompleteCallback = $mixed_filename;
            $curl->downloadFileName         = null;
            $curl->fileHandle               = \tmpfile();
        }
        else {
            $filename = $mixed_filename;

            // Use a temporary file when downloading. Not using a temporary file can cause an error when an existing
            // file has already fully completed downloading and a new download is started with the same destination save
            // path. The download request will include the header "Range: bytes=$filesize-" which is syntactically valid,
            // but unsatisfiable.
            $download_filename      = $filename.'.tmp';
            $curl->downloadFileName = $download_filename;

            // Attempt to resume download only when a temporary download file exists and is not empty.
            if ( \is_file( $download_filename ) && $filesize = \filesize( $download_filename ) ) {
                $first_byte_position = $filesize;
                $range               = $first_byte_position.'-';
                $curl->setRange( $range );
                $curl->fileHandle = \fopen( $download_filename, 'ab' );

                // Move the downloaded temporary file to the destination save path.
                $curl->downloadCompleteCallback = function( $instance, $fh ) use ( $download_filename, $filename ) {
                    // Close the open file handle before renaming the file.
                    if ( \is_resource( $fh ) ) {
                        \fclose( $fh );
                    }

                    \rename( $download_filename, $filename );
                };
            }
            else {
                $curl->fileHandle               = \fopen( 'php://temp', 'wb' );
                $curl->downloadCompleteCallback = function( $instance, $fh ) use ( $filename ) {
                    $contents = \stream_get_contents( $fh );
                    if ( $contents !== false ) {
                        \file_put_contents( $filename, $contents );
                    }
                };
            }
        }

        $curl->setFile( $curl->fileHandle );
        $curl->setOpt( CURLOPT_CUSTOMREQUEST, 'GET' );
        $curl->setOpt( CURLOPT_HTTPGET, true );
        return $curl;
    }

    /**
     * Add Get
     *
     * @param string|string[] $url
     * @param mixed           $data
     *
     * @return Curl
     */
    public function addGet(
        string|array $url,
        mixed        $data = [],
    ) : Curl {
        if ( \is_array( $url ) ) {
            $data = $url;
            $url  = $this->baseUrl;
        }

        $curl = new Curl( $this->baseUrl, $this->options );
        $this->queueHandle( $curl );
        $this->setUrl( $url, $data );
        $curl->setUrl( $url, $data );
        $curl->setOpt( CURLOPT_CUSTOMREQUEST, 'GET' );
        $curl->setOpt( CURLOPT_HTTPGET, true );
        return $curl;
    }

    /**
     * Add Head
     *
     * @param string|string[] $url
     * @param mixed           $data
     *
     * @return Curl
     */
    public function addHead(
        string|array $url,
        mixed        $data = [],
    ) : Curl {
        if ( \is_array( $url ) ) {
            $data = $url;
            $url  = $this->baseUrl;
        }

        $curl = new Curl( $this->baseUrl, $this->options );
        $this->queueHandle( $curl );
        $this->setUrl( $url, $data );
        $curl->setUrl( $url, $data );
        $curl->setOpt( CURLOPT_CUSTOMREQUEST, 'HEAD' );
        $curl->setOpt( CURLOPT_NOBODY, true );
        return $curl;
    }

    /**
     * Add Options
     *
     * @param string|string[] $url
     * @param mixed           $data
     *
     * @return Curl
     */
    public function addOptions(
        string|array $url,
        mixed        $data = [],
    ) : Curl {
        if ( \is_array( $url ) ) {
            $data = $url;
            $url  = $this->baseUrl;
        }

        $curl = new Curl( $this->baseUrl, $this->options );
        $this->queueHandle( $curl );
        $this->setUrl( $url, $data );
        $curl->setUrl( $url, $data );
        $curl->removeHeader( 'Content-Length' );
        $curl->setOpt( CURLOPT_CUSTOMREQUEST, 'OPTIONS' );
        return $curl;
    }

    /**
     * Add Patch
     *
     * @param string|string[] $url
     * @param mixed           $data
     *
     * @return Curl
     */
    public function addPatch(
        string|array $url,
        mixed        $data = [],
    ) : Curl {
        if ( \is_array( $url ) ) {
            $data = $url;
            $url  = $this->baseUrl;
        }

        $curl = new Curl( $this->baseUrl, $this->options );

        if ( \is_array( $data ) && empty( $data ) ) {
            $curl->removeHeader( 'Content-Length' );
        }

        $this->queueHandle( $curl );
        $this->setUrl( $url );
        $curl->setUrl( $url );
        $curl->setOpt( CURLOPT_CUSTOMREQUEST, 'PATCH' );
        $curl->setOpt( CURLOPT_POSTFIELDS, $curl->buildPostData( $data ) );
        return $curl;
    }

    /**
     * Add Post
     *
     * @param string|string[] $url
     * @param mixed           $data
     * @param bool            $follow_303_with_post
     *                                              If true will cause 303 redirections to be followed using a POST request
     *                                              (default: false). Note: Redirections are only followed if the
     *                                              CURLOPT_FOLLOWLOCATION option is set to true.
     *
     * @return Curl
     */
    public function addPost(
        string|array $url,
        mixed        $data = '',
        bool         $follow_303_with_post = false,
    ) : Curl {
        if ( \is_array( $url ) ) {
            $follow_303_with_post = (bool) $data;
            $data                 = $url;
            $url                  = $this->baseUrl;
        }

        $curl = new Curl( $this->baseUrl, $this->options );
        $this->queueHandle( $curl );
        $this->setUrl( $url );

        if ( \is_array( $data ) && empty( $data ) ) {
            $curl->removeHeader( 'Content-Length' );
        }

        $curl->setUrl( $url );

        // Set the request method to "POST" when following a 303 redirect with
        // an additional POST request is desired. This is equivalent to setting
        // the -X, --request command line option where curl won't change the
        // request method according to the HTTP 30x response code.
        if ( $follow_303_with_post ) {
            $curl->setOpt( CURLOPT_CUSTOMREQUEST, 'POST' );
        }

        $curl->setOpt( CURLOPT_POST, true );
        $curl->setOpt( CURLOPT_POSTFIELDS, $curl->buildPostData( $data ) );
        return $curl;
    }

    /**
     * Add Put
     *
     * @param string|string[] $url
     * @param mixed           $data
     *
     * @return Curl
     */
    public function addPut(
        string|array $url,
        mixed        $data = [],
    ) : Curl {
        if ( \is_array( $url ) ) {
            $data = $url;
            $url  = $this->baseUrl;
        }

        $curl = new Curl( $this->baseUrl, $this->options );
        $this->queueHandle( $curl );
        $this->setUrl( $url );
        $curl->setUrl( $url );
        $curl->setOpt( CURLOPT_CUSTOMREQUEST, 'PUT' );
        $put_data = $curl->buildPostData( $data );
        if ( \is_string( $put_data ) ) {
            $curl->setHeader( 'Content-Length', \strlen( $put_data ) );
        }
        $curl->setOpt( CURLOPT_POSTFIELDS, $put_data );
        return $curl;
    }

    /**
     * Add Search
     *
     * @param string|string[] $url
     * @param mixed           $data
     *
     * @return Curl
     */
    public function addSearch(
        string|array $url,
        mixed        $data = [],
    ) : Curl {
        if ( \is_array( $url ) ) {
            $data = $url;
            $url  = $this->baseUrl;
        }

        $curl = new Curl( $this->baseUrl, $this->options );
        $this->queueHandle( $curl );
        $this->setUrl( $url );
        $curl->setUrl( $url );
        $curl->setOpt( CURLOPT_CUSTOMREQUEST, 'SEARCH' );
        $put_data = $curl->buildPostData( $data );
        if ( \is_string( $put_data ) ) {
            $curl->setHeader( 'Content-Length', \strlen( $put_data ) );
        }
        $curl->setOpt( CURLOPT_POSTFIELDS, $put_data );
        return $curl;
    }

    /**
     * Add Curl
     *
     * Add a Curl instance to the handle queue.
     *
     * @param Curl $curl
     *
     * @return Curl
     */
    public function addCurl( Curl $curl ) : Curl
    {
        $this->queueHandle( $curl );
        return $curl;
    }

    /**
     * Close
     */
    public function close() : void
    {
        foreach ( $this->queuedCurls as $curl ) {
            $curl->close();
        }

        if ( \is_resource( $this->multiCurl ) || $this->multiCurl instanceof CurlMultiHandle ) {
            \curl_multi_close( $this->multiCurl );
        }
        $this->multiCurl = null;
    }

    /**
     * Set Concurrency
     *
     * @param $concurrency
     */
    public function setConcurrency( $concurrency ) : void
    {
        $this->concurrency = $concurrency;
    }

    /**
     * Set Cookie
     *
     * @param string $key
     * @param string $value
     */
    public function setCookie( string $key, string $value ) : void
    {
        $this->cookies[$key] = $value;
    }

    /**
     * Set Cookies
     *
     * @param array<string, string> $cookies
     */
    public function setCookies( array $cookies ) : void
    {
        foreach ( $cookies as $key => $value ) {
            $this->cookies[$key] = $value;
        }
    }

    /**
     * Set Cookie String
     *
     * @param $string
     *
     * @return self
     */
    public function setCookieString( $string ) : self
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
     * @param $cookie_jar
     *
     * @return self
     */
    public function setCookieJar( $cookie_jar ) : self
    {
        return $this->setOpt( CURLOPT_COOKIEJAR, $cookie_jar );
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
        $this->headers[$key] = (string) $value;
        $this->updateHeaders();
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

        $this->updateHeaders();
    }

    /**
     * Set JSON Decoder
     *
     * @param callable|false $decoder
     */
    public function setJsonDecoder( false|callable $decoder ) : void
    {
        $this->jsonDecoder = $decoder;
    }

    /**
     * Set XML Decoder
     *
     * @param $mixed boolean|callable
     */
    public function setXmlDecoder( $mixed ) : void
    {
        if ( $mixed === false ) {
            $this->xmlDecoder = false;
        }
        elseif ( \is_callable( $mixed ) ) {
            $this->xmlDecoder = $mixed;
        }
    }

    /**
     * Set Proxies
     *
     * Set proxies to tunnel requests through. When set, a random proxy will be
     * used for the request.
     *
     * @param array $proxies - A list of HTTP proxies to tunnel requests
     *                       through. May include port number.
     */
    public function setProxies( array $proxies ) : void
    {
        $this->proxies = $proxies;
    }

    /**
     * Set Opt
     *
     * @param $option
     * @param $value
     *
     * @return self
     */
    public function setOpt( $option, $value ) : self
    {
        $this->options[$option] = $value;

        // Make changing the url an instance-specific option. Set the value of
        // existing instances when they have not already been set to avoid
        // unexpectedly changing the request url after it has been specified.
        if ( $option === CURLOPT_URL ) {
            foreach ( $this->queuedCurls as $curl_id => $curl ) {
                if (
                    ! isset( $this->instanceSpecificOptions[$curl_id][$option] )
                    || $this->instanceSpecificOptions[$curl_id][$option] === null
                ) {
                    $this->instanceSpecificOptions[$curl_id][$option] = $value;
                }
            }
        }

        return $this;
    }

    /**
     * Set Opts
     *
     * @param array<array-key,mixed> $options
     */
    public function setOpts( array $options ) : bool
    {
        foreach ( $options as $option => $value ) {
            $this->setOpt( $option, $value );
        }
        return true;
    }

    /**
     * Set Rate Limit
     *
     * @param $rate_limit string (e.g. "60/1m").
     *
     * @throws UnexpectedValueException
     */
    public function setRateLimit( string $rate_limit ) : void
    {
        $rate_limit_pattern
                = '#'       // delimiter
                  .'^'       // assert start
                  .'(\d+)'   // digit(s)
                  .'/'      // slash
                  .'(\d+)?'  // digit(s), optional
                  .'([smh])' // unit, s for seconds, m for minutes, h for hours
                  .'$'       // assert end
                  .'#'       // delimiter
                  .'i';
        if ( ! \preg_match( $rate_limit_pattern, $rate_limit, $matches ) ) {
            throw new UnexpectedValueException(
                'rate limit must be formatted as $max_requests/$interval(s|m|h) '
                    .'(e.g. "60/1m" for a maximum of 60 requests per 1 minute)',
            );
        }

        $max_requests = (int) $matches['1'];
        if ( $matches[2] === '' ) {
            $interval = 1;
        }
        else {
            $interval = (int) $matches[2];
        }

        $unit = \strtolower( $matches[3] );

        // Convert the interval to seconds based on the unit.
        $interval_seconds = null;
        if ( $unit === 's' ) {
            $interval_seconds = (float) $interval;
        }
        elseif ( $unit === 'm' ) {
            $interval_seconds = (float) ( $interval * 60 );
        }
        elseif ( $unit === 'h' ) {
            $interval_seconds = (float) ( $interval * 3_600 );
        }

        // $this->rateLimit        = (string) $max_requests.'/'.(string) $interval.$unit;
        $this->rateLimitEnabled = true;
        $this->maxRequests      = $max_requests;
        // $this->interval         = $interval;
        $this->intervalSeconds = $interval_seconds;
        // $this->unit             = $unit;
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
     *
     * @return MultiCurl
     */
    public function setRetry( int|callable $retry ) : self
    {
        $this->retry = $retry;

        return $this;
    }

    /**
     * Set Url
     *
     * @param string $url
     * @param mixed  $data
     *
     * @return MultiCurl
     */
    public function setUrl( string $url, mixed $data = '' ) : self
    {
        $built_url = Url::buildUrl( $url, $data );

        if ( $this->baseUrl === null ) {
            $this->baseUrl = (string) new Url( $built_url );
        }
        else {
            $this->baseUrl = (string) new Url( $this->baseUrl, $built_url );
        }

        return $this->setOpt( CURLOPT_URL, $this->baseUrl );
    }

    /**
     * Start
     *
     * @throws ErrorException
     */
    public function start() : void
    {
        if ( $this->isStarted ) {
            return;
        }

        $this->isStarted           = true;
        $this->startTime           = (float) \microtime( true );
        $this->currentStartTime    = (float) \microtime( true );
        $this->currentRequestCount = 0;

        do {
            while (
                \count( $this->queuedCurls )
                && \count( $this->activeCurls ) < $this->concurrency
                && ( ! $this->rateLimitEnabled || $this->hasRequestQuota() )
            ) {
                $this->initHandle();
            }

            if ( $this->rateLimitEnabled && ! \count( $this->activeCurls ) && ! $this->hasRequestQuota() ) {
                $this->waitUntilRequestQuotaAvailable();
            }

            if ( $this->preferRequestTimeAccuracy ) {
                // Wait for activity on any curl_multi connection when curl_multi_select (libcurl) fails to correctly
                // block.
                // https://bugs.php.net/bug.php?id=63411
                //
                // Also, use a shorter curl_multi_select() timeout instead the default of one second. This allows
                // pending requests to have more accurate start times. Without a shorter timeout, it can be nearly a
                // full second before the available request quota is rechecked and pending requests can be initialized.
                if ( \curl_multi_select( $this->multiCurl, 0.2 ) === -1 ) {
                    \usleep( 100_000 );
                }

                \curl_multi_exec( $this->multiCurl, $active );
            }
            else {
                // Use multiple loops to get data off of the multi handler. Without this, the following error may appear
                // intermittently on certain versions of PHP:
                //   curl_multi_exec(): supplied resource is not a valid cURL handle resource

                // Clear out the curl buffer.
                do {
                    $status = \curl_multi_exec( $this->multiCurl, $active );
                }
                while ( $status === CURLM_CALL_MULTI_PERFORM );

                // Wait for more information and then get that information.
                while ( $active && $status === CURLM_OK ) {
                    // Check if the network socket has some data.
                    if ( \curl_multi_select( $this->multiCurl ) !== -1 ) {
                        // Process the data for as long as the system tells us to keep getting it.
                        do {
                            $status = \curl_multi_exec( $this->multiCurl, $active );
                        }
                        while ( $status === CURLM_CALL_MULTI_PERFORM );
                    }
                }
            }

            while (
                ( \is_resource( $this->multiCurl ) || $this->multiCurl instanceof CurlMultiHandle )
                && ( ( $info_array = \curl_multi_info_read( $this->multiCurl ) ) !== false )
            ) {
                if ( $info_array['msg'] === CURLMSG_DONE ) {
                    foreach ( $this->activeCurls as $key => $curl ) {
                        if ( $curl->curl === $info_array['handle'] ) {
                            // Set the error code for multi handles using the "result" key in the array returned by
                            // curl_multi_info_read(). Using curl_errno() on a multi handle will incorrectly return 0
                            // for errors.
                            $curl->curlErrorCode = $info_array['result'];
                            $curl->exec( $curl->curl );

                            if ( $curl->attemptRetry() ) {
                                // Remove the completed handle before adding again to retry request.
                                \curl_multi_remove_handle( $this->multiCurl, $curl->curl );

                                $curlm_error_code = \curl_multi_add_handle( $this->multiCurl, $curl->curl );
                                if ( $curlm_error_code !== CURLM_OK ) {
                                    throw new ErrorException(
                                        'cURL multi add handle error: '.\curl_multi_strerror( $curlm_error_code ),
                                    );
                                }

                                $curl->call( $curl->beforeSendCallback );
                            }
                            else {
                                $curl->execDone();

                                // Remove completed instances from active curls.
                                unset( $this->activeCurls[$key] );

                                // Remove the handle of the completed instance.
                                \curl_multi_remove_handle( $this->multiCurl, $curl->curl );

                                // Clean up completed instances.
                                $curl->close();
                            }

                            break;
                        }
                    }
                }
            }
        }
        while ( $active || \count( $this->activeCurls ) || \count( $this->queuedCurls ) );

        $this->isStarted = false;
        $this->stopTime  = \microtime( true );
    }

    /**
     * Stop
     */
    public function stop() : void
    {
        // Remove any queued curl requests.
        while ( \count( $this->queuedCurls ) ) {
            $curl = \array_pop( $this->queuedCurls );
            $curl->close();
        }

        // Attempt to stop active curl requests.
        while ( \count( $this->activeCurls ) ) {
            // Remove instance from active curls.
            $curl = \array_pop( $this->activeCurls );

            // Remove any active curl handle.
            \curl_multi_remove_handle( $this->multiCurl, $curl->curl );

            $curl->stop();
        }
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
    }

    /**
     * Set request time accuracy
     */
    public function setRequestTimeAccuracy() : void
    {
        $this->preferRequestTimeAccuracy = true;
    }

    /**
     * Destruct
     */
    public function __destruct()
    {
        $this->close();
    }

    /**
     * Update Headers
     */
    private function updateHeaders() : void
    {
        foreach ( $this->queuedCurls as $curl ) {
            $curl->setHeaders( $this->headers );
        }
    }

    /**
     * Queue Handle
     *
     * @param $curl
     */
    private function queueHandle( $curl ) : void
    {
        // Use sequential ids to allow for ordered post-processing.
        $curl->id                     = $this->nextCurlId++;
        $curl->childOfMultiCurl       = true;
        $this->queuedCurls[$curl->id] = $curl;

        // Avoid overwriting any existing header.
        if ( $curl->getOpt( CURLOPT_HTTPHEADER ) === null ) {
            $curl->setHeaders( $this->headers );
        }
    }

    /**
     * Init Handle
     *
     * @throws ErrorException
     */
    private function initHandle() : void
    {
        $curl = \array_shift( $this->queuedCurls );
        if ( $curl === null ) {
            return;
        }

        // Add the instance to the list of active curls.
        $this->currentRequestCount++;
        $this->activeCurls[$curl->id] = $curl;

        // Set callbacks if not already individually set.
        if ( $curl->beforeSendCallback === null ) {
            $curl->beforeSend( $this->beforeSendCallback );
        }
        if ( $curl->afterSendCallback === null ) {
            $curl->afterSend( $this->afterSendCallback );
        }
        if ( $curl->successCallback === null ) {
            $curl->success( $this->successCallback );
        }
        if ( $curl->errorCallback === null ) {
            $curl->error( $this->errorCallback );
        }
        if ( $curl->completeCallback === null ) {
            $curl->complete( $this->completeCallback );
        }

        // Set decoders if not already individually set.
        if ( $curl->jsonDecoder === null ) {
            $curl->setJsonDecoder( $this->jsonDecoder );
        }
        if ( $curl->xmlDecoder === null ) {
            $curl->setXmlDecoder( $this->xmlDecoder );
        }

        // Set instance-specific options on the Curl instance when present.
        if ( isset( $this->instanceSpecificOptions[$curl->id] ) ) {
            $curl->setOpts( $this->instanceSpecificOptions[$curl->id] );
        }

        $curl->setRetry( $this->retry );
        $curl->setCookies( $this->cookies );

        // Use a random proxy for the curl instance when proxies have been set
        // and the curl instance doesn't already have a proxy set.
        if ( ! empty( $this->proxies ) && $curl->getOpt( CURLOPT_PROXY ) === null ) {
            $random = \mt_rand( 0, \count( $this->proxies ) - 1 );
            $curl->setProxy( $this->proxies[$random] );
        }

        $curlm_error_code = \curl_multi_add_handle( $this->multiCurl, $curl->curl );
        if ( $curlm_error_code !== CURLM_OK ) {
            throw new ErrorException( 'cURL multi add handle error: '.\curl_multi_strerror( $curlm_error_code ) );
        }

        $curl->call( $curl->beforeSendCallback );
    }

    /**
     * Has Request Quota
     *
     * Checks if there is any available quota to make additional requests while
     * rate limiting is enabled.
     */
    private function hasRequestQuota() : bool
    {
        // Calculate if there's a request quota since rate-limiting is enabled.
        if ( $this->rateLimitEnabled ) {
            // Determine if the limit of requests per interval has been reached.
            if ( $this->currentRequestCount >= $this->maxRequests ) {
                $micro_time      = \microtime( true );
                $elapsed_seconds = $micro_time - $this->currentStartTime;
                if ( $elapsed_seconds <= $this->intervalSeconds ) {
                    $this->rateLimitReached = true;
                    return false;
                }
                if ( $this->rateLimitReached ) {
                    $this->rateLimitReached    = false;
                    $this->currentStartTime    = $micro_time;
                    $this->currentRequestCount = 0;
                }
            }

            return true;
        }
        return true;
    }

    /**
     * Wait Until Request Quota Available
     *
     * Waits until there is an available request quota available based on the rate limit.
     */
    private function waitUntilRequestQuotaAvailable() : void
    {
        $sleep_until   = (float) ( $this->currentStartTime + $this->intervalSeconds );
        $sleep_seconds = $sleep_until - \microtime( true );

        // Avoid using time_sleep_until() as it appears to be less precise and not sleep long enough.
        // Avoid using usleep(): "Values larger than 1_000_000 (i.e., sleeping for
        //   more than a second) may not be supported by the operating system.
        //   Use sleep() instead."
        $sleep_seconds_int = (int) $sleep_seconds;
        if ( $sleep_seconds_int >= 1 ) {
            \sleep( $sleep_seconds_int );
        }

        // Ensure that enough time has passed as usleep() may not have waited long enough.
        $this->currentStartTime = \microtime( true );
        if ( $this->currentStartTime < $sleep_until ) {
            do {
                \usleep( 1_000_000 / 4 );
                $this->currentStartTime = \microtime( true );
            }
            while ( $this->currentStartTime < $sleep_until );
        }

        $this->currentRequestCount = 0;
    }

    public function getActiveCurls() : array
    {
        return $this->activeCurls;
    }
}
