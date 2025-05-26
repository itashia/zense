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
    public function post(): JsonResponse
    {
        return response()->json(Post::all());
    }

    /**
     * Get all cached searches
     */
    public function searches(): JsonResponse
    {
        return response()->json(Cache::all());
    }

    /**
     * Get specific cached search
     */
    public function GetSearch(string $name): JsonResponse
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
    public function PupularPost(): JsonResponse
    {
        return response()->json(PopularPosts::all());
    }

    /**
     * Get popular searches
     */
    public function PopularSearch(): JsonResponse
    {
        return response()->json(PopularSearchs::all());
    }

    /**
     * Get post by title
     */
    public function GetPost(string $name): JsonResponse
    {
        return response()->json(Post::where('title', $name)->get());
    }

    /**
     * Chat with GPT
     */
    public function chat(string $text): JsonResponse
    {
        try {
            $chat = $this->openAi->chat([
                'model' => 'gpt-3.5-turbo',
                'messages' => [
                    [
                        "role" => "user",
                        "content" => $text
                    ],
                ],
                'temperature' => 1.0,
                'frequency_penalty' => 0,
                'presence_penalty' => 0,
            ]);

            $chatData = json_decode($chat, true);
            
            return response()->json($chatData['choices'][0]['message']['content']);
        } catch (\Exception $e) {
            Log::error('Chat error: ' . $e->getMessage());
            return response()->json(['error' => 'An error occurred'], 500);
        }
    }

    /**
     * Translate text
     */
    public function translate(string $lan, string $text): JsonResponse
    {
        try {
            $this->translator->setTarget($lan);
            return response()->json($this->translator->translate($text));
        } catch (\Exception $e) {
            Log::error('Translation error: ' . $e->getMessage());
            return response()->json(['error' => 'Translation failed'], 500);
        }
    }

    /**
     * Search for a keyword
     */
    public function search(string $key_word): JsonResponse
    {
        try {
            if ($cachedResult = Cache::get($key_word)) {
                return response()->json([
                    "summarize" => $cachedResult["summarize"],
                    "article" => $cachedResult["article"],
                    "img" => $cachedResult["img_src"],
                    "okay" => $cachedResult["key_word"],
                ]);
            }

            $languageCode = DetectLanguage::simpleDetect($key_word);
            $wikiData = $this->getWikipediaData($languageCode, $key_word);
            $wikiImage = $this->getWikipediaImage($languageCode, $key_word);

            $content = $this->prepareContent($languageCode, $wikiData['extract']);
            $summarize = $this->getGptResponse($content['summarize']);
            $article = $this->getGptResponse($content['article']);

            $this->updatePopularSearches($key_word, $summarize, $wikiImage);

            $result = [
                "key_word" => $key_word,
                "summarize" => $summarize,
                "article" => $article,
                "img_src" => $wikiImage['original']['source']
            ];

            Cache::put($key_word, $result, now()->addHours(24));

            return response()->json($result);
        } catch (\Exception $e) {
            Log::error('Search error: ' . $e->getMessage());
            return response()->json(['error' => 'Search failed'], 500);
        }
    }

    private function getWikipediaData(string $languageCode, string $keyWord): array
    {
        $response = Http::get("https://{$languageCode}.wikipedia.org/w/api.php", [
            'format' => 'json',
            'action' => 'query',
            'prop' => 'extracts',
            'exintro' => true,
            'explaintext' => true,
            'redirects' => 1,
            'titles' => $keyWord
        ]);

        $data = $response->json()['query']['pages'];
        $result = array_values($data)[0];
        $result['extract'] = $result['extract'] ?? $keyWord;

        if (strlen($result['extract']) > 390) {
            $split = explode('.', $result['extract']);
            $result['extract'] = $split[0];
            if (strlen($result['extract']) > 390) {
                $result['extract'] = substr($result['extract'], 0, 300);
            }
        }

        return $result;
    }

    private function getWikipediaImage(string $languageCode, string $keyWord): array
    {
        $response = Http::get("https://{$languageCode}.wikipedia.org/w/api.php", [
            'action' => 'query',
            'prop' => 'pageimages',
            'format' => 'json',
            'piprop' => 'original',
            'titles' => $keyWord
        ]);

        $data = $response->json()['query']['pages'];
        $result = array_values($data)[0];
        
        if (!isset($result['original'])) {
            $result['original'] = ['source' => '/img/azar.png'];
        }

        return $result;
    }

    private function prepareContent(string $languageCode, string $text): array
    {
        if ($languageCode === "fa") {
            return [
                'summarize' => "توضیحی ای در مورد این متن ارائه دهید. متن: " . $text,
                'article' => "مقاله ای در مورد : " . $text
            ];
        }

        return [
            'summarize' => "explanation of this text . text is: " . $text,
            'article' => "Write an article about" . $text
        ];
    }

    private function getGptResponse(string $content): string
    {
        $response = $this->openAi->chat([
            'model' => 'gpt-3.5-turbo',
            'messages' => [
                [
                    "role" => "user",
                    "content" => $content
                ],
            ],
            'temperature' => 1.0,
            'frequency_penalty' => 0,
            'presence_penalty' => 0,
        ]);

        $data = json_decode($response, true);
        return $data['choices'][0]['message']['content'];
    }

    private function updatePopularSearches(string $keyWord, string $summarize, array $imageData): void
    {
        $popularSearch = PopularSearchs::firstOrNew(['key_word' => $keyWord]);
        
        if ($popularSearch->exists) {
            $popularSearch->increment('views');
        } else {
            $popularSearch->fill([
                "text" => implode(' ', array_slice(explode(' ', $summarize), 0, 10)),
                "img_src" => $imageData['original']['source'],
                "views" => 1
            ])->save();
        }
    }
}
