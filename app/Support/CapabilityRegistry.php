<?php
namespace App\Support;

// MARKER-PATCH-611 — single source of truth for granular capabilities.
// Consumed by: the Roles & access editor (renders the toggles),
// TenantUser::can() (enforcement), and each feature's controllers.
// Keys are stored in tenant_roles.capabilities, so treat them as durable
// identifiers — never rename a key without a data migration.
//
// Convention mirrors SectionRegistry: a role with capabilities === null has
// ALL capabilities (full access, pre-capability behavior). Owner always passes.
// A capability is only meaningful when its owning section is visible to the
// role — the editor nests them under their section for that reason.
class CapabilityRegistry
{
    /**
     * key => [
     *   'label'   => human label in the editor,
     *   'section' => the SectionRegistry key this lives under (visibility parent),
     *   'desc'    => one-line explanation,
     *   'gate'    => tenant attribute that must be truthy (or null),
     *   'default_roles' => system role names that get it by default when a
     *                      tenant first materializes capability sets (Owner
     *                      always implicitly has everything).
     * ]
     */
    public static function all(): array
    {
        return [
            // ---- Time clock ----
            'timeclock.manage' => [
                'label'   => 'Manage the team timesheet',
                'section' => 'timeclock',
                'desc'    => 'View everyone’s punches and the team grid (not just their own).',
                'gate'    => null,
                'default_roles' => ['Manager'],
            ],
            'timeclock.edit' => [
                'label'   => 'Edit anyone’s punches',
                'section' => 'timeclock',
                'desc'    => 'Add, edit, or fix any staff member’s punches (audit-logged).',
                'gate'    => null,
                'default_roles' => ['Manager'],
            ],
            // MARKER-TC-EDIT-SCOPE — the narrower half. Someone can be trusted
            // to correct their own missed clock-out without being able to
            // touch the rest of the team's hours.
            'timeclock.edit_own' => [
                'label'   => 'Edit their own punches',
                'section' => 'timeclock',
                'desc'    => 'Correct or add punches on their own timesheet only (audit-logged).',
                'gate'    => null,
                'default_roles' => ['Manager'],
            ],
            // MARKER-TC-EXEMPT-CAP — deciding you don't clock in is a payroll
            // decision, not a personal preference. Empty default_roles: nobody
            // has it until it is granted here. Owner passes implicitly.
            'timeclock.exempt_self' => [
                'label'   => 'Turn off their own clock-in requirement',
                'section' => 'timeclock',
                'desc'    => 'Mark themselves as never clocks in, which hides the prompt and blocks the clock. Managers set this for other people on the team member page.',
                'gate'    => null,
                'default_roles' => [],
            ],
            'timeclock.approve' => [
                'label'   => 'Approve & lock pay periods',
                'section' => 'timeclock',
                'desc'    => 'Sign off pay periods and lock them as payroll truth.',
                'gate'    => null,
                'default_roles' => ['Manager'],
            ],

            // ---- Customers ---- MARKER-IMPORT1
            'customers.import' => [
                'label'   => 'Import from a spreadsheet',
                'section' => 'customers',
                'desc'    => 'Upload a CSV to create or update customers in bulk.',
                'gate'    => null,
                'default_roles' => ['Manager'],
            ],

            // ---- Customers ---- MARKER-CUST-ACCOUNT
            'customers.account_manage' => [
                'label'   => 'Manage customer portal accounts',
                'section' => 'customers',
                'desc'    => 'Send account invites and password reset links to customers.',
                'gate'    => null,
                'default_roles' => ['Manager'],
            ],

            // ---- Register ---- MARKER-GIFTCARDS-ADMIN
            'giftcards.manage' => [
                'label'   => 'Manage gift cards',
                'section' => 'register',
                'desc'    => 'Issue cards manually, adjust balances, and deactivate lost or stolen cards.',
                'gate'    => null,
                'default_roles' => ['Manager'],
            ],

            // ---- Scheduling ----
            'scheduling.build' => [
                'label'   => 'Build & publish schedules',
                'section' => 'scheduling',
                'desc'    => 'Create, edit, and publish the staff schedule.',
                'gate'    => null,
                'default_roles' => ['Manager'],
            ],
            'scheduling.timeoff' => [
                'label'   => 'Approve time-off requests',
                'section' => 'scheduling',
                'desc'    => 'Approve or deny staff time-off and availability changes.',
                'gate'    => null,
                'default_roles' => ['Manager'],
            ],
        ];
    }

    /** Capabilities that live under a given section key. */
    public static function forSection(string $sectionKey): array
    {
        return array_filter(self::all(), fn ($d) => $d['section'] === $sectionKey);
    }

    /** All capability keys (for validation / full-access materialization). */
    public static function keys(): array
    {
        return array_keys(self::all());
    }

    /** Default capability keys for a system role name. */
    public static function defaultsFor(string $roleName): array
    {
        if ($roleName === 'Owner') return self::keys(); // owner = everything
        $out = [];
        foreach (self::all() as $key => $def) {
            if (in_array($roleName, $def['default_roles'], true)) $out[] = $key;
        }
        return $out;
    }
}

