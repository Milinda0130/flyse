<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\DocumentType;
use App\Http\Controllers\Controller;
use App\Http\Requests\ExpenseRequest;
use App\Http\Resources\ExpenseResource;
use App\Models\Expense;
use App\Services\DocumentStorageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Gate;

class ExpenseController extends Controller
{
    public function __construct(private DocumentStorageService $storage) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('manage-expenses');
        $f = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'category' => ['nullable', 'string'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $expenses = Expense::query()
            ->with(['receipt', 'creator:id,name'])
            ->when($f['from'] ?? null, fn ($q, $v) => $q->whereDate('expense_date', '>=', $v))
            ->when($f['to'] ?? null, fn ($q, $v) => $q->whereDate('expense_date', '<=', $v))
            ->when($f['category'] ?? null, fn ($q, $v) => $q->where('category', $v))
            ->orderByDesc('expense_date')->orderByDesc('id')
            ->paginate($f['per_page'] ?? 25);

        return ExpenseResource::collection($expenses);
    }

    public function store(ExpenseRequest $request): JsonResponse
    {
        $data = Arr::except($request->validated(), ['receipt']);
        if ($request->hasFile('receipt')) {
            $data['receipt_document_id'] = $this->storage->store($request->file('receipt'), ['document_type' => DocumentType::ExpenseReceipt])->id;
        }
        $expense = Expense::create([...$data, 'created_by' => $request->user()->id]);

        return (new ExpenseResource($expense->load(['receipt', 'creator:id,name'])))->response()->setStatusCode(201);
    }

    public function update(ExpenseRequest $request, Expense $expense): ExpenseResource
    {
        $data = Arr::except($request->validated(), ['receipt']);
        if ($request->hasFile('receipt')) {
            $data['receipt_document_id'] = $this->storage->store($request->file('receipt'), ['document_type' => DocumentType::ExpenseReceipt])->id;
        }
        $expense->update($data);

        return new ExpenseResource($expense->load(['receipt', 'creator:id,name']));
    }

    public function destroy(Expense $expense): JsonResponse
    {
        Gate::authorize('manage-expenses');
        $expense->delete();

        return response()->json(null, 204);
    }
}
