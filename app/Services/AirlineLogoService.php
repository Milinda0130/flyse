<?php

namespace App\Services;

use App\Models\Airline;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Stores airline logos uploaded by staff. Every upload (PNG, JPEG or WebP) is decoded and
 * re-encoded as a PNG of at most 600 x 300 px with transparency kept, so no embedded content
 * survives and PDFs stay small. Logos are private files, embedded into PDFs as data.
 */
class AirlineLogoService
{
    private const MAX_W = 600;

    private const MAX_H = 300;

    public function store(Airline $airline, UploadedFile $file): Airline
    {
        $image = match ($file->getMimeType()) {
            'image/png' => @imagecreatefrompng($file->getRealPath()),
            'image/jpeg' => @imagecreatefromjpeg($file->getRealPath()),
            'image/webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($file->getRealPath()) : false,
            default => false,
        };
        if (! $image) {
            throw ValidationException::withMessages(['logo' => 'The logo could not be read. Upload a PNG, JPG or WebP image.']);
        }

        $png = $this->normalise($image);
        $disk = Storage::disk(DocumentStorageService::DISK);
        $key = 'airline-logos/'.Str::uuid()->toString().'.png';
        $disk->put($key, $png);
        $old = $airline->logo_key;
        $airline->update(['logo_key' => $key, 'logo_updated_at' => now()]);
        if ($old) {
            $disk->delete($old);
        }

        return $airline;
    }

    public function remove(Airline $airline): void
    {
        if ($airline->logo_key) {
            Storage::disk(DocumentStorageService::DISK)->delete($airline->logo_key);
        }
        $airline->update(['logo_key' => null, 'logo_updated_at' => now()]);
    }

    /** data: URI for PDFs, or null when there is no (readable) logo. */
    public function dataUri(?Airline $airline): ?string
    {
        if (! $airline?->logo_key) {
            return null;
        }
        $disk = Storage::disk(DocumentStorageService::DISK);

        return $disk->exists($airline->logo_key) ? 'data:image/png;base64,'.base64_encode($disk->get($airline->logo_key)) : null;
    }

    private function normalise(\GdImage $src): string
    {
        $w = imagesx($src);
        $h = imagesy($src);
        $scale = min(1, self::MAX_W / $w, self::MAX_H / $h);
        $nw = max(1, (int) round($w * $scale));
        $nh = max(1, (int) round($h * $scale));

        $dst = imagecreatetruecolor($nw, $nh);
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
        imagefill($dst, 0, 0, imagecolorallocatealpha($dst, 0, 0, 0, 127));
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);

        ob_start();
        imagepng($dst, null, 9);

        return (string) ob_get_clean();
    }
}
