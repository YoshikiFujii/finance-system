# Lotus（MVP）


## セットアップ
1. PHP 8.2 / MySQL 8 を用意
2. DB作成 `CREATE DATABASE finance_db CHARACTER SET utf8mb4;`
3. `sql/schema.sql` を実行
4. `.env.sample` をコピーして `.env` を作成し、接続情報を入れる
5. `public/` をWebルートに設定（Apache/Nginx）。または `php -S localhost:8080 -t public` で起動
6. `passwords` のハッシュは `password_hash('平文', PASSWORD_BCRYPT)` の結果で置換


## 使い方
- `/pages/login.html` から役割を選んでログイン
- 役員は `/pages/officer.html` でExcel+概要+氏名/部署を提出
- 財務は `/pages/finance.html` で受理/状態操作/検算
- 管理者は `/pages/admin.html` でマスタ編集


## 備考
- Excelの自動読取（expected_total算出）は将来PhpSpreadsheet導入で拡張可能
- ファイル配信はAPI経由+認可に変更推奨（本MVPは保存のみ）
- CSRFはAPIトークン追加で強化可