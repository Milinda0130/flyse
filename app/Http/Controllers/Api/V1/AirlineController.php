<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\AirlineRequest;
use App\Http\Resources\AirlineResource;
use App\Models\Airline;
use App\Models\FlightQuotationSegment;
use App\Services\AirlineLogoService;
use App\Services\DocumentStorageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/** Airlines & logos, managed by staff (logos are uploaded files, never hot-linked). */
class AirlineController extends Controller
{
    public function __construct(private AirlineLogoService $logos) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $like = fn (string $v) => '%'.addcslashes($v, '%_\\').'%';

        return AirlineResource::collection(Airline::query()
            ->withCount('segments')
            ->when($request->q, fn ($query, $q) => $query->where(fn ($w) => $w->where('name', 'like', $like($q))
                ->orWhere('iata_code', strtoupper($q))->orWhere('icao_code', strtoupper($q))))
            ->when($request->boolean('active_only'), fn ($q) => $q->where('is_active', true))
            ->orderByDesc('is_active')->orderBy('name')
            ->limit(500)->get());
    }

    public function store(AirlineRequest $request): JsonResponse
    {
        return (new AirlineResource(Airline::create($request->validated())))->response()->setStatusCode(201);
    }

    public function update(AirlineRequest $request, Airline $airline): AirlineResource
    {
        $airline->update($request->validated());

        return new AirlineResource($airline);
    }

    /** Deleting is only for airlines never used in a quotation; otherwise deactivate instead. */
    public function destroy(Airline $airline): JsonResponse
    {
        Gate::authorize('delete-airlines');
        $used = $airline->segments()->count() + FlightQuotationSegment::where('operating_airline_id', $airline->id)->count();
        abort_if($used > 0, 422, "{$airline->name} is used in $used quoted flight(s). Deactivate it instead, so existing quotations keep their logo.");
        $this->logos->remove($airline);
        $airline->delete();

        return response()->json(null, 204);
    }

    public function logo(Airline $airline): Response
    {
        $disk = Storage::disk(DocumentStorageService::DISK);
        abort_unless($airline->logo_key && $disk->exists($airline->logo_key), 404, 'No logo uploaded.');

        return $disk->response($airline->logo_key, 'logo.png', ['Content-Type' => 'image/png', 'Cache-Control' => 'private, max-age=86400', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function uploadLogo(Request $request, Airline $airline): AirlineResource
    {
        Gate::authorize('manage-airlines');
        $request->validate(
            ['logo' => ['required', 'file', 'max:2048', 'mimes:png,jpg,jpeg,webp', 'mimetypes:image/png,image/jpeg,image/webp', 'dimensions:max_width=5000,max_height=5000']],
            ['logo.mimes' => 'Upload a PNG, JPG or WebP image (SVG files are not accepted for security reasons; export the SVG as PNG first).'],
        );

        return new AirlineResource($this->logos->store($airline, $request->file('logo')));
    }

    public function deleteLogo(Airline $airline): AirlineResource
    {
        Gate::authorize('manage-airlines');
        $this->logos->remove($airline);

        return new AirlineResource($airline);
    }
}
