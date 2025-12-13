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
        try {
            // Use Storage facade directly - it handles path correctly
            if (!Storage::exists($this->path)) {
                Log::error("FILE NOT FOUND in storage: " . $this->path);
                return;
            }

            Log::info("Processing file: " . $this->path);

            $content = '';
            $extension = strtolower(pathinfo($this->path, PATHINFO_EXTENSION));

            // Handle different file types
            switch ($extension) {
                case 'pdf':
                    $content = $this->extractPdfContent();
                    break;
                
                case 'docx':
                    $content = $this->extractDocxContent();
                    break;
                
                case 'txt':
                case 'md':
                    $content = Storage::get($this->path);
                    break;
                
                default:
                    Log::error("Unsupported file type: " . $extension);
                    return;
            }

            // Clean and validate content
            $content = $this->cleanContent($content);
            
            if (strlen($content) < 10) {
                Log::error("EMPTY CONTENT in file: " . $this->path);
                return;
            }

            Log::info("Extracted content length: " . strlen($content) . " characters");

            // Use better chunking with overlap
            $chunks = $this->chunkText($content, 500, 100);
            
            Log::info("Created " . count($chunks) . " chunks");

            // Process chunks in batches to improve speed
            $this->processChunksInBatches($chunks);

            Log::info("✅ File processing completed: " . $this->path);

        } catch (\Exception $e) {
            Log::error("Fatal error processing file: " . $e->getMessage());
            Log::error($e->getTraceAsString());
        }
    }

    private function extractPdfContent()
    {
        $fullPath = Storage::path($this->path);
        Log::info("Extracting PDF: " . $fullPath);

        try {
            $parser = new \Smalot\PdfParser\Parser();
            $pdf = $parser->parseFile($fullPath);
            return $pdf->getText();
        } catch (\Exception $e) {
            Log::error("PDF parsing error: " . $e->getMessage());
            throw $e;
        }
    }

    private function extractDocxContent()
    {
        $fullPath = Storage::path($this->path);
        Log::info("Extracting DOCX: " . $fullPath);

        try {
            // Simple DOCX extraction using ZipArchive
            $zip = new \ZipArchive();
            if ($zip->open($fullPath) === true) {
                $xml = $zip->getFromName('word/document.xml');
                $zip->close();
                
                if ($xml) {
                    // Extract text from XML
                    $xml = simplexml_load_string($xml);
                    $xml->registerXPathNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');
                    $texts = $xml->xpath('//w:t');
                    return implode(' ', array_map(fn($t) => (string)$t, $texts));
                }
            }
            
            Log::error("Could not extract DOCX content");
            return '';
        } catch (\Exception $e) {
            Log::error("DOCX extraction error: " . $e->getMessage());
            return '';
        }
    }

    private function cleanContent($content)
    {
        // Remove extra whitespace, tabs, newlines
        $content = preg_replace('/\s+/', ' ', $content);
        // Remove special characters that might cause issues
        $content = preg_replace('/[^\x20-\x7E\x0A\x0D]/', '', $content);
        return trim($content);
    }

    private function processChunksInBatches($chunks)
    {
        $batchSize = 3; // Process 3 chunks at a time
        $batches = array_chunk($chunks, $batchSize);
        $totalProcessed = 0;

        foreach ($batches as $batchIndex => $batch) {
            Log::info("Processing batch " . ($batchIndex + 1) . "/" . count($batches));

            foreach ($batch as $index => $chunk) {
                $chunk = trim($chunk);
                
                if (strlen($chunk) < 20) continue;

                $globalIndex = $totalProcessed + $index;

                try {
                    // Get embedding from OpenRouter
                    $response = Http::timeout(30)
                        ->retry(3, 1000) // Retry 3 times with 1 second delay
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
                        Log::error("Embedding API error for chunk $globalIndex: " . $response->body());
                        continue;
                    }

                    $data = $response->json();
                    $embedding = $data['data'][0]['embedding'] ?? null;

                    if (!$embedding) {
                        Log::error("No embedding returned for chunk $globalIndex");
                        continue;
                    }

                    // Store in database
                    DB::table('embeddings')->insert([
                        'content' => $chunk,
                        'embedding' => json_encode($embedding),
                        'created_at' => now(),
                        'updated_at' => now()
                    ]);

                    Log::info("✓ Chunk $globalIndex embedded");

                } catch (\Exception $e) {
                    Log::error("Error processing chunk $globalIndex: " . $e->getMessage());
                    continue;
                }
            }

            $totalProcessed += count($batch);
            
            // Small delay between batches to avoid rate limits
            usleep(50000); // 0.05 seconds
        }

        Log::info("Total chunks processed: $totalProcessed");
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