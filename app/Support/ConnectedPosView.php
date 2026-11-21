<?php

namespace App\Support;

use App\Models\Tenant\TenantPosConnection;
use App\Services\Pos\PosImportService;
use App\Services\Pos\PosPushService;
use Illuminate\Support\Facades\DB;

/**
 * MARKER-CONNECTED-POS-SECTION: what the Connected POS pages show, read in one place so
 * the section, the Move to Intake checklist and the E-commerce tab can't disagree.
 */
class ConnectedPosView
{
    public static function connection(string $tenantId): ?TenantPosConnection
    {
        return TenantPosConnection::where('tenant_id', $tenantId)->with('connectedByUser')->first();
    }

    /** Everything a page might need; each page reads the parts it shows. */
    public static function data($tenant, ?TenantPosConnection $conn): array
    {
        $tid = $tenant->id;
        $pushes = fn () => DB::table('tenant_pos_pushes')->where('tenant_id', $tid);

        return [
            'conn'      => $conn,
            'label'     => $conn ? \App\Models\PlatformPosAdapter::forCode($conn->adapter_code)->displayLabel() : null,
            'available' => app(\App\Services\Pos\Adapters\PosAdapterRegistry::class)->available(),
            'use'       => $tenant->connectedPosUse(),
            'intake_locations' => $tenant->activeLocations()->get(['id', 'name']),
            'items'     => $conn ? app(PosImportService::class)->counts($conn) : ['total' => 0, 'linked' => 0, 'waiting' => 0, 'new' => 0, 'removed' => 0],
            'conflicts' => $conn ? DB::table('tenant_pos_items as p')
                ->join('tenant_inventory_items as i', 'i.id', '=', 'p.inventory_item_id')
                ->where('p.tenant_id', $tid)->whereNotNull('p.conflict')
                ->orderBy('i.name')->limit(100)->get(['p.id', 'p.conflict', 'i.id as item_id', 'i.name as item_name']) : collect(),
            'counts'    => $conn ? $pushes()->selectRaw('status, count(*) n')->groupBy('status')->pluck('n', 'status') : collect(),
            // every kind and every status, newest first, so a send that failed or is retrying is never missing
            'latest'    => $conn ? $pushes()->orderByDesc('updated_at')->limit(12)->get() : collect(),
            // orders and refunds that failed stay pinned until they are sent: money records matter more than items
            'money_failed' => $conn ? $pushes()->whereIn('kind', ['sale', 'refund'])->where('status', 'failed')->orderByDesc('updated_at')->limit(20)->get() : collect(),
            'last_sale' => $conn ? $pushes()->where('kind', 'sale')->where('status', 'done')->max('updated_at') : null,
            'details_missing' => $conn ? DB::table('tenant_pos_items')->where('tenant_id', $tid)->whereNotNull('inventory_item_id')->whereNull('removed_at')->whereNull('details_sent_at')->count() : 0,
            'details_error'   => $conn ? DB::table('tenant_pos_items')->where('tenant_id', $tid)->whereNotNull('details_error')->orderByDesc('id')->value('details_error') : null,
            'catchup'   => $conn ? PosPushService::catchupOrders($tid)->count() : 0,
            'linked'    => $conn ? DB::table('tenant_pos_items')->where('tenant_id', $tid)->whereNotNull('inventory_item_id')->whereNull('removed_at')->count() : 0,
        ];
    }

    /**
     * The Move to Intake checklist: each step, whether it is done, and what to do next.
     * History is not a step yet; it joins this list when it can be brought in.
     *
     * @return array{steps: list<array<string,mixed>>, done: int, total: int}
     */
    public static function moveSteps(?TenantPosConnection $conn, array $items): array
    {
        $connected = $conn && $conn->status === 'connected';
        $read = $connected && $conn->last_synced_at !== null;
        $cs = (array) ($conn->customer_sync ?? []);
        $imp = (array) ($conn->import_state ?? []);
        $switched = $conn && ! $conn->isTwoWay();
        $finished = ! empty($imp['finished_at']) && empty($imp['stopped']) && empty($imp['error']); // a stopped run isn't a finished one
        $itemsDone = $read && ! ($imp['running'] ?? false) && ($items['waiting'] === 0 || $finished || $switched);

        $steps = [
            ['key' => 'connect',   'done' => $connected],
            ['key' => 'read',      'done' => $read],
            ['key' => 'items',     'done' => $itemsDone],
            ['key' => 'customers', 'done' => $connected && ! empty($cs['last_done'])],
            ['key' => 'switch',    'done' => $switched],
        ];
        $now = null;
        foreach ($steps as $i => $s) {
            if (! $s['done'] && $now === null) {
                $now = $i;
            }
            $steps[$i]['n'] = $i + 1;
        }
        foreach ($steps as $i => $s) {
            $steps[$i]['now'] = $i === $now;
        }

        return ['steps' => $steps, 'done' => count(array_filter($steps, fn ($s) => $s['done'])), 'total' => count($steps)];
    }
}
