@props(['check' => null, 'target'])

{{-- Live result of a VMID / IP check: green "frei", red reason; a pulse while checking. --}}
<span wire:loading.flex wire:target="{{ $target }}" class="hidden items-center gap-1 text-xs text-cerulean-600 dark:text-cerulean-400">
    <span class="h-1.5 w-1.5 rounded-full bg-cerulean-500 animate-pulse"></span>prüfe …
</span>
@if ($check)
    <span wire:loading.remove wire:target="{{ $target }}" @class([
        'inline-flex max-w-[16rem] items-center gap-1 truncate rounded px-1.5 py-0.5 text-[11px] font-semibold',
        'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-100' => $check['ok'],
        'bg-rose-100 text-rose-800 dark:bg-rose-900 dark:text-rose-100' => ! $check['ok'],
    ]) title="{{ $check['msg'] }}">
        <x-icon :name="$check['ok'] ? 'check' : 'warn'" class="h-3 w-3 shrink-0" sw="2.4" />{{ $check['msg'] }}
    </span>
@endif
