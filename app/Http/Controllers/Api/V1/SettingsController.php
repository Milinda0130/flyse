<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\SettingsRequest;
use App\Services\DocumentStorageService;
use App\Services\SettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class SettingsController extends Controller
{
    public function show(SettingsService $settings): JsonResponse
    {
        Gate::authorize('manage-settings');

        return response()->json($settings->all());
    }

    public function update(SettingsRequest $request, SettingsService $settings): JsonResponse
    {
        return response()->json($settings->update($request->validated()));
    }

    /** The uploaded agency logo (printed on generated ticket PDFs). */
    public function logo(SettingsService $settings): Response
    {
        Gate::authorize('manage-settings');
        $key = (string) $settings->get('agency_logo_file');
        $disk = Storage::disk(DocumentStorageService::DISK);
        abort_unless($key !== '' && $disk->exists($key), 404, 'No logo uploaded.');

        return $disk->response($key, 'logo', ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    /** Replaces the agency logo: PNG or JPG up to 1 MB, kept in private storage. */
    public function uploadLogo(Request $request, SettingsService $settings): JsonResponse
    {
        Gate::authorize('manage-settings');
        $request->validate(['logo' => ['required', 'file', 'max:1024', 'mimes:png,jpg,jpeg', 'mimetypes:image/png,image/jpeg', 'dimensions:max_width=3000,max_height=3000']]);
        $file = $request->file('logo');
        $disk = Storage::disk(DocumentStorageService::DISK);
        $old = (string) $settings->get('agency_logo_file');
        $key = 'branding/logo-'.Str::uuid()->toString().'.'.($file->guessExtension() === 'png' ? 'png' : 'jpg');
        $disk->putFileAs('branding', $file, basename($key));
        $result = $settings->update(['agency_logo_file' => $key]);
        if ($old !== '' && $old !== $key) {
            $disk->delete($old);
        }

        return response()->json($result);
    }

    public function deleteLogo(SettingsService $settings): JsonResponse
    {
        Gate::authorize('manage-settings');
        $old = (string) $settings->get('agency_logo_file');
        $result = $settings->update(['agency_logo_file' => '']);
        if ($old !== '') {
            Storage::disk(DocumentStorageService::DISK)->delete($old);
        }

        return response()->json($result);
    }
}
