param(
    [string] $VendorVolume = '',
    [string] $Case = ''
)

$ErrorActionPreference = 'Stop'
$root = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$id = [guid]::NewGuid().ToString('N').Substring(0, 12)
$prefix = "newsroom-workspace-browser-$id"
$network = "$prefix-net"
$varVolume = "$prefix-var"
$assetVolume = "$prefix-assets"
$app = "$prefix-app"
$db = "$prefix-db"
$redis = "$prefix-redis"
$builder = "$prefix-builder"
$browserImage = "newsroom-workspace-browser:$id"
$resources = [System.Collections.Generic.List[string]]::new()
$volumes = [System.Collections.Generic.List[string]]::new()
$networkCreated = $false
$imageCreated = $false

function Assert-Docker {
    if ($LASTEXITCODE -ne 0) { throw "Docker command failed with exit code $LASTEXITCODE." }
}

Push-Location $root
try {
    # Read only the existing Composer dependency mount; never attach its DB or Redis.
    if ($VendorVolume) {
        docker volume inspect $VendorVolume | Out-Null
        Assert-Docker
        $vendorMount = "type=volume,source=$VendorVolume,target=/app/vendor,readonly"
    } else {
        $phpId = docker compose ps -q php
        Assert-Docker
        if (-not $phpId) { throw 'Start compose PHP or provide its Composer dependency volume with -VendorVolume.' }
        $info = (docker inspect $phpId | ConvertFrom-Json)[0]
        Assert-Docker
        $vendor = @($info.Mounts | Where-Object Destination -eq '/app/vendor')
        if ($vendor.Count -ne 1) { throw 'Expected exactly one /app/vendor mount on the compose PHP container.' }
        if ($vendor[0].Type -eq 'volume') {
            $vendorMount = "type=volume,source=$($vendor[0].Name),target=/app/vendor,readonly"
        } elseif ($vendor[0].Type -eq 'bind') {
            $vendorMount = "type=bind,source=$($vendor[0].Source),target=/app/vendor,readonly"
        } else {
            throw 'Unsupported Composer dependency mount type.'
        }
    }

    # Browser tooling installation has no repository mount. The later browser
    # and application containers use an internal Docker network only.
    docker run --name $builder --entrypoint sh mcr.microsoft.com/playwright:v1.56.1-noble -c 'npm install --prefix /opt/workspace-browser --no-audit --no-fund playwright@1.56.1'
    Assert-Docker
    $resources.Add($builder)
    docker commit $builder $browserImage
    Assert-Docker
    $imageCreated = $true
    docker rm $builder
    Assert-Docker
    $resources.Remove($builder) | Out-Null

    docker network create --internal $network
    Assert-Docker
    $networkCreated = $true
    docker volume create $varVolume
    Assert-Docker
    $volumes.Add($varVolume)
    docker volume create $assetVolume
    Assert-Docker
    $volumes.Add($assetVolume)
    docker run -d --name $db --network $network --network-alias newsroom-workspace-browser-db -e POSTGRES_USER=fixture -e POSTGRES_PASSWORD=fixture -e POSTGRES_DB=workspace_fixture postgres:17-alpine
    Assert-Docker
    $resources.Add($db)
    docker run -d --name $redis --network $network --network-alias newsroom-workspace-browser-redis redis:7-alpine
    Assert-Docker
    $resources.Add($redis)
    docker exec $db sh -c 'for i in $(seq 1 60); do pg_isready -U fixture -d workspace_fixture && exit 0; sleep 1; done; exit 1'
    Assert-Docker

    # Public SWC compiler only; this download container has no repository mount.
    docker run --rm --mount "type=volume,source=$varVolume,target=/fixture" --entrypoint sh node:22 -c 'curl -fLsS https://github.com/swc-project/swc/releases/download/v1.16.22/swc-linux-x64-gnu -o /fixture/swc-linux-x64-gnu && chmod +x /fixture/swc-linux-x64-gnu'
    Assert-Docker
    docker run -d --name $app --network $network --network-alias newsroom-workspace-browser-app `
        --mount "type=bind,source=$root,target=/app,readonly" --mount $vendorMount `
        --mount "type=volume,source=$varVolume,target=/app/var" `
        --mount "type=volume,source=$assetVolume,target=/app/public/assets" `
        -w /app -e WORKSPACE_BROWSER_FIXTURE=isolated --entrypoint sh newsroom-php -c 'sleep infinity'
    Assert-Docker
    $resources.Add($app)
    docker exec $app php tests/Browser/workspace-fixture.php seed
    Assert-Docker
    docker exec $app php tests/Browser/workspace-fixture.php compile
    Assert-Docker
    docker exec -d $app php -S 0.0.0.0:8087 -t public tests/Browser/workspace-fixture.php
    Assert-Docker
    docker exec $app sh -c 'for i in $(seq 1 60); do curl -fsS -o /dev/null http://127.0.0.1:8087/reading-nook && exit 0; sleep 1; done; exit 1'
    Assert-Docker
    docker run --rm --network $network `
        --mount "type=bind,source=$root\tests\Browser\workspace-acceptance.cjs,target=/workspace/workspace-acceptance.cjs,readonly" `
        -e NODE_PATH=/opt/workspace-browser/node_modules -e "WORKSPACE_CASE=$Case" --entrypoint node $browserImage /workspace/workspace-acceptance.cjs
    Assert-Docker
} finally {
    foreach ($resource in $resources) { docker rm -f $resource | Out-Null }
    foreach ($volume in $volumes) { docker volume rm $volume | Out-Null }
    if ($networkCreated) { docker network rm $network | Out-Null }
    if ($imageCreated) { docker image rm $browserImage | Out-Null }
    Pop-Location
}
