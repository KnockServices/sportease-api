<?php

namespace App\Services;

use App\Models\Client;
use App\Models\CommunicationLog;
use App\Models\Lead;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Sends SMS / WhatsApp / email messages and records every one in the communication log.
 *
 * Provider credentials are third-party (see proposal §7), so the default "log" driver
 * records the message without contacting a provider. Plug real SMS/WhatsApp gateways
 * into deliver() once the academy supplies API keys.
 */
class Messenger
{
    public static function send(string $channel, ?string $recipient, string $body, ?string $subject = null, ?string $template = null, ?Client $client = null, ?Lead $lead = null): ?CommunicationLog
    {
        if (! $recipient) {
            return null;
        }

        $log = CommunicationLog::create([
            'channel' => $channel,
            'recipient' => $recipient,
            'client_id' => $client?->id,
            'lead_id' => $lead?->id,
            'template' => $template,
            'subject' => $subject,
            'body' => $body,
            'status' => 'queued',
            'provider' => Hub::setting("provider.$channel", 'log'),
        ]);

        try {
            self::deliver($log);
            $log->update(['status' => 'sent']);
        } catch (Throwable $e) {
            $log->update(['status' => 'failed', 'error' => $e->getMessage()]);
        }

        return $log;
    }

    /** Send to a lead or client on their phone (SMS + WhatsApp when enabled) and email. */
    public static function notify(Client|Lead $to, string $template, string $subject, string $body): void
    {
        $client = $to instanceof Client ? $to : null;
        $lead = $to instanceof Lead ? $to : null;

        // Child clients are contacted through the family's billing parent.
        if ($client && $client->type === 'child') {
            $parent = Client::where('family_id', $client->family_id)->whereIn('type', ['parent', 'adult'])->orderByDesc('billing_flag')->first();
            $phone = $parent?->phone;
            $email = $parent?->email;
        } else {
            $phone = $to->phone;
            $email = $to->email;
        }

        self::send('sms', $phone, $body, null, $template, $client, $lead);
        if (Hub::setting('whatsapp.enabled', true)) {
            self::send('whatsapp', $phone, $body, null, $template, $client, $lead);
        }
        self::send('email', $email, $body, $subject, $template, $client, $lead);
    }

    protected static function deliver(CommunicationLog $log): void
    {
        if ($log->provider === 'log') {
            Log::info("[{$log->channel}] to {$log->recipient}: {$log->body}");

            return;
        }

        if ($log->channel === 'email' && $log->provider === 'mail') {
            Mail::raw($log->body, fn ($m) => $m->to($log->recipient)->subject($log->subject ?? Tenant::name()));

            return;
        }

        throw new \RuntimeException("Provider {$log->provider} for {$log->channel} is not configured.");
    }
}
