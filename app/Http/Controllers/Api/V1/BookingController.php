<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\BookingStatus;
use App\Enums\IssueStatus;
use App\Enums\PaymentDirection;
use App\Enums\PaymentStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\StatusTransitionRequest;
use App\Http\Requests\StoreBookingRequest;
use App\Http\Requests\UpdateBookingRequest;
use App\Http\Resources\AuditLogResource;
use App\Http\Resources\BookingResource;
use App\Models\AuditLog;
use App\Models\Booking;
use App\Models\BookingFlight;
use App\Models\Payment;
use App\Services\BookingCodeService;
use App\Services\BookingFinanceService;
use App\Services\BookingWorkflowService;
use App\Services\SettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class BookingController extends Controller
{
    private const SORTS = ['booking_date', 'booking_code', 'created_at', 'first_departure_at', 'status'];

    public function __construct(private BookingFinanceService $finance, private BookingWorkflowService $workflow) {}

    /** Search by booking code, PNR, passenger, ticket, phone, flight number; filter by status/route/dates. */
    public function index(Request $request): AnonymousResourceCollection
    {
        $f = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'string'],
            'customer_id' => ['nullable', 'integer'],
            'supplier_id' => ['nullable', 'integer'],
            'airline' => ['nullable', 'string', 'max:100'],
            'origin' => ['nullable', 'string', 'size:3'],
            'destination' => ['nullable', 'string', 'size:3'],
            'departure_from' => ['nullable', 'date'],
            'departure_to' => ['nullable', 'date', 'after_or_equal:departure_from'],
            'booking_from' => ['nullable', 'date'],
            'booking_to' => ['nullable', 'date', 'after_or_equal:booking_from'],
            'has_open_issues' => ['nullable', 'boolean'],
            'sort' => ['nullable', Rule::in(self::SORTS)],
            'direction' => ['nullable', Rule::in(['asc', 'desc'])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = Booking::query()
            ->select('bookings.*')
            ->addSelect(['first_departure_at' => BookingFlight::selectRaw('min(departure_at)')->whereColumn('booking_id', 'bookings.id')])
            ->with(['customer:id,full_name,phone,email', 'supplier:id,name', 'flights'])
            ->withCount(['passengers', 'issues' => fn ($q) => $q->whereIn('status', array_map(fn ($s) => $s->value, IssueStatus::openStatuses()))]);
        $this->finance->withPaymentSums($query);

        if (! empty($f['q'])) {
            $term = trim($f['q']);
            $like = '%'.$term.'%';
            // Phone matching ignores formatting and a local leading zero: "0771234567" finds "+94 77 123 4567".
            $digits = ltrim((string) preg_replace('/\D+/', '', $term), '0');
            $plainPhone = "replace(replace(replace(replace(replace(phone, ' ', ''), '-', ''), '+', ''), '(', ''), ')', '')";
            $query->where(function ($w) use ($like, $digits, $plainPhone) {
                $w->where('booking_code', 'like', $like)
                    ->orWhere('pnr', 'like', $like)
                    ->orWhereHas('customer', function ($c) use ($like, $digits, $plainPhone) {
                        $c->where('full_name', 'like', $like)->orWhere('phone', 'like', $like)->orWhere('email', 'like', $like);
                        if (strlen($digits) >= 6) {
                            $c->orWhereRaw("$plainPhone like ?", ['%'.$digits.'%']);
                        }
                    })
                    ->orWhereHas('passengers', fn ($p) => $p->where('full_name_as_document', 'like', $like)->orWhere('phone', 'like', $like))
                    ->orWhereHas('tickets', fn ($t) => $t->where('ticket_number', 'like', $like)->orWhere('airline_pnr', 'like', $like))
                    ->orWhereHas('flights', fn ($fl) => $fl->where('flight_number', 'like', $like));
            });
        }

        if (! empty($f['status'])) {
            $query->whereIn('status', array_intersect(explode(',', $f['status']), BookingStatus::values()));
        }
        $query->when($f['customer_id'] ?? null, fn ($q, $v) => $q->where('customer_id', $v))
            ->when($f['supplier_id'] ?? null, fn ($q, $v) => $q->where('supplier_id', $v))
            ->when($f['booking_from'] ?? null, fn ($q, $v) => $q->whereDate('booking_date', '>=', $v))
            ->when($f['booking_to'] ?? null, fn ($q, $v) => $q->whereDate('booking_date', '<=', $v))
            ->when($f['airline'] ?? null, fn ($q, $v) => $q->whereHas('flights', fn ($fl) => $fl->where('airline', 'like', '%'.$v.'%')))
            ->when($f['origin'] ?? null, fn ($q, $v) => $q->whereHas('flights', fn ($fl) => $fl->where('origin_code', strtoupper($v))))
            ->when($f['destination'] ?? null, fn ($q, $v) => $q->whereHas('flights', fn ($fl) => $fl->where('destination_code', strtoupper($v))));

        if (! empty($f['departure_from']) || ! empty($f['departure_to'])) {
            $query->whereHas('flights', function ($fl) use ($f) {
                if (! empty($f['departure_from'])) {
                    $fl->where('departure_at', '>=', $f['departure_from'].' 00:00:00');
                }
                if (! empty($f['departure_to'])) {
                    $fl->where('departure_at', '<=', $f['departure_to'].' 23:59:59');
                }
            });
        }
        if (array_key_exists('has_open_issues', $f) && $f['has_open_issues'] !== null) {
            $method = $request->boolean('has_open_issues') ? 'whereHas' : 'whereDoesntHave';
            $query->{$method}('issues', fn ($i) => $i->whereIn('status', array_map(fn ($s) => $s->value, IssueStatus::openStatuses())));
        }

        $sort = $f['sort'] ?? 'booking_date';
        $direction = $f['direction'] ?? 'desc';
        $query->orderBy($sort, $direction)->orderBy('bookings.id', $direction);

        return BookingResource::collection($query->paginate($f['per_page'] ?? 20)->withQueryString());
    }

    /** Creates the booking with passengers, segments and an optional first receipt in one transaction. */
    /** Creates a booking. Only the customer and requested trip are needed; an inquiry is the default. */
    public function store(StoreBookingRequest $request, BookingCodeService $codes, SettingsService $settings): JsonResponse
    {
        $data = $request->validated();
        $data['booking_date'] ??= now()->toDateString();
        $data['currency'] ??= $settings->get('default_currency');
        if (! empty($data['initial_payment'])) {
            Gate::authorize('record-payment', PaymentDirection::CustomerReceipt);
        }

        $booking = DB::transaction(function () use ($data, $codes, $request) {
            $booking = Booking::create([
                ...Arr::except($data, ['passengers', 'flights', 'initial_payment']),
                'booking_code' => $codes->next(),
                'status' => $data['status'] ?? BookingStatus::Inquiry->value,
                'created_by' => $request->user()->id,
                'updated_by' => $request->user()->id,
            ]);

            foreach ($data['passengers'] ?? [] as $passenger) {
                $booking->passengers()->create($passenger);
            }
            foreach (array_values($data['flights'] ?? []) as $i => $flight) {
                $booking->flights()->create(['segment_order' => $i + 1, ...$flight]);
            }
            if (! empty($data['initial_payment'])) {
                Payment::create([
                    ...$data['initial_payment'],
                    'booking_id' => $booking->id,
                    'direction' => PaymentDirection::CustomerReceipt,
                    'currency' => $booking->currency,
                    'status' => PaymentStatus::Completed,
                    'created_by' => $request->user()->id,
                ]);
            }

            return $booking;
        });

        return $this->detail($booking)->response()->setStatusCode(201);
    }

    public function show(Booking $booking): BookingResource
    {
        return $this->detail($booking);
    }

    /** Edits booking details. The status is changed only through transition(). */
    public function update(UpdateBookingRequest $request, Booking $booking): BookingResource
    {
        $booking->update([...$request->validated(), 'updated_by' => $request->user()->id]);

        return $this->detail($booking);
    }

    /** Moves the booking to another status, enforcing that step's requirements. */
    public function transition(StatusTransitionRequest $request, Booking $booking): BookingResource
    {
        $data = $request->validated();
        $this->workflow->transition($booking, BookingStatus::from($data['status']), $data, $request->user()->id);

        return $this->detail($booking);
    }

    /** Booking activity: audit entries for the booking and its child records. */
    public function activity(Request $request, Booking $booking): AnonymousResourceCollection
    {
        Gate::authorize('view-audit');
        $logs = AuditLog::where('booking_id', $booking->id)
            ->with('user:id,name')
            ->latest('created_at')->orderByDesc('id')
            ->paginate(50);

        return AuditLogResource::collection($logs);
    }

    private function detail(Booking $booking): BookingResource
    {
        $booking->load([
            'customer', 'supplier', 'passengers.tickets.eTicket', 'flights', 'tickets.eTicket', 'creator:id,name',
            'documents.uploader:id,name', 'documents.verifier:id,name', 'documents.linkedTickets:id,ticket_number,e_ticket_document_id',
            'issues.assignee:id,name', 'payments.creator:id,name',
        ]);

        return (new BookingResource($booking))->withWorkflow($this->workflow);
    }
}
