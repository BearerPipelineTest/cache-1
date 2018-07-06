<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\Cache;

use Psr\Cache\CacheItemInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Cache\Exception\InvalidArgumentException;
use Symfony\Component\Cache\Exception\LogicException;

/**
 * @author Nicolas Grekas <p@tchwork.com>
 */
final class CacheItem implements CacheItemInterface
{
    /**
     * References the Unix timestamp stating when the item will expire.
     */
    const METADATA_EXPIRY = 'expiry';

    /**
     * References the time the item took to be created, in milliseconds.
     */
    const METADATA_CTIME = 'ctime';

    /**
     * References the list of tags that were assigned to the item, as string[].
     */
    const METADATA_TAGS = 'tags';

    private const METADATA_EXPIRY_OFFSET = 1527506807;

    protected $key;
    protected $value;
    protected $isHit = false;
    protected $expiry;
    protected $defaultLifetime;
    protected $metadata = array();
    protected $newMetadata = array();
    protected $innerItem;
    protected $poolHash;
    protected $isTaggable = false;

    /**
     * {@inheritdoc}
     */
    public function getKey()
    {
        return $this->key;
    }

    /**
     * {@inheritdoc}
     */
    public function get()
    {
        return $this->value;
    }

    /**
     * {@inheritdoc}
     */
    public function isHit()
    {
        return $this->isHit;
    }

    /**
     * {@inheritdoc}
     */
    public function set($value)
    {
        $this->value = $value;

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function expiresAt($expiration)
    {
        if (null === $expiration) {
            $this->expiry = $this->defaultLifetime > 0 ? microtime(true) + $this->defaultLifetime : null;
        } elseif ($expiration instanceof \DateTimeInterface) {
            $this->expiry = (float) $expiration->format('U.u');
        } else {
            throw new InvalidArgumentException(sprintf('Expiration date must implement DateTimeInterface or be null, "%s" given', is_object($expiration) ? get_class($expiration) : gettype($expiration)));
        }

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function expiresAfter($time)
    {
        if (null === $time) {
            $this->expiry = $this->defaultLifetime > 0 ? microtime(true) + $this->defaultLifetime : null;
        } elseif ($time instanceof \DateInterval) {
            $this->expiry = microtime(true) + \DateTime::createFromFormat('U', 0)->add($time)->format('U.u');
        } elseif (\is_int($time)) {
            $this->expiry = $time + microtime(true);
        } else {
            throw new InvalidArgumentException(sprintf('Expiration date must be an integer, a DateInterval or null, "%s" given', is_object($time) ? get_class($time) : gettype($time)));
        }

        return $this;
    }

    /**
     * Adds a tag to a cache item.
     *
     * @param string|string[] $tags A tag or array of tags
     *
     * @return static
     *
     * @throws InvalidArgumentException When $tag is not valid
     */
    public function tag($tags)
    {
        if (!$this->isTaggable) {
            throw new LogicException(sprintf('Cache item "%s" comes from a non tag-aware pool: you cannot tag it.', $this->key));
        }
        if (!\is_iterable($tags)) {
            $tags = array($tags);
        }
        foreach ($tags as $tag) {
            if (!\is_string($tag)) {
                throw new InvalidArgumentException(sprintf('Cache tag must be string, "%s" given', is_object($tag) ? get_class($tag) : gettype($tag)));
            }
            if (isset($this->newMetadata[self::METADATA_TAGS][$tag])) {
                continue;
            }
            if ('' === $tag) {
                throw new InvalidArgumentException('Cache tag length must be greater than zero');
            }
            if (false !== strpbrk($tag, '{}()/\@:')) {
                throw new InvalidArgumentException(sprintf('Cache tag "%s" contains reserved characters {}()/\@:', $tag));
            }
            $this->newMetadata[self::METADATA_TAGS][$tag] = $tag;
        }

        return $this;
    }

    /**
     * Returns the list of tags bound to the value coming from the pool storage if any.
     *
     * @return array
     *
     * @deprecated since Symfony 4.2, use the "getMetadata()" method instead.
     */
    public function getPreviousTags()
    {
        @trigger_error(sprintf('The "%s()" method is deprecated since Symfony 4.2, use the "getMetadata()" method instead.', __METHOD__), E_USER_DEPRECATED);

        return $this->metadata[self::METADATA_TAGS] ?? array();
    }

    /**
     * Returns a list of metadata info that were saved alongside with the cached value.
     *
     * See public CacheItem::METADATA_* consts for keys potentially found in the returned array.
     */
    public function getMetadata(): array
    {
        return $this->metadata;
    }

    /**
     * Validates a cache key according to PSR-6.
     *
     * @param string $key The key to validate
     *
     * @return string
     *
     * @throws InvalidArgumentException When $key is not valid
     */
    public static function validateKey($key)
    {
        if (!\is_string($key)) {
            throw new InvalidArgumentException(sprintf('Cache key must be string, "%s" given', is_object($key) ? get_class($key) : gettype($key)));
        }
        if ('' === $key) {
            throw new InvalidArgumentException('Cache key length must be greater than zero');
        }
        if (false !== strpbrk($key, '{}()/\@:')) {
            throw new InvalidArgumentException(sprintf('Cache key "%s" contains reserved characters {}()/\@:', $key));
        }

        return $key;
    }

    /**
     * Internal logging helper.
     *
     * @internal
     */
    public static function log(LoggerInterface $logger = null, $message, $context = array())
    {
        if ($logger) {
            $logger->warning($message, $context);
        } else {
            $replace = array();
            foreach ($context as $k => $v) {
                if (is_scalar($v)) {
                    $replace['{'.$k.'}'] = $v;
                }
            }
            @trigger_error(strtr($message, $replace), E_USER_WARNING);
        }
    }
}
