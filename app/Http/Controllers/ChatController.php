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

    public function testAsk(Request $request)
    {
        $query = $request->input('message');
        
        $debug = "Raw input: " . var_export($request->all(), true) . "\n";
        $debug .= "Message field: [" . $query . "]\n";
        $debug .= "Message length: " . strlen($query ?? '') . "\n";
        $debug .= "Is empty: " . (empty($query) ? 'YES' : 'NO') . "\n";
        
        Log::info($debug);
        
        return back()->with([
            'query' => $query,
            'answer' => 'Test successful! Your message was received: "' . $query . '"',
            'debug' => $debug
        ]);
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
            $rows = DB::table('embeddings')
                ->select('id', 'content', 'embedding')
                ->get();

            Log::info("Found " . $rows->count() . " embeddings in database");

            if ($rows->isEmpty()) {
                return back()->with([
                    'answer' => 'No documents have been uploaded yet. Please upload documents first.',
                    'query' => $query
                ]);
            }

            // 3) Calculate similarity with each chunk (optimized)
            $scoredChunks = [];
            
            // Pre-calculate query magnitude once
            $queryMag = sqrt(array_sum(array_map(fn($x) => $x * $x, $queryEmbedding)));

            foreach ($rows as $row) {
                $docEmbedding = json_decode($row->embedding, true);

                if (!$docEmbedding || !is_array($docEmbedding)) {
                    continue;
                }

                // Fast similarity calculation
                $score = $this->fastCosineSimilarity($queryEmbedding, $docEmbedding, $queryMag);

                // Only keep chunks with decent similarity (pre-filter)
                if ($score > 0.15) {
                    $scoredChunks[] = [
                        'content' => $row->content,
                        'score' => $score
                    ];
                }
            }

            if (empty($scoredChunks)) {
                return back()->with([
                    'answer' => "I don't have relevant information to answer this question based on the uploaded documents.",
                    'query' => $query
                ]);
            }

            // Sort by similarity descending
            usort($scoredChunks, fn($a, $b) => $b['score'] <=> $a['score']);

            // Get top 3 most relevant chunks (reduced from 5 for faster response)
            $topChunks = array_slice($scoredChunks, 0, 3);
            
            Log::info("Top 3 similarity scores: " . json_encode(array_map(fn($c) => round($c['score'], 3), $topChunks)));

            $topContext = collect($topChunks)
                ->pluck('content')
                ->implode("\n\n---\n\n");

            Log::info("Using " . count($topChunks) . " chunks for context");

            // 4) Generate final answer from LLM (optimized)
            Log::info("Generating answer from LLM...");

            $finalResponse = Http::timeout(45) // Reduced from 60
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
                            "content" => "You are a helpful assistant. Answer concisely based on the context. If unsure, say so briefly."
                        ],
                        [
                            "role" => "user",
                            "content" => "Context:\n$topContext\n\nQuestion: $query\n\nAnswer:"
                        ]
                    ],
                    'temperature' => 0.2, // Lower for faster, more focused responses
                    'max_tokens' => 300 // Reduced from 500 for faster response
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

    // Fast cosine similarity with pre-calculated magnitude
    private function fastCosineSimilarity($a, $b, $queryMag = null)
    {
        if (!is_array($a) || !is_array($b) || count($a) !== count($b)) {
            return 0;
        }

        $dot = 0;
        $magB = 0;

        for ($i = 0; $i < count($a); $i++) {
            $dot += $a[$i] * $b[$i];
            $magB += $b[$i] ** 2;
        }

        if ($magB == 0) return 0;

        // Use pre-calculated query magnitude if available
        if ($queryMag === null) {
            $magA = sqrt(array_sum(array_map(fn($x) => $x * $x, $a)));
        } else {
            $magA = $queryMag;
        }

        if ($magA == 0) return 0;

        return $dot / ($magA * sqrt($magB));
    }

    // Keep old method for backward compatibility
    private function cosineSimilarity($a, $b)
    {
        return $this->fastCosineSimilarity($a, $b);
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