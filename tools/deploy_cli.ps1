param(
    [ValidateSet('status', 'hostinger-sites', 'push', 'workflow', 'ssh-key')]
    [string]$Action = 'status'
)

$Root = Split-Path -Parent $PSScriptRoot
Set-Location $Root

function Show-Status {
    Write-Host "=== Deploy CLI Coresuite ===" -ForegroundColor Cyan
    Write-Host "Git branch: $(git branch --show-current)"
    Write-Host "Ultimo commit: $(git log -1 --oneline)"
    Write-Host ""
    Write-Host "Hostinger API (siti):" -ForegroundColor Yellow
    php tools/hostinger_websites.php
    Write-Host ""
    Write-Host "GitHub secrets (se gh configurato):" -ForegroundColor Yellow
    gh secret list 2>$null
    if ($LASTEXITCODE -ne 0) { Write-Host "  gh non autenticato o secrets non visibili" }
    Write-Host ""
    Write-Host "SSH locale Docker (192.168.1.50):" -ForegroundColor Yellow
    ssh -i "$env:USERPROFILE\.ssh\id_ed25519_coresuite" -o BatchMode=yes -o ConnectTimeout=5 Carmine@192.168.1.50 "hostname" 2>&1
}

switch ($Action) {
    'status' { Show-Status }
    'hostinger-sites' { php tools/hostinger_websites.php }
    'push' {
        git push origin main
        Write-Host "Push completato. Avvia deploy con: .\tools\deploy_cli.ps1 -Action workflow"
    }
    'workflow' {
        gh workflow run deploy.yml --ref main
        gh run list --workflow=deploy.yml --limit 3
    }
    'ssh-key' {
        Get-Content "$env:USERPROFILE\.ssh\id_ed25519_coresuite.pub"
        Write-Host ""
        Write-Host "Aggiungi su Carmine@192.168.1.50 in ~/.ssh/authorized_keys"
        Write-Host "Oppure come secret DOCKER_SSH_KEY su GitHub per deploy automatico."
    }
}
