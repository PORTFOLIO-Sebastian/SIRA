$content = Get-Content 'especialista_reportes.php' -Raw -Encoding UTF8
$lines = $content -split "`n"
$total = $lines.Count

# Insert ?> after line 120 (0-indexed = 120), before <!DOCTYPE html> (line 122)
$before = $lines[0..120]
$after  = $lines[121..($total-1)]
$result = $before + '?>' + $after
Set-Content 'especialista_reportes.php' -Value ($result -join "`n") -Encoding UTF8 -NoNewline
Write-Output "Inserted closing PHP tag. Total lines: $($result.Count)"
