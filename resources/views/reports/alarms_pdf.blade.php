<!DOCTYPE html>
<html>
<head>
    <title>Reporte de Alarmas</title>
    <style>
        body { font-family: 'Helvetica', sans-serif; font-size: 11px; color: #333; margin: 0; padding: 0; }
        
        /* HEADER PRINCIPAL - DISEÑO INVERTIDO */
        .header-table { width: 100%; border-bottom: 2px solid #3b82f6; padding-bottom: 15px; margin-bottom: 20px; }
        
        /* 1. TEXTO A LA IZQUIERDA */
        .info-container { 
            text-align: left; 
            width: 60%; 
            vertical-align: middle; 
        }
        
        /* 2. LOGO A LA DERECHA Y MÁS GRANDE */
        .logo-container { 
            text-align: right; 
            width: 40%; 
            vertical-align: middle; 
        }
        
        .logo-img { 
            max-height: 85px; /* 🔥 AUMENTADO (Antes 50px) */
            width: auto; 
        }
        
        .report-title { font-size: 22px; font-weight: bold; color: #1e293b; text-transform: uppercase; margin: 0 0 5px 0; }
        .meta-text { font-size: 12px; color: #64748b; margin: 2px 0; }

        /* TABLA DATOS */
        table.data { width: 100%; border-collapse: collapse; margin-top: 10px; }
        table.data th { 
            background-color: #f1f5f9; 
            color: #1e293b; 
            padding: 10px 6px; 
            text-align: left; 
            border-bottom: 2px solid #cbd5e1; 
            font-size: 10px; 
            font-weight: bold;
            text-transform: uppercase; 
        }
        table.data td { padding: 8px 6px; border-bottom: 1px solid #e2e8f0; vertical-align: top; }
        
        /* CELDAS */
        .date-cell { white-space: nowrap; font-weight: bold; color: #334155; }
        .time-small { font-size: 9px; color: #64748b; font-weight: normal; display: block; }
        .type-badge { font-weight: bold; color: #0f172a; font-size: 11px; }
        .message-text { color: #475569; }
        .coord-text { font-family: monospace; font-size: 9px; color: #94a3b8; display: block; margin-top: 2px;}
        .map-link { color: #3b82f6; text-decoration: none; font-size: 10px; font-weight: bold; }
        
        /* Footer */
        .footer { position: fixed; bottom: 0; left: 0; right: 0; font-size: 9px; color: #cbd5e1; text-align: center; border-top: 1px solid #f1f5f9; padding-top: 8px; }
    </style>
</head>
<body>

    <table class="header-table">
        <tr>
            <td class="info-container">
                <h1 class="report-title">Historial de Alarmas</h1>
                <p class="meta-text"><strong>Unidad:</strong> {{ $device->name }} ({{ $device->imei ?? 'S/N' }})</p>
                <p class="meta-text"><strong>Periodo:</strong> {{ $start_date }} - {{ $end_date }}</p>
                <p class="meta-text">Generado: {{ $date_now }}</p>
            </td>

            <td class="logo-container">
                @if($logo)
                    <img src="{{ $logo }}" class="logo-img"/>
                @else
                    <h2 style="color:#ccc;">SOLHEX</h2>
                @endif
            </td>
        </tr>
    </table>

    <table class="data">
        <thead>
            <tr>
                <th width="12%">Fecha</th>
                <th width="18%">Evento</th>
                <th width="35%">Mensaje</th>
                <th width="8%">Km/h</th>
                <th width="27%">Ubicación</th>
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
                <td style="font-weight:bold; color: #475569;">
                    {{ $alarm->speed_kmh }}
                </td>
                <td>
                    @if($alarm->lat != 0)
                        <a href="{{ $alarm->maps_link }}" target="_blank" class="map-link">
                            ? Ver en Mapa
                        </a>
                    @endif
                    <span class="coord-text">{{ number_format($alarm->lat, 5) }}, {{ number_format($alarm->lon, 5) }}</span>
                </td>
            </tr>
            @empty
            <tr>
                <td colspan="5" style="text-align: center; padding: 40px; color: #94a3b8;">
                    No existen alarmas registradas para el periodo seleccionado.
                </td>
            </tr>
            @endforelse
        </tbody>
    </table>

    <div class="footer">
        Reporte generado por Plataforma TRACK GPX

</body>
</html>