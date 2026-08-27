@php
    // Saudação em vez de um título genérico: o dashboard é a primeira tela do
    // dia e o topo da página é o espaço mais caro da interface — gastá-lo
    // repetindo o nome do menu não informa nada.
    $sessionUser = \App\Support\DesktopSession::user();
    $fullName = trim((string) ($sessionUser['nome'] ?? ''));
    $firstName = $fullName !== '' ? explode(' ', $fullName)[0] : '';
    $hour = (int) now()->format('G');
    $greeting = $hour < 5 ? 'Boa noite' : ($hour < 12 ? 'Bom dia' : ($hour < 18 ? 'Boa tarde' : 'Boa noite'));

    // Nomes escritos à mão em vez de translatedFormat(): APP_LOCALE só é pt_BR
    // no ambiente real, e a suíte roda com o default 'en' — a data do topo não
    // deve mudar de idioma conforme a configuração.
    $now = \Carbon\CarbonImmutable::now();
    $weekdays = ['Domingo', 'Segunda-feira', 'Terça-feira', 'Quarta-feira', 'Quinta-feira', 'Sexta-feira', 'Sábado'];
    $months = ['janeiro', 'fevereiro', 'março', 'abril', 'maio', 'junho', 'julho', 'agosto', 'setembro', 'outubro', 'novembro', 'dezembro'];
    $today = sprintf(
        '%s, %d de %s de %d',
        $weekdays[(int) $now->format('w')],
        (int) $now->format('j'),
        $months[(int) $now->format('n') - 1],
        (int) $now->format('Y')
    );
@endphp

<section class="desktop-page-hero dashboard-page-hero">
    <div class="desktop-page-hero-copy">
        <h2>{{ $greeting }}{{ $firstName !== '' ? ', ' . $firstName : '' }} !</h2>
        <p>{{ $today }}</p>
    </div>

    <div class="desktop-hero-actions">
        <x-favorite-toggle />

        <a href="{{ route('dashboard.help') }}" class="btn btn-outline-light">
            <i class="bi bi-question-circle me-1"></i>
            Ajuda do dashboard
        </a>
    </div>
</section>
