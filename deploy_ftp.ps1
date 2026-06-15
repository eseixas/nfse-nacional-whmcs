# Redireciona para o deploy via SSH (OpenSSH no PowerShell).
# O metodo FTP/lftp foi descontinuado.
# Uso: .\deploy_ftp.ps1  ou  .\deploy.ps1

& (Join-Path $PSScriptRoot 'deploy.ps1') @args
exit $LASTEXITCODE