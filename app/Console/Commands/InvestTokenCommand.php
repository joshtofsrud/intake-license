<?php

namespace App\Console\Commands;

use App\Models\InvestToken;
use Illuminate\Console\Command;

// MARKER-INVEST-SITE
class InvestTokenCommand extends Command
{
    protected $signature = 'intake:invest-token
                            {--rotate : Revoke every live link and issue a new one}
                            {--label= : Optional note stored against the new link}';

    protected $description = 'Show or rotate the shareable investment-site link';

    public function handle(): int
    {
        if ($this->option('rotate')) {
            $token = InvestToken::rotate($this->option('label'));
            $this->warn('Previous links are now dead.');
        } else {
            $token = InvestToken::current() ?: InvestToken::rotate($this->option('label'));
        }

        $this->newLine();
        $this->line('  ' . url('/invest/' . $token->token));
        $this->newLine();
        $this->line('  views: ' . $token->views . '   leads: ' . $token->leads()->count());
        $this->newLine();

        return self::SUCCESS;
    }
}
