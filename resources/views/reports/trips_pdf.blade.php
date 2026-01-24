<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Reporte de Viajes</title>
    <style>
        @page { margin: 20px; }
        body { font-family: sans-serif; font-size: 11px; color: #333; }
        
        .header { background-color: #1e293b; color: white; padding: 20px; border-radius: 6px; }
        .logo { height: 40px; float: right; }
        h1 { margin: 0; font-size: 18px; text-transform: uppercase; }
        
        /* KPI SECTION */
        .kpi-row { display: table; width: 100%; margin: 15px 0; background: #f1f5f9; padding: 10px; border-radius: 6px; }
        .kpi-cell { display: table-cell; text-align: center; border-right: 1px solid #cbd5e1; }
        .kpi-cell:last-child { border-right: none; }
        .kpi-val { font-size: 16px; font-weight: bold; color: #0f172a; display: block; }
        .kpi-lbl { font-size: 9px; color: #64748b; text-transform: uppercase; }

        /* DATA TABLE */
        table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        th { background-color: #0f172a; color: white; padding: 8px; text-align: left; font-size: 9px; text-transform: uppercase; }
        td { padding: 8px; border-bottom: 1px solid #e2e8f0; vertical-align: middle; }
        tr:nth-child(even) { background-color: #f8fafc; }
        
        .map-link { color: #3b82f6; text-decoration: none; font-size: 9px; background: #eff6ff; padding: 2px 5px; border-radius: 3px; border: 1px solid #bfdbfe; }
        .cost { color: #059669; font-weight: bold; }
    </style>
</head>
<body>

    <div class="header">
        @if($logo) <img src="{{ $logo }}" class="logo"> @endif
        <h1>Reporte Detallado de Viajes</h1>
        <div style="font-size: 10px; opacity: 0.8; margin-top: 4px;">
            {{ $device->name }} | {{ $range }}
        </div>
    </div>

    <div class="kpi-row">
        <div class="kpi-cell">
            <span class="kpi-val">{{ $summary['total_trips'] }}</span>
            <span class="kpi-lbl">Viajes Totales</span>
        </div>
        <div class="kpi-cell">
            <span class="kpi-val">{{ number_format($summary['total_km'], 2) }} km</span>
            <span class="kpi-lbl">Distancia Total</span>
        </div>
        <div class="kpi-cell">
            <span class="kpi-val">{{ gmdate("H\h i\m", $summary['total_time']) }}</span>
            <span class="kpi-lbl">Tiempo Conducción</span>
        </div>
        <div class="kpi-cell">
            <span class="kpi-val" style="color:#ef4444">{{ $summary['max_speed'] }} km/h</span>
            <span class="kpi-lbl">Vel. Máxima Reg.</span>
        </div>
    </div>

    <table>
        <thead>
            <tr>
                <th width="10%">Fecha</th>
                <th width="12%">Horario</th>
                <th width="10%">Duración</th>
                <th width="10%">Distancia</th>
                <th width="10%">Costo Est.</th>
                <th width="24%">Punto Inicial</th>
                <th width="24%">Punto Final</th>
            </tr>
        </thead>
        <tbody>
            @foreach($trips as $t)
            <tr>
                <td><strong>{{ $t['date'] }}</strong></td>
                <td>{{ $t['start_time'] }} - {{ $t['end_time'] }}</td>
                <td>{{ $t['duration'] }}</td>
                <td>{{ $t['distance'] }} km</td>
                <td><span class="cost">${{ $t['fuel_est'] }}</span></td>
                <td>
                    <a href="https://maps.google.com/?q={{ $t['start_lat'] }},{{ $t['start_lon'] }}" target="_blank" class="map-link">
                        📍 Ver en Mapa
                    </a><br>
                    <span style="color:#64748b; font-size:9px">{{ number_format($t['start_lat'], 5) }}, {{ number_format($t['start_lon'], 5) }}</span>
                </td>
                <td>
                    <a href="https://maps.google.com/?q={{ $t['end_lat'] }},{{ $t['end_lon'] }}" target="_blank" class="map-link">
                        🏁 Ver en Mapa
                    </a><br>
                    <span style="color:#64748b; font-size:9px">{{ number_format($t['end_lat'], 5) }}, {{ number_format($t['end_lon'], 5) }}</span>
                </td>
            </tr>
            @endforeach
        </tbody>
    </table>

    <div style="margin-top: 20px; font-size: 9px; color: #94a3b8; text-align: center;">
        * El costo estimado se basa en un rendimiento promedio de 10km/L y gasolina a $24.00/L.
    </div>

</body>
</html>