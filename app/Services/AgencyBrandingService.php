<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * The agency's configured name, contact details and logo for customer documents (PDFs).
 * Never hard-coded: everything comes from Settings; missing values are simply left out.
 */
class AgencyBrandingService
{
    public function __construct(private SettingsService $settings) {}

    /** @return array{name: string, address: ?string, phone: ?string, email: ?string, website: ?string, logo: ?string} */
    public function agency(): array
    {
        $s = $this->settings->all();
        $clean = fn ($v) => is_string($v) && trim($v) !== '' ? trim($v) : null;

        return [
            'name' => $clean($s['agency_name']) ?? 'Travel Agency',
            'address' => $clean($s['agency_address'] ?? null),
            'phone' => $clean($s['agency_phone'] ?? null),
            'email' => filter_var($s['agency_email'] ?? '', FILTER_VALIDATE_EMAIL) ? $s['agency_email'] : null,
            'website' => $clean($s['agency_website'] ?? null),
            'logo' => $this->uploadedLogo($clean($s['agency_logo_file'] ?? null)) ?? $this->remoteLogo($clean($s['agency_logo_url'] ?? null)),
        ];
    }

    /**
     * Background of the flight quotation PDF only: faint FLYSE logo watermark and a light-blue
     * cloud band with an aircraft wing at the bottom (see resources/pdf/quotation-watermark.php).
     */
    public function quotationWatermark(): ?string
    {
        return once(function () {
            $file = resource_path('pdf/quotation-watermark.jpg');

            return is_file($file) ? 'data:image/jpeg;base64,'.base64_encode((string) file_get_contents($file)) : null;
        });
    }

    /** The uploaded logo as PNG bytes (for embedding in emails), or null. */
    public function logoPng(): ?string
    {
        $key = (string) $this->settings->get('agency_logo_file');
        $disk = Storage::disk(DocumentStorageService::DISK);

        return $key !== '' && str_ends_with($key, '.png') && $disk->exists($key) ? $disk->get($key) : null;
    }

    /** The logo uploaded in Settings (preferred: no network needed). */
    private function uploadedLogo(?string $key): ?string
    {
        $disk = Storage::disk(DocumentStorageService::DISK);
        if (! $key || ! $disk->exists($key)) {
            return null;
        }
        $type = str_ends_with($key, '.png') ? 'image/png' : 'image/jpeg';

        return "data:$type;base64,".base64_encode($disk->get($key));
    }

    /**
     * The configured logo URL, embedded as data (PDF renderers never fetch remote files themselves).
     * Only https images up to 1 MB; cached for a day. No logo on any failure.
     */
    private function remoteLogo(?string $url): ?string
    {
        if (! $url || ! str_starts_with($url, 'https://')) {
            return null;
        }

        return Cache::remember('ticket-pdf-logo:'.md5($url), now()->addDay(), function () use ($url) {
            try {
                $res = Http::timeout(5)->get($url);
                $type = strtolower(explode(';', (string) $res->header('Content-Type'))[0]);
                if (! $res->successful() || ! in_array($type, ['image/png', 'image/jpeg', 'image/gif'], true) || strlen($res->body()) > 1024 * 1024) {
                    return null;
                }

                return "data:$type;base64,".base64_encode($res->body());
            } catch (Throwable) {
                return null;
            }
        });
    }
}
