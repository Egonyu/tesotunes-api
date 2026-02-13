# Migration Linter Script (PowerShell)
#
# Purpose: Detect migrations that rename/drop columns without approval token
#
# Usage:
#   .\scripts\migrations\lint_migrations.ps1 [migration_file]
#   .\scripts\migrations\lint_migrations.ps1                   # Check all
#
# Exit Codes:
#   0 - All migrations pass lint checks
#   1 - Found unapproved rename/drop operations
#
# Approval Token Format:
#   // MIGRATION-ALTER-APPROVED: TICKET-1234

$ErrorActionPreference = "Stop"

$ScriptDir = Split-Path -Parent $MyInvocation.MyCommand.Path
$ProjectRoot = Resolve-Path (Join-Path $ScriptDir "..\..")
$MigrationsDir = Join-Path $ProjectRoot "database\migrations"

# Counters
$Checked = 0
$Violations = 0

Write-Host "🔍 Migration Linter - Checking for unapproved schema alterations..." -ForegroundColor Cyan
Write-Host ""

function Has-ApprovalToken {
    param([string]$FilePath)
    
    $content = Get-Content $FilePath -Raw
    return $content -match "MIGRATION-ALTER-APPROVED:"
}

function Check-Migration {
    param([string]$FilePath)
    
    $filename = Split-Path $FilePath -Leaf
    $violationsFound = $false
    
    if (-not (Test-Path $FilePath)) {
        return $false
    }
    
    $content = Get-Content $FilePath -Raw
    
    # Check for renameColumn
    if ($content -match "renameColumn|rename\(") {
        if (-not (Has-ApprovalToken $FilePath)) {
            Write-Host "❌ VIOLATION: $filename" -ForegroundColor Red
            Write-Host "   Contains: renameColumn() or rename()"
            Write-Host "   Missing approval token: // MIGRATION-ALTER-APPROVED: TICKET-XXX"
            Write-Host ""
            $violationsFound = $true
        }
    }
    
    # Check for dropColumn
    if ($content -match "dropColumn|drop\(['\`"]") {
        if (-not (Has-ApprovalToken $FilePath)) {
            Write-Host "❌ VIOLATION: $filename" -ForegroundColor Red
            Write-Host "   Contains: dropColumn() or drop()"
            Write-Host "   Missing approval token: // MIGRATION-ALTER-APPROVED: TICKET-XXX"
            Write-Host ""
            $violationsFound = $true
        }
    }
    
    # Check for change() (column type changes)
    if ($content -match "->change\(\)") {
        if (-not (Has-ApprovalToken $FilePath)) {
            Write-Host "⚠️  WARNING: $filename" -ForegroundColor Yellow
            Write-Host "   Contains: ->change() (column type modification)"
            Write-Host "   Consider adding approval token for column type changes"
            Write-Host ""
            # Don't count as violation, just warning
        }
    }
    
    return $violationsFound
}

# Main
if ($args.Count -eq 1) {
    # Check single file
    $targetFile = $args[0]
    if (-not (Test-Path $targetFile)) {
        Write-Host "Error: File not found: $targetFile" -ForegroundColor Red
        exit 1
    }
    
    Write-Host "Checking: $(Split-Path $targetFile -Leaf)"
    Write-Host ""
    
    if (Check-Migration $targetFile) {
        exit 1
    } else {
        Write-Host "✅ No violations found" -ForegroundColor Green
        exit 0
    }
} else {
    # Check all migrations
    if (-not (Test-Path $MigrationsDir)) {
        Write-Host "Error: Migrations directory not found: $MigrationsDir" -ForegroundColor Red
        exit 1
    }
    
    $migrations = Get-ChildItem -Path $MigrationsDir -Filter "*.php"
    
    foreach ($migration in $migrations) {
        $Checked++
        
        if (Check-Migration $migration.FullName) {
            $Violations++
        }
    }
    
    Write-Host "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
    Write-Host ""
    Write-Host "📊 Summary:"
    Write-Host "   Migrations checked: $Checked"
    Write-Host "   Violations found: $Violations"
    Write-Host ""
    
    if ($Violations -eq 0) {
        Write-Host "✅ All migrations passed lint checks!" -ForegroundColor Green
        exit 0
    } else {
        Write-Host "❌ Found $Violations migration(s) with violations" -ForegroundColor Red
        Write-Host ""
        Write-Host "To approve a migration, add this comment at the top:"
        Write-Host "  // MIGRATION-ALTER-APPROVED: TICKET-1234"
        Write-Host ""
        exit 1
    }
}
