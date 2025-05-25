<?php

namespace App\Http\Controllers;

use App\Models\History;
use App\Models\PopularPosts;
use App\Models\PopularSearchs;
use App\Models\Post;
use Illuminate\Http\JsonResponse;
use Orhanerday\OpenAi\OpenAi;
use Stichoza\GoogleTranslate\GoogleTranslate;
use Illuminate\Support\Facades\Cache;
use DetectLanguage\DetectLanguage;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ApiController extends Controller
{
    private OpenAi $openAi;
    private GoogleTranslate $translator;
    
    public function __construct()
    {
        $this->openAi = new OpenAi(config('services.openai.key'));
        $this->translator = new GoogleTranslate();
        DetectLanguage::setApiKey(config('services.detect_language.key'));
    }

    /**
     * Get all posts
     */
    public function posts(): JsonResponse
    {
        return response()->json(Post::all());
    }

    /**
     * Get all cached searches
     */
    public function searches(): JsonResponse
    {
        return response()->json(Cache::getStore()->many(Cache->keys('*')));
    }

    /**
     * Get specific cached search
     */
    public function getSearch(string $name): JsonResponse
    {
        return response()->json(Cache::get($name));
    }

    /**
     * Get search history
     */
    public function history(): JsonResponse
    {
        return response()->json(History::all());
    }

    /**
     * Get popular posts
     */
    public function popularPosts(): JsonResponse
    {
        return response()->json(PopularPosts::all());
    }

    /**
     * Get popular searches
     */
    public function popularSearches(): JsonResponse
    {
        return response()->json(PopularSearchs::all());
    }

    /**
     * Get post by title
     */
    public function getPost(string $title): JsonResponse
    {
        return response()->json(Post::where('title', $title)->firstOrFail());
    }

    /**
     * Chat with GPT
     */
    public function chat(string $text): JsonResponse
    {
        try {
            $response = $this->openAi->chat([
                'model' => 'gpt-3.5-turbo',
                'messages' => [
                    ['role' => 'user', 'content' => $text]
                ],
                'temperature' => 1.0,
                'frequency_penalty' => 0,
                'presence_penalty' => 0,
            ]);

            $responseData = json_decode($response, true, 512, JSON_THROW_ON_ERROR);
            
            return response()->json($responseData['choices'][0]['message']['content']);
        } catch (\Exception $e) {
            Log::error('Chat API error: ' . $e->getMessage());
            return response()->json(['error' => 'An error occurred'], 500);
        }
    }

    /**
     * Translate text
     */
    public function translate(string $language, string $text): JsonResponse
    {
        try {
            $this->translator->setTarget($language);
            return response()->json($this->translator->translate($text));
        } catch (\Exception $e) {
            Log::error('Translation error: ' . $e->getMessage());
            return response()->json(['error' => 'Translation failed'], 500);
        }
    }

    /**
     * Search for a keyword
     */
    public function search(string $keyword): JsonResponse
    {
        try {
            // Check cache first
            if ($cachedResult = Cache::get($keyword)) {
                return $this->formatSearchResponse($cachedResult);
            }

            // Detect language
            $languageCode = DetectLanguage::simpleDetect($keyword);
            
            // Get Wikipedia data
            $wikiData = $this->getWikipediaData($keyword, $languageCode);
            $wikiImage = $this->getWikipediaImage($keyword, $languageCode);

            // Generate AI content
            $aiResponses = $this->generateAiContent($wikiData['extract'], $languageCode);
            
            // Update popular searches
            $this->updatePopularSearches($keyword, $aiResponses['summarize'], $wikiImage['source']);

            // Prepare and cache result
            $result = [
                'key_word' => $keyword,
                'summarize' => $aiResponses['summarize'],
                'article' => $aiResponses['article'],
                'img_src' => $wikiImage['source']
            ];

            Cache::put($keyword, $result, now()->addHours(24));

            return $this->formatSearchResponse($result);
        } catch (\Exception $e) {
            Log::error('Search error: ' . $e->getMessage());
            return response()->json(['error' => 'Search failed'], 500);
        }
    }

    /**
     * Helper method to format search response
     */
    private function formatSearchResponse(array $data): JsonResponse
    {
        return response()->json([
            'summarize' => $data['summarize'],
            'article' => $data['article'],
            'img' => $data['img_src'],
            'okay' => $data['key_word'],
        ]);
    }

    /**
     * Get Wikipedia extract data
     */
    private function getWikipediaData(string $keyword, string $languageCode): array
    {
        $response = Http::get("https://{$languageCode}.wikipedia.org/w/api.php", [
            'format' => 'json',
            'action' => 'query',
            'prop' => 'extracts',
            'exintro' => true,
            'explaintext' => true,
            'redirects' => 1,
            'titles' => $keyword
        ]);

        $pages = $response->json()['query']['pages'];
        $page = reset($pages);

        return [
            'extract' => $page['extract'] ?? $keyword,
            'exists' => isset($page['extract'])
        ];
    }

    /**
     * Get Wikipedia image
     */
    private function getWikipediaImage(string $keyword, string $languageCode): array
    {
        $response = Http::get("https://{$languageCode}.wikipedia.org/w/api.php", [
            'action' => 'query',
            'prop' => 'pageimages',
            'format' => 'json',
            'piprop' => 'original',
            'titles' => $keyword
        ]);

        $pages = $response->json()['query']['pages'];
        $page = reset($pages);

        return [
            'source' => $page['original']['source'] ?? '/img/azar.png',
            'exists' => isset($page['original'])
        ];
    }

    /**
     * Generate AI content
     */
    private function generateAiContent(string $text, string $languageCode): array
    {
        $summarizePrompt = $languageCode === 'fa' 
            ? "توضیحی ای در مورد این متن ارائه دهید. متن: {$text}"
            : "explanation of this text. text is: {$text}";

        $articlePrompt = $languageCode === 'fa'
            ? "مقاله ای در مورد: {$text}"
            : "Write an article about {$text}";

        return [
            'summarize' => $this->getAiResponse($summarizePrompt),
            'article' => $this->getAiResponse($articlePrompt)
        ];
    }

    /**
     * Get AI response
     */
    private function getAiResponse(string $prompt): string
    {
        $response = $this->openAi->chat([
            'model' => 'gpt-3.5-turbo',
            'messages' => [['role' => 'user', 'content' => $prompt]],
            'temperature' => 1.0,
            'frequency_penalty' => 0,
            'presence_penalty' => 0,
        ]);

        $data = json_decode($response, true, 512, JSON_THROW_ON_ERROR);
        return $data['choices'][0]['message']['content'];
    }

    /**
     * Update popular searches
     */
    private function updatePopularSearches(string $keyword, string $text, string $imageSrc): void
    {
        PopularSearchs::updateOrCreate(
            ['key_word' => $keyword],
            [
                'text' => implode(' ', array_slice(explode(' ', $text), 0, 10)),
                'img_src' => $imageSrc,
                'views' => \DB::raw('views + 1')
            ]
        );
    }
}
