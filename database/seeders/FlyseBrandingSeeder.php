<?php

namespace Database\Seeders;

use App\Services\DocumentStorageService;
use App\Services\SettingsService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * FLYSE TRAVELS AB branding: agency details and logo used on PDFs, emails and in the app.
 * Safe to run again: php artisan db:seed --class=FlyseBrandingSeeder
 */
class FlyseBrandingSeeder extends Seeder
{
    public const LOGO_SOURCE = 'asset/Main logo PNG.webp';

    public function run(SettingsService $settings): void
    {
        $values = [
            'agency_name' => 'FLYSE TRAVELS AB',
            'agency_address' => "Lärdomsgatan 9, Göteborg, Västra Götaland County\n417 56, Sweden",
            'agency_phone' => '+46735265341',
            'agency_email' => 'geethnsl@gmail.com',
        ];

        // Logo for PDFs and emails: the WebP is converted to a transparent PNG (max 600 px wide).
        $source = resource_path(self::LOGO_SOURCE);
        if (is_file($source) && function_exists('imagecreatefromwebp') && ($img = @imagecreatefromwebp($source))) {
            $w = imagesx($img);
            $h = imagesy($img);
            $nw = min(600, $w);
            $nh = (int) round($h * $nw / $w);
            $dst = imagecreatetruecolor($nw, $nh);
            imagealphablending($dst, false);
            imagesavealpha($dst, true);
            imagefill($dst, 0, 0, imagecolorallocatealpha($dst, 0, 0, 0, 127));
            imagecopyresampled($dst, $img, 0, 0, 0, 0, $nw, $nh, $w, $h);
            ob_start();
            imagepng($dst, null, 9);
            $png = (string) ob_get_clean();

            $disk = Storage::disk(DocumentStorageService::DISK);
            $key = 'branding/logo-'.Str::uuid()->toString().'.png';
            $disk->put($key, $png);
            $old = (string) $settings->get('agency_logo_file');
            $values['agency_logo_file'] = $key;
            $settings->update($values);
            if ($old !== '' && $old !== $key) {
                $disk->delete($old);
            }
            $this->command?->info('Agency details and logo saved.');

            return;
        }

        $settings->update($values);
        $this->command?->warn('Agency details saved; the logo could not be read ('.self::LOGO_SOURCE.').');
    }
}
