<?php

namespace App\Services;

use App\Models\Team;
use App\Models\WaitingListEntry;

/**
 * FIFO waiting list for capacity-managed teams. When a spot opens the first waiting
 * entry receives a time-limited offer; expired/declined offers fall to the next candidate.
 */
class WaitingList
{
    public static function enrolledCount(Team $team): int
    {
        return $team->registrations()->where('status', 'active')->count();
    }

    public static function openSpots(Team $team): int
    {
        $pending = $team->waitingList()->where('status', 'offered')->count();

        return max(0, $team->capacity - self::enrolledCount($team) - $pending);
    }

    /** Offer open spots to the next people in line. Returns number of offers made. */
    public static function process(Team $team): int
    {
        $offers = 0;
        $hours = (int) Hub::setting('waitlist.response_hours', 48);

        while (self::openSpots($team) > 0) {
            $next = $team->waitingList()->where('status', 'waiting')->orderBy('created_at')->orderBy('id')->first();
            if (! $next) {
                break;
            }
            $next->update(['status' => 'offered', 'offered_at' => now(), 'offer_expires_at' => now()->addHours($hours)]);
            $client = $next->client;

            Messenger::notify($client, 'waitlist_offer', 'A spot is available',
                "Good news! A spot opened in {$team->name} for {$client->full_name}. Please confirm before ".$next->offer_expires_at->format('D d M H:i').' or the spot passes to the next family.');
            Hub::alert('waitlist', "Waiting list offer sent: {$client->full_name}", "{$team->name} — expires ".$next->offer_expires_at->format('d M H:i'), '/waiting-list', null, 'reception');
            Hub::journey($client->id, 'waitlist', "Offered a spot in {$team->name}", [], $client->family_id);
            $offers++;
        }

        return $offers;
    }

    public static function expireOffers(): int
    {
        $expired = WaitingListEntry::where('status', 'offered')->where('offer_expires_at', '<', now())->with('team', 'client')->get();
        foreach ($expired as $entry) {
            $entry->update(['status' => 'expired', 'responded_at' => now()]);
            Hub::journey($entry->client_id, 'waitlist', "Waiting list offer for {$entry->team->name} expired", [], $entry->client->family_id);
        }
        $expired->pluck('team')->unique('id')->each(fn ($team) => self::process($team));

        return $expired->count();
    }

    public static function position(WaitingListEntry $entry): ?int
    {
        if ($entry->status !== 'waiting') {
            return null;
        }

        return WaitingListEntry::where('team_id', $entry->team_id)->where('status', 'waiting')
            ->where(fn ($q) => $q->where('created_at', '<', $entry->created_at)
                ->orWhere(fn ($q) => $q->where('created_at', $entry->created_at)->where('id', '<', $entry->id)))
            ->count() + 1;
    }
}
