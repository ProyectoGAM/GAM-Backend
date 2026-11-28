<!doctype html>
<html lang="es">
<head><meta charset="utf-8"><style>
@page { margin: 18px; } body { font-family: DejaVu Sans, sans-serif; font-size: 9px; }
h1 { font-size: 16px; } h2 { font-size: 13px; } table { border-collapse: collapse; width: 100%; }
thead { display: table-header-group; } tr { page-break-inside: avoid; }
th, td { border: 1px solid #999; padding: 4px; text-align: left; } th { background: #eee; }
.next-section { page-break-before: always; }
</style></head>
<body>
<h1>{{ $title }}</h1>
@foreach($sections as $section)
@php($header = $section['header'])
<section @class(['next-section' => !$loop->first])>
@if($combined)<h2>{{ $loop->iteration }}. {{ $header['label'] }}</h2>@endif
<p>Generado: {{ $header['generated_at'] }} · Filas: {{ $header['total'] }}</p>
@if($section['dated'])
<p>Período: {{ $header['query']['from'] ?? 'Inicio del historial registrado' }} — {{ $header['query']['to'] ?? 'Último registro' }}</p>
@else
<p>Existencias al momento de la consulta</p>
@endif
<table><thead><tr>@foreach($header['columns'] as $column)<th>{{ $header['metadata'][$column]['label'] }}@if(isset($header['metadata'][$column]['unit'])) ({{ $header['metadata'][$column]['unit'] }})@endif</th>@endforeach</tr></thead>
<tbody>@foreach($section['rows'] as $row)<tr>@foreach($header['columns'] as $column)<td>{{ $row[$column] ?? '—' }}</td>@endforeach</tr>@endforeach</tbody></table>
</section>
@endforeach
</body></html>
