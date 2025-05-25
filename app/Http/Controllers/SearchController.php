<?php

namespace App\Http\Controllers;

use Inertia\Inertia;
use App\Models\History;
use App\Models\PopularSearchs;
use Illuminate\Support\Facades\Auth;
use Carbon\Carbon;

class SearchController extends Controller
{
    /**
     * Display search results for a keyword
     *
     * @param string $key_word
     * @return \Inertia\Response
     */
    public function show(string $key_word)
    {
        $this->saveSearchHistory($key_word);

        return Inertia::render('Search', [
            "key_word" => $key_word,
        ]);
    }

    /**
     * Display top searches from the last week
     *
     * @return \Inertia\Response
     */
    public function top()
    {
        $searches = $this->getPopularSearchesLastWeek();

        return Inertia::render('Top', [
            "tops" => $searches
        ]);
    }

    /**
     * Save search history for authenticated users
     *
     * @param string $keyword
     * @return void
     */
    protected function saveSearchHistory(string $keyword): void
    {
        if (Auth::check()) {
            History::firstOrCreate([
                'user_id' => Auth::id(),
                'search_text' => $keyword
            ]);
        }
    }

    /**
     * Get popular searches from the last 7 days
     *
     * @return \Illuminate\Database\Eloquent\Collection
     */
    protected function getPopularSearchesLastWeek()
    {
        return PopularSearchs::where('created_at', '>=', Carbon::today()->subDays(7))
            ->orderBy('views', 'desc')
            ->take(10)
            ->get();
    }
}
