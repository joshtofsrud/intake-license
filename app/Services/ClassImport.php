<?php

namespace App\Services;

use App\Models\Tenant;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Bringing passes and memberships over from another studio system.
 *
 * For now a studio uploads its export and it is kept privately, listed as
 * received. Each source gets its own mapping once a real export from that
 * system is in hand; until then run() refuses. Customers already import
 * through the CSV importer (Customers › Import). MARKER-CLASSES-P12
 */
class ClassImport
{
    public const SOURCES = [
        'mindbody'       => 'Mindbody',
        'momence'        => 'Momence',
        'glofox'         => 'Glofox',
        'wellnessliving' => 'WellnessLiving',
        'zenplanner'     => 'Zen Planner',
        'other'          => 'Something else',
    ];

    /** Sources with a finished mapping. Empty until one is built from a real export. */
    public const READY = [];

    private const KEEP = 20;

    /** Keep the file privately and note it on the studio's list. */
    public static function receive(Tenant $tenant, UploadedFile $file, string $source, ?string $userId): array
    {
        $name = now()->format('Ymd-His') . '-' . $source . '-' . Str::random(6) . '.' . strtolower($file->getClientOriginalExtension() ?: 'csv');
        $path = $file->storeAs('class-imports/' . $tenant->id, $name, 'local');

        $row = [
            'path'        => $path,
            'source'      => $source,
            'name'        => mb_substr($file->getClientOriginalName(), 0, 120),
            'bytes'       => (int) $file->getSize(),
            'uploaded_at' => now()->toIso8601String(),
            'by'          => $userId,
            'status'      => 'received',
        ];
        $settings = (array) ($tenant->settings ?? []);
        $list = array_slice(array_merge([$row], (array) ($settings['class_imports'] ?? [])), 0, self::KEEP);
        $settings['class_imports'] = $list;
        $tenant->forceFill(['settings' => $settings])->save();

        logger()->info('classes.import: export received', ['tenant' => $tenant->id, 'source' => $source, 'path' => $path]);
        return $row;
    }

    public static function received(Tenant $tenant): array
    {
        return (array) (((array) ($tenant->settings ?? []))['class_imports'] ?? []);
    }

    /** Read a received export into Intake. Not built for any source yet. */
    public static function run(Tenant $tenant, array $row): void
    {
        if (! in_array($row['source'] ?? '', self::READY, true)) {
            throw new RuntimeException('Importing from ' . (self::SOURCES[$row['source'] ?? 'other'] ?? 'this system') . " isn't built yet.");
        }
        // Each source's mapping goes here: customers, then packs (credits left,
        // expiry), then memberships (product, renewal date, how they pay).
        if (! Storage::disk('local')->exists($row['path'] ?? '')) {
            throw new RuntimeException('That file is no longer stored.');
        }
    }
}
