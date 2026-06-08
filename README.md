# 寶可夢食材模擬器

繁體中文版 ing-simulator。頁面會直接讀取 `data/pokemon_data.json`，可部署到 GitHub Pages。

## 本機展示

```powershell
python -m http.server 5500
```

開啟 `http://localhost:5500`。

## 重新產生資料

先設定 MySQL 帳密：

```powershell
$env:PSS_MYSQL_USER = "你的帳號"
$env:PSS_MYSQL_PASSWORD = "你的密碼"
```

從 WSL SQLite 與 stable DB 產生 `data/pokemon_data.json`：

```powershell
php .\scripts\generate_pokemon_data.php `
  --asset-dir "C:\cygwin64\home\Chester.Yang\work\pokemon-sleep-assets\grouped\ExportedProject\Assets\Asset_Bundles" `
  --sqlite-db "\\wsl.localhost\Ubuntu\home\chester_yang\works\pokemon-sleep-longlong\database\masterdata_server.db" `
  --mysql-host "127.0.0.1" `
  --mysql-port "3306" `
  --mysql-db "stable" `
  --mysql-table "pickup_status_extra_data_list" `
  --out ".\data\pokemon_data.json"
```

如果 WSL distro 不是 `Ubuntu`，請把路徑中的 `Ubuntu` 換成實際名稱，或改用 `/home/chester_yang/...` 並加上 `--wsl-distro <名稱>`。

## 檢查資料庫欄位

```powershell
php .\scripts\generate_pokemon_data.php `
  --asset-dir "C:\cygwin64\home\Chester.Yang\work\pokemon-sleep-assets\grouped\ExportedProject\Assets\Asset_Bundles" `
  --sqlite-db "\\wsl.localhost\Ubuntu\home\chester_yang\works\pokemon-sleep-longlong\database\masterdata_server.db" `
  --mysql-host "127.0.0.1" `
  --mysql-port "3306" `
  --mysql-db "stable" `
  --mysql-table "pickup_status_extra_data_list" `
  --out ".\data\pokemon_data.json" `
  --inspect
```

## 驗證輸出

```powershell
php .\scripts\validate_pokemon_data.php .\data\pokemon_data.json
```

## GitHub Pages

此 repo 內含 `.github/workflows/pages.yml`，push 到 `main` 後會用 GitHub Actions 發佈靜態頁。

如果 GitHub Pages 尚未啟用，請在 GitHub repo 的 `Settings > Pages` 選擇：

- Source: `GitHub Actions`

不要提交 SQLite、MySQL dump、`.env` 或任何密碼。
