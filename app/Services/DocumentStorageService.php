<?php

namespace App\Services;

use App\Models\Document;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Stores uploaded files on the private "documents" disk under generated names;
 * the uploaded filename is kept only as metadata and never used as a path.
 */
class DocumentStorageService
{
    public const DISK = 'documents';

    /** @param  array<string, mixed>  $attributes */
    public function store(UploadedFile $file, array $attributes): Document
    {
        $extension = strtolower($file->guessExtension() ?: $file->getClientOriginalExtension());
        $key = now()->format('Y/m').'/'.Str::uuid()->toString().'.'.$extension;

        Storage::disk(self::DISK)->putFileAs(dirname($key), $file, basename($key));

        return Document::create([
            ...$attributes,
            'original_name' => Str::limit($this->safeName($file->getClientOriginalName()), 250, ''),
            'storage_key' => $key,
            'mime_type' => $file->getMimeType() ?? 'application/octet-stream',
            'size_bytes' => $file->getSize(),
            'uploaded_by' => auth()->id(),
        ]);
    }

    /** True when the document's file is really present in private storage. */
    public function exists(Document $document): bool
    {
        return Storage::disk(self::DISK)->exists($document->storage_key);
    }

    public function stream(Document $document, bool $inline = false): StreamedResponse
    {
        $disk = Storage::disk(self::DISK);
        abort_unless($disk->exists($document->storage_key), 404, 'The file is missing from storage.');

        $response = $disk->download($document->storage_key, $document->original_name, [
            'Content-Type' => $document->mime_type,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
        if ($inline) {
            $response->headers->set('Content-Disposition', $response->headers->makeDisposition('inline', $document->original_name, 'document'));
        }

        return $response;
    }

    private function safeName(string $name): string
    {
        $name = preg_replace('/[\x00-\x1F\x7F\/\\\\]+/u', '_', $name) ?? 'document';

        return trim($name) === '' ? 'document' : $name;
    }
}
