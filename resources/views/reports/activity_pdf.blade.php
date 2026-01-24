<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Reporte de Actividad</title>
    <style>
        @page { margin: 0px; }
        body { font-family: 'Helvetica', 'Arial', sans-serif; margin: 0; padding: 0; color: #333; font-size: 12px; }
        
        /* HEADER */
        .header { background-color: #0f172a; color: white; padding: 30px 40px; }
        .header-table { width: 100%; border-collapse: collapse; }
        .company-name { font-size: 24px; font-weight: bold; text-transform: uppercase; margin: 0; }
        .report-title { font-size: 14px; color: #94a3b8; margin-top: 5px; text-transform: uppercase; letter-spacing: 2px; }
        .logo-img { max-height: 60px; }

        /* INFO SECTION */
        .info-section { padding: 20px 40px; background-color: #f8fafc; border-bottom: 1px solid #e2e8f0; }
        .info-table { width: 100%; }
        .label { font-size: 10px; color: #64748b; text-transform: uppercase; font-weight: bold; }
        .value { font-size: 14px; color: #0f172a; font-weight: bold; margin-bottom: 10px; display: block; }

        /* KPI CARDS (RESUMEN) */
        .kpi-section { padding: 20px 40px; }
        .kpi-table { width: 100%; border-spacing: 10px; margin-left: -10px; } /* Negative margin compensates spacing */
        .kpi-card { background: #fff; border: 1px solid #cbd5e1; border-radius: 8px; padding: 15px; text-align: center; }
        .kpi-title { font-size: 11px; color: #64748b; text-transform: uppercase; letter-spacing: 1px; }
        .kpi-number { font-size: 22px; color: #0f172a; font-weight: 800; margin-top: 5px; display: block; }
        .kpi-icon { color: #3b82f6; font-size: 18px; margin-bottom: 5px; }

        /* TABLA DE DATOS */
        .data-section { padding: 0 40px; margin-top: 10px; }
        .data-table { width: 100%; border-collapse: collapse; }
        .data-table th { text-align: left; padding: 12px; background-color: #0f172a; color: white; font-size: 11px; text-transform: uppercase; }
        .data-table td { padding: 12px; border-bottom: 1px solid #e2e8f0; color: #334155; }
        .data-table tr:nth-child(even) { background-color: #f8fafc; }
        
        /* ESTADOS */
        .badge { padding: 4px 8px; border-radius: 4px; font-size: 10px; font-weight: bold; text-transform: uppercase; display: inline-block; }
        .badge-active { background-color: #dbeafe; color: #1e40af; }
        .badge-inactive { background-color: #f1f5f9; color: #64748b; }

        /* FOOTER */
        .footer { position: fixed; bottom: 0; left: 0; right: 0; background-color: #f8fafc; padding: 15px 40px; text-align: center; font-size: 10px; color: #94a3b8; border-top: 1px solid #e2e8f0; }
    </style>
</head>
<body>

    <div class="header">
        <table class="header-table">
            <tr>
                <td align="left">
                    <h1 class="company-name">SOLHEX</h1> <div class="report-title">Reporte de Actividad de Flota</div>
                </td>
                <td align="right">
                    @if($logo)
                        <img src="{{ $logo }}" class="logo-img">
                    @else
                        <h2 style="color:white; opacity:0.3;">LOGO AQUÍ</h2>
                    @endif
                </td>
            </tr>
        </table>
    </div>

    <div class="info-section">
        <table class="info-table">
            <tr>
                <td width="33%">
                    <span class="label">Cliente / Empresa</span>
                    <span class="value">{{ $client_name }}</span>
                </td>
                <td width="33%">
                    <span class="label">Dispositivo / Vehículo</span>
                    <span class="value">{{ $device->name }}</span>
                    <small style="color:#64748b">IMEI: {{ $device->imei }}</small>
                </td>
                <td width="33%">
                    <span class="label">Periodo del Reporte</span>
                    <span class="value">{{ $range }}</span>
                </td>
            </tr>
        </table>
    </div>

    <div class="kpi-section">
        <table class="kpi-table">
            <tr>
                <td width="33%">
                    <div class="kpi-card">
                        <div class="kpi-title">Distancia Total</div>
                        <span class="kpi-number">{{ $summary['total_km'] }} km</span>
                    </div>
                </td>
                <td width="33%">
                    <div class="kpi-card">
                        <div class="kpi-title">Tiempo en Movimiento</div>
                        <span class="kpi-number">{{ floor($summary['move_time'] / 60) }}h {{ $summary['move_time'] % 60 }}m</span>
                    </div>
                </td>
                <td width="33%">
                    <div class="kpi-card">
                        <div class="kpi-title">Velocidad Máxima</div>
                        <span class="kpi-number" style="color: #ef4444;">{{ $summary['max_speed'] }} km/h</span>
                    </div>
                </td>
            </tr>
        </table>
    </div>

    <div class="data-section">
        <h3 style="margin-bottom: 10px; color: #0f172a; text-transform: uppercase; font-size: 12px;">Desglose Diario</h3>
        <table class="data-table">
            <thead>
                <tr>
                    <th>Fecha</th>
                    <th>Estado</th>
                    <th>Distancia</th>
                    <th>Vel. Máx</th>
                    <th>Primer Movimiento</th>
                    <th>Último Movimiento</th>
                    <th>Duración</th>
                </tr>
            </thead>
            <tbody>
                @foreach($days as $day)
                <tr>
                    <td><strong>{{ $day['date_human'] }}</strong></td>
                    <td>
                        @if($day['is_active'])
                            <span class="badge badge-active">Activo</span>
                        @else
                            <span class="badge badge-inactive">Inactivo</span>
                        @endif
                    </td>
                    <td>{{ $day['distance'] }} km</td>
                    <td>{{ $day['max_speed'] }} km/h</td>
                    <td>{{ $day['start_time'] }}</td>
                    <td>{{ $day['end_time'] }}</td>
                    <td>{{ $day['duration'] }}</td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    <div class="footer">
        Este reporte fue generado automáticamente por la plataforma SOLHEX el {{ $generated_at }}.<br>
        Para soporte técnico contacte a soporte@solhex.com.mx
    </div>

</body>
</html>