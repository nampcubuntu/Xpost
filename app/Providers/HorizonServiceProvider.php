<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Laravel\Horizon\HorizonApplicationServiceProvider;

class HorizonServiceProvider extends HorizonApplicationServiceProvider
{
    protected function gate(): void
    {
        /*
         * Who may open Horizon outside local.
         *
         * The dashboard shows every team's jobs at once -- payloads, failures,
         * account ids -- so it sits above the tenant hierarchy entirely: being
         * owner of one team is not a reason to see another team's queue. It
         * belongs to whoever runs Xpost.
         *
         * This shipped as an empty array, which locks everyone out including
         * the operator. Nobody noticed because Horizon lets anyone in on local,
         * so the first sign of it would have been losing the queue dashboard on
         * the day it was most needed.
         */
        Gate::define('viewHorizon', function (?User $user = null): bool {
            if ($user === null) {
                return false;
            }

            return in_array(strtolower($user->email), $this->operatorEmails(), true);
        });
    }

    /**
     * Emails allowed in, lowercased.
     *
     * Comes from the environment rather than a list in this file: adding an
     * operator should not need a deploy, and an email committed here is one
     * more thing to remember to remove when somebody leaves.
     *
     * Falls back to the platform owner, so a correct install is never locked
     * out of its own dashboard by an unset variable.
     *
     * @return list<string>
     */
    private function operatorEmails(): array
    {
        $configured = array_filter(array_map(
            'trim',
            explode(',', (string) config('xpost.horizon_emails')),
        ));

        if ($configured === []) {
            $configured = array_filter([config('xpost.platform_owner_email')]);
        }

        return array_values(array_map('strtolower', $configured));
    }
}
