<?php

namespace App\Services\License\Renewal;

/** The renewal conversation with the provider's control plane (ADR-018 decision 8). A seam: tests and a future second transport need no network. */
interface ControlPlane
{
    /**
     * Sends one signed renewal request, trying the URLs in order (primary first) until one gives a final answer.
     *
     * @param  list<string>  $urls  the control plane base URLs the activation bundle named
     * @param  array<string, mixed>  $body  the renewal request document
     */
    public function renew(array $urls, array $body, string $keyId, string $secretKey): ControlPlaneReply;
}
