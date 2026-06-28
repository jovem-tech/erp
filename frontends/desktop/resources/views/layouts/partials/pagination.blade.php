@php
    $currentPage = (int) ($pagination['current_page'] ?? 1);
    $lastPage = max(1, (int) ($pagination['last_page'] ?? 1));
    $from = (int) ($pagination['from'] ?? 0);
    $to = (int) ($pagination['to'] ?? 0);
    $total = (int) ($pagination['total'] ?? 0);
    $query = collect($filters ?? request()->query())->except('page')->all();
    $pages = range(max(1, $currentPage - 2), min($lastPage, $currentPage + 2));
@endphp

@if ($lastPage > 1)
    <div class="desktop-pagination">
        <div class="desktop-pagination-copy">
            Mostrando <strong>{{ $from }}</strong> a <strong>{{ $to }}</strong> de <strong>{{ $total }}</strong> registros
        </div>

        <nav aria-label="Paginação">
            <ul class="pagination pagination-sm mb-0">
                <li class="page-item {{ $currentPage <= 1 ? 'disabled' : '' }}">
                    <a class="page-link" href="{{ $currentPage <= 1 ? '#' : request()->fullUrlWithQuery([...$query, 'page' => $currentPage - 1]) }}">Anterior</a>
                </li>

                @foreach ($pages as $page)
                    <li class="page-item {{ $page === $currentPage ? 'active' : '' }}">
                        <a class="page-link" href="{{ request()->fullUrlWithQuery([...$query, 'page' => $page]) }}">{{ $page }}</a>
                    </li>
                @endforeach

                <li class="page-item {{ $currentPage >= $lastPage ? 'disabled' : '' }}">
                    <a class="page-link" href="{{ $currentPage >= $lastPage ? '#' : request()->fullUrlWithQuery([...$query, 'page' => $currentPage + 1]) }}">Próxima</a>
                </li>
            </ul>
        </nav>
    </div>
@endif
