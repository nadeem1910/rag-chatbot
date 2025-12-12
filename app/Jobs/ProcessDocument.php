<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ProcessDocument implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $path;

    public function __construct($path)
    {
        $this->path = $path;
    }

    public function handle()
    {
        // Use Storage facade directly - it handles path correctly
        if (!Storage::exists($this->path)) {
            Log::error("FILE NOT FOUND in storage: " . $this->path);
            return;
        }

        Log::info("Processing file: " . $this->path);

        // Get file content using Storage facade
        if (str_ends_with($this->path, '.pdf')) {
            // For PDF, get actual path
            $fullPath = Storage::path($this->path);
            
            Log::info("Full PDF path: " . $fullPath);

            try {
                $parser = new \Smalot\PdfParser\Parser();
                $pdf = $parser->parseFile($fullPath);
                $content = $pdf->getText();
            } catch (\Exception $e) {
                Log::error("PDF parsing error: " . $e->getMessage());
                return;
            }
        } else {
            // For text files, use Storage::get
            $content = Storage::get($this->path);
        }

        // Clean and validate content
        $content = trim($content);
        
        if (strlen($content) < 10) {
            Log::error("EMPTY CONTENT in file: " . $this->path);
            return;
        }

        Log::info("Extracted content length: " . strlen($content));

        // Use better chunking with overlap
        $chunks = $this->chunkText($content, 500, 100);
        
        Log::info("Created " . count($chunks) . " chunks");

        foreach ($chunks as $index => $chunk) {
            $chunk = trim($chunk);
            
            if (strlen($chunk) < 20) continue; // Skip very small chunks

            try {
                // Get embedding from OpenRouter
                $response = Http::timeout(30)
                    ->withHeaders([
                        'Authorization' => 'Bearer ' . env('OPENROUTER_API_KEY'),
                        'HTTP-Referer' => env('APP_URL'),
                        'Content-Type' => 'application/json'
                    ])
                    ->post(env('OPENROUTER_BASE_URL') . '/embeddings', [
                        'model' => env('OPENROUTER_EMBED_MODEL'),
                        'input' => $chunk
                    ]);

                if (!$response->successful()) {
                    Log::error("Embedding API error: " . $response->body());
                    continue;
                }

                $data = $response->json();
                $embedding = $data['data'][0]['embedding'] ?? null;

                if (!$embedding) {
                    Log::error("No embedding returned for chunk $index");
                    continue;
                }

                // Store in database
                DB::table('embeddings')->insert([
                    'content' => $chunk,
                    'embedding' => json_encode($embedding),
                    'created_at' => now(),
                    'updated_at' => now()
                ]);

                Log::info("Chunk $index embedded successfully");

            } catch (\Exception $e) {
                Log::error("Error processing chunk $index: " . $e->getMessage());
                continue;
            }

            // Add small delay to avoid rate limits
            usleep(100000); // 0.1 second
        }

        Log::info("File processing completed: " . $this->path);
    }

    private function chunkText($text, $chunkSize = 500, $overlap = 100)
    {
        // Split by sentences for better context
        $sentences = preg_split('/(?<=[.!?])\s+/', $text, -1, PREG_SPLIT_NO_EMPTY);
        
        $chunks = [];
        $currentChunk = '';
        
        foreach ($sentences as $sentence) {
            $sentence = trim($sentence);
            
            if (strlen($currentChunk . ' ' . $sentence) > $chunkSize) {
                if ($currentChunk) {
                    $chunks[] = $currentChunk;
                }
                
                // Start new chunk with overlap from previous
                $words = explode(' ', $currentChunk);
                $overlapWords = array_slice($words, -($overlap / 10));
                $currentChunk = implode(' ', $overlapWords) . ' ' . $sentence;
            } else {
                $currentChunk .= ($currentChunk ? ' ' : '') . $sentence;
            }
        }
        
        // Add last chunk
        if ($currentChunk) {
            $chunks[] = $currentChunk;
        }
        
        return array_filter($chunks, fn($c) => strlen(trim($c)) > 20);
    }
}