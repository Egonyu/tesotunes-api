<?php

namespace App\Services\Events;

use App\Models\Event;
use App\Models\EventTicket;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * The one place ticket tiers are written.
 *
 * Tier saving lived in three controller methods with three sets of rules. The
 * artist update ignored tiers entirely, so edits on the artist edit page were
 * silently thrown away; the artist create read `sale_starts_at` while the form
 * sent `sales_start_date`, so sale windows were dropped; and the admin paths
 * defaulted differently again.
 *
 * Rules, for every caller:
 * - a tier that has sold is never deleted, and its quantity can't drop below sold;
 * - sale windows are read in the event's timezone and stored as UTC;
 * - a sale end left blank closes sales when the event ends;
 * - max per order is clamped to 1..quantity.
 */
final class EventTicketTierSync
{
    /**
     * @param  mixed  $tiers  array or JSON string from the form
     *
     * @throws ValidationException
     */
    public function sync(Event $event, mixed $tiers, bool $pruneMissing = true): void
    {
        if (is_string($tiers)) {
            $tiers = json_decode($tiers, true);
        }

        if (! is_array($tiers)) {
            return;
        }

        $tiers = array_values(array_filter($tiers, 'is_array'));
        $timezone = $event->timezone;

        DB::transaction(function () use ($event, $tiers, $pruneMissing, $timezone) {
            $existing = $event->tickets()->lockForUpdate()->get()->keyBy('id');
            $keepIds = [];

            foreach ($tiers as $index => $tier) {
                $id = is_numeric($tier['id'] ?? null) && $existing->has((int) $tier['id']) ? (int) $tier['id'] : null;
                $current = $id ? $existing->get($id) : null;

                $name = trim((string) ($tier['name'] ?? ''));
                if ($name === '') {
                    throw ValidationException::withMessages(["ticket_tiers.{$index}.name" => 'Give this ticket a name.']);
                }

                $price = max(0, (float) ($tier['price'] ?? $tier['price_ugx'] ?? 0));
                $quantity = (int) ($tier['quantity'] ?? $tier['quantity_total'] ?? 0);
                $sold = (int) ($current?->quantity_sold ?? 0);

                if ($quantity < 1) {
                    throw ValidationException::withMessages(["ticket_tiers.{$index}.quantity" => "{$name}: how many tickets are available?"]);
                }
                if ($quantity < $sold) {
                    throw ValidationException::withMessages(["ticket_tiers.{$index}.quantity" => "{$name} has already sold {$sold}; quantity can't be lower."]);
                }

                $maxPerOrder = min($quantity, max(1, (int) ($tier['max_per_order'] ?? 10)));
                $saleStarts = EventScheduleInput::instant($tier['sale_starts_at'] ?? $tier['sales_start_date'] ?? null, $timezone);
                $saleEnds = EventScheduleInput::instant($tier['sale_ends_at'] ?? $tier['sales_end_date'] ?? null, $timezone)
                    ?? $event->ends_at
                    ?? $event->starts_at;

                if ($saleStarts && $saleEnds && $saleEnds->lessThanOrEqualTo($saleStarts)) {
                    throw ValidationException::withMessages(["ticket_tiers.{$index}.sale_ends_at" => "{$name}: sales must end after they start."]);
                }

                $attributes = [
                    'name' => $name,
                    'description' => (string) ($tier['description'] ?? ''),
                    'price_ugx' => $price,
                    'price_credits' => max(0, (int) ($tier['price_credits'] ?? 0)),
                    'is_free' => $price == 0.0,
                    'quantity_total' => $quantity,
                    'max_per_order' => $maxPerOrder,
                    'sale_starts_at' => $saleStarts,
                    'sale_ends_at' => $saleEnds,
                    'sort_order' => $index,
                ];

                if (array_key_exists('is_active', $tier)) {
                    $attributes['is_active'] = (bool) $tier['is_active'];
                }

                if ($current) {
                    $current->update($attributes);
                    $keepIds[] = $current->id;
                } else {
                    $created = EventTicket::create($attributes + [
                        'uuid' => (string) Str::uuid(),
                        'event_id' => $event->id,
                        'quantity_sold' => 0,
                        'min_per_order' => 1,
                        'is_active' => $attributes['is_active'] ?? true,
                    ]);
                    $keepIds[] = $created->id;
                }
            }

            if ($pruneMissing) {
                $event->tickets()
                    ->where('quantity_sold', 0)
                    ->when($keepIds !== [], fn ($query) => $query->whereNotIn('id', $keepIds))
                    ->delete();
            }
        });
    }
}
