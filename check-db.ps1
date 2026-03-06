# ==============================
# Load Laravel .env
# ==============================
$envFile = ".env"

if (-Not (Test-Path $envFile)) {
    Write-Host "❌ .env file not found"
    exit 1
}

Get-Content $envFile | ForEach-Object {
    if ($_ -match "^\s*([^#][^=]+)=(.*)$") {
        $name = $matches[1].Trim()
        $value = $matches[2].Trim('"')
        Set-Variable -Name $name -Value $value
    }
}

# ==============================
# DB Config from .env
# ==============================
$DB_HOST = $DB_HOST
$DB_PORT = $DB_PORT
$DB_NAME = $DB_DATABASE
$DB_USER = $DB_USERNAME
$DB_PASS = $DB_PASSWORD

# ==============================
# Tables to Ensure
# ==============================
$tables = @{

    users = @"
CREATE TABLE IF NOT EXISTS users (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150),
    email VARCHAR(150) UNIQUE,
    password VARCHAR(255),
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL
);
"@

    roles = @"
CREATE TABLE IF NOT EXISTS roles (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) UNIQUE,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL
);
"@

    permissions = @"
CREATE TABLE IF NOT EXISTS permissions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) UNIQUE,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL
);
"@

    role_user = @"
CREATE TABLE IF NOT EXISTS role_user (
    user_id BIGINT UNSIGNED,
    role_id BIGINT UNSIGNED,
    PRIMARY KEY (user_id, role_id)
);
"@
}

# ==============================
# Execute Table Creation
# ==============================
foreach ($table in $tables.Keys) {

    Write-Host "🔍 Ensuring table: $table"

    mysql `
        --host=$DB_HOST `
        --port=$DB_PORT `
        --user=$DB_USER `
        --password=$DB_PASS `
        $DB_NAME `
        -e $tables[$table]

    Write-Host "✅ $table ready"
}

Write-Host "`n🎉 Database check completed"
