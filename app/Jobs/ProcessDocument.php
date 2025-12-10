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
    $fullPath = storage_path('app/' . $this->path);
    $extension = pathinfo($fullPath, PATHINFO_EXTENSION);
    $content = '';

    if ($extension === 'pdf') {
        $parser = new \Smalot\PdfParser\Parser();
        $pdf = $parser->parseFile($fullPath);
        $content = $pdf->getText();
    } else {
        $content = file_get_contents($fullPath);
    }

    // clean UTF-8
    $content = mb_convert_encoding($content, 'UTF-8', 'UTF-8');

    $chunks = str_split($content, 300);

    foreach ($chunks as $chunk) {
        if (trim($chunk) === '') continue;

        $response = \Illuminate\Support\Facades\Http::withHeaders([
            'Authorization' => 'Bearer ' . env('OPENROUTER_API_KEY'),
            'HTTP-Referer' => env('APP_URL')
        ])->post(env('OPENROUTER_BASE_URL') . '/embeddings', [
            'model' => env('OPENROUTER_EMBED_MODEL'),
            'input' => $chunk
        ]);

        $data = $response->json();
        $embedding = $data['data'][0]['embedding'] ?? null;

        \Illuminate\Support\Facades\DB::table('embeddings')->insert([
            'content' => $chunk,
            'embedding' => json_encode($embedding)
        ]);
    }
}



    private function chunkText($text, $chunkSize = 800, $overlap = 200)
    {
        // split by words for cleaner boundaries
        $words = preg_split('/\s+/', $text);
        $chunks = [];
        $i = 0;
        $count = count($words);

        while ($i < $count) {
            $slice = array_slice($words, $i, $chunkSize);
            $chunks[] = implode(' ', $slice);
            $i += ($chunkSize - $overlap);
        }

        return $chunks;
    }
}
