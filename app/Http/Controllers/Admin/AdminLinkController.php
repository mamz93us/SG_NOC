<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\AdminLink;
use App\Models\AdminLinkCategory;
use App\Models\AdminLinkClick;
use App\Models\UserFavoriteLink;
use Illuminate\Http\Request;

class AdminLinkController extends Controller
{
    public function index(Request $request)
    {
        $search = $request->input('search');
        $categoryFilter = $request->input('category');

        $categories = AdminLinkCategory::orderBy('sort_order')->get();

        $linksQuery = AdminLink::with('category', 'clicks')
            ->active()
            ->when($search, fn ($q) => $q->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('description', 'like', "%{$search}%")
                  ->orWhere('url', 'like', "%{$search}%");
            }))
            ->when($categoryFilter, fn ($q) => $q->where('category_id', $categoryFilter))
            ->orderBy('sort_order');

        $links = $linksQuery->get()->groupBy('category_id');

        $favoriteIds = UserFavoriteLink::where('user_id', auth()->id())
            ->pluck('link_id')
            ->toArray();

        $favorites = AdminLink::with('category')
            ->active()
            ->whereIn('id', $favoriteIds)
            ->orderBy('name')
            ->get();

        $topLinks = AdminLinkClick::selectRaw('link_id, COUNT(*) as click_count')
            ->groupBy('link_id')
            ->orderByDesc('click_count')
            ->limit(10)
            ->with('link')
            ->get();

        return view('admin.admin-links.index', compact(
            'categories', 'links', 'favoriteIds', 'favorites',
            'topLinks', 'search', 'categoryFilter'
        ));
    }

    public function manage(Request $request)
    {
        $categories = AdminLinkCategory::withCount('links')->orderBy('sort_order')->get();
        $links = AdminLink::with('category', 'creator')->orderBy('sort_order')->paginate(20);

        return view('admin.admin-links.manage', compact('categories', 'links'));
    }

    public function create()
    {
        $categories = AdminLinkCategory::orderBy('sort_order')->get();
        return view('admin.admin-links.create', compact('categories'));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name'        => 'required|string|max:255',
            'category_id' => 'required|exists:admin_link_categories,id',
            'url'         => 'required|url|max:500',
            'description' => 'nullable|string|max:255',
            'icon'        => 'nullable|string|max:100',
            'sort_order'  => 'nullable|integer|min:0',
            'is_active'   => 'nullable|boolean',
        ]);

        $validated['is_active']   = $request->boolean('is_active');
        $validated['created_by']  = auth()->id();
        $validated['sort_order']  = $validated['sort_order'] ?? 0;

        $link = AdminLink::create($validated);

        ActivityLog::create([
            'model_type' => AdminLink::class,
            'model_id'   => $link->id,
            'action'     => 'admin_link_created',
            'changes'    => $link->toArray(),
            'user_id'    => auth()->id(),
        ]);

        return redirect()->route('admin.admin-links.manage')
            ->with('success', 'Admin link created successfully.');
    }

    public function edit(AdminLink $adminLink)
    {
        $categories = AdminLinkCategory::orderBy('sort_order')->get();
        return view('admin.admin-links.edit', compact('adminLink', 'categories'));
    }

    public function update(Request $request, AdminLink $adminLink)
    {
        $validated = $request->validate([
            'name'        => 'required|string|max:255',
            'category_id' => 'required|exists:admin_link_categories,id',
            'url'         => 'required|url|max:500',
            'description' => 'nullable|string|max:255',
            'icon'        => 'nullable|string|max:100',
            'sort_order'  => 'nullable|integer|min:0',
            'is_active'   => 'nullable|boolean',
        ]);

        $validated['is_active']  = $request->boolean('is_active');
        $validated['sort_order'] = $validated['sort_order'] ?? 0;

        $before = $adminLink->only(array_keys($validated));
        $adminLink->update($validated);

        ActivityLog::create([
            'model_type' => AdminLink::class,
            'model_id'   => $adminLink->id,
            'action'     => 'admin_link_updated',
            'changes'    => ['old' => $before, 'new' => $adminLink->getChanges()],
            'user_id'    => auth()->id(),
        ]);

        return redirect()->route('admin.admin-links.manage')
            ->with('success', 'Admin link updated successfully.');
    }

    public function destroy(AdminLink $adminLink)
    {
        $snapshot = $adminLink->toArray();
        $id       = $adminLink->id;
        $adminLink->delete();

        ActivityLog::create([
            'model_type' => AdminLink::class,
            'model_id'   => $id,
            'action'     => 'admin_link_deleted',
            'changes'    => $snapshot,
            'user_id'    => auth()->id(),
        ]);

        return redirect()->route('admin.admin-links.manage')
            ->with('success', 'Admin link deleted successfully.');
    }

    // ── Category CRUD ────────────────────────────────────────────────

    public function storeCategory(Request $request)
    {
        $validated = $request->validate([
            'name'       => 'required|string|max:255',
            'icon'       => 'nullable|string|max:100',
            'sort_order' => 'nullable|integer|min:0',
        ]);

        $validated['sort_order'] = $validated['sort_order'] ?? 0;

        $category = AdminLinkCategory::create($validated);

        ActivityLog::create([
            'model_type' => AdminLinkCategory::class,
            'model_id'   => $category->id,
            'action'     => 'admin_link_category_created',
            'changes'    => $category->toArray(),
            'user_id'    => auth()->id(),
        ]);

        return redirect()->route('admin.admin-links.manage')
            ->with('success', 'Category created successfully.');
    }

    public function updateCategory(Request $request, AdminLinkCategory $category)
    {
        $validated = $request->validate([
            'name'       => 'required|string|max:255',
            'icon'       => 'nullable|string|max:100',
            'sort_order' => 'nullable|integer|min:0',
        ]);

        $before = $category->only(array_keys($validated));
        $category->update($validated);

        ActivityLog::create([
            'model_type' => AdminLinkCategory::class,
            'model_id'   => $category->id,
            'action'     => 'admin_link_category_updated',
            'changes'    => ['old' => $before, 'new' => $category->getChanges()],
            'user_id'    => auth()->id(),
        ]);

        return redirect()->route('admin.admin-links.manage')
            ->with('success', 'Category updated successfully.');
    }

    public function destroyCategory(AdminLinkCategory $category)
    {
        if ($category->links()->count() > 0) {
            return back()->with('error', 'Cannot delete category with existing links. Move or delete them first.');
        }

        $snapshot = $category->toArray();
        $id       = $category->id;
        $category->delete();

        ActivityLog::create([
            'model_type' => AdminLinkCategory::class,
            'model_id'   => $id,
            'action'     => 'admin_link_category_deleted',
            'changes'    => $snapshot,
            'user_id'    => auth()->id(),
        ]);

        return redirect()->route('admin.admin-links.manage')
            ->with('success', 'Category deleted successfully.');
    }

    // ── Click Tracking ───────────────────────────────────────────────

    public function trackClick(AdminLink $adminLink)
    {
        AdminLinkClick::create([
            'link_id'    => $adminLink->id,
            'user_id'    => auth()->id(),
            'clicked_at' => now(),
        ]);

        return redirect()->away($adminLink->url);
    }

    // ── Favorites ────────────────────────────────────────────────────

    public function toggleFavorite(AdminLink $adminLink)
    {
        $userId = auth()->id();
        $existing = UserFavoriteLink::where('user_id', $userId)
            ->where('link_id', $adminLink->id)
            ->first();

        if ($existing) {
            $existing->delete();
            $message = 'Removed from favorites.';
        } else {
            UserFavoriteLink::create(['user_id' => $userId, 'link_id' => $adminLink->id]);
            $message = 'Added to favorites.';
        }

        return back()->with('success', $message);
    }
}
