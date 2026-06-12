param(
    [string]$RemoteHost = "Carmine@192.168.1.50",
    [string]$AppDir = "/opt/coresuite/business",
    [string]$IdentityFile = "$env:USERPROFILE\.ssh\id_ed25519_coresuite"
)

$ErrorActionPreference = "Stop"
$ProjectRoot = Split-Path -Parent $PSScriptRoot

Write-Host "==> Deploy Coresuite Business + Automata su $RemoteHost"

if (-not (Test-Path $IdentityFile)) {
    ssh-keygen -t ed25519 -f $IdentityFile -N '""' -q
}

$pubKey = Get-Content "$IdentityFile.pub" -Raw
Write-Host ""
Write-Host "Se SSH fallisce, sul server esegui UNA volta:"
Write-Host "mkdir -p ~/.ssh && echo '$($pubKey.Trim())' >> ~/.ssh/authorized_keys && chmod 600 ~/.ssh/authorized_keys"
Write-Host ""

$sshArgs = @("-i", $IdentityFile, "-o", "StrictHostKeyChecking=accept-new", $RemoteHost)

& ssh @sshArgs "mkdir -p $AppDir"

$tarPath = Join-Path $env:TEMP "coresuite-deploy.tar"
if (Test-Path $tarPath) { Remove-Item $tarPath -Force }

Push-Location $ProjectRoot
& git archive --format=tar -o $tarPath HEAD
Pop-Location

& scp -i $IdentityFile -o StrictHostKeyChecking=accept-new $tarPath "${RemoteHost}:/tmp/coresuite-deploy.tar"
& ssh @sshArgs "cd $AppDir && tar -xf /tmp/coresuite-deploy.tar && rm -f /tmp/coresuite-deploy.tar"
& ssh @sshArgs @"
set -e
cd $AppDir
if [ ! -f .env ]; then
  cp .env.example .env 2>/dev/null || true
  php tools/setup_caf_encryption_key.php || true
  php tools/setup_automata_env.php || true
fi
docker compose pull automata 2>/dev/null || true
docker compose up -d --build
docker compose exec -T business php composer.phar install --no-interaction 2>/dev/null || php composer.phar install --no-interaction 2>/dev/null || true
echo Deploy completato.
"@

Write-Host "Deploy terminato."
