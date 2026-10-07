# Kurage 取引先チェック（ktorihiki）

取引先の一覧（会社名・13桁の法人番号・T＋13桁の登録番号のどれでも、1行に1社）を貼ると、国税庁の公表データから、商号・所在地・登記の閉鎖・インボイスの登録・取消・失効をまとめて照合する PHP＋SQLite です。確認の要る取引先を上に並べます。

公開: https://kurage.exbridge.jp/ktorihiki.php/ （愛知県に本店のある法人）

## 置き方

1. PHP 8.1 以上と SQLite（pdo_sqlite）が使えるレンタルサーバーに `php/` の中身を置く。`ktorihiki_data/` の `.htaccess` でデータは外から読めない（Apache の場合）。`SITE` / `BASE` 定数を自分の URL に変える。
2. データを組む手元のサーバー（Python 3.10 以上・標準ライブラリだけ）に `scripts/` を置き、リポジトリ直下に `.env` を作る：

```
FTP_HOST=ftp.example.com
FTP_USER=...
FTP_PASS=...
REMOTE_DIR=/web/example_com/ktorihiki_data
```

3. cron で1日1回 `python3 scripts/update.py` を回す（新しいデータがあるときだけ組み直して FTPS で置く）。

## 構成

- `scripts/fetch.py` … 国税庁の全件データを取る（法人番号＝都道府県の CSV・Unicode、インボイス＝法人分の CSV 全部）
- `scripts/build_db.py` … 2つを法人番号でつなぎ、`php/ktorihiki_data/ktorihiki.sqlite` を組む（最新履歴だけ・検索対象除外は入れない）
- `scripts/update.py` … 1日1回、全件データの更新日を見て、新しいときだけ取り直し・組み直し・heteml に FTPS 1接続で置く（実際に動くのは月1回）
- `php/ktorihiki.php` … 一括チェック（POST・結果は noindex・入力は保存しない）と「データと照合のしかた」

## 扱わないもの

個人事業者（国税庁が「本人の同意なく公表すると個人情報保護法に抵触するおそれ」と明記）。愛知県以外に本店のある法人。今月に入ってからの変更（翌月の全件データで反映）。

## 出典

国税庁法人番号公表サイト（国税庁）・国税庁適格請求書発行事業者公表サイト（国税庁）を加工して作成（公共データ利用規約 第1.0版）。

## ライセンス

MIT
