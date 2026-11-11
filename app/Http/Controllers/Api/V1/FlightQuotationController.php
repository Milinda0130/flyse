<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\QuotationStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\QuotationRequest;
use App\Http\Resources\QuotationEmailResource;
use App\Http\Resources\QuotationListResource;
use App\Http\Resources\QuotationPdfResource;
use App\Http\Resources\QuotationResource;
use App\Mail\QuotationMail;
use App\Models\FlightQuotation;
use App\Models\FlightQuotationPdf;
use App\Models\QuotationEmail;
use App\Services\QuotationEmailService;
use App\Services\QuotationPdfService;
use App\Services\QuotationService;
use App\Services\TicketEmailService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Manual flight quotations: list, create/edit, status, PDF versions and customer emails. */
class FlightQuotationController extends Controller
{
    public function __construct(
        private QuotationService $service,
        private QuotationPdfService $pdfs,
        private QuotationEmailService $emails,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $like = fn (string $v) => '%'.addcslashes($v, '%_\\').'%';
        $quotations = FlightQuotation::query()
            ->with(['customer:id,full_name', 'options', 'emails' => fn ($q) => $q->latest('id')])
            ->when($request->q, function ($query, $q) use ($like) {
                $query->where(fn ($w) => $w->where('reference', 'like', $like($q))
                    ->orWhere('origin_city', 'like', $like($q))->orWhere('destination_city', 'like', $like($q))
                    ->orWhere('origin_code', strtoupper($q))->orWhere('destination_code', strtoupper($q))
                    ->orWhereHas('customer', fn ($c) => $c->where('full_name', 'like', $like($q))->orWhere('email', 'like', $like($q))->orWhere('phone', 'like', $like($q))));
            })
            ->when($request->status, fn ($q, $s) => $q->whereIn('status', explode(',', $s)))
            ->when($request->currency, fn ($q, $c) => $q->where('currency', strtoupper($c)))
            ->when($request->customer_id, fn ($q, $c) => $q->where('customer_id', $c))
            ->when($request->departure_from, fn ($q, $d) => $q->whereDate('outbound_date', '>=', $d))
            ->when($request->departure_to, fn ($q, $d) => $q->whereDate('outbound_date', '<=', $d))
            ->latest('id')
            ->paginate(min(100, max(5, (int) $request->input('per_page', 20))));

        return QuotationListResource::collection($quotations);
    }

    public function store(QuotationRequest $request): JsonResponse
    {
        $quotation = $this->service->save(null, $request->validated(), $request->user());

        return $this->detail($quotation)->setStatusCode(201);
    }

    public function show(FlightQuotation $flightQuotation): JsonResponse
    {
        return $this->detail($flightQuotation);
    }

    public function update(QuotationRequest $request, FlightQuotation $flightQuotation): JsonResponse
    {
        return $this->detail($this->service->save($flightQuotation, $request->validated(), $request->user()));
    }

    /** Only drafts that were never emailed can be deleted (soft delete, audited). */
    public function destroy(FlightQuotation $flightQuotation): JsonResponse
    {
        Gate::authorize('edit-records');
        abort_unless($flightQuotation->status === QuotationStatus::Draft && ! $flightQuotation->emails()->exists(), 422, 'Only draft quotations that were never emailed can be deleted.');
        $flightQuotation->delete();

        return response()->json(null, 204);
    }

    public function duplicate(Request $request, FlightQuotation $flightQuotation): JsonResponse
    {
        Gate::authorize('edit-records');

        return $this->detail($this->service->duplicate($flightQuotation, $request->user()))->setStatusCode(201);
    }

    public function status(Request $request, FlightQuotation $flightQuotation): JsonResponse
    {
        Gate::authorize('edit-records');
        $data = $request->validate([
            'status' => ['required', Rule::enum(QuotationStatus::class)],
            'accepted_option_id' => ['nullable', 'integer'],
            'booking_id' => ['nullable', 'integer'],
        ]);

        return $this->detail($this->service->transition($flightQuotation, $data['status'], $data));
    }

    // ------------------------------------------------------------------ PDF

    /** The current content as a PDF, not stored (works for drafts too). */
    public function preview(FlightQuotation $flightQuotation): Response
    {
        return response($this->pdfs->render($flightQuotation), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="preview-'.$flightQuotation->reference.'.pdf"',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /** Stores the current content as a PDF version (or returns the unchanged latest one). */
    public function generatePdf(Request $request, FlightQuotation $flightQuotation): QuotationPdfResource
    {
        Gate::authorize('edit-records');

        return new QuotationPdfResource($this->pdfs->current($flightQuotation, $request->user())->load('generator:id,name'));
    }

    /** Downloads the PDF of the saved content, creating the version if needed. */
    public function download(Request $request, FlightQuotation $flightQuotation): StreamedResponse
    {
        $current = $this->pdfs->isCurrent($flightQuotation) ? $flightQuotation->pdfs()->first() : null;
        if (! $current) {
            Gate::authorize('edit-records');
            $current = $this->pdfs->current($flightQuotation, $request->user());
        }

        return $this->pdfs->stream($current, $request->boolean('inline'));
    }

    /** A specific stored version (e.g. the one that was emailed). */
    public function downloadVersion(Request $request, FlightQuotation $flightQuotation, FlightQuotationPdf $pdf): StreamedResponse
    {
        abort_unless($pdf->flight_quotation_id === $flightQuotation->id, 404);

        return $this->pdfs->stream($pdf, $request->boolean('inline'));
    }

    // ------------------------------------------------------------------ email

    /** Everything the email dialog needs. */
    public function emailInfo(FlightQuotation $flightQuotation, TicketEmailService $mail): JsonResponse
    {
        Gate::authorize('email-tickets');
        $flightQuotation->load(['customer', 'options.segments']);

        return response()->json([
            'customer' => ['name' => $flightQuotation->customer?->full_name, 'email' => $flightQuotation->customer?->email],
            'subject' => $this->emails->subject($flightQuotation),
            'problem' => $this->emails->problem($flightQuotation),
            'content_problems' => $this->service->problems($flightQuotation),
            'pdf_is_current' => $this->pdfs->isCurrent($flightQuotation),
            'latest_pdf' => ($latest = $flightQuotation->pdfs()->with('generator:id,name')->first()) ? new QuotationPdfResource($latest) : null,
            'delivers_mail' => $mail->deliversMail(),
            'mailer' => config('mail.default'),
            'history' => QuotationEmailResource::collection($flightQuotation->emails()->with(['requester:id,name', 'pdf:id,version'])->limit(20)->get()),
        ]);
    }

    /** Renders the email exactly as the customer will receive it (nothing is sent or stored). */
    public function emailPreview(Request $request, FlightQuotation $flightQuotation): JsonResponse
    {
        Gate::authorize('email-tickets');
        $data = $request->validate(['recipient' => ['nullable', 'string', 'max:255']]);
        [$recipient, $isOverride] = $this->emails->resolveRecipient($flightQuotation->load('customer'), $data['recipient'] ?? null);
        $subject = $this->emails->subject($flightQuotation);
        $latest = $flightQuotation->pdfs()->first();
        $filename = $latest?->filename() ?? "Flight-Quotation-{$flightQuotation->reference}.pdf";

        return response()->json([
            'recipient' => $recipient,
            'recipient_is_override' => $isOverride,
            'subject' => $subject,
            'html' => (new QuotationMail($flightQuotation, $subject, null, $filename))->render(),
        ]);
    }

    public function sendEmail(Request $request, FlightQuotation $flightQuotation): JsonResponse
    {
        Gate::authorize('email-tickets');
        $data = $request->validate([
            'recipient' => ['nullable', 'string', 'max:255'],
            'idempotency_key' => ['nullable', 'string', 'max:64', 'regex:/^[A-Za-z0-9-]+$/'],
            'confirm_resend' => ['sometimes', 'boolean'],
        ]);
        $email = $this->emails->request($flightQuotation, $data['recipient'] ?? null, $data['idempotency_key'] ?? null, $request->boolean('confirm_resend'), $request->user());

        return (new QuotationEmailResource($email->load(['requester:id,name', 'pdf:id,version'])))->response()->setStatusCode($email->wasRecentlyCreated ? 201 : 200);
    }

    public function retryEmail(QuotationEmail $quotationEmail): QuotationEmailResource
    {
        Gate::authorize('email-tickets');

        return new QuotationEmailResource($this->emails->retry($quotationEmail)->load(['requester:id,name', 'pdf:id,version']));
    }

    public function emailHistory(FlightQuotation $flightQuotation): AnonymousResourceCollection
    {
        Gate::authorize('email-tickets');

        return QuotationEmailResource::collection($flightQuotation->emails()->with(['requester:id,name', 'pdf:id,version'])->limit(50)->get());
    }

    // ------------------------------------------------------------------

    /** The quotation plus "meta" (checks, warnings, PDF versions) as one flat object. */
    private function detail(FlightQuotation $q): JsonResponse
    {
        $q->load(['customer', 'options.segments', 'booking:id,booking_code', 'creator:id,name']);
        $canEmail = request()->user()?->can('email-tickets');

        return response()->json([
            ...(new QuotationResource($q))->resolve(request()),
            'meta' => [
                'problems' => $this->service->problems($q),
                'warnings' => $this->service->warnings($q),
                'editable' => in_array($q->status, QuotationService::EDITABLE, true),
                'transitions' => QuotationService::TRANSITIONS[$q->status->value] ?? [],
                'pdf_is_current' => $this->pdfs->isCurrent($q),
                'pdfs' => QuotationPdfResource::collection($q->pdfs()->with('generator:id,name')->get())->resolve(request()),
                'email_problem' => $canEmail ? $this->emails->problem($q) : null,
            ],
        ]);
    }
}
