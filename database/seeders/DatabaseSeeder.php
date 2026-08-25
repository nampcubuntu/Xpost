<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\AccountStatus;
use App\Enums\XAppTier;
use App\Models\AccountGroup;
use App\Models\Post;
use App\Models\Proxy;
use App\Models\Team;
use App\Models\User;
use App\Models\XAccount;
use App\Models\XApp;
use App\Support\TeamContext;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * A working demo tenant: one team, a pool of apps and proxies, and enough
 * accounts that the pool logic, the pagination and the bulk actions all have
 * something real to operate on.
 *
 * `--accounts` is read from the ACCOUNTS env var so the same seeder can produce
 * a 20-account sandbox or a 10k load-test fixture:
 *
 *   ACCOUNTS=10000 php artisan db:seed
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $accountCount = (int) (env('ACCOUNTS') ?: 200);

        $owner = User::factory()->create([
            'name' => 'Xpost Admin',
            'email' => 'admin@xpost.test',
            'password' => 'password',
            'locale' => 'vi',
            'timezone' => 'Asia/Ho_Chi_Minh',
        ]);

        $team = Team::create([
            'name' => 'Xpost Demo',
            'slug' => 'xpost-demo-'.Str::lower(Str::random(6)),
            'owner_id' => $owner->id,
            'timezone' => 'Asia/Ho_Chi_Minh',
        ]);

        $team->members()->attach($owner->id, ['role' => 'owner']);
        $owner->forceFill(['current_team_id' => $team->id])->save();

        TeamContext::runAs($team->id, function () use ($team, $accountCount): void {
            // Enough apps that the pool selector has somewhere to spread to.
            $apps = collect([
                ['name' => 'Xpost Primary', 'tier' => XAppTier::Pro, 'weight' => 3],
                ['name' => 'Xpost Secondary', 'tier' => XAppTier::Basic, 'weight' => 2],
                ['name' => 'Xpost Overflow', 'tier' => XAppTier::Basic, 'weight' => 1],
            ])->map(fn (array $spec) => XApp::factory()->tier($spec['tier'])->create([
                'team_id' => $team->id,
                'name' => $spec['name'],
                'weight' => $spec['weight'],
                'max_accounts' => 500,
            ]));

            $proxies = Proxy::factory()->count(10)->create(['team_id' => $team->id]);

            $groups = collect(['Miền Bắc', 'Miền Trung', 'Miền Nam', 'Tài khoản mới'])
                ->map(fn (string $name) => AccountGroup::factory()->create([
                    'team_id' => $team->id,
                    'name' => $name,
                ]));

            // Inserted in chunks: at ACCOUNTS=10000 one-at-a-time would take
            // minutes, and the point of the fixture is to be re-runnable.
            $created = 0;

            while ($created < $accountCount) {
                $chunk = min(200, $accountCount - $created);

                $accounts = XAccount::factory()
                    ->count($chunk)
                    ->create([
                        'team_id' => $team->id,
                        'x_app_id' => $apps->random()->id,
                        'proxy_id' => $proxies->random()->id,
                    ]);

                foreach ($accounts as $account) {
                    $account->groups()->attach($groups->random()->id);
                }

                $created += $chunk;
            }

            // A handful of unhealthy accounts, so the filters and the
            // needs-reauth badge are not permanently empty in the demo.
            XAccount::query()
                ->inRandomOrder()
                ->limit(max(1, (int) ($accountCount * 0.05)))
                ->update(['status' => AccountStatus::NeedsReauth->value]);

            foreach ($groups as $group) {
                $group->syncCount();
            }

            Post::factory()->count(5)->create([
                'team_id' => $team->id,
                'created_by' => $team->owner_id,
            ]);

            Post::factory()->spintax()->withWebsite()->create([
                'team_id' => $team->id,
                'created_by' => $team->owner_id,
                'name' => 'Chiến dịch spintax mẫu',
            ]);
        });

        $this->command->info("Seeded team [{$team->name}] with {$accountCount} accounts.");
        $this->command->info('Login: admin@xpost.test / password');
    }
}
