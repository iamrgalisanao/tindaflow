<?php

namespace App\Services\License\Renewal;

/** What the control plane answered, or why no answer came. `status` 0 means no URL gave a final answer (every one was unreachable or failing). */
final class ControlPlaneReply
{
    /** @param  array<string, mixed>|null  $json  the decoded body, null when it was not a JSON object */
    public function __construct(
        public readonly int $status,
        public readonly ?array $json = null,
        public readonly ?string $url = null,
        public readonly ?string $error = null,
    ) {}

    public static function unreachable(string $error): self
    {
        return new self(0, null, null, $error);
    }
}
