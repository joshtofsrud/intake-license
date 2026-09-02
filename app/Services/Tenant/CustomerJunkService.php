<?php

namespace App\Services\Tenant;

use App\Models\Tenant;
use App\Models\Tenant\TenantCustomer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * MARKER-CUST-CLEANUP — what looks like junk in a shop's customer list.
 *
 * Every group is a judgement, not a fact, so nothing here removes anything:
 * it reports, the operator decides. Groups are ordered by how confident the
 * signal is — malformed and suppressed are close to certain, "empty shell" is
 * a hint.
 */
class CustomerJunkService
{
    /** Mailbox names that are a department, not a person. */
    private const ROLE_LOCALS = [
        'info', 'sales', 'orders', 'order', 'support', 'help', 'admin', 'office',
        'billing', 'accounts', 'accounting', 'noreply', 'no-reply', 'donotreply',
        'webmaster', 'postmaster', 'service', 'contact', 'enquiries', 'inquiries',
        'shop', 'store', 'team', 'hello', 'mail', 'marketing', 'returns', 'warranty',
    ];

    /** Throwaway inbox providers. */
    private const DISPOSABLE = [
        'mailinator.com', 'guerrillamail.com', 'guerrillamail.net', '10minutemail.com',
        'tempmail.com', 'temp-mail.org', 'throwawaymail.com', 'yopmail.com', 'trashmail.com',
        'sharklasers.com', 'getnada.com', 'dispostable.com', 'maildrop.cc', 'fakeinbox.com',
        'mailnesia.com', 'spam4.me', 'grr.la', 'mohmal.com', 'emailondeck.com',
    ];

    /** Names and locals that mean somebody was testing. */
    private const TEST_WORDS = ['test', 'testing', 'asdf', 'qwerty', 'aaaa', 'xxxx', 'sample', 'dummy', 'foobar', 'delete me', 'deleteme'];

    public function groups(Tenant $tenant): array
    {
        return [
            'malformed'   => $this->malformed($tenant),
            'suppressed'  => $this->suppressed($tenant),
            'disposable'  => $this->disposable($tenant),
            'role'        => $this->roleAddresses($tenant),
            'test'        => $this->testRows($tenant),
            'duplicate'   => $this->duplicates($tenant),
            'empty'       => $this->emptyShells($tenant),
        ];
    }

    public function meta(): array
    {
        return [
            'malformed'  => ['label' => 'Malformed addresses',      'why' => "Not a valid address — no @, no domain, or spaces. These can never be delivered and each attempt still costs a send.", 'confident' => true],
            'suppressed' => ['label' => 'Bounced or complained',    'why' => "Already on the suppression list from a hard bounce or a spam complaint, but still sitting in the customer list looking mailable.", 'confident' => true],
            'disposable' => ['label' => 'Throwaway inboxes',        'why' => 'Temporary mailbox providers. Nobody reads these after the day they were made.', 'confident' => true],
            'role'       => ['label' => 'Department addresses',     'why' => 'info@, sales@, orders@ and the like. Usually a supplier or the shop itself, captured as a customer by mistake.', 'confident' => false],
            'test'       => ['label' => 'Test rows',                'why' => 'Names or addresses that read as somebody trying the system out.', 'confident' => false],
            'duplicate'  => ['label' => 'Duplicate addresses',      'why' => 'The same email on more than one customer record — one person, billed twice per campaign.', 'confident' => false],
            'empty'      => ['label' => 'Empty records',            'why' => 'No name, no phone, no appointment, no sale. Usually an import that brought in blank rows.', 'confident' => false],
        ];
    }

    // ---- groups ------------------------------------------------------

    private function base(Tenant $tenant)
    {
        return TenantCustomer::where('tenant_id', $tenant->id)->notErased();
    }

    private function malformed(Tenant $tenant)
    {
        return $this->base($tenant)
            ->whereNotNull('email')->where('email', '!=', '')
            ->where(function ($q) {
                $q->where('email', 'not like', '%@%')
                  ->orWhere('email', 'like', '% %')
                  ->orWhere('email', 'like', '%..%')
                  ->orWhere('email', 'like', '%@%@%')
                  ->orWhere('email', 'not like', '%.__%');
            });
    }

    private function suppressed(Tenant $tenant)
    {
        if (! Schema::hasTable('tenant_email_suppressions')) {
            return $this->base($tenant)->whereRaw('1 = 0');
        }

        $sup = DB::table('tenant_email_suppressions')
            ->where(function ($q) use ($tenant) {
                $q->where('tenant_id', $tenant->id)->orWhereNull('tenant_id');
            })
            ->whereIn('reason', ['bounce', 'hard_bounce', 'complaint', 'spam_complaint'])
            ->select('email');

        return $this->base($tenant)
            ->whereNotNull('email')
            ->whereIn('email', $sup);
    }

    private function disposable(Tenant $tenant)
    {
        return $this->base($tenant)->whereNotNull('email')->where(function ($q) {
            foreach (self::DISPOSABLE as $domain) {
                $q->orWhere('email', 'like', '%@' . $domain);
            }
        });
    }

    private function roleAddresses(Tenant $tenant)
    {
        return $this->base($tenant)->whereNotNull('email')->where(function ($q) {
            foreach (self::ROLE_LOCALS as $local) {
                $q->orWhere('email', 'like', $local . '@%');
            }
        });
    }

    private function testRows(Tenant $tenant)
    {
        return $this->base($tenant)->where(function ($q) {
            foreach (self::TEST_WORDS as $w) {
                $q->orWhere('first_name', 'like', $w . '%')
                  ->orWhere('last_name', 'like', $w . '%')
                  ->orWhere('email', 'like', $w . '@%')
                  ->orWhere('email', 'like', '%' . $w . '@%');
            }
        });
    }

    private function duplicates(Tenant $tenant)
    {
        $dupes = DB::table('tenant_customers')
            ->select('email')
            ->where('tenant_id', $tenant->id)
            ->whereNull('erased_at')
            ->whereNotNull('email')->where('email', '!=', '')
            ->groupBy('email')
            ->havingRaw('COUNT(*) > 1');

        return $this->base($tenant)
            ->whereNotNull('email')
            ->whereIn('email', $dupes)
            ->orderBy('email');
    }

    private function emptyShells(Tenant $tenant)
    {
        return $this->base($tenant)
            ->where(function ($q) {
                $q->where(function ($w) {
                    $w->whereNull('first_name')->orWhere('first_name', '');
                })->where(function ($w) {
                    $w->whereNull('last_name')->orWhere('last_name', '');
                });
            })
            ->where(function ($q) { $q->whereNull('phone')->orWhere('phone', ''); })
            ->doesntHave('appointments');
    }
}
