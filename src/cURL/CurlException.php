<?php

declare(strict_types=1);

namespace Support\cURL;

use Exception;
use Throwable;

final class CurlException extends Exception
{
    public function __construct(
        public readonly int    $httpCode,
        public readonly string $curlError,
        ?string                $message = null,
        ?Throwable             $previous = null,
    ) {
        if ( ! $message ) {
            $message = $this->getThrowCall();
            $message = $message ? ' ' : '';
            $message .= "[{$httpCode}] ".$curlError;
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
