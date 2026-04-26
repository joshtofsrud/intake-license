<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\ChangelogEntry;
use App\Models\RoadmapEntry;

/**
 * What's New / What's Coming — tenant-facing read-only views of the
 * platform changelog and roadmap. Same data as the public marketing
 * pages at intake.works/changelog and /roadmap, rendered inside the
 * tenant admin chrome.
 */
class WhatsNewController extends Controller
{
    public function changelog()
    {
        $entries = ChangelogEntry::published()
            ->orderByDesc('is_highlighted')
            ->orderByDesc('shipped_on')
            ->orderByDesc('created_at')
            ->get();

        return view('tenant.whats_new.changelog', compact('entries'));
    }

    public function roadmap()
    {
        $entries = RoadmapEntry::published()
            ->orderBy('display_order')
            ->orderBy('created_at')
            ->get()
            ->groupBy('status');

        $orderedGroups = [];
        foreach (array_keys(RoadmapEntry::STATUSES) as $statusKey) {
            if (isset($entries[$statusKey]) && $entries[$statusKey]->count() > 0) {
                $orderedGroups[$statusKey] = $entries[$statusKey];
            }
        }

        return view('tenant.whats_new.roadmap', ['groups' => $orderedGroups]);
    }
}
