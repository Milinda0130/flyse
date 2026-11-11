<?php

namespace App\Services;

use App\Enums\QuotationStatus;
use App\Enums\SegmentDirection;
use App\Models\Airline;
use App\Models\Booking;
use App\Models\FlightQuotation;
use App\Models\FlightQuotationOption;
use App\Models\FlightQuotationSegment;
use App\Models\User;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Flight quotations: saving (with options and segments), prices calculated here (never trusted
 * from the browser), readiness checks, itinerary warnings and the status workflow.
 * A quotation never creates a reservation, a ticket or a booking by itself.
 */
class QuotationService
{
    /** Statuses in which the quotation content can still be edited. */
    public const EDITABLE = [QuotationStatus::Draft, QuotationStatus::Ready, QuotationStatus::Sent];

    /** Manual status changes (Sent is set only by a successful email). */
    public const TRANSITIONS = [
        'draft' => ['ready'],
        'ready' => ['draft', 'accepted', 'rejected', 'expired'],
        'sent' => ['draft', 'accepted', 'rejected', 'expired'],
        'accepted' => ['converted', 'rejected', 'sent'],
        'rejected' => ['draft'],
        'expired' => ['draft'],
        'converted' => [],
    ];

    // ------------------------------------------------------------------ saving

    /** Creates or updates a quotation with all its options and segments in one transaction. */
    public function save(?FlightQuotation $quotation, array $data, User $user): FlightQuotation
    {
        if ($quotation && ! in_array($quotation->status, self::EDITABLE, true)) {
            throw ValidationException::withMessages(['status' => "A quotation that is {$quotation->status->label()} can no longer be edited. Duplicate it to make a new version."]);
        }

        return DB::transaction(function () use ($quotation, $data, $user) {
            $fields = collect($data)->except(['options'])->all();
            if ($quotation) {
                $quotation->update($fields);
            } else {
                $quotation = FlightQuotation::create([
                    ...$fields,
                    'reference' => $this->nextReference(),
                    'created_by' => $user->id,
                    'status' => QuotationStatus::Draft,
                    'status_changed_at' => now(),
                ]);
            }

            if (array_key_exists('options', $data)) {
                $quotation->options()->delete();
                foreach (array_values($data['options'] ?? []) as $i => $option) {
                    $this->createOption($quotation, $option, $i);
                }
            }
            $quotation->load('options.segments');

            // Ready means complete: if an edit made it incomplete it goes back to Draft.
            if ($quotation->status === QuotationStatus::Ready && $this->problems($quotation)) {
                $quotation->update(['status' => QuotationStatus::Draft, 'status_changed_at' => now()]);
            }

            return $quotation;
        });
    }

    private function createOption(FlightQuotation $quotation, array $option, int $index): FlightQuotationOption
    {
        $segments = $option['segments'] ?? [];
        $model = $quotation->options()->create([
            ...collect($option)->except(['segments', 'id'])->all(),
            'currency' => $option['currency'] ?? $quotation->currency,
            'display_order' => $index,
        ]);

        $airlines = Airline::whereIn('id', collect($segments)->flatMap(fn ($s) => [$s['airline_id'] ?? null, $s['operating_airline_id'] ?? null])->filter()->unique())->get()->keyBy('id');
        $order = ['outbound' => 0, 'return' => 0];
        foreach ($segments as $segment) {
            $direction = $segment['direction'] ?? 'outbound';
            $airline = $airlines[$segment['airline_id'] ?? 0] ?? null;
            $operating = $airlines[$segment['operating_airline_id'] ?? 0] ?? null;
            $model->segments()->create([
                ...collect($segment)->except(['id'])->all(),
                'direction' => $direction,
                'segment_order' => ++$order[$direction],
                // The airline record's name wins; free text when no record was chosen.
                'airline_name' => $airline?->name ?? $segment['airline_name'],
                'operating_airline_name' => $operating?->name ?? ($segment['operating_airline_name'] ?? null),
                'from_name' => $segment['from_name'] ?? Airports::name($segment['from_code']),
                'to_name' => $segment['to_name'] ?? Airports::name($segment['to_code']),
            ]);
        }

        return $model;
    }

    public function nextReference(?int $year = null): string
    {
        $year ??= (int) now()->format('Y');
        DB::table('quotation_code_sequences')->insertOrIgnore(['year' => $year, 'last_number' => 0]);
        $current = DB::table('quotation_code_sequences')->where('year', $year)->lockForUpdate()->value('last_number');
        DB::table('quotation_code_sequences')->where('year', $year)->update(['last_number' => $current + 1]);

        return sprintf('FQ-%d-%04d', $year, $current + 1);
    }

    /** A new Draft copy (new reference) with the same requirements, options and segments. */
    public function duplicate(FlightQuotation $source, User $user): FlightQuotation
    {
        $source->load('options.segments');

        return DB::transaction(function () use ($source, $user) {
            $copy = $source->replicate(['reference', 'accepted_option_id', 'booking_id', 'status_changed_at', 'created_by', 'deleted_at']);
            $copy->fill([
                'reference' => $this->nextReference(),
                'status' => QuotationStatus::Draft,
                'status_changed_at' => now(),
                'created_by' => $user->id,
            ])->save();
            foreach ($source->options as $option) {
                $newOption = $option->replicate();
                $newOption->flight_quotation_id = $copy->id;
                $newOption->save();
                foreach ($option->segments as $segment) {
                    $newSegment = $segment->replicate();
                    $newSegment->flight_quotation_option_id = $newOption->id;
                    $newSegment->save();
                }
            }

            return $copy->load('options.segments');
        });
    }

    // ------------------------------------------------------------------ status

    public function transition(FlightQuotation $quotation, string $to, array $data): FlightQuotation
    {
        $from = $quotation->status->value;
        if (! in_array($to, self::TRANSITIONS[$from] ?? [], true)) {
            throw ValidationException::withMessages(['status' => 'A quotation that is '.$quotation->status->label().' cannot be changed to '.QuotationStatus::from($to)->label().'.']);
        }
        $changes = ['status' => $to, 'status_changed_at' => now()];

        if ($to === 'ready' && ($problems = $this->problems($quotation->load('options.segments')))) {
            throw ValidationException::withMessages(['status' => $problems]);
        }
        if ($to === 'sent' && ! $quotation->emails()->where('status', 'sent')->exists()) {
            throw ValidationException::withMessages(['status' => 'This quotation has never been emailed.']);
        }
        if ($to === 'accepted') {
            $optionId = (int) ($data['accepted_option_id'] ?? 0);
            if (! $quotation->options()->whereKey($optionId)->exists()) {
                throw ValidationException::withMessages(['accepted_option_id' => 'Choose which option the customer accepted.']);
            }
            $changes['accepted_option_id'] = $optionId;
        }
        if (in_array($to, ['rejected', 'draft', 'expired', 'sent'], true)) {
            $changes['accepted_option_id'] = null;
        }
        if ($to === 'converted') {
            // Linking only: the booking itself is created through the normal booking workflow.
            $booking = Booking::find($data['booking_id'] ?? 0);
            if (! $booking || ($quotation->customer_id && $booking->customer_id !== $quotation->customer_id)) {
                throw ValidationException::withMessages(['booking_id' => $booking ? 'Choose a booking of the same customer.' : 'Choose a booking.']);
            }
            $changes['booking_id'] = $booking->id;
        }

        $quotation->update($changes);

        return $quotation;
    }

    /** Called after a quotation email was accepted by the mail server. */
    public function markSent(FlightQuotation $quotation): void
    {
        if (in_array($quotation->status, [QuotationStatus::Draft, QuotationStatus::Ready], true)) {
            $quotation->update(['status' => QuotationStatus::Sent, 'status_changed_at' => now()]);
        }
    }

    // ------------------------------------------------------------------ checks

    /** What must be fixed before the quotation can be marked ready, turned into a PDF or emailed. @return list<string> */
    public function problems(FlightQuotation $quotation): array
    {
        $quotation->loadMissing('options.segments');
        $problems = [];
        if ($quotation->options->isEmpty()) {
            $problems[] = 'Add at least one flight option.';
        }
        foreach ($quotation->options as $option) {
            $name = $option->label;
            $outbound = $option->segments->where('direction', SegmentDirection::Outbound);
            $return = $option->segments->where('direction', SegmentDirection::Return);
            if ($outbound->isEmpty()) {
                $problems[] = "$name: add the outbound flight(s).";
            }
            if ($quotation->isReturn() && $return->isEmpty()) {
                $problems[] = "$name: add the return flight(s).";
            }
            foreach ($option->segments as $s) {
                if (! $s->arrival_at) {
                    $problems[] = "$name: flight {$s->flight_number} has no arrival time.";
                }
            }
            $pricing = $this->pricing($quotation, $option);
            foreach ($pricing['rows'] as $row) {
                if ($row['per_person'] === null) {
                    $problems[] = "$name: enter the {$row['label']} fare.";
                }
            }
        }

        return $problems;
    }

    /** Things that look inconsistent but may be correct; shown to staff, never auto-corrected. @return list<string> */
    public function warnings(FlightQuotation $quotation): array
    {
        $quotation->loadMissing('options.segments');
        $warnings = [];
        foreach ($quotation->options as $option) {
            foreach ($this->itinerary($option) as $direction => $leg) {
                $segments = $leg['segments'];
                $first = $segments[0]['model'];
                $expected = $direction === 'outbound' ? $quotation->outbound_date : $quotation->return_date;
                if ($expected && $first->departure_at->format('Y-m-d') !== $expected->format('Y-m-d')) {
                    $warnings[] = "{$option->label}: the {$direction} flight departs on {$first->departure_at->format('d M Y')}, but the requested date is {$expected->format('d M Y')}.";
                }
                foreach ($segments as $i => $seg) {
                    if ($seg['duration'] !== null && $seg['duration'] <= 0) {
                        $warnings[] = "{$option->label}: flight {$seg['model']->flight_number} arrives before it departs (check the times and timezones).";
                    }
                    if ($i > 0) {
                        $prev = $segments[$i - 1]['model'];
                        if ($prev->to_code !== $seg['model']->from_code) {
                            $warnings[] = "{$option->label}: flight {$prev->flight_number} arrives at {$prev->to_code} but {$seg['model']->flight_number} departs from {$seg['model']->from_code}.";
                        } elseif ($seg['layover'] !== null && $seg['layover'] < 0) {
                            $warnings[] = "{$option->label}: flight {$seg['model']->flight_number} departs before {$prev->flight_number} arrives.";
                        }
                    }
                }
            }
            $legs = $this->itinerary($option);
            if (isset($legs['outbound'], $legs['return'])) {
                $lastOut = end($legs['outbound']['segments'])['model'];
                $firstBack = $legs['return']['segments'][0]['model'];
                if ($lastOut->arrival_at && $firstBack->departure_at->lt($lastOut->arrival_at)) {
                    $warnings[] = "{$option->label}: the return flight departs before the outbound journey arrives.";
                }
            }
        }
        if ($quotation->valid_until && $quotation->valid_until->isPast() && in_array($quotation->status, self::EDITABLE, true)) {
            $warnings[] = 'The quotation validity date has passed.';
        }

        return $warnings;
    }

    // ------------------------------------------------------------------ prices

    private static function cents($amount): ?int
    {
        return $amount === null || $amount === '' ? null : Money::cents($amount);
    }

    /**
     * Prices of one option in its own currency, in cents. Per person = fare + taxes + other charges.
     * A passenger type with no fare entered makes the party total unknown (never treated as 0).
     */
    public function pricing(FlightQuotation $quotation, FlightQuotationOption $option): array
    {
        $other = self::cents($option->other_charges);
        $rows = [];
        foreach (['adult' => ['Adult', $quotation->adults], 'child' => ['Child', $quotation->children], 'infant' => ['Infant', $quotation->infants]] as $type => [$label, $count]) {
            if ($count < 1) {
                continue;
            }
            $fare = self::cents($option->{$type.'_fare'});
            $tax = self::cents($option->{$type.'_tax'});
            $per = $fare === null ? null : $fare + ($tax ?? 0) + ($other ?? 0);
            $rows[] = ['type' => $type, 'label' => strtolower($label), 'title' => $label, 'count' => $count, 'fare' => $fare, 'tax' => $tax,
                'other' => $other, 'per_person' => $per, 'subtotal' => $per === null ? null : $per * $count];
        }
        $complete = $rows && collect($rows)->every(fn ($r) => $r['per_person'] !== null);
        $party = $complete ? array_sum(array_column($rows, 'subtotal')) : null;
        $cost = self::cents($option->supplier_cost);

        return [
            'currency' => $option->currency,
            'rows' => $rows,
            'adult_per_person' => collect($rows)->firstWhere('type', 'adult')['per_person'] ?? null,
            'party_total' => $party,
            'supplier_cost' => $cost,
            'margin' => $party !== null && $cost !== null ? $party - $cost : null,
        ];
    }

    // ------------------------------------------------------------------ itinerary

    /**
     * The option's segments grouped by direction, with durations (only when they can be known:
     * both timezones recorded, or entered by staff) and layovers between connecting flights.
     *
     * @return array<string, array{segments: list<array>, travel_minutes: ?int, stops: int}>
     */
    public function itinerary(FlightQuotationOption $option): array
    {
        $legs = [];
        foreach (['outbound', 'return'] as $direction) {
            /** @var Collection<int, FlightQuotationSegment> $segments */
            $segments = $option->segments->filter(fn ($s) => $s->direction->value === $direction)->sortBy('segment_order')->values();
            if ($segments->isEmpty()) {
                continue;
            }
            $rows = [];
            foreach ($segments as $i => $s) {
                $prev = $i > 0 ? $segments[$i - 1] : null;
                $layover = $prev && $prev->arrival_at && $prev->to_code === $s->from_code
                    ? (int) $prev->arrival_at->diffInMinutes($s->departure_at, false) // same airport: local times compare directly
                    : null;
                $rows[] = ['model' => $s, 'duration' => $this->segmentMinutes($s), 'layover' => $layover];
            }
            $legs[$direction] = ['segments' => $rows, 'travel_minutes' => $this->travelMinutes($rows), 'stops' => count($rows) - 1];
        }

        return $legs;
    }

    public function segmentMinutes(FlightQuotationSegment $s): ?int
    {
        if ($s->arrival_at && $s->departure_tz && $s->arrival_tz) {
            try {
                $dep = CarbonImmutable::parse($s->departure_at->format('Y-m-d H:i'), $s->departure_tz);
                $arr = CarbonImmutable::parse($s->arrival_at->format('Y-m-d H:i'), $s->arrival_tz);

                return (int) $dep->diffInMinutes($arr, false);
            } catch (Throwable) {
                // fall through to the entered duration
            }
        }

        return $s->duration_minutes;
    }

    /** Total door-to-door time of one direction, when every part of it is known. */
    private function travelMinutes(array $rows): ?int
    {
        $first = $rows[0]['model'];
        $last = end($rows)['model'];
        if ($last->arrival_at && $first->departure_tz && $last->arrival_tz) {
            try {
                return (int) CarbonImmutable::parse($first->departure_at->format('Y-m-d H:i'), $first->departure_tz)
                    ->diffInMinutes(CarbonImmutable::parse($last->arrival_at->format('Y-m-d H:i'), $last->arrival_tz), false);
            } catch (Throwable) {
                // fall back to the sum below
            }
        }
        $total = 0;
        foreach ($rows as $i => $row) {
            if ($row['duration'] === null || ($i > 0 && $row['layover'] === null)) {
                return null;
            }
            $total += $row['duration'] + ($i > 0 ? $row['layover'] : 0);
        }

        return $total;
    }

    public static function minutesLabel(?int $minutes): ?string
    {
        return $minutes === null || $minutes < 0 ? null : sprintf('%dh %02dm', intdiv($minutes, 60), $minutes % 60);
    }
}
