<?php

declare(strict_types=1);

namespace Support\cURL;

use ArrayAccess;
use Countable;
use Iterator;
use ReturnTypeWillChange;

/**
 * @internal
 */
final class ArrayData implements ArrayAccess, Countable, Iterator
{
    /**
     * @var array<array-key,mixed> data storage with lowercase keys
     *
     * @see offsetSet()
     * @see offsetExists()
     * @see offsetUnset()
     * @see offsetGet()
     * @see count()
     * @see current()
     * @see next()
     * @see key()
     */
    private array $data = [];

    /**
     * @var string[] case-sensitive keys
     *
     * @see offsetSet()
     * @see offsetUnset()
     * @see key()
     */
    private array $keys = [];

    /**
     * Construct
     *
     * Allow creating an empty array or converting an existing array to a
     * case-insensitive array. Caution: Data may be lost when converting
     * case-sensitive arrays to case-insensitive arrays.
     *
     * @param array<array-key,mixed> $initial (optional) Existing array to convert
     */
    public function __construct( ?array $initial = null )
    {
        if ( $initial !== null ) {
            foreach ( $initial as $key => $value ) {
                $this->offsetSet( $key, $value );
            }
        }
    }

    /**
     * Offset Set
     *
     * Set data at a specified offset. Converts the offset to lowercase, and
     * stores the case-sensitive offset and the data at the lowercase indexes in
     * $this->keys and @this->data.
     *
     * @param ?string $offset the offset to store the data at (case-insensitive)
     * @param mixed   $value  the data to store at the specified offset
     *
     * @return void
     * @see https://secure.php.net/manual/en/arrayaccess.offsetset.php
     */
    #[ReturnTypeWillChange]
    public function offsetSet( mixed $offset, mixed $value ) : void
    {
        if ( $offset === null ) {
            $this->data[] = $value;
        }
        else {
            $offsetlower              = \strtolower( $offset );
            $this->data[$offsetlower] = $value;
            $this->keys[$offsetlower] = $offset;
        }
    }

    /**
     * Offset Exists
     *
     * Checks if the offset exists in data storage. The index is looked up with
     * the lowercase version of the provided offset.
     *
     * @param string $offset Offset to check
     *
     * @return bool if the offset exists
     * @see https://secure.php.net/manual/en/arrayaccess.offsetexists.php
     */
    #[ReturnTypeWillChange]
    public function offsetExists( $offset ) : bool
    {
        return \array_key_exists( \strtolower( $offset ), $this->data );
    }

    /**
     * Offset Unset
     *
     * Unsets the specified offset. Converts the provided offset to lowercase,
     * and unsets the case-sensitive key, as well as the stored data.
     *
     * @param mixed $offset the offset to unset
     *
     * @return void
     * @see https://secure.php.net/manual/en/arrayaccess.offsetunset.php
     */
    #[ReturnTypeWillChange]
    public function offsetUnset( mixed $offset ) : void
    {
        $offsetlower = \strtolower( $offset );
        unset( $this->data[$offsetlower], $this->keys[$offsetlower] );
    }

    /**
     * Offset Get
     *
     * Return the stored data at the provided offset.
     *
     * The offset is converted to lowercase, and the lookup is done on the data store directly.
     *
     * @param string $offset offset to lookup
     *
     * @return mixed the data stored at the offset
     * @see https://secure.php.net/manual/en/arrayaccess.offsetget.php
     */
    #[ReturnTypeWillChange]
    public function offsetGet( $offset ) : mixed
    {
        $offsetlower = \strtolower( $offset );
        return $this->data[$offsetlower] ?? null;
    }

    /**
     * Count
     *
     * @return int the number of elements stored in the array
     * @see https://secure.php.net/manual/en/countable.count.php
     */
    #[ReturnTypeWillChange]
    public function count() : int
    {
        return \count( $this->data );
    }

    /**
     * Current
     *
     * @return mixed data at the current position
     * @see https://secure.php.net/manual/en/iterator.current.php
     */
    #[ReturnTypeWillChange]
    public function current() : mixed
    {
        return \current( $this->data );
    }

    /**
     * Next
     *
     * @return void
     * @see https://secure.php.net/manual/en/iterator.next.php
     */
    #[ReturnTypeWillChange]
    public function next() : void
    {
        \next( $this->data );
    }

    /**
     * Key
     *
     * @return mixed case-sensitive key at the current position
     * @see https://secure.php.net/manual/en/iterator.key.php
     */
    #[ReturnTypeWillChange]
    public function key() : mixed
    {
        $key = \key( $this->data );
        return $this->keys[$key] ?? $key;
    }

    /**
     * Valid
     *
     * @return bool if the current position is valid
     * @see https://secure.php.net/manual/en/iterator.valid.php
     */
    #[ReturnTypeWillChange]
    public function valid() : bool
    {
        return \key( $this->data ) !== null;
    }

    /**
     * Rewind
     *
     * @return void
     * @see https://secure.php.net/manual/en/iterator.rewind.php
     */
    #[ReturnTypeWillChange]
    public function rewind() : void
    {
        \reset( $this->data );
    }

    /**
     * Is Array Associative?
     *
     * @param mixed $array
     *
     * @return bool
     */
    public static function isAssociative( mixed $array ) : bool
    {
        return $array instanceof self
               || \count( \array_filter( \array_keys( $array ), 'is_string' ) );
    }
}
