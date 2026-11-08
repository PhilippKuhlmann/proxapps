{{--
    Toasts bottom right. Two sources: the session flash after a normal request, and the
    browser event "notice" ({kind, text}) dispatched by Livewire components.
    Success hides itself after 4 s; errors stay until closed.
--}}
<div class="pointer-events-none fixed bottom-4 right-4 z-50 flex w-full max-w-sm flex-col items-end gap-2">
    <div x-data="toast('error', @js((string) session('error')))"
        x-on:notice.window="empfangen($event.detail)"
        x-show="show" x-cloak
        x-transition:enter="transition ease-out duration-200" x-transition:enter-start="opacity-0 translate-y-2"
        x-transition:leave="transition ease-in duration-150" x-transition:leave-end="opacity-0 translate-y-2"
        class="pointer-events-auto flex items-start p-4 w-full max-w-sm text-gray-700 bg-white border border-red-200 rounded-xl shadow-lg dark:text-gray-200 dark:bg-gray-800 dark:border-red-900"
        role="alert">
        <div class="inline-flex shrink-0 justify-center items-center w-8 h-8 text-red-500 bg-red-100 rounded-lg dark:bg-red-800 dark:text-red-200">
            <svg aria-hidden="true" class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" clip-rule="evenodd" d="M18 10A8 8 0 11 2 10a8 8 0 0116 0zm-9-4a1 1 0 012 0v5a1 1 0 01-2 0V6zm1 9a1.25 1.25 0 110-2.5 1.25 1.25 0 010 2.5z"/></svg>
        </div>
        <div class="ml-3 mr-2 self-center text-sm font-normal wrap-anywhere" x-text="text"></div>
        <button type="button" x-on:click="show = false"
            class="ml-auto -mx-1.5 -my-1.5 shrink-0 bg-white text-gray-400 hover:text-gray-900 rounded-lg focus:ring-2 focus:ring-gray-300 p-1.5 hover:bg-gray-100 inline-flex h-8 w-8 dark:text-gray-500 dark:hover:text-white dark:bg-gray-800 dark:hover:bg-gray-700">
            <span class="sr-only">Schließen</span>
            <svg aria-hidden="true" class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" clip-rule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z"/></svg>
        </button>
    </div>

    <div x-data="toast('success', @js((string) session('success')))"
        x-on:notice.window="empfangen($event.detail)"
        x-show="show" x-cloak
        x-transition:enter="transition ease-out duration-200" x-transition:enter-start="opacity-0 translate-y-2"
        x-transition:leave="transition ease-in duration-150" x-transition:leave-end="opacity-0 translate-y-2"
        class="pointer-events-auto flex items-center p-4 w-full max-w-xs text-gray-700 bg-white border border-gray-100 rounded-xl shadow-lg dark:text-gray-200 dark:bg-gray-800 dark:border-gray-700"
        role="status" aria-live="polite">
        <div class="inline-flex shrink-0 justify-center items-center w-8 h-8 text-green-500 bg-green-100 rounded-lg dark:bg-green-800 dark:text-green-200">
            <svg aria-hidden="true" class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" clip-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z"/></svg>
        </div>
        <div class="ml-3 mr-2 text-sm font-normal" x-text="text"></div>
        <button type="button" x-on:click="show = false"
            class="ml-auto -mx-1.5 -my-1.5 shrink-0 bg-white text-gray-400 hover:text-gray-900 rounded-lg focus:ring-2 focus:ring-gray-300 p-1.5 hover:bg-gray-100 inline-flex h-8 w-8 dark:text-gray-500 dark:hover:text-white dark:bg-gray-800 dark:hover:bg-gray-700">
            <span class="sr-only">Schließen</span>
            <svg aria-hidden="true" class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" clip-rule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z"/></svg>
        </button>
    </div>
</div>
