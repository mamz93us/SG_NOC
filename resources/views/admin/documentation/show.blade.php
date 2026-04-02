@extends('layouts.admin')

@section('title', $filename)

@section('content')
<div class="py-4">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">

        {{-- Toolbar --}}
        <div class="flex items-center justify-between mb-4 gap-3 flex-wrap">
            <div class="flex items-center gap-3">
                <a href="{{ route('admin.documentation.index') }}"
                   class="inline-flex items-center gap-1.5 text-sm text-gray-500 dark:text-gray-400 hover:text-gray-800 dark:hover:text-gray-100 transition">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/></svg>
                    Documentation
                </a>
                <span class="text-gray-300 dark:text-gray-600">/</span>
                <span class="text-sm font-medium text-gray-800 dark:text-gray-200 truncate max-w-xs" title="{{ $filename }}">{{ $filename }}</span>
            </div>

            <div class="flex items-center gap-2">
                <a href="{{ route('admin.documentation.raw', $filename) }}" target="_blank"
                   class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-medium bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-300 hover:bg-gray-200 dark:hover:bg-gray-600 rounded-lg transition">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"/></svg>
                    Open in new tab
                </a>

                @can('manage-documentation')
                <form method="POST" action="{{ route('admin.documentation.destroy', $filename) }}"
                      onsubmit="return confirm('Delete this document?')">
                    @csrf @method('DELETE')
                    <button type="submit"
                        class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-medium bg-red-50 dark:bg-red-900/30 text-red-600 dark:text-red-400 hover:bg-red-100 dark:hover:bg-red-900/50 rounded-lg transition">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                        Delete
                    </button>
                </form>
                @endcan
            </div>
        </div>

        {{-- HTML viewer — sandboxed iframe using srcdoc --}}
        <div class="rounded-xl border border-gray-200 dark:border-gray-700 overflow-hidden shadow-sm bg-white dark:bg-gray-900" style="height: calc(100vh - 180px);">
            <iframe
                srcdoc="{{ htmlspecialchars($html, ENT_QUOTES, 'UTF-8') }}"
                sandbox="allow-same-origin allow-scripts"
                class="w-full h-full border-0"
                title="{{ $filename }}"
            ></iframe>
        </div>

    </div>
</div>
@endsection
