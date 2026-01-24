<!DOCTYPE html>
<html>
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <title>Reporte de Actividad</title>
    <style>
        body { font-family: sans-serif; color: #334155; }
        .header-bg { background-color: #0f172a; color: white; padding: 20px; border-radius: 8px; margin-bottom: 20px; }
        .logo { float: right; height: 50px; } /* Ajusta según tu logo */
        h1 { margin: 0; font-size: 24px; text-transform: uppercase; }
        .meta { font-size: 12px; color: #94a3b8; margin-top: 5px; }
        
        .kpi-row { width: 100%; margin-bottom: 20px; }
        .kpi-box { width: 30%; display: inline-block; background: #f1f5f9; padding: 10px; border-radius: 6px; text-align: center; }
        .kpi-val { font-size: 18px; font-weight: bold; color: #0f172a; display: block; }
        .kpi-lbl { font-size: 10px; color: #64748b; text-transform: uppercase; }

        table { width: 100%; border-collapse: collapse; font-size: 12px; }
        th { background: #3b82f6; color: white; padding: 10px; text-align: left; }
        td { padding: 10px; border-bottom: 1px solid #e2e8f0; }
        tr:nth-child(even) { background-color: #f8fafc; }
        
        .badge { background: #dbeafe; color: #1e40af; padding: 2px 6px; border-radius: 4px; font-size: 10px; font-weight: bold; }
        .zero { color: #cbd5e1; }
    </style>
</head>
<body>

    <div class="header-bg">
        <h1>Reporte de Unidad</h1>
        <div class="meta">
            DISPOSITIVO: <strong>{{ $device->name }}</strong><br>
            PERIODO: {{ $start_date }} al {{ $end_date }}
        </div>
    </div>

    <div class="kpi-row">
        <div class="kpi-box">
            <span class="kpi-val">{{ $total_km }} km</span>
            <span class="kpi-lbl">Distancia Total</span>
        </div>
        <div class="kpi-box">
            <span class="kpi-val">{{ count($days) }}</span>
            <span class="kpi-lbl">Días Reportados</span>
        </div>
        </div>

    <table>
        <thead>
            <tr>
                <th>Fecha</th>
                <th>Actividad</th>
                <th>Distancia</th>
                <th>Vel. Máx</th>
                <th>Horario</th>
            </tr>
        </thead>
        <tbody>
            @foreach($days as $day)
            <tr>
                <td width="25%">
                    <strong>{{ $day['date'] }}</strong>
                </td>
                <td width="20%">
                    @if($day['distance_km'] > 0)
                        <span class="badge">ACTIVO</span>
                    @else
                        <span class="zero">INACTIVO</span>
                    @endif
                </td>
                <td>{{ $day['distance_km'] }} km</td>
                <td>{{ $day['max_speed'] }} km/h</td>
                <td>
                    @if($day['distance_km'] > 0)
                        {{ $day['start_time'] }} - {{ $day['end_time'] }}
                    @else
                        --
                    @endif
                </td>
            </tr>
            @endforeach
        </tbody>
    </table>

    <div style="margin-top: 30px; text-align: center; font-size: 10px; color: #94a3b8;">
        Reporte generado automáticamente el {{ $generated_at }}
    </div>

</body>
</html>