<!DOCTYPE html>
<html>
<head>
    <title>Reporte de Alarmas</title>
    <style>
        body { font-family: sans-serif; font-size: 12px; color: #333; }
        .header { text-align: center; margin-bottom: 20px; border-bottom: 2px solid #3b82f6; padding-bottom: 10px; }
        .header h1 { margin: 0; color: #1e293b; font-size: 20px; text-transform: uppercase; }
        .meta { width: 100%; margin-bottom: 20px; }
        .meta td { padding: 4px; }
        .label { font-weight: bold; color: #64748b; }
        
        table.data { width: 100%; border-collapse: collapse; }
        table.data th { background-color: #f1f5f9; color: #1e293b; padding: 8px; text-align: left; border-bottom: 2px solid #cbd5e1; font-size: 10px; text-transform: uppercase; }
        table.data td { padding: 8px; border-bottom: 1px solid #e2e8f0; }
        
        .type-badge { padding: 2px 6px; border-radius: 4px; font-size: 10px; font-weight: bold; background: #eee; }
        .coord { font-family: monospace; color: #64748b; font-size: 10px; }
        .link { color: #3b82f6; text-decoration: none; }
        
        /* Colores por tipo (Opcional) */
        .type-OVERSPEED { color: #dc2626; background: #fecaca; }
        .type-SOS { color: #991b1b; background: #fee2e2; }
    </style>
</head>
<body>

    <div class="header">
        <h1>Reporte de Historial de Alarmas</h1>
        <p>Generado el: {{ $date_now }}</p>
    </div>

    <table class="meta">
        <tr>
            <td class="label">Unidad / Dispositivo:</td>
            <td><strong>{{ $device->name }}</strong> (IMEI: {{ $device->imei }})</td>
            <td class="label">Rango de Fechas:</td>
            <td>{{ $start_date }} al {{ $end_date }}</td>
        </tr>
    </table>

    <table class="data">
        <thead>
            <tr>
                <th width="15%">Fecha / Hora</th>
                <th width="15%">Tipo</th>
                <th width="35%">Mensaje</th>
                <th width="10%">Velocidad</th>
                <th width="25%">Ubicación</th>
            </tr>
        </thead>
        <tbody>
            @forelse($alarms as $alarm)
            <tr>
                <td>
                    {{ $alarm->formatted_date->format('Y-m-d') }}<br>
                    <small style="color:#666">{{ $alarm->formatted_date->format('H:i:s') }}</small>
                </td>
                <td>
                    <span class="type-badge type-{{ $alarm->type }}">{{ $alarm->type }}</span>
                </td>
                <td>{{ $alarm->message }}</td>
                <td>{{ round($alarm->speed) }} km/h</td>
                <td>
                    <span class="coord">{{ number_format($alarm->lat, 5) }}, {{ number_format($alarm->lon, 5) }}</span><br>
                    @if($alarm->lat != 0)
                        <a href="{{ $alarm->maps_link }}" target="_blank" class="link">Ver en Mapa</a>
                    @endif
                </td>
            </tr>
            @empty
            <tr>
                <td colspan="5" style="text-align: center; padding: 20px; color: #999;">
                    No se encontraron alarmas en este periodo.
                </td>
            </tr>
            @endforelse
        </tbody>
    </table>

</body>
</html>