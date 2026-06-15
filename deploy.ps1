# Deploy NFSe Nacional -> portugal.nitmail.com via SCP + sudo (OpenSSH no PowerShell)
# Uso: .\deploy.ps1
# SSH: eseixas@portugal.nitmail.com (chave portugal_nitmail_ed25519, sudo sem senha)
# Nao envia certs/ nem debug/ (permanecem apenas no servidor).

$ErrorActionPreference = 'Stop'

$RepoRoot      = $PSScriptRoot
$SshKey        = Join-Path $env:USERPROFILE '.ssh\portugal_nitmail_ed25519'
$SshTarget     = 'eseixas@portugal.nitmail.com'
$RemoteStaging = '/home/eseixas/nfse-deploy-staging'
$RemoteAddon   = '/home/nitmail/sites/secure.nitmail.com/billing/modules/addons/nfse_nacional'
$RemoteHooks   = '/home/nitmail/sites/secure.nitmail.com/billing/includes/hooks/nfse_nacional_hooks.php'
$AddonLocal    = Join-Path $RepoRoot 'modules\addons\nfse_nacional'
$HooksLocal    = Join-Path $RepoRoot 'includes\hooks\nfse_nacional_hooks.php'

$SshArgs = @(
    '-o', 'BatchMode=yes',
    '-o', 'IdentitiesOnly=yes',
    '-i', $SshKey
)

function Test-DeployPrerequisites {
    if (-not (Test-Path $SshKey)) {
        throw "Chave SSH nao encontrada: $SshKey"
    }
    if (-not (Test-Path $AddonLocal)) {
        throw "Pasta do addon nao encontrada: $AddonLocal"
    }
    if (-not (Test-Path $HooksLocal)) {
        throw "Hook nao encontrado: $HooksLocal"
    }
}

function Invoke-RemoteEcho {
    Write-Host 'Testando SSH (eseixas)...' -ForegroundColor Cyan
    & ssh @SshArgs $SshTarget 'echo ok' | Out-Null
    if ($LASTEXITCODE -ne 0) {
        throw "Falha na conexao SSH com $SshTarget (exit $LASTEXITCODE)"
    }

    & ssh @SshArgs $SshTarget 'sudo -n true' 2>$null | Out-Null
    if ($LASTEXITCODE -ne 0) {
        throw 'sudo sem senha indisponivel para eseixas. Deploy abortado.'
    }
}

function Initialize-RemoteStaging {
    Write-Host 'Preparando staging remoto...' -ForegroundColor Cyan
    & ssh @SshArgs $SshTarget "mkdir -p ${RemoteStaging}/addon" | Out-Null
    if ($LASTEXITCODE -ne 0) {
        throw "Falha ao criar staging em ${RemoteStaging}"
    }
}

function Send-StagingFiles {
    Write-Host 'Enviando arquivos para staging...' -ForegroundColor Cyan
    & scp @SshArgs -r `
        (Join-Path $AddonLocal 'lib') `
        (Join-Path $AddonLocal 'assets') `
        (Join-Path $AddonLocal 'nfse_nacional.php') `
        (Join-Path $AddonLocal 'whmcs.json') `
        (Join-Path $AddonLocal 'logo.png') `
        "${SshTarget}:${RemoteStaging}/addon/"
    if ($LASTEXITCODE -ne 0) {
        throw "Falha ao enviar arquivos do addon para staging (exit $LASTEXITCODE)"
    }

    & scp @SshArgs $HooksLocal "${SshTarget}:${RemoteStaging}/nfse_nacional_hooks.php"
    if ($LASTEXITCODE -ne 0) {
        throw "Falha ao enviar hook para staging (exit $LASTEXITCODE)"
    }
}

function Publish-RemoteFiles {
    Write-Host 'Publicando no WHMCS com sudo (nitmail)...' -ForegroundColor Cyan
    $cmd = @"
set -e
sudo -n rsync -a '${RemoteStaging}/addon/lib/' '${RemoteAddon}/lib/'
sudo -n rsync -a '${RemoteStaging}/addon/assets/' '${RemoteAddon}/assets/'
sudo -n cp '${RemoteStaging}/addon/nfse_nacional.php' '${RemoteAddon}/nfse_nacional.php'
sudo -n cp '${RemoteStaging}/addon/whmcs.json' '${RemoteAddon}/whmcs.json'
sudo -n cp '${RemoteStaging}/addon/logo.png' '${RemoteAddon}/logo.png'
sudo -n cp '${RemoteStaging}/nfse_nacional_hooks.php' '${RemoteHooks}'
sudo -n chown -R nitmail:nitmail '${RemoteAddon}/lib' '${RemoteAddon}/assets'
sudo -n chown nitmail:nitmail '${RemoteAddon}/nfse_nacional.php' '${RemoteAddon}/whmcs.json' '${RemoteAddon}/logo.png' '${RemoteHooks}'
echo PUBLISHED
sudo -n rm -rf '${RemoteStaging}' 2>/dev/null || true
"@

    $out = & ssh @SshArgs $SshTarget $cmd 2>$null
    if ($LASTEXITCODE -ne 0) {
        throw "Falha ao publicar arquivos com sudo (exit $LASTEXITCODE)"
    }
}

function Confirm-Deploy {
    Write-Host 'Verificando arquivos no servidor...' -ForegroundColor Cyan
    $cmd = @"
sudo -n grep -q 'getStatus' '${RemoteAddon}/lib/CertManager.php' &&
sudo -n grep -q 'renderCertStatusAlert' '${RemoteAddon}/lib/NfseController.php' &&
sudo -n grep -q 'nfse_nacional_cert_ready' '${RemoteHooks}' &&
echo VERIFIED
"@

    & ssh @SshArgs $SshTarget $cmd 2>$null | Out-Null
    if ($LASTEXITCODE -ne 0) {
        throw 'Verificacao pos-deploy falhou. Arquivos podem estar incompletos.'
    }
}

try {
    Test-DeployPrerequisites
    Invoke-RemoteEcho
    Initialize-RemoteStaging
    Send-StagingFiles
    Publish-RemoteFiles
    Confirm-Deploy
    Write-Host 'Deploy concluido com sucesso!' -ForegroundColor Green
}
catch {
    Write-Host $_.Exception.Message -ForegroundColor Red
    exit 1
}