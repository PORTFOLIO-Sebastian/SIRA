# Script para subir archivos por SFTP
# Requiere WinSCP

# Configuración
$sftpHost = "ftp.awardspace.com"  # Cambia si es diferente
$sftpUser = "4582023_ugel"         # Usuario SFTP
$sftpPass = "8CUunqWk8Zn8@Gk"      # Contraseña SFTP
$remotePath = "/ugel/"             # Ruta remota en el servidor

# Archivos locales a subir
$filesToUpload = @(
    @{local = "C:\Users\hugoa\Downloads\proyect_dev\ugel_system_dev\htdocs\dashboard.php"; remote = "htdocs/dashboard.php"},
    @{local = "C:\Users\hugoa\Downloads\proyect_dev\ugel_system_dev\htdocs\dashboard_especialista.php"; remote = "htdocs/dashboard_especialista.php"},
    @{local = "C:\Users\hugoa\Downloads\proyect_dev\ugel_system_dev\htdocs\dashboard_jefe_agp.php"; remote = "htdocs/dashboard_jefe_agp.php"},
    @{local = "C:\Users\hugoa\Downloads\proyect_dev\ugel_system_dev\htdocs\assets\css\fluent.css"; remote = "htdocs/assets/css/fluent.css"},
    @{local = "C:\Users\hugoa\Downloads\proyect_dev\ugel_system_dev\htdocs\conexion.php"; remote = "htdocs/conexion.php"}
)

Write-Host "Iniciando carga SFTP..." -ForegroundColor Green

# Descargar WinSCP si no existe
$winScpPath = "C:\Users\hugoa\Downloads\WinSCPnet.dll"
if (-not (Test-Path $winScpPath)) {
    Write-Host "Descargando WinSCP..." -ForegroundColor Yellow
    # Instalamos WinSCP via Chocolatey o descarga manual
    # Por ahora asumimos que está disponible en el PATH
}

# Usar WinSCP para subir archivos
try {
    # Agregar WinSCP a la sesión de PowerShell
    Add-Type -Path "C:\Program Files (x86)\WinSCP\WinSCPnet.dll" -ErrorAction SilentlyContinue

    if (-not ([System.Management.Automation.PSTypeName]'WinSCP.SessionOptions').Type) {
        Write-Host "WinSCP no encontrado. Instálalo desde: https://winscp.net/download" -ForegroundColor Red
        Write-Host "O usa FileZilla manualmente" -ForegroundColor Yellow
        exit
    }

    # Crear sesión SFTP
    $sessionOptions = New-Object WinSCP.SessionOptions -Property @{
        Protocol = [WinSCP.Protocol]::Sftp
        HostName = $sftpHost
        UserName = $sftpUser
        Password = $sftpPass
        PortNumber = 22
    }

    $session = New-Object WinSCP.Session
    $session.Open($sessionOptions)

    # Subir archivos
    foreach ($file in $filesToUpload) {
        $localFile = $file.local
        $remoteFile = $file.remote

        if (Test-Path $localFile) {
            Write-Host "Subiendo: $($file.remote)" -ForegroundColor Cyan
            $transferResult = $session.PutFiles($localFile, $remoteFile, $false)

            if ($transferResult.IsSuccess) {
                Write-Host "✓ $($file.remote)" -ForegroundColor Green
            } else {
                Write-Host "✗ Error al subir $($file.remote)" -ForegroundColor Red
                foreach ($failure in $transferResult.Failures) {
                    Write-Host "  Error: $($failure.Message)" -ForegroundColor Red
                }
            }
        } else {
            Write-Host "✗ Archivo local no encontrado: $localFile" -ForegroundColor Red
        }
    }

    $session.Close()
    Write-Host "`n✅ Carga completada" -ForegroundColor Green

} catch {
    Write-Host "Error: $_" -ForegroundColor Red
    Write-Host "`nAlternativa: Usa FileZilla (https://filezilla-project.org/)" -ForegroundColor Yellow
}
