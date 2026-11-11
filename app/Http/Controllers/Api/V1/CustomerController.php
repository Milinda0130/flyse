<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\CustomerRequest;
use App\Http\Resources\BookingResource;
use App\Http\Resources\CustomerResource;
use App\Models\Customer;
use App\Services\BookingFinanceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class CustomerController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate(['q' => ['nullable', 'string', 'max:100'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:100']]);

        $q = trim((string) $request->input('q'));
        // Escape LIKE wildcards so "%" or "_" typed by a user match literally.
        $safe = addcslashes($q, '%_\\');

        $customers = Customer::query()
            ->withCount('bookings')
            ->when($q !== '', function ($query) use ($safe) {
                $like = '%'.$safe.'%';
                $query->where(fn ($w) => $w->where('full_name', 'like', $like)
                    ->orWhere('phone', 'like', $like)
                    ->orWhere('email', 'like', $like));
                // Suggestions: names starting with the text first, then a word starting with it, then anywhere.
                $query->orderByRaw('case when full_name like ? then 0 when full_name like ? then 1 else 2 end', [$safe.'%', '% '.$safe.'%']);
            })
            ->orderBy('full_name')
            ->paginate($request->integer('per_page', 25));

        return CustomerResource::collection($customers);
    }

    public function store(CustomerRequest $request): JsonResponse
    {
        $customer = Customer::create([...$request->validated(), 'created_by' => $request->user()->id]);

        return (new CustomerResource($customer))->detailed()->response()->setStatusCode(201);
    }

    public function show(Request $request, Customer $customer, BookingFinanceService $finance): JsonResponse
    {
        $bookings = $finance->withPaymentSums($customer->bookings()->getQuery())
            ->with('flights')
            ->latest('booking_date')
            ->limit(100)
            ->get();

        return response()->json([
            'customer' => (new CustomerResource($customer))->detailed(),
            'bookings' => BookingResource::collection($bookings),
        ]);
    }

    public function update(CustomerRequest $request, Customer $customer): CustomerResource
    {
        $customer->update($request->validated());

        return (new CustomerResource($customer))->detailed();
    }

    /** Archives (soft-deletes) a customer that has no bookings. */
    public function destroy(Customer $customer): JsonResponse
    {
        Gate::authorize('manage-settings');
        abort_if($customer->bookings()->withTrashed()->exists(), 422, 'Customers with bookings cannot be deleted.');
        $customer->delete();

        return response()->json(null, 204);
    }
}
