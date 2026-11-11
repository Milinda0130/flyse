<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\ReportOverviewService;
use App\Services\ReportService;
use App\Services\SettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportController extends Controller
{
    public function __construct(private ReportService $reports) {}

    /** Reports dashboard: KPIs, trends and breakdowns for one currency. Defaults to the last 12 months. */
    public function overview(Request $request, ReportOverviewService $overview, SettingsService $settings): JsonResponse
    {
        $filters = $this->filters($request);
        $filters['from'] ??= now()->startOfMonth()->subMonths(11)->toDateString();
        $filters['to'] ??= now()->toDateString();
        $filters['currency'] = strtoupper($filters['currency'] ?? $settings->get('default_currency'));

        return response()->json($overview->build($filters));
    }

    public function sales(Request $request): JsonResponse
    {
        return response()->json($this->reports->sales($this->filters($request)));
    }

    public function outstanding(Request $request): JsonResponse
    {
        return response()->json($this->reports->outstanding($this->filters($request)));
    }

    public function issues(Request $request): JsonResponse
    {
        return response()->json($this->reports->issues($this->filters($request)));
    }

    /** CSV export of a filtered report. Personal identifiers beyond name/phone are never included. */
    public function export(Request $request): StreamedResponse
    {
        Gate::authorize('view-reports');
        $request->validate([
            'report' => ['required', Rule::in(['sales', 'customer_balances', 'supplier_payables', 'issues'])],
        ]);
        $filters = $this->filters($request);
        $report = $request->input('report');
        $data = match ($report) {
            'sales' => $this->reports->sales($filters),
            'customer_balances', 'supplier_payables' => $this->reports->outstanding($filters),
            'issues' => $this->reports->issues($filters),
        };
        $rows = $this->reports->toCsvRows($report, $data);
        $filename = $report.'-'.now()->format('Ymd-His').'.csv';

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM so Excel shows names correctly
            foreach ($rows as $row) {
                fputcsv($out, array_map([$this, 'csvSafe'], $row), ',', '"', '');
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** Neutralises spreadsheet formula injection in text cells. */
    public function csvSafe(mixed $value): string
    {
        $value = (string) ($value ?? '');
        if ($value !== '' && ! is_numeric($value) && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
            return "'".$value;
        }

        return $value;
    }

    /** Authorises the report request and returns validated filters. */
    private function filters(Request $request): array
    {
        Gate::authorize('view-reports');

        return $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'date_basis' => ['nullable', Rule::in(['booking_date', 'departure_date'])],
            'group_by' => ['nullable', Rule::in(['month', 'supplier', 'airline', 'route', 'status'])],
            'currency' => ['nullable', 'string', 'size:3'],
            'supplier_id' => ['nullable', 'integer'],
            'airline' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'string', 'max:30'],
        ]);
    }
}
