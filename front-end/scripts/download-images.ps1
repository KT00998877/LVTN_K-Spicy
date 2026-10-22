# =============================================================================
# download-images.ps1
# Tai anh minh hoa tu Unsplash ve thu muc public/images/ cua front-end.
# Chay: powershell -ExecutionPolicy Bypass -File scripts/download-images.ps1
# =============================================================================

$ErrorActionPreference = 'Stop'

# Bat output UTF-8 de in tieng Viet khong loi
[Console]::OutputEncoding = [System.Text.Encoding]::UTF8
$PSDefaultParameterValues['Out-File:Encoding'] = 'utf8'

$ProjectRoot = Resolve-Path "$PSScriptRoot\.."
$PublicDir   = Join-Path $ProjectRoot 'public\images'

$msgRoot = "Project root : "
$msgDir  = "Target dir   : "
$msgDone = "Hoan tat: {0}/{1} thanh cong, {2} loi"
$msgSkip = "  [SKIP] {0} (da ton tai)"
$msgGet  = "  [GET ] {0}"
$msgOk   = "  OK ({0} KB)"
$msgErr  = "  LOI: {0}"

Write-Host ("==> " + $msgRoot + $ProjectRoot) -ForegroundColor Cyan
Write-Host ("==> " + $msgDir  + $PublicDir)   -ForegroundColor Cyan

# --- Danh sach anh can tai --------------------------------------------------
$Images = @(
    # MENU - 5 danh muc chinh
    @{ Target = 'menu\mi-cay-han-quoc.jpg'; Url = 'https://images.unsplash.com/photo-1569718212165-3a8278d5f624?w=800&q=80' },
    @{ Target = 'menu\topping-them.jpg';    Url = 'https://images.unsplash.com/photo-1582450871972-ab5ca641643d?w=800&q=80' },
    @{ Target = 'menu\mon-phu.jpg';         Url = 'https://images.unsplash.com/photo-1496116218417-1a781b1c416c?w=800&q=80' },
    @{ Target = 'menu\trang-mieng.jpg';     Url = 'https://images.unsplash.com/photo-1551024506-0bccd828d307?w=800&q=80' },
    @{ Target = 'menu\do-uong.jpg';         Url = 'https://images.unsplash.com/photo-1556679343-c7306c1976bc?w=800&q=80' },
    @{ Target = 'menu\default.jpg';         Url = 'https://images.unsplash.com/photo-1565299624946-b28f40a0ae38?w=800&q=80' },

    # BLOG - 5 loai bai viet
    @{ Target = 'blog\news.jpg';      Url = 'https://images.unsplash.com/photo-1504674900247-0877df9cc836?w=800&q=80' },
    @{ Target = 'blog\promotion.jpg'; Url = 'https://images.unsplash.com/photo-1565299507177-b0ac66763828?w=800&q=80' },
    @{ Target = 'blog\new-dish.jpg';  Url = 'https://images.unsplash.com/photo-1546069901-ba9599a7e63c?w=800&q=80' },
    @{ Target = 'blog\event.jpg';     Url = 'https://images.unsplash.com/photo-1555244162-803834f70033?w=800&q=80' },
    @{ Target = 'blog\culinary.jpg';  Url = 'https://images.unsplash.com/photo-1551782450-a2132b4ba21d?w=800&q=80' },

    # BRAND - hero & logo
    @{ Target = 'brand\hero.jpg';       Url = 'https://images.unsplash.com/photo-1552566626-52f8b828add9?w=1600&q=80' },
    @{ Target = 'brand\hero-bg.jpg';    Url = 'https://images.unsplash.com/photo-1505935428862-770b6f24f629?w=1600&q=80' },
    @{ Target = 'brand\restaurant.jpg'; Url = 'https://images.unsplash.com/photo-1517248135467-4c7edcad34c4?w=1200&q=80' }
)

# --- Tai anh -----------------------------------------------------------------
$Total  = $Images.Count
$Ok     = 0
$Failed = 0

foreach ($img in $Images) {
    $RelPath  = $img.Target
    $Url      = $img.Url
    $FullPath = Join-Path $PublicDir $RelPath

    # Tao thu muc cha neu chua co
    $ParentDir = Split-Path $FullPath -Parent
    if (!(Test-Path $ParentDir)) {
        New-Item -ItemType Directory -Force -Path $ParentDir | Out-Null
    }

    # Bo qua neu da ton tai
    if (Test-Path $FullPath) {
        Write-Host ("  [SKIP] " + $RelPath + " (da ton tai)") -ForegroundColor DarkGray
        $Ok++
        continue
    }

    Write-Host ("  [GET ] " + $RelPath) -NoNewline
    try {
        Invoke-WebRequest -Uri $Url -OutFile $FullPath -UseBasicParsing -TimeoutSec 30 | Out-Null
        $Size = (Get-Item $FullPath).Length
        if ($Size -gt 0) {
            $Kb = [math]::Round($Size/1KB, 1)
            Write-Host ("  OK (" + $Kb + " KB)") -ForegroundColor Green
            $Ok++
        } else {
            Write-Host "  LOI: file rong" -ForegroundColor Red
            Remove-Item $FullPath -Force
            $Failed++
        }
    } catch {
        Write-Host ("  LOI: " + $_.Exception.Message) -ForegroundColor Red
        $Failed++
    }
}

Write-Host ""
$Summary = "  " + ($msgDone -f $Ok, $Total, $Failed)
$Color = if ($Failed -eq 0) { 'Green' } else { 'Yellow' }
Write-Host ("==>" + $Summary) -ForegroundColor $Color
