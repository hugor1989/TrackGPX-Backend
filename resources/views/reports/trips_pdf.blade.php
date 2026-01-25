<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <title>Reporte de Viajes</title>
    <style>
        @page {
            margin: 30px;
        }

        body {
            font-family: 'Helvetica', sans-serif;
            font-size: 11px;
            color: #1e293b;
        }

        /* HEADER */
        .header {
            margin-bottom: 20px;
            border-bottom: 2px solid #3b82f6;
            padding-bottom: 10px;
        }

        .logo {
            max-height: 80px;
            float: right;
            margin-top: -10px;
        }

        /* 🔥 LOGO MÁS GRANDE */
        h1 {
            margin: 0;
            font-size: 24px;
            text-transform: uppercase;
            color: #0f172a;
        }

        .sub {
            font-size: 12px;
            color: #64748b;
            margin-top: 5px;
        }

        /* KPI SUMMARY */
        .kpi-row {
            display: table;
            width: 100%;
            margin-bottom: 20px;
            background-color: #f1f5f9;
            border-radius: 8px;
            padding: 15px 0;
        }

        .kpi-box {
            display: table-cell;
            text-align: center;
            width: 25%;
            border-right: 1px solid #cbd5e1;
        }

        .kpi-box:last-child {
            border-right: none;
        }

        .kpi-val {
            font-size: 18px;
            font-weight: 800;
            color: #0f172a;
            display: block;
        }

        .kpi-lbl {
            font-size: 10px;
            text-transform: uppercase;
            color: #64748b;
            margin-top: 4px;
            letter-spacing: 1px;
        }

        /* TABLE */
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 10px;
        }

        th {
            background-color: #0f172a;
            color: white;
            padding: 10px;
            text-align: left;
            font-size: 10px;
            text-transform: uppercase;
        }

        td {
            padding: 10px;
            border-bottom: 1px solid #e2e8f0;
            vertical-align: middle;
        }

        tr:nth-child(even) {
            background-color: #f8fafc;
        }

        /* ICONS & BADGES */
        .dot {
            height: 10px;
            width: 10px;
            border-radius: 50%;
            display: inline-block;
            margin-right: 5px;
        }

        .dot-green {
            background-color: #10b981;
        }

        .dot-red {
            background-color: #ef4444;
        }

        .btn-map {
            display: inline-block;
            background-color: #eff6ff;
            color: #3b82f6;
            padding: 4px 8px;
            border-radius: 4px;
            text-decoration: none;
            font-size: 9px;
            border: 1px solid #bfdbfe;
            font-weight: bold;
        }

        .coord {
            font-size: 9px;
            color: #94a3b8;
            display: block;
            margin-top: 2px;
            font-family: monospace;
        }

        .cost-badge {
            color: #059669;
            font-weight: bold;
            background: #ecfdf5;
            padding: 2px 6px;
            border-radius: 4px;
        }
    </style>
</head>

<body>

    <div class="header">
        @if($logo) <img src="{{ $logo }}" class="logo"> @endif
        <h1>Reporte de Viajes</h1>
        <div class="sub">
            Dispositivo: <strong>{{ $device->name }}</strong> (IMEI: {{ $device->imei }})<br>
            Rango: {{ $range }}
        </div>
    </div>

    <div class="kpi-row">
        <div class="kpi-box">
            <span class="kpi-val">{{ $summary['total_trips'] }}</span>
            <span class="kpi-lbl">Viajes Totales</span>
        </div>
        <div class="kpi-box">
            <span class="kpi-val">{{ number_format($summary['total_km'], 2) }} km</span>
            <span class="kpi-lbl">Distancia Total</span>
        </div>
        <div class="kpi-box">
            <span class="kpi-val">{{ gmdate("H\h i\m", $summary['total_time']) }}</span>
            <span class="kpi-lbl">Tiempo Conducción</span>
        </div>
        <div class="kpi-box">
            <span class="kpi-val" style="color: #ef4444">{{ $summary['max_speed'] }} km/h</span>
            <span class="kpi-lbl">Vel. Máxima</span>
        </div>
    </div>

    <table>
        <thead>
            <tr>
                <th width="10%">Fecha</th>
                <th width="12%">Horario</th>
                <th width="8%">Duración</th>
                <th width="10%">Distancia</th>
                <th width="8%">Costo</th>
                <th width="20%">Punto Inicial</th>
                <th width="20%">Punto Final</th>
                <th width="12%">Acción</th>
            </tr>
        </thead>
        <tbody>
            @foreach($trips as $t)
            <tr>
                <td><strong>{{ $t['date'] }}</strong></td>
                <td>{{ $t['start_time'] }} - {{ $t['end_time'] }}</td>
                <td>{{ $t['duration'] }}</td>
                <td>{{ $t['distance'] }} km</td>
                <td><span class="cost-badge">${{ $t['fuel_est'] }}</span></td>

                <td>
                    <span class="dot dot-green"></span> Inicio<br>
                    <span class="coord">{{ number_format($t['start_lat'], 5) }}, {{ number_format($t['start_lon'], 5) }}</span>
                </td>

                <td>
                    <span class="dot dot-red"></span> Fin<br>
                    <span class="coord">{{ number_format($t['end_lat'], 5) }}, {{ number_format($t['end_lon'], 5) }}</span>
                </td>

                <td style="text-align: center;">
                    <a href="{{ $t['route_link'] }}" target="_blank" class="btn-map">
                        🗺️ Ver Ruta
                    </a>
                </td>
            </tr>
            @endforeach
        </tbody>
    </table>

    <div style="margin-top: 30px; text-align: center; color: #94a3b8; font-size: 9px; border-top: 1px solid #e2e8f0; padding-top: 10px;">
        Reporte generado el {{ $generated_at }}.<br>
        * Costo estimado calculado con: Gasolina ${{ number_format($config['price'], 2) }}/L y Rendimiento {{ $config['efficiency'] }} km/L.
    </div>

</body>

</html>