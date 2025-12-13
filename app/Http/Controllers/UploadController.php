<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Jobs\ProcessDocument;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

class UploadController extends Controller
{
    public function store(Request $request)
    {
        // Validate with better error messages
        $request->validate([
            'files' => 'required|array|max:5', // Max 5 files at once
            'files.*' => 'file|mimes:txt,pdf,md,docx|max:10240' // max 10MB
        ], [
            'files.required' => 'Please select at least one file',
            'files.*.mimes' => 'Only PDF, TXT, MD, and DOCX files are supported',
            'files.*.max' => 'File size should not exceed 10MB'
        ]);

        $files = $request->file('files');

        if (!$files || count($files) === 0) {
            return back()->with('error', 'No files uploaded.');
        }

        $uploadedCount = 0;
        $failedFiles = [];

        foreach ($files as $file) {
            try {
                $originalName = $file->getClientOriginalName();
                $extension = strtolower($file->getClientOriginalExtension());
                
                // Verify file is readable
                if (!$file->isValid()) {
                    $failedFiles[] = $originalName . ' (invalid file)';
                    Log::error("Invalid file: " . $originalName);
                    continue;
                }

                // Store file in storage/app/documents
                $path = $file->store('documents');

                // Double-check file exists
                if (!Storage::exists($path)) {
                    $failedFiles[] = $originalName . ' (upload failed)';
                    Log::error("File upload failed: " . $originalName);
                    continue;
                }

                $fileSize = Storage::size($path);
                Log::info("✓ File uploaded: $originalName ($fileSize bytes) -> $path");

                // Store metadata in documents table
                DB::table('documents')->insert([
                    'original_name' => $originalName,
                    'path' => $path,
                    'size' => $fileSize,
                    'mime' => $file->getMimeType(),
                    'created_at' => now(),
                    'updated_at' => now()
                ]);

                // Dispatch job to process document
                ProcessDocument::dispatch($path)->onQueue('default');
                
                $uploadedCount++;

            } catch (\Exception $e) {
                $failedFiles[] = $file->getClientOriginalName() . ' (' . $e->getMessage() . ')';
                Log::error("Upload error for " . $file->getClientOriginalName() . ": " . $e->getMessage());
                continue;
            }
        }

        // Build response message
        $message = '';
        if ($uploadedCount > 0) {
            $message = "✅ $uploadedCount file(s) uploaded successfully! Processing started (check back in 30 seconds).";
        }
        
        if (!empty($failedFiles)) {
            $message .= " ⚠️ Failed: " . implode(', ', $failedFiles);
        }

        if ($uploadedCount === 0) {
            return back()->with('error', 'Failed to upload any files. ' . implode(', ', $failedFiles));
        }

        return back()->with('success', $message);
    }
}