{{-- resources/views/portal.blade.php --}}
<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">Grafana</h2>
    </x-slot>

    <iframe
        src="{{ route('grafana.proxy', 'dashboards') }}?orgId={{ auth()->user()->grafana_org_id }}"
        class="flex-1 w-full border-0"
        loading="lazy"
        referrerpolicy="no-referrer"
    ></iframe>
</x-app-layout>
