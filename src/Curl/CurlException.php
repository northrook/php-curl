<?php

declare(strict_types=1);

namespace Support\Curl;

use Exception;
use Support\Curl;
use Throwable;

final class CurlException extends Exception
{
    public readonly int $httpCode;

    public readonly string $curlError;

    public function __construct(
        public readonly Curl $curl,
        ?string              $message = null,
        ?Throwable           $previous = null,
    ) {
        $this->httpCode  = $this->curl->httpStatusCode ?: 'E_RECOVERABLE_ERROR';
        $this->curlError = $this->curl->errorMessage ?? 'No error message';

        if ( ! $message ) {
            $message = $this->getThrowCall();
            $message = $message ? ' ' : '';
            $message .= "[{$this->httpCode}] ".$this->curlError;
        }

        parent::__construct( $message, E_RECOVERABLE_ERROR, $previous );
    }

    final protected function getThrowCall() : ?string
    {
        $trace = \debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS, 3 );

        $caller = $trace[2] ?? null;

        if ( \count( $trace ) > 2 && $caller ) {
            $class    = $caller['class'] ?? null;
            $function = $caller['function'] ?: null;

            if ( $class ) {
                return "{$class}::{$function}";
            }
            if ( $function ) {
                return $function;
            }
        }

        $file = $trace[1]['file'] ?? null;
        $line = $trace[1]['line'] ?? null;

        if ( $line !== null ) {
            $file .= ":{$line}";
        }

        return $file;
    }
}
