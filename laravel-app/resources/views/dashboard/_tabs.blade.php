<div class="flex items-center gap-6 border-b mb-6 text-sm font-medium">
    <a href="{{ route('dashboard.online') }}"
       class="pb-3 -mb-px border-b-2 {{ request()->routeIs('dashboard.online') ? 'border-slate-900 text-slate-900' : 'border-transparent text-slate-500 hover:text-slate-700' }}">
        Dashboard Online
    </a>
    <a href="{{ route('dashboard.offline') }}"
       class="pb-3 -mb-px border-b-2 {{ request()->routeIs('dashboard.offline') ? 'border-slate-900 text-slate-900' : 'border-transparent text-slate-500 hover:text-slate-700' }}">
        Dashboard Offline
    </a>
</div>
