<?php

namespace App\Http\Resources;

use App\Enums\PaymentDirection;
use App\Services\BookingFinanceService;
use App\Services\BookingWorkflowService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Booking JSON for both list and detail views. Supplier cost, margin and
 * supplier-side transactions are removed for users without the view-costs ability.
 */
class BookingResource extends JsonResource
{
    private ?BookingWorkflowService $workflow = null;

    /** Detail view: include the outstanding-task checklist, next step and allowed status changes. */
    public function withWorkflow(BookingWorkflowService $workflow): static
    {
        $this->workflow = $workflow;

        return $this;
    }

    public function toArray(Request $request): array
    {
        $canSeeCosts = $request->user()?->can('view-costs') ?? false;
        $finance = null;
        if ($this->relationLoaded('payments') || array_key_exists('sum_customer_receipt', $this->resource->getAttributes())) {
            $finance = app(BookingFinanceService::class)->summary($this->resource);
            if (! $canSeeCosts) {
                $finance = array_diff_key($finance, array_flip([
                    'supplier_total', 'supplier_paid', 'supplier_refunded', 'supplier_balance', 'gross_margin',
                ]));
            }
        }

        $flights = $this->relationLoaded('flights') ? $this->flights : null;

        return [
            'id' => $this->id,
            'booking_code' => $this->booking_code,
            'customer_id' => $this->customer_id,
            'customer' => $this->whenLoaded('customer', fn () => [
                'id' => $this->customer->id,
                'full_name' => $this->customer->full_name,
                'phone' => $this->customer->phone,
                'email' => $this->customer->email,
            ]),
            'supplier_id' => $this->supplier_id,
            'supplier' => $this->whenLoaded('supplier', fn () => $this->supplier ? [
                'id' => $this->supplier->id,
                'name' => $this->supplier->name,
            ] : null),
            'pnr' => $this->pnr,
            'booking_date' => $this->booking_date?->format('Y-m-d'),
            // What the customer asked for (may exist before any confirmed itinerary)
            'trip_type' => $this->trip_type?->value,
            'origin' => $this->origin,
            'destination' => $this->destination,
            'departure_date' => $this->departure_date?->format('Y-m-d'),
            'return_date' => $this->return_date?->format('Y-m-d'),
            'cabin_class' => $this->cabin_class,
            'passenger_count' => $this->passenger_count,
            'requested_route' => $this->resource->requestedRoute(),
            'cancellation_reason' => $this->cancellation_reason,
            'cancelled_at' => $this->cancelled_at?->toIso8601String(),
            'currency' => $this->currency,
            'status' => $this->status->value,
            'payment_due_date' => $this->payment_due_date?->format('Y-m-d'),
            'ticketing_deadline' => $this->ticketing_deadline?->format('Y-m-d\TH:i'),
            'selling_total' => $this->selling_total,
            'service_fee' => $this->service_fee,
            'supplier_total' => $this->when($canSeeCosts, $this->supplier_total),
            'next_action' => $this->next_action,
            'notes' => $this->notes,
            'route' => $flights ? $this->resource->routeString() : null,
            'first_departure_at' => $flights?->min('departure_at')?->format('Y-m-d\TH:i'),
            'airlines' => $flights ? $flights->pluck('airline')->unique()->values() : null,
            'passengers_count' => $this->whenCounted('passengers'),
            'open_issues_count' => $this->whenCounted('issues'),
            'finance' => $finance,
            'passengers' => PassengerResource::collection($this->whenLoaded('passengers')),
            'flights' => FlightResource::collection($this->whenLoaded('flights')),
            'tickets' => TicketResource::collection($this->whenLoaded('tickets')),
            'documents' => DocumentResource::collection($this->whenLoaded('documents')),
            'issues' => IssueResource::collection($this->whenLoaded('issues')),
            'payments' => $this->whenLoaded('payments', fn () => PaymentResource::collection(
                $canSeeCosts ? $this->payments : $this->payments->filter(fn ($p) => in_array(
                    $p->direction, [PaymentDirection::CustomerReceipt, PaymentDirection::CustomerRefund], true
                ))->values()
            )),
            'creator' => new UserSummaryResource($this->whenLoaded('creator')),
            $this->mergeWhen($this->workflow !== null, fn () => [
                'checklist' => $this->workflow->checklist($this->resource, $canSeeCosts),
                'next_step' => $this->workflow->nextStep($this->resource, $canSeeCosts),
                'ticketing_problems' => $this->workflow->ticketingProblems($this->resource),
                'allowed_transitions' => $request->user()?->can('edit-records') ? $this->workflow->allowedFrom($this->status) : [],
            ]),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'deleted_at' => $this->deleted_at?->toIso8601String(),
        ];
    }
}
