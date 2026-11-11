<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\BookingStatus;
use App\Enums\IssueStatus;
use App\Enums\IssueType;
use App\Enums\PaymentDirection;
use App\Enums\PaymentStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\FlightResource;
use App\Http\Resources\IssueResource;
use App\Models\Booking;
use App\Models\BookingFlight;
use App\Models\BookingIssue;
use App\Models\Payment;
use App\Services\ReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function summary(Request $request, ReportService $reports): JsonResponse
    {
        $user = $request->user();
        $openStatuses = array_map(fn ($s) => $s->value, IssueStatus::openStatuses());
        $inactive = [BookingStatus::Cancelled->value, BookingStatus::Completed->value];

        $statusCounts = Booking::query()->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');

        $upcoming = BookingFlight::query()
            ->whereBetween('departure_at', [now()->startOfDay(), now()->addDays(14)->endOfDay()])
            ->whereHas('booking', fn ($q) => $q->whereNotIn('status', [BookingStatus::Cancelled->value]))
            ->with(['booking' => fn ($q) => $q->withCount('passengers')->with('customer:id,full_name,phone')])
            ->orderBy('departure_at')
            ->limit(25)
            ->get()
            ->map(fn (BookingFlight $f) => [
                ...(new FlightResource($f))->toArray($request),
                'booking' => [
                    'id' => $f->booking->id,
                    'booking_code' => $f->booking->booking_code,
                    'pnr' => $f->booking->pnr,
                    'status' => $f->booking->status->value,
                    'customer' => $f->booking->customer?->full_name,
                    'phone' => $f->booking->customer?->phone,
                    'passengers_count' => $f->booking->passengers_count,
                ],
            ]);

        $openIssues = BookingIssue::query()
            ->whereIn('status', $openStatuses)
            ->with(['booking:id,booking_code,pnr,customer_id', 'booking.customer:id,full_name', 'assignee:id,name'])
            ->orderByRaw('case when due_at is null then 1 else 0 end')
            ->orderBy('due_at')
            ->limit(10)
            ->get();

        $deadlines = Booking::query()
            ->whereNotIn('status', [...$inactive, BookingStatus::Ticketed->value])
            ->whereNotNull('ticketing_deadline')
            ->where('ticketing_deadline', '<=', now()->addDays(3)->endOfDay())
            ->with('customer:id,full_name')
            ->orderBy('ticketing_deadline')
            ->limit(10)
            ->get()
            ->map(fn (Booking $b) => [
                'id' => $b->id,
                'booking_code' => $b->booking_code,
                'customer' => $b->customer?->full_name,
                'status' => $b->status->value,
                'ticketing_deadline' => $b->ticketing_deadline?->format('Y-m-d\TH:i'),
            ]);

        $outstanding = $reports->outstanding([]);

        $data = [
            'bookings' => [
                'total' => (int) $statusCounts->sum(),
                'by_status' => $statusCounts,
                'this_month' => Booking::whereBetween('booking_date', [now()->startOfMonth()->toDateString(), now()->endOfMonth()->toDateString()])->count(),
            ],
            'issues' => [
                'open' => BookingIssue::whereIn('status', $openStatuses)->count(),
                'overdue' => BookingIssue::whereIn('status', $openStatuses)->where('due_at', '<', now())->count(),
                'list' => IssueResource::collection($openIssues),
            ],
            'refunds_awaiting' => [
                'open_refund_issues' => BookingIssue::whereIn('status', $openStatuses)->where('issue_type', IssueType::Refund->value)->count(),
                'pending_refund_payments' => Payment::whereIn('direction', [PaymentDirection::CustomerRefund->value, PaymentDirection::SupplierRefund->value])
                    ->where('status', PaymentStatus::Pending->value)->count(),
            ],
            'customer_balances' => [
                'count' => count(array_filter($outstanding['customer_balances'], fn ($r) => (float) $r['balance'] > 0)),
                'totals' => $outstanding['customer_totals'],
                'overdue' => array_values(array_slice(array_filter(
                    $outstanding['customer_balances'],
                    fn ($r) => (float) $r['balance'] > 0 && $r['payment_due_date'] !== null && $r['payment_due_date'] <= now()->addDays(3)->toDateString(),
                ), 0, 10)),
            ],
            'upcoming_departures' => $upcoming,
            'ticketing_deadlines' => $deadlines,
        ];

        if ($user->can('view-reports')) {
            $data['supplier_payables'] = $outstanding['supplier_totals'];
            $data['monthly'] = $reports->monthly(12);
        }

        return response()->json($data);
    }
}
