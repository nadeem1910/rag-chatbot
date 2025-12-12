<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Jobs\ProcessDocument;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;

class UploadController extends Controller
{
    public function store(Request $request)
    {
        // Validate
        $request->validate([
            'files' => 'required',
            'files.*' => 'file|mimes:txt,pdf,md,docx'
        ]);

        $files = $request->file('files');

        if (!$files) {
            return back()->with('error', 'No files uploaded.');
        }

        foreach ($files as $file) {

            // store in storage/app/documents
            $path = $file->store('documents');

            Log::info("Uploaded file stored at: $path — Exists? " . (Storage::exists($path) ? 'YES' : 'NO'));

            // queue job
            ProcessDocument::dispatch($path);
        }

        return back()->with('success', 'File uploaded and processing started.');
    }
}
