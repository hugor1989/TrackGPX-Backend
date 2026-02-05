<!DOCTYPE html>
<html>
<head>
    <title>Reporte de Alarmas</title>
    <style>
        body { font-family: 'Helvetica', sans-serif; font-size: 11px; color: #333; margin: 0; padding: 0; }
        
        /* HEADER PRINCIPAL */
        .header-table { width: 100%; border-bottom: 2px solid #3b82f6; padding-bottom: 10px; margin-bottom: 15px; }
        .logo-container { text-align: left; width: 30%; }
        .logo-img { max-height: 50px; width: auto; }
        .info-container { text-align: right; width: 70%; vertical-align: middle; }
        
        .report-title { font-size: 18px; font-weight: bold; color: #1e293b; text-transform: uppercase; margin: 0; }
        .meta-text { font-size: 11px; color: #64748b; margin: 2px 0; }

        /* TABLA DATOS */
        table.data { width: 100%; border-collapse: collapse; margin-top: 10px; }
        table.data th { 
            background-color: #f1f5f9; 
            color: #1e293b; 
            padding: 8px 6px; 
            text-align: left; 
            border-bottom: 2px solid #cbd5e1; 
            font-size: 10px; 
            font-weight: bold;
            text-transform: uppercase; 
        }
        table.data td { padding: 6px; border-bottom: 1px solid #e2e8f0; vertical-align: top; }
        
        /* ESTILOS DE CELDAS */
        .date-cell { white-space: nowrap; }
        .time-small { font-size: 9px; color: #64748b; display: block; }
        .type-badge { font-weight: bold; color: #0f172a; font-size: 10px; }
        .message-text { color: #475569; }
        .coord-text { font-family: monospace; font-size: 9px; color: #94a3b8; }
        .map-link { color: #3b82f6; text-decoration: none; font-size: 10px; font-weight: bold; }
        
        /* Footer */
        .footer { position: fixed; bottom: 0; left: 0; right: 0; font-size: 9px; color: #aaa; text-align: center; border-top: 1px solid #eee; padding-top: 5px; }
    </style>
</head>
<body>

    <table class="header-table">
        <tr>
            <td class="logo-container">
                @if($logo)
                    <img src="{{ $logo }}" class="logo-img"/>
                @else
                    <h2>SOLHEX</h2>
                @endif
            </td>
            <td class="info-container">
                <h1 class="report-title">Historial de Alarmas</h1>
                <p class="meta-text"><strong>Unidad:</strong> {{ $device->name }} ({{ $device->imei ?? 'S/N' }})</p>
                <p class="meta-text"><strong>Periodo:</strong> {{ $start_date }} - {{ $end_date }}</p>
                <p class="meta-text">Generado: {{ $date_now }}</p>
            </td>
        </tr>
    </table>

    <table class="data">
        <thead>
            <tr>
                <th width="12%">Fecha</th>
                <th width="15%">Evento</th>
                <th width="35%">Mensaje</th>
                <th width="8%">Km/h</th>
                <th width="30%">Ubicación</th>
            </tr>
        </thead>
        <tbody>
            @forelse($alarms as $alarm)
            <tr>
                <td class="date-cell">
                    {{ $alarm->formatted_date->format('d/m/Y') }}
                    <span class="time-small">{{ $alarm->formatted_date->format('H:i:s') }}</span>
                </td>
                <td>
                    <span class="type-badge">{{ $alarm->type_label }}</span>
                </td>
                <td class="message-text">
                    {{ $alarm->message }}
                </td>
                <td>
                    {{ $alarm->speed }}
                </td>
                <td>
                    @if($alarm->lat != 0)
                        <a href="{{ $alarm->maps_link }}" target="_blank" class="map-link">
                            📍 Ver en Mapa
                        </a>
                        <br>
                    @endif
                    <span class="coord-text">{{ number_format($alarm->lat, 5) }}, {{ number_format($alarm->lon, 5) }}</span>
                </td>
            </tr>
            @empty
            <tr>
                <td colspan="5" style="text-align: center; padding: 30px; color: #94a3b8;">
                    No existen alarmas registradas para el periodo y filtros seleccionados.
                </td>
            </tr>
            @endforelse
        </tbody>
    </table>

    <div class="footer">
        Reporte generado por Plataforma SOLHEX - Software & Cloud Solutions
    </div>

</body>
</html>