<article class="dashboard-panel dashboard-panel-lg dashboard-equipment-stacked-panel" data-dashboard-equipment-panel>
    <div class="dashboard-panel-head">
        <div>
            <h2>Tipos de Equipamento</h2>
            <p>Entradas mensais por tipo, empilhadas para evidenciar volume e composição.</p>
        </div>

        <div class="dashboard-filter-inline">
            <label for="dashboardEquipmentYear">Ano</label>
            <select id="dashboardEquipmentYear" class="form-select form-select-sm" data-dashboard-equipment-year-filter>
                @foreach ($equipmentYears as $year)
                    <option value="{{ $year }}" @selected((int) $year === (int) ($filters['equipmentYear'] ?? $year))>
                        {{ $year }}
                    </option>
                @endforeach
            </select>
        </div>
    </div>

    <div class="dashboard-chart-wrap dashboard-chart-wrap-stacked">
        <canvas id="dashboardEquipmentChart" aria-label="Entradas mensais por tipo de equipamento" role="img"></canvas>
    </div>

    {{-- Legenda em faixa única com rolagem própria: o backend já limita a 8
         tipos + "Outros" (EQUIPMENT_CHART_MAX_TYPES), mas nove chips quebravam
         em duas fileiras e empurravam o resto da página para baixo. --}}
    <div class="dashboard-equipment-legend" data-dashboard-equipment-legend></div>
</article>
