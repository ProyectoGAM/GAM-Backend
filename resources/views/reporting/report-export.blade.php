<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $source->label }} · GAM</title>
    <style>
        :root { color-scheme: light; font-family: Inter, ui-sans-serif, system-ui, sans-serif; color: #20372f; background: #f3f7f4; }
        body { margin: 0; padding: 2rem 1rem; }
        main { max-width: 1100px; margin: 0 auto; padding: 2rem; border: 1px solid #d7e5dc; border-radius: 1rem; background: #fff; box-shadow: 0 1rem 3rem rgb(32 55 47 / 0.08); }
        .eyebrow { margin: 0 0 .45rem; color: #438965; font-size: .75rem; font-weight: 700; letter-spacing: .12em; text-transform: uppercase; }
        h1 { margin: 0; font-size: clamp(1.5rem, 3vw, 2.2rem); }
        .meta { margin: .65rem 0 1.75rem; color: #667a70; font-size: .9rem; }
        .empty { padding: 1rem; border-radius: .65rem; background: #f3f7f4; color: #667a70; }
        .table-wrap { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; font-size: .9rem; }
        th, td { padding: .7rem .75rem; border-bottom: 1px solid #e4ece7; text-align: left; vertical-align: top; }
        th { color: #438965; background: #f3f7f4; font-size: .75rem; letter-spacing: .04em; text-transform: uppercase; }
        tr:last-child td { border-bottom: 0; }
    </style>
</head>
<body>
<main>
    <p class="eyebrow">Reporte compartido · GAM</p>
    <h1>{{ $source->label }}</h1>
    <p class="meta">Exportación {{ $export->file_name }} · Generada {{ optional($export->completed_at)->format('d/m/Y H:i') }}</p>

    @if ($result->rows === [])
        <p class="empty">No hay datos para esta consulta.</p>
    @else
        <div class="table-wrap">
            <table>
                <thead>
                <tr>
                    @foreach ($result->columnas as $column)
                        <th scope="col">{{ $source->columnas[$column]['label'] ?? $column }}</th>
                    @endforeach
                </tr>
                </thead>
                <tbody>
                @foreach ($result->rows as $row)
                    <tr>
                        @foreach ($result->columnas as $column)
                            @php($value = $row[$column] ?? null)
                            <td>{{ $value === null || $value === '' ? '—' : (is_scalar($value) ? (string) $value : (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) }}</td>
                        @endforeach
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    @endif
</main>
</body>
</html>
