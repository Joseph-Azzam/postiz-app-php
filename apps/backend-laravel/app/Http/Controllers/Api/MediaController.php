<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Storage;

/**
 * GET /api/media for Postiz media library (paginated).
 * POST /api/media/upload-server for Uppy XHRUpload (local storage).
 */
class MediaController extends Controller
{
    /**
     * GET /api/media?page=1
     * Frontend expects { results: [], pages: number }.
     */
    public function index(Request $request): JsonResponse
    {
        return response()->json([
            'results' => [],
            'pages' => 0,
        ]);
    }

    /**
     * POST /api/media/upload-server
     * Accepts multipart file upload (Uppy XHRUpload). Stores in storage/app/public/media.
     * Feature parity with NestJS: FileInterceptor('file'), returns media-like { id, name, path }.
     * Frontend uses response.body in onUploadSuccess and expects .path for display.
     */
    public function uploadServer(Request $request): JsonResponse
    {
        // Uppy XHRUpload sends field "file" by default (or "files[]" when bundle: true).
        $file = $request->file('file')
            ?? $request->file('files[]')
            ?? collect($request->allFiles())->first();

        if (! $file) {
            $inputKeys = array_keys($request->all());
            $fileKeys = array_keys($request->allFiles());
            return response()->json([
                'error' => 'No file in request',
                'hint' => 'Send multipart/form-data with field name "file". Input keys: '.implode(', ', $inputKeys).'; file keys: '.implode(', ', $fileKeys),
            ], 400);
        }

        if (! $file->isValid()) {
            return response()->json([
                'error' => 'File upload failed',
                'message' => $file->getErrorMessage(),
            ], 400);
        }

        $ext = $file->getClientOriginalExtension() ?: $file->guessExtension();
        $name = Str::ulid().($ext ? '.'.$ext : '');
        $storedPath = $file->storeAs('media', $name, 'public');
        $url = Storage::disk('public')->url($storedPath);

        // NestJS saveFile returns { id, name, path, thumbnail, alt }. We return path = URL for parity.
        return response()->json([
            'id' => Str::ulid(),
            'name' => $file->getClientOriginalName(),
            'path' => $url,
            'url' => $url,
            'thumbnail' => null,
            'alt' => null,
        ]);
    }
}
