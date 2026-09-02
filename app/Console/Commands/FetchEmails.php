<?php

namespace App\Console\Commands;

use App\Domains\Conversation\Jobs\ProcessInboundEmailJob;
use App\Domains\Mailbox\Models\Mailbox;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Webklex\PHPIMAP\ClientManager;

class FetchEmails extends Command
{
    protected $signature = 'emails:fetch
                            {--mailbox=* : Specific mailbox IDs to fetch}
                            {--limit= : Max messages per mailbox per run (default env IMAP_FETCH_LIMIT or 50)}
                            {--since-days= : Only fetch mail newer than N days (default env IMAP_FETCH_SINCE_DAYS or 3; 0 = no date filter)}';

    protected $description = 'Fetch new emails from all active IMAP mailboxes';

    public function handle(): int
    {
        $query = Mailbox::where('active', true)->whereNotNull('imap_config');

        if ($ids = $this->option('mailbox')) {
            $query->whereIn('id', $ids);
        }

        $mailboxes = $query->get();

        if ($mailboxes->isEmpty()) {
            $this->info('No active IMAP mailboxes found.');

            return 0;
        }

        foreach ($mailboxes as $mailbox) {
            $this->fetchForMailbox($mailbox);
        }

        return 0;
    }

    private function fetchForMailbox(Mailbox $mailbox): void
    {
        $config = $mailbox->imap_config;
        if (! $config) {
            return;
        }

        $this->info("[emails:fetch] Fetching mailbox id={$mailbox->id} name={$mailbox->name} <{$mailbox->email}>");
        Log::info('emails:fetch start', ['mailbox_id' => $mailbox->id, 'email' => $mailbox->email, 'host' => $config['host'] ?? null]);

        try {
            $cm = new ClientManager;

            $client = $cm->make([
                'host' => $config['host'],
                'port' => $config['port'] ?? 993,
                'encryption' => $config['encryption'] ?? 'ssl',
                'validate_cert' => $config['validate_cert'] ?? true,
                'username' => $config['username'],
                'password' => $config['password'],
                'protocol' => 'imap',
            ]);

            $client->connect();

            // Bound each run so huge/legacy inboxes (tens of thousands of unseen
            // messages) don't exhaust memory or import years of history as tickets.
            $limit = (int) ($this->option('limit') ?: env('IMAP_FETCH_LIMIT', 50));
            $sinceDays = $this->option('since-days');
            $sinceDays = $sinceDays === null ? (int) env('IMAP_FETCH_SINCE_DAYS', 3) : (int) $sinceDays;

            $folder = $client->getFolder('INBOX');
            $query = $folder->query()->unseen();
            if ($sinceDays > 0) {
                $query->since(now()->subDays($sinceDays));
            }
            $query->setFetchOrder('desc');
            $messages = $query->limit(max(1, $limit))->get();

            $count = $messages->count();
            $this->info("[emails:fetch]   Found {$count} new message(s) (limit {$limit}".($sinceDays > 0 ? ", last {$sinceDays}d" : '').').');
            Log::info('emails:fetch counted', ['mailbox_id' => $mailbox->id, 'count' => $count, 'limit' => $limit, 'since_days' => $sinceDays]);

            foreach ($messages as $message) {
                ProcessInboundEmailJob::dispatch($mailbox->id, [
                    'message_id' => $message->getMessageId()->first() ?? '',
                    'subject' => (string) $message->getSubject()->first(),
                    'from_email' => $message->getFrom()->first()->mail ?? '',
                    'from_name' => $message->getFrom()->first()->personal ?? '',
                    'body_html' => $message->hasHTMLBody() ? $message->getHTMLBody() : '',
                    'body_text' => $message->hasTextBody() ? $message->getTextBody() : '',
                    'in_reply_to' => $message->getInReplyTo()->first() ?? '',
                    'references' => $message->getReferences()->first() ?? '',
                    'attachments' => $this->extractAttachments($message),
                    'cc' => $this->extractCc($message),
                    'headers' => [
                        'auto_submitted' => (string) ($message->getHeader('Auto-Submitted') ?? ''),
                        'x_auto_response_suppress' => (string) ($message->getHeader('X-Auto-Response-Suppress') ?? ''),
                        'precedence' => (string) ($message->getHeader('Precedence') ?? ''),
                        'x_fusterai_auto_reply' => (string) ($message->getHeader('X-FusterAI-AutoReply') ?? ''),
                    ],
                ])->onQueue('email-inbound');

                // Mark as seen
                $message->setFlag('Seen');
            }

            $client->disconnect();
        } catch (\Exception $e) {
            $this->error("  Error fetching {$mailbox->email}: ".$e->getMessage());
            Log::error('FetchEmails failed', [
                'mailbox_id' => $mailbox->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function extractAttachments($message): array
    {
        $attachments = [];
        foreach ($message->getAttachments() as $attachment) {
            $attachments[] = [
                'name' => $attachment->getName(),
                'content' => base64_encode($attachment->getContent()),
                'mime' => $attachment->getMimeType(),
                'size' => $attachment->getSize(),
            ];
        }

        return $attachments;
    }

    private function extractCc($message): array
    {
        $cc = [];
        try {
            foreach ($message->getCC() as $address) {
                if (! empty($address->mail)) {
                    $cc[] = ['email' => $address->mail, 'name' => $address->personal ?? ''];
                }
            }
        } catch (\Throwable) {
            // Some IMAP servers return malformed CC — skip silently
        }

        return $cc;
    }
}
