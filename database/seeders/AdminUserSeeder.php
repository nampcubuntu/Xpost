<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * The operator account, separate from the demo fixture.
 *
 * DatabaseSeeder wipes and rebuilds a whole demo tenant, which is the wrong
 * tool for "give me my login back". This one is idempotent and touches nothing
 * else: run it as often as you like, on an empty database or a full one.
 *
 *   php artisan db:seed --class=AdminUserSeeder
 *
 * Credentials come from the environment rather than being written here. This
 * file is in version control, and a password committed once stays in every
 * clone and every backup of the repository forever.
 */
class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        $name = env('ADMIN_NAME', 'namads');
        $email = env('ADMIN_EMAIL', 'namads@xpost.local');
        $password = env('ADMIN_PASSWORD');

        if (blank($password)) {
            // Better a working account with a password on screen than a silent
            // failure or a guessable default nobody remembers to change.
            $password = Str::password(16);
            $generated = true;
        }

        $user = User::updateOrCreate(
            ['email' => $email],
            [
                'name' => $name,
                'password' => $password,   // hashed by the model cast
                'locale' => 'vi',
                'timezone' => 'Asia/Ho_Chi_Minh',
            ],
        );

        // Reuse whatever team already exists -- on a seeded box that is the demo
        // tenant with its accounts and apps, which is what you want to log into.
        $team = Team::query()->orderBy('id')->first() ?? Team::create([
            'name' => 'Xpost',
            'slug' => 'xpost-'.Str::lower(Str::random(6)),
            'owner_id' => $user->id,
            'timezone' => 'Asia/Ho_Chi_Minh',
        ]);

        // syncWithoutDetaching would leave an existing lower role in place, so
        // set the pivot explicitly: this seeder's job is to guarantee owner.
        $team->members()->syncWithoutDetaching([$user->id => ['role' => 'owner']]);
        $team->members()->updateExistingPivot($user->id, ['role' => 'owner']);

        $user->forceFill(['current_team_id' => $team->id])->save();

        $this->command->info("Tài khoản admin sẵn sàng: {$email}");
        $this->command->line("  tên   : {$name}");
        $this->command->line("  team  : {$team->name} (owner)");

        if ($generated ?? false) {
            $this->command->warn("  mật khẩu (sinh ngẫu nhiên): {$password}");
            $this->command->line('  Đặt ADMIN_PASSWORD trong .env để cố định mật khẩu.');
        } else {
            $this->command->line('  mật khẩu: lấy từ ADMIN_PASSWORD trong .env');
        }
    }
}
