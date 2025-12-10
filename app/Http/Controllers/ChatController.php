<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class ChatController extends Controller
{
    public function ask(Request $request)
    {
        $query = $request->input('message');

        // 1) Create embedding for user query
        $embedResponse = Http::withHeaders([
            'Authorization' => 'Bearer ' . env('OPENROUTER_API_KEY'),
            'HTTP-Referer'  => env('APP_URL'),
        ])->post(env('OPENROUTER_BASE_URL') . '/embeddings', [
            'model' => env('OPENROUTER_EMBED_MODEL'),
            'input' => $query
        ]);

        $embedData = $embedResponse->json();
        $queryEmbedding = $embedData['data'][0]['embedding'] ?? null;

        // 2) Fetch all document embeddings from DB
        $rows = DB::table('embeddings')->get();

        // 3) Calculate similarity with each chunk
        $scoredChunks = [];

        foreach ($rows as $row) {
            $docEmbedding = json_decode($row->embedding, true);

            $score = $this->cosineSimilarity($queryEmbedding, $docEmbedding);

            $scoredChunks[] = [
                'content' => $row->content,
                'score'   => $score
            ];
        }

        // Sort by similarity desc
        usort($scoredChunks, fn($a, $b) => $b['score'] <=> $a['score']);

        // Top 3 best chunks
        $topContext = collect($scoredChunks)->take(3)->pluck('content')->implode("\n\n");

        // 4) If user asks personal or HR related question → custom response
        if ($query && $this->isRestrictedQuery($query)) {
            return view('chat', [
                'answer' => "For more knowledge on this, please meet the HR."
            ]);
        }

        // 5) Generate final answer from LLM
        $finalResponse = Http::withHeaders([
            'Authorization' => 'Bearer ' . env('OPENROUTER_API_KEY'),
            'HTTP-Referer'  => env('APP_URL'),
        ])->post(env('OPENROUTER_BASE_URL') . '/chat/completions', [
            'model' => 'openai/gpt-4o-mini', // Free model
            'messages' => [
                [
                    "role" => "system",
                    "content" =>
                        "You are a helpful RAG assistant. Answer only from context. 
                         If answer not in context, say: 'I don't know based on the provided documents.'"
                ],
                [
                    "role" => "user",
                    "content" =>
                        "Context:\n$topContext\n\nUser Question:\n$query"
                ]
            ]
        ]);

        $answer = $finalResponse->json()['choices'][0]['message']['content'] ?? 'Error.';

        return view('chat', compact('answer'));
    }

    // Cosine similarity function
    private function cosineSimilarity($a, $b)
    {
        $dot = 0;
        $magA = 0;
        $magB = 0;

        for ($i = 0; $i < count($a); $i++) {
            $dot += $a[$i] * $b[$i];
            $magA += $a[$i] ** 2;
            $magB += $b[$i] ** 2;
        }

        return $dot / (sqrt($magA) * sqrt($magB));
    }

    // Detect HR / personal question
    private function isRestrictedQuery($q)
    {
        if (!is_string($q)) return false;
        
        $q = strtolower($q);

        $restricted = [
            'salary', 'personal', 'phone', 'contact', 
            'email', 'address', 'hr', 'manager', 'security'
        ];

        foreach ($restricted as $word) {
            if (str_contains($q, $word)) return true;
        }

        return false;
    }
}
