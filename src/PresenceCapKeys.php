<?php

namespace Webpatser\ResonateUserCap;

use RuntimeException;

/**
 * The user-cap key schema.
 *
 * A user's connections on one Resonate node live in
 * "{prefix}:{{appId}:{userId}}:{nodeId}", a Redis set of socket ids. Per-node
 * keys mean a dead node's count expires by TTL without a live node holding
 * it open, exactly the pattern resonate-roster uses.
 *
 * The inner braces are a Redis Cluster hash tag. Cluster hashes only what sits
 * between the first "{" and the next "}", so every node's key for one user
 * lands in the same slot and the cap script, which reads all of them in one
 * EVAL, cannot fail with CROSSSLOT. A "}" inside a user id only ends the tag
 * early; the tag is still identical for every node's key of that user.
 *
 * Keys written before the tag existed ("{prefix}:{appId}:{userId}:{nodeId}")
 * are still matched by {@see legacyUserScanPattern()}, so a rolling upgrade
 * keeps counting the connections that older nodes hold.
 */
class PresenceCapKeys
{
    /**
     * Create a new key schema instance.
     */
    public function __construct(protected string $prefix = 'cap')
    {
        //
    }

    /**
     * The set key holding one node's socket ids for one user on one app.
     */
    public function userKey(string $appId, string $userId, string $node): string
    {
        return $this->userTag($appId, $userId).':'.$node;
    }

    /**
     * The SCAN pattern matching every node's key for one user on one app.
     *
     * Braces are literal in a Redis glob, so the tag needs no escaping here.
     */
    public function userScanPattern(string $appId, string $userId): string
    {
        return $this->userTag($appId, $userId).':*';
    }

    /**
     * The SCAN pattern matching the untagged keys older releases wrote.
     *
     * Only read, never written: it lets a node count the connections an
     * older node still holds during a rolling upgrade. Those keys expire by
     * TTL once the last older node is gone.
     */
    public function legacyUserScanPattern(string $appId, string $userId): string
    {
        return $this->prefix.':'.$appId.':'.$this->encodeIdentity($userId).':*';
    }

    /**
     * The hash-tagged part every node's key for one user shares.
     */
    protected function userTag(string $appId, string $userId): string
    {
        return $this->prefix.':{'.$appId.':'.$this->encodeIdentity($userId).'}';
    }

    /**
     * Neutralise an untrusted identity segment before it forms a key.
     *
     * The `user_id` arrives from presence `channel_data`, which is only weakly
     * constrained, so a value may carry a `:` (colliding key namespaces) or a
     * glob metacharacter (`* ? [ ] \`) that would broaden a SCAN MATCH pattern.
     * Each unsafe byte, plus `%` itself, is percent-encoded so the mapping stays
     * injective: distinct ids always yield distinct, glob-safe segments. Safe
     * ids such as "u-7" pass through unchanged.
     *
     * @throws RuntimeException when the identity could not be encoded, so a
     *                          key is never built from an unsanitised segment.
     */
    protected function encodeIdentity(string $userId): string
    {
        $encoded = preg_replace_callback(
            '/[%:*?\[\]\\\\]/',
            static fn (array $match): string => '%'.strtoupper(bin2hex($match[0])),
            $userId,
        );

        if ($encoded === null) {
            throw new RuntimeException('Unable to encode the user-cap identity segment.');
        }

        return $encoded;
    }

    /**
     * A stable, colon-free identifier for the current Resonate process.
     */
    public static function nodeId(): string
    {
        $host = gethostname() ?: 'node';

        return str_replace(':', '-', $host).'-'.getmypid();
    }
}
