@push('sidebar-menu')
@if(auth()->check())
<a href="{{ route('wptoolkit.index') }}"
   class="flex items-center px-3 py-2 rounded-lg text-sm {{ request()->routeIs('wptoolkit.*') ? 'bg-gray-800 text-white' : 'text-gray-400 hover:text-white hover:bg-gray-800' }}">
    <svg class="w-5 h-5 mr-3 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/>
    </svg>
    WP Toolkit
</a>
@endif
@endpush
