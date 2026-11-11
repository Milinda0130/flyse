<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\PaymentDirection;
use App\Http\Controllers\Controller;
use App\Http\Requests\PaymentRequest;
use App\Http\Requests\UpdatePaymentRequest;
use App\Http\Resources\PaymentResource;
use App\Models\Booking;
use App\Models\Payment;
use App\Services\BookingFinanceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/**
 * Payments are append-only transactions. Corrections are made by the owner editing
 * or voiding a row (audited); rows are never deleted.
 */
class PaymentController extends Controller
{
    public function __construct(private BookingFinanceService $finance) {}

    /** All transactions across bookings (Payments page). */
    public function index(Request $request): AnonymousResourceCollection
    {
        $f = $request->validate([
            'direction' => ['nullable', Rule::enum(PaymentDirection::class)],
            'status' => ['nullable', 'string'],
            'currency' => ['nullable', 'string', 'size:3'],
            'method' => ['nullable', 'string', 'max:40'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'q' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = Payment::query()
            ->with(['booking:id,booking_code', 'creator:id,name'])
            ->when(! $request->user()->can('view-costs'), fn ($q) => $q->whereIn('direction', $this->customerDirections()))
            ->when($f['direction'] ?? null, fn ($q, $v) => $q->where('direction', $v))
            ->when($f['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($f['currency'] ?? null, fn ($q, $v) => $q->where('currency', strtoupper($v)))
            ->when($f['method'] ?? null, fn ($q, $v) => $q->where('method', $v))
            ->when($f['from'] ?? null, fn ($q, $v) => $q->whereDate('transaction_date', '>=', $v))
            ->when($f['to'] ?? null, fn ($q, $v) => $q->whereDate('transaction_date', '<=', $v))
            ->when($f['q'] ?? null, fn ($q, $v) => $q->where(fn ($w) => $w
                ->where('transaction_reference', 'like', '%'.$v.'%')
                ->orWhereHas('booking', fn ($b) => $b->where('booking_code', 'like', '%'.$v.'%'))))
            ->orderByDesc('transaction_date')->orderByDesc('id');

        return PaymentResource::collection($query->paginate($f['per_page'] ?? 25)->withQueryString());
    }

    public function bookingIndex(Request $request, Booking $booking): JsonResponse
    {
        $booking->load('payments.creator:id,name');
        $payments = $request->user()->can('view-costs')
            ? $booking->payments
            : $booking->payments->filter(fn ($p) => in_array($p->direction->value, $this->customerDirections(), true))->values();
        $summary = $this->finance->summary($booking);
        if (! $request->user()->can('view-costs')) {
            $summary = array_diff_key($summary, array_flip(['supplier_total', 'supplier_paid', 'supplier_refunded', 'supplier_balance', 'gross_margin']));
        }

        return response()->json([
            'payments' => PaymentResource::collection($payments),
            'summary' => $summary,
        ]);
    }

    public function store(PaymentRequest $request, Booking $booking): JsonResponse
    {
        $payment = $booking->payments()->create([
            ...$request->validated(),
            'status' => $request->validated('status', 'completed'),
            'created_by' => $request->user()->id,
        ]);

        return (new PaymentResource($payment->load('creator:id,name')))->response()->setStatusCode(201);
    }

    public function update(UpdatePaymentRequest $request, Payment $payment): PaymentResource
    {
        $payment->update([...$request->validated(), 'updated_by' => $request->user()->id]);

        return new PaymentResource($payment->load('creator:id,name'));
    }

    private function customerDirections(): array
    {
        return [PaymentDirection::CustomerReceipt->value, PaymentDirection::CustomerRefund->value];
    }
}
