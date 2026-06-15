# Deploy NFSe Nacional -> portugal.nitmail.com via SCP (OpenSSH no PowerShell)
# Uso: .\deploy.ps1
# Nao envia certs/ nem debug/ (permanecem apenas no servidor).

$ErrorActionPreference = 'Stop'

$RepoRoot    = $PSScriptRoot
$SshKey      = Join-Path $env:USERPROFILE '.ssh\nitmail_cpanel'
$SshTarget   = 'nitmail@portugal.nitmail.com'
$RemoteAddon = '/home/nitmail/sites/secure.nitmail.com/billing/modules/addons/nfse_nacional/'
$RemoteHooks = '/home/nitmail/sites/secure.nitmail.com/billing/includes/hooks/nfse_nacional_hooks.php'
$AddonLocal  = Join-Path $RepoRoot 'modules\addons\nfse_nacional'
$HooksLocal  = Join-Path $RepoRoot 'includes\hooks\nfse_nacional_hooks.php'

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
    Write-Host 'Testando SSH...' -ForegroundColor Cyan
    & ssh @SshArgs $SshTarget 'echo ok' | Out-Null
    if ($LASTEXITCODE -ne 0) {
        throw "Falha na conexao SSH com $SshTarget (exit $LASTEXITCODE)"
    }
}

function Send-AddonFiles {
    Write-Host 'Enviando addon (lib, assets, nfse_nacional.php, whmcs.json, logo.png)...' -ForegroundColor Cyan
    & scp @SshArgs -r `
        (Join-Path $AddonLocal 'lib') `
        (Join-Path $AddonLocal 'assets') `
        (Join-Path $AddonLocal 'nfse_nacional.php') `
        (Join-Path $AddonLocal 'whmcs.json') `
        (Join-Path $AddonLocal 'logo.png') `
        "${SshTarget}:${RemoteAddon}"
    if ($LASTEXITCODE -ne 0) {
        throw "Falha ao enviar arquivos do addon (exit $LASTEXITCODE)"
    }
}

function Send-HooksFile {
    Write-Host 'Enviando hook nfse_nacional_hooks.php...' -ForegroundColor Cyan
    & scp @SshArgs $HooksLocal "${SshTarget}:${RemoteHooks}"
    if ($LASTEXITCODE -ne 0) {
        throw "Falha ao enviar hook (exit $LASTEXITCODE)"
    }
}

function Confirm-Deploy {
    Write-Host 'Verificando arquivos no servidor...' -ForegroundColor Cyan
    $cmd = @(
        "grep -q 'getStatus' ${RemoteAddon}lib/CertManager.php",
        "grep -q 'renderCertStatusAlert' ${RemoteAddon}lib/NfseController.php",
        "grep -q 'nfse_nacional_cert_ready' ${RemoteHooks}",
        'echo VERIFIED'
    ) -join ' && '

    $out = & ssh @SshArgs $SshTarget $cmd 2>&1
    if ($LASTEXITCODE -ne 0 -or ($out -join '') -notmatch 'VERIFIED') {
        throw 'Verificacao pos-deploy falhou. Arquivos podem estar incompletos.'
    }
}

try {
    Test-DeployPrerequisites
    Invoke-RemoteEcho
    Send-AddonFiles
    Send-HooksFile
    Confirm-Deploy
    Write-Host 'Deploy concluido com sucesso!' -ForegroundColor Green
}
catch {
    Write-Host $_.Exception.Message -ForegroundColor Red
    exit 1
}