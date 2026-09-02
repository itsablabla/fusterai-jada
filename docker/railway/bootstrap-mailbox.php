<?php
/**
 * One-shot mailbox bootstrap. Env vars:
 *   FUSTERAI_BOOTSTRAP_MAILBOX_EMAIL, _PASSWORD, _NAME, _USERNAME
 *   FUSTERAI_BOOTSTRAP_MAILBOX_IMAP_HOST / _IMAP_PORT / _IMAP_ENCRYPTION
 *   FUSTERAI_BOOTSTRAP_MAILBOX_SMTP_HOST / _SMTP_PORT / _SMTP_ENCRYPTION
 * Creates an active email mailbox in the first workspace (sync on) and grants
 * every user in that workspace access. Skips if the email already exists.
 */
require '/var/www/html/vendor/autoload.php';
$app = require '/var/www/html/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$email = getenv('FUSTERAI_BOOTSTRAP_MAILBOX_EMAIL');
$pass  = getenv('FUSTERAI_BOOTSTRAP_MAILBOX_PASSWORD');
$name  = getenv('FUSTERAI_BOOTSTRAP_MAILBOX_NAME') ?: $email;
$user  = getenv('FUSTERAI_BOOTSTRAP_MAILBOX_USERNAME') ?: $email;

$ws = \App\Models\Workspace::query()->orderBy('id')->first();
if (! $ws) {
    echo '[mailbox] no workspace yet - skipping'.PHP_EOL;
    exit(0);
}

$existing = \App\Domains\Mailbox\Models\Mailbox::where('email', $email)->first();
if ($existing) {
    if (! $existing->active) {
        $existing->update(['active' => true]);
        echo "[mailbox] {$email} existed but was inactive - sync turned on".PHP_EOL;
    } else {
        echo "[mailbox] {$email} already exists and is active - skipping".PHP_EOL;
    }
    exit(0);
}

$mb = \App\Domains\Mailbox\Models\Mailbox::create([
    'workspace_id' => $ws->id,
    'name'         => $name,
    'email'        => $email,
    'channel_type' => 'email',
    'active'       => true,
    'imap_config'  => [
        'host'          => getenv('FUSTERAI_BOOTSTRAP_MAILBOX_IMAP_HOST') ?: 'imap.zoho.com',
        'port'          => (int) (getenv('FUSTERAI_BOOTSTRAP_MAILBOX_IMAP_PORT') ?: 993),
        'encryption'    => getenv('FUSTERAI_BOOTSTRAP_MAILBOX_IMAP_ENCRYPTION') ?: 'ssl',
        'validate_cert' => true,
        'username'      => $user,
        'password'      => $pass,
    ],
    'smtp_config'  => [
        'host'       => getenv('FUSTERAI_BOOTSTRAP_MAILBOX_SMTP_HOST') ?: 'smtp.zoho.com',
        'port'       => (int) (getenv('FUSTERAI_BOOTSTRAP_MAILBOX_SMTP_PORT') ?: 465),
        'encryption' => getenv('FUSTERAI_BOOTSTRAP_MAILBOX_SMTP_ENCRYPTION') ?: 'ssl',
        'username'   => $user,
        'password'   => $pass,
    ],
]);

foreach (\App\Models\User::where('workspace_id', $ws->id)->get() as $u) {
    $mb->users()->syncWithoutDetaching([$u->id]);
}

echo "[mailbox] created id={$mb->id} email={$mb->email} active=1 users=".$mb->users()->count().PHP_EOL;
