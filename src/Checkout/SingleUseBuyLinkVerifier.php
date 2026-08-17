<?php

namespace Bemo\LiveShopping\Checkout;

if (!defined('_PS_VERSION_')) {
    exit;
}

class SingleUseBuyLinkVerifier
{
    /** @var BuyLinkVerifier */
    private $verifier;

    /** @var BuyLinkNonceRepositoryInterface */
    private $nonces;

    public function __construct(BuyLinkVerifier $verifier, BuyLinkNonceRepositoryInterface $nonces)
    {
        $this->verifier = $verifier;
        $this->nonces = $nonces;
    }

    public function verify($shopId, $token, $secret, $nowMs)
    {
        $payload = $this->verifier->verify($token, $secret, $nowMs);
        if (!is_array($payload)) {
            return null;
        }

        if (!$this->nonces->claim($shopId, $payload['nonce'], (int) $payload['expiresAt'])) {
            return null;
        }

        return $payload;
    }
}
