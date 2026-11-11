<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\IssueStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\IssueRequest;
use App\Http\Requests\IssueUpdateRequest;
use App\Http\Resources\IssueResource;
use App\Http\Resources\IssueUpdateResource;
use App\Models\AuditLog;
use App\Models\Booking;
use App\Models\BookingIssue;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class BookingIssueController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $f = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'string'],
            'open_only' => ['nullable', 'boolean'],
            'overdue' => ['nullable', 'boolean'],
            'issue_type' => ['nullable', 'string'],
            'severity' => ['nullable', 'string'],
            'assigned_to' => ['nullable', 'integer'],
            'booking_id' => ['nullable', 'integer'],
            'sort' => ['nullable', Rule::in(['due_at', 'reported_at', 'severity'])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $open = array_map(fn ($s) => $s->value, IssueStatus::openStatuses());

        $query = BookingIssue::query()
            ->with(['booking:id,booking_code,pnr,customer_id', 'booking.customer:id,full_name', 'assignee:id,name', 'flight'])
            ->when($f['status'] ?? null, fn ($q, $v) => $q->whereIn('status', explode(',', $v)))
            ->when($request->boolean('open_only'), fn ($q) => $q->whereIn('status', $open))
            ->when($request->boolean('overdue'), fn ($q) => $q->whereIn('status', $open)->where('due_at', '<', now()))
            ->when($f['issue_type'] ?? null, fn ($q, $v) => $q->whereIn('issue_type', explode(',', $v)))
            ->when($f['severity'] ?? null, fn ($q, $v) => $q->where('severity', $v))
            ->when($f['assigned_to'] ?? null, fn ($q, $v) => $q->where('assigned_to', $v))
            ->when($f['booking_id'] ?? null, fn ($q, $v) => $q->where('booking_id', $v))
            ->when($f['q'] ?? null, function ($q, $term) {
                $like = '%'.$term.'%';
                $q->where(fn ($w) => $w->where('summary', 'like', $like)
                    ->orWhereHas('booking', fn ($b) => $b->where('booking_code', 'like', $like)->orWhere('pnr', 'like', $like)));
            });

        match ($f['sort'] ?? 'due_at') {
            'reported_at' => $query->latest('reported_at'),
            'severity' => $query->orderByRaw("case severity when 'critical' then 0 when 'high' then 1 when 'medium' then 2 else 3 end")->orderBy('due_at'),
            default => $query->orderByRaw('case when due_at is null then 1 else 0 end')->orderBy('due_at')->latest('reported_at'),
        };

        return IssueResource::collection($query->paginate($f['per_page'] ?? 25)->withQueryString());
    }

    public function store(IssueRequest $request, Booking $booking): JsonResponse
    {
        $data = $request->validated();
        $issue = DB::transaction(function () use ($data, $booking, $request) {
            $issue = $booking->issues()->create([
                ...Arr::except($data, ['update_note']),
                'status' => $data['status'] ?? IssueStatus::Open->value,
                'reported_at' => $data['reported_at'] ?? now(),
                'created_by' => $request->user()->id,
            ]);
            $issue->updates()->create([
                'update_text' => $data['update_note'] ?? 'Issue created.',
                'status_to' => $issue->status->value,
                'created_by' => $request->user()->id,
            ]);

            return $issue;
        });

        return (new IssueResource($this->loadDetail($issue)))->response()->setStatusCode(201);
    }

    public function show(BookingIssue $issue): IssueResource
    {
        return new IssueResource($this->loadDetail($issue));
    }

    /** Updates fields; a status change is written to the issue timeline automatically. */
    public function update(IssueRequest $request, BookingIssue $issue): IssueResource
    {
        $data = $request->validated();
        $note = $data['update_note'] ?? null;
        unset($data['update_note']);

        DB::transaction(function () use ($issue, $data, $note, $request) {
            $from = $issue->status;
            $issue->fill($data);
            if ($issue->isDirty('status')) {
                $issue->resolved_at = $issue->status->isClosed() ? ($issue->resolved_at ?? now()) : null;
            }
            $statusChanged = $issue->isDirty('status');
            $issue->save();

            if ($statusChanged || $note) {
                $issue->updates()->create([
                    'update_text' => $note ?? 'Status changed from '.$from->label().' to '.$issue->status->label().'.',
                    'status_from' => $statusChanged ? $from->value : null,
                    'status_to' => $statusChanged ? $issue->status->value : null,
                    'created_by' => $request->user()->id,
                ]);
            }
        });

        return new IssueResource($this->loadDetail($issue));
    }

    public function addUpdate(IssueUpdateRequest $request, BookingIssue $issue): JsonResponse
    {
        $data = $request->validated();
        $update = DB::transaction(function () use ($issue, $data, $request) {
            $from = $issue->status;
            $to = isset($data['status']) ? IssueStatus::from($data['status']) : null;
            if ($to && $to !== $from) {
                $issue->status = $to;
                $issue->resolved_at = $to->isClosed() ? ($issue->resolved_at ?? now()) : null;
                $issue->save();
            }

            return $issue->updates()->create([
                'update_text' => $data['update_text'],
                'status_from' => $to && $to !== $from ? $from->value : null,
                'status_to' => $to && $to !== $from ? $to->value : null,
                'created_by' => $request->user()->id,
            ]);
        });

        return (new IssueUpdateResource($update->load('author:id,name')))->response()->setStatusCode(201);
    }

    private function loadDetail(BookingIssue $issue): BookingIssue
    {
        $issue->load([
            'booking:id,booking_code,pnr,customer_id', 'booking.customer:id,full_name', 'flight',
            'assignee:id,name', 'creator:id,name', 'updates.author:id,name', 'documents.uploader:id,name',
        ]);

        // The affected segment's previous values (schedule changes are audited, never silently overwritten).
        if ($issue->booking_flight_id) {
            $issue->setRelation('flightHistory', AuditLog::query()
                ->where('entity_type', 'BookingFlight')->where('entity_id', (string) $issue->booking_flight_id)
                ->with('user:id,name')->latest('created_at')->orderByDesc('id')->limit(20)->get());
        }

        return $issue;
    }
}
