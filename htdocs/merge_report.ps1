$header = Get-Content 'especialista_reportes_new_header.php' -Raw -Encoding UTF8
$full = Get-Content 'especialista_reportes.php' -Raw -Encoding UTF8
# Find the closing ?> of the first PHP block (line 71 originally)
$bodyStart = $full.IndexOf('?>') + 2
$body = $full.Substring($bodyStart)
$merged = $header + $body
Set-Content 'especialista_reportes.php' -Value $merged -Encoding UTF8 -NoNewline
Write-Output 'Merge OK - Total chars: ' + $merged.Length
