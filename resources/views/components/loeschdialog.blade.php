{{--
    The confirmation before destructive actions - a sheet of the application instead of
    the browser's confirm(). One instance lives in the layout and is driven by
    Alpine.store('confirm'):  $store.confirm.ask({ title, text, button, ok: () => $wire.delete(1) })

    Cancel gets the focus, not the red button: whoever dismisses the dialog with the
    keyboard must not delete anything. Escape and a click on the backdrop do the same.
--}}
<div x-data x-show="$store.confirm.open" x-cloak
    x-on:keydown.escape.window="$store.confirm.cancel()"
    class="fixed inset-0 z-50 flex items-center justify-center p-4">

    <div x-show="$store.confirm.open" x-transition.opacity.duration.150ms
        x-on:click="$store.confirm.cancel()" aria-hidden="true"
        class="absolute inset-0 bg-gray-900/60 dark:bg-black/70"></div>

    <div x-show="$store.confirm.open"
        x-transition:enter="ease-out duration-150"
        x-transition:enter-start="opacity-0 scale-95"
        x-transition:enter-end="opacity-100 scale-100"
        x-transition:leave="ease-in duration-100"
        x-transition:leave-start="opacity-100 scale-100"
        x-transition:leave-end="opacity-0 scale-95"
        role="dialog" aria-modal="true" x-bind:aria-label="$store.confirm.title"
        class="relative w-full max-w-md rounded-lg border border-gray-200 bg-white shadow-xl
               dark:border-gray-700 dark:bg-gray-800">

        <div class="flex gap-4 p-6">
            <span x-bind:class="$store.confirm.tone === 'blue'
                    ? 'bg-cerulean-100 text-cerulean-600 dark:bg-cerulean-900 dark:text-cerulean-300'
                    : 'bg-red-100 text-red-600 dark:bg-red-900 dark:text-red-400'"
                class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full" aria-hidden="true">
                <template x-if="$store.confirm.tone === 'blue'"><x-icon name="refresh" class="h-5 w-5" /></template>
                <template x-if="$store.confirm.tone !== 'blue'"><x-icon name="trash" class="h-5 w-5" /></template>
            </span>
            <div class="min-w-0 flex-1">
                <h2 class="font-DINPro-bold text-gray-900 dark:text-gray-100" x-text="$store.confirm.title"></h2>
                <p x-show="$store.confirm.text" class="mt-2 text-sm leading-relaxed text-gray-500 dark:text-gray-400"
                    x-text="$store.confirm.text"></p>
            </div>
        </div>

        <div class="flex justify-end gap-2 border-t border-gray-200 px-6 py-4 dark:border-gray-700">
            <x-input.button type="button" color="gray" id="confirm-cancel" x-on:click="$store.confirm.cancel()" label="Abbrechen" />
            <button type="button" x-on:click="$store.confirm.ok()"
                x-bind:class="$store.confirm.tone === 'blue'
                    ? 'bg-cerulean-600 hover:bg-cerulean-700 focus:ring-cerulean-500'
                    : 'bg-red-600 hover:bg-red-700 focus:ring-red-500'"
                class="items-center justify-center gap-1.5 rounded-lg font-DINPro-bold shadow-xs transition-colors focus:outline-hidden focus:ring-2 focus:ring-offset-2 disabled:opacity-50 inline-flex px-4 py-2 text-sm text-white dark:focus:ring-offset-gray-800"
                x-text="$store.confirm.button"></button>
        </div>
    </div>
</div>
