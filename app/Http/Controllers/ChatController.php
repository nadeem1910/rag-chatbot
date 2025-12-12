<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ChatController extends Controller
{
    public function index()
    {
        // Show empty chat page
        return view('chat');
    }

    public function ask(Request $request)
    {
        // Debug: Log all request data
        Log::info("=== NEW CHAT REQUEST ===");
        Log::info("All request data: " . json_encode($request->all()));
        Log::info("Request method: " . $request->method());
        
        // Get and validate message
        $query = $request->input('message');

        Log::info("Received query: [" . $query . "]");
        Log::info("Query length: " . strlen($query ?? ''));

        if (!$query || strlen(trim($query)) < 3) {
            return back()->with('answer', 'Please ask a valid question.');
        }

        // Check for restricted queries first
        if ($this->isRestrictedQuery($query)) {
            return back()->with([
                'answer' => "For more information on this topic, please contact HR directly.",
                'query' => $query
            ]);
        }

        try {
            // 1) Create embedding for user query
            Log::info("Creating embedding for query...");
            
            $embedResponse = Http::timeout(30)
                ->withHeaders([
                    'Authorization' => 'Bearer ' . env('OPENROUTER_API_KEY'),
                    'HTTP-Referer' => env('APP_URL'),
                    'Content-Type' => 'application/json'
                ])
                ->post(env('OPENROUTER_BASE_URL') . '/embeddings', [
                    'model' => env('OPENROUTER_EMBED_MODEL'),
                    'input' => $query
                ]);

            if (!$embedResponse->successful()) {
                Log::error("Embedding API error: " . $embedResponse->body());
                return back()->with([
                    'answer' => 'Error creating query embedding. Please try again.',
                    'query' => $query
                ]);
            }

            $embedData = $embedResponse->json();
            $queryEmbedding = $embedData['data'][0]['embedding'] ?? null;

            if (!$queryEmbedding) {
                return back()->with([
                    'answer' => 'Error processing your query. Please try again.',
                    'query' => $query
                ]);
            }

            Log::info("Query embedding created successfully");

            // 2) Fetch all document embeddings from DB
            $rows = DB::table('embeddings')->get();

            Log::info("Found " . $rows->count() . " embeddings in database");

            if ($rows->isEmpty()) {
                return back()->with([
                    'answer' => 'No documents have been uploaded yet. Please upload documents first.',
                    'query' => $query
                ]);
            }

            // 3) Calculate similarity with each chunk
            $scoredChunks = [];

            foreach ($rows as $row) {
                $docEmbedding = json_decode($row->embedding, true);

                if (!$docEmbedding || !is_array($docEmbedding)) {
                    continue;
                }

                $score = $this->cosineSimilarity($queryEmbedding, $docEmbedding);

                $scoredChunks[] = [
                    'content' => $row->content,
                    'score' => $score
                ];
            }

            // Sort by similarity descending
            usort($scoredChunks, fn($a, $b) => $b['score'] <=> $a['score']);

            // Get top 5 most relevant chunks
            $topChunks = array_slice($scoredChunks, 0, 5);
            
            Log::info("Top 5 similarity scores: " . json_encode(array_map(fn($c) => $c['score'], $topChunks)));

            // Filter chunks with reasonable similarity (above 0.2 threshold - lowered for better results)
            $relevantChunks = array_filter($topChunks, fn($chunk) => $chunk['score'] > 0.2);

            if (empty($relevantChunks)) {
                return back()->with([
                    'answer' => "I don't have enough relevant information to answer this question based on the uploaded documents. (Best match score: " . number_format($topChunks[0]['score'], 3) . ")",
                    'query' => $query
                ]);
            }

            $topContext = collect($relevantChunks)
                ->pluck('content')
                ->implode("\n\n---\n\n");

            Log::info("Using " . count($relevantChunks) . " relevant chunks for context");

            // 4) Generate final answer from LLM
            Log::info("Generating answer from LLM...");

            $finalResponse = Http::timeout(60)
                ->withHeaders([
                    'Authorization' => 'Bearer ' . env('OPENROUTER_API_KEY'),
                    'HTTP-Referer' => env('APP_URL'),
                    'Content-Type' => 'application/json'
                ])
                ->post(env('OPENROUTER_BASE_URL') . '/chat/completions', [
                    'model' => 'openai/gpt-4o-mini',
                    'messages' => [
                        [
                            "role" => "system",
                            "content" => "You are a helpful RAG assistant. Answer questions ONLY based on the provided context. If the answer is not clearly in the context, state: 'I don't have this information in the provided documents.' Be concise and accurate."
                        ],
                        [
                            "role" => "user",
                            "content" => "Context from documents:\n\n$topContext\n\n---\n\nQuestion: $query\n\nAnswer based only on the context above:"
                        ]
                    ],
                    'temperature' => 0.3,
                    'max_tokens' => 500
                ]);

            if (!$finalResponse->successful()) {
                Log::error("Chat completion error: " . $finalResponse->body());
                return back()->with([
                    'answer' => 'Error generating response. Please try again.',
                    'query' => $query
                ]);
            }

            $answer = $finalResponse->json()['choices'][0]['message']['content'] ?? 'Unable to generate answer.';

            Log::info("Answer generated successfully");

            return back()->with([
                'answer' => $answer,
                'query' => $query
            ]);

        } catch (\Exception $e) {
            Log::error("Chat error: " . $e->getMessage());
            Log::error($e->getTraceAsString());
            
            return back()->with([
                'answer' => 'An error occurred: ' . $e->getMessage(),
                'query' => $query
            ]);
        }
    }

    // Cosine similarity function
    private function cosineSimilarity($a, $b)
    {
        if (!is_array($a) || !is_array($b) || count($a) !== count($b)) {
            return 0;
        }

        $dot = 0;
        $magA = 0;
        $magB = 0;

        for ($i = 0; $i < count($a); $i++) {
            $dot += $a[$i] * $b[$i];
            $magA += $a[$i] ** 2;
            $magB += $b[$i] ** 2;
        }

        if ($magA == 0 || $magB == 0) {
            return 0;
        }

        return $dot / (sqrt($magA) * sqrt($magB));
    }

    // Detect HR / personal questions
    private function isRestrictedQuery($q)
    {
        if (!is_string($q)) return false;

        $q = strtolower($q);

        $restricted = [
            'salary', 'personal', 'phone', 'contact',
            'email', 'address', 'hr', 'manager', 'security',
            'password', 'confidential', 'private'
        ];

        foreach ($restricted as $word) {
            if (str_contains($q, $word)) {
                return true;
            }
        }

        return false;
    }
}