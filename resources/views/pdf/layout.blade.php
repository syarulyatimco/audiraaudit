<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>{{ $title }}</title>
<style>
    @page { size: A4; margin: 20mm 18mm 22mm; }
    * { box-sizing: border-box; }
    body { font-family: "Helvetica Neue", Arial, sans-serif; font-size: 10.5pt; color: #101828; line-height: 1.45; }
    h1 { font-size: 15pt; margin: 0 0 2pt; color: #0f1b31; }
    h2 { font-size: 12pt; margin: 18pt 0 6pt; color: #0f1b31; page-break-after: avoid; }
    h3 { font-size: 10.5pt; margin: 12pt 0 4pt; page-break-after: avoid; }
    .muted { color: #667085; }
    .small { font-size: 8.5pt; }
    .meta { border-bottom: 1.5pt solid #172642; padding-bottom: 6pt; margin-bottom: 12pt; }
    .body { white-space: pre-line; }
    table { width: 100%; border-collapse: collapse; margin-top: 4pt; page-break-inside: auto; }
    tr { page-break-inside: avoid; }
    th { text-align: left; font-size: 8.5pt; text-transform: uppercase; letter-spacing: .04em; color: #475467; border-bottom: 1pt solid #c3cfe2; padding: 4pt 4pt; }
    td { padding: 3pt 4pt; vertical-align: top; }
    td.num, th.num { text-align: right; white-space: nowrap; font-variant-numeric: tabular-nums; width: 22%; }
    td.note, th.note { width: 8%; text-align: center; }
    tr.heading td { font-weight: 700; padding-top: 8pt; }
    tr.subtotal td { font-weight: 700; border-top: .75pt solid #94a8c8; }
    tr.total td { font-weight: 700; border-top: 1pt solid #172642; border-bottom: 2.5pt double #172642; }
    .stamp { display: inline-block; border: 1.5pt solid #b42318; color: #b42318; padding: 2pt 8pt; font-weight: 700; font-size: 9pt; letter-spacing: .08em; }
    .ok { color: #067647; }
    .page-break { page-break-before: always; }
    .footer { margin-top: 18pt; border-top: .75pt solid #c3cfe2; padding-top: 4pt; }
</style>
</head>
<body>
@yield('content')
</body>
</html>
