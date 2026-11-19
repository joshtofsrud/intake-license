<?php

namespace App\Services\Pos\Adapters;

use App\Models\PlatformPosAdapter;
use InvalidArgumentException;

/**
 * MARKER-POS-CONNECT: resolves a POS adapter by code. Nothing else news up an
 * adapter; adding a POS is one entry here plus its class.
 */
class PosAdapterRegistry
{
    /** code => adapter class */
    private const ADAPTERS = [
        'lightspeed-r' => LightspeedRClient::class,
    ];

    /** @return list<string> */
    public function codes(): array
    {
        return array_keys(self::ADAPTERS);
    }

    public function adapterClass(string $code): ?string
    {
        return self::ADAPTERS[strtolower($code)] ?? null;
    }

    /** An adapter built with Intake's master-admin credentials. */
    public function make(string $code): PosAdapter
    {
        $class = $this->adapterClass($code);
        if (! $class) {
            throw new InvalidArgumentException("Unknown POS adapter [$code].");
        }

        return new $class(PlatformPosAdapter::forCode(strtolower($code)));
    }

    /** Adapters switched on and fully configured in master admin. @return list<PlatformPosAdapter> */
    public function available(): array
    {
        $out = [];
        foreach ($this->codes() as $code) {
            $row = PlatformPosAdapter::forCode($code);
            if ($row->is_active && $row->configured()) {
                $out[] = $row;
            }
        }
        return $out;
    }
}
