<?php
/**
 * One-shot admin bootstrap. Driven entirely by env vars:
 *   FUSTERAI_BOOTSTRAP_ADMIN_EMAIL, _PASSWORD, _NAME, FUSTERAI_BOOTSTRAP_WORKSPACE
 * Idempotent: skips when the email already exists. Otherwise wipes any stray
 * workspace/user left from earlier deploys so this becomes the first account.
 */
require '/var/www/html/vendor/autoload.php';
$app = require '/var/www/html/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$email     = getenv('FUSTERAI_BOOTSTRAP_ADMIN_EMAIL');
$password  = getenv('FUSTERAI_BOOTSTRAP_ADMIN_PASSWORD');
$name      = getenv('FUSTERAI_BOOTSTRAP_ADMIN_NAME') ?: 'Admin';
$workspace = getenv('FUSTERAI_BOOTSTRAP_WORKSPACE') ?: 'My Support Team';

echo '[bootstrap] existing users='.\App\Models\User::count().', workspaces='.\App\Models\Workspace::count().PHP_EOL;

if ($existing = \App\Models\User::where('email', $email)->first()) {
    // Idempotent "ensure": make the env password + name authoritative, keep super_admin.
    $existing->forceFill([
        'name'              => $name,
        'password'          => \Illuminate\Support\Facades\Hash::make($password),
        'role'              => 'super_admin',
        'email_verified_at' => $existing->email_verified_at ?? now(),
    ])->save();
    if ($ws = \App\Models\Workspace::find($existing->workspace_id)) {
        $ws->update(['name' => $workspace, 'slug' => \Illuminate\Support\Str::slug($workspace)]);
    }
    echo "[bootstrap] user {$email} exists - password/name/role synced from env".PHP_EOL;
    exit(0);
}

\Illuminate\Support\Facades\DB::transaction(function () use ($workspace, $name, $email, $password) {
    \App\Models\User::query()->forceDelete();
    \App\Models\Workspace::query()->forceDelete();

    $u = app(\App\Services\RegistrationService::class)->register([
        'workspace_name' => $workspace,
        'name'           => $name,
        'email'          => $email,
        'password'       => $password,
    ]);
    $u->forceFill(['email_verified_at' => now()])->save();

    echo "[bootstrap] created workspace={$u->workspace_id} user={$u->id} email={$u->email} role={$u->role}".PHP_EOL;
});
