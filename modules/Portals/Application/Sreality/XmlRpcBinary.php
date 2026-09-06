<?php

declare(strict_types=1);

namespace Modules\Portals\Application\Sreality;

/**
 * Wrapper binárních dat (base64) pro XML-RPC.
 */
final class XmlRpcBinary
{
    public function __construct(public readonly string $base64)
    {
    }

    public static function fromRaw(string $bytes): self
    {
        return new self(base64_encode($bytes));
    }
}
