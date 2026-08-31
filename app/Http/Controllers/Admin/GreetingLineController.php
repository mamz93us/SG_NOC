<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\GreetingLine;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Admin → Greeting Lines.
 *
 * The warm sub-line under "Good morning, {name}" on the home portal. One table,
 * saved in a single submit — this is copy, not configuration, and it should be
 * as easy to reword as a document.
 */
class GreetingLineController extends Controller
{
    public function index(): View
    {
        return view('admin.greeting-lines.index', [
            'lines' => GreetingLine::orderBy('time_of_day')
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get(),
            'times' => GreetingLine::TIMES,
            'days' => GreetingLine::DAYS,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'text' => 'required|string|max:200',
            'text_ar' => 'nullable|string|max:200',
            'time_of_day' => ['nullable', Rule::in(GreetingLine::TIMES)],
            'day_of_week' => 'nullable|integer|min:0|max:6',
            'sort_order' => 'nullable|integer|min:0|max:9999',
        ]);

        $data['is_active'] = true;
        $data['sort_order'] ??= 0;

        GreetingLine::create($data);
        $this->flushCache();

        return back()->with('success', 'Greeting line added.');
    }

    public function update(Request $request, GreetingLine $greetingLine): RedirectResponse
    {
        $data = $request->validate([
            'text' => 'required|string|max:200',
            'text_ar' => 'nullable|string|max:200',
            'time_of_day' => ['nullable', Rule::in(GreetingLine::TIMES)],
            'day_of_week' => 'nullable|integer|min:0|max:6',
            'sort_order' => 'nullable|integer|min:0|max:9999',
        ]);

        $data['is_active'] = $request->boolean('is_active');
        $data['sort_order'] ??= 0;

        $greetingLine->update($data);
        $this->flushCache();

        return back()->with('success', 'Greeting line updated.');
    }

    public function destroy(GreetingLine $greetingLine): RedirectResponse
    {
        $greetingLine->delete();
        $this->flushCache();

        return back()->with('success', 'Greeting line deleted.');
    }

    /**
     * Greeter caches the eligible pool per time-of-day and weekday for 15
     * minutes. Twenty-one combinations, so clearing them all is cheap and
     * beats explaining to someone why their new line has not appeared.
     */
    private function flushCache(): void
    {
        try {
            foreach (GreetingLine::TIMES as $time) {
                for ($day = 0; $day <= 6; $day++) {
                    Cache::forget("home.greeting_lines.{$time}.{$day}");
                }
            }
        } catch (\Throwable) {
            // Entries expire on their own; never fail a save over this.
        }
    }
}
