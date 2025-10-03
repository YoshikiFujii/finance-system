# 財務管理システム 運用マニュアル

## 概要
このシステムは、財務管理業務を効率化するためのWebアプリケーションです。
Docker環境で動作し、MySQLデータベースを使用します。

## システム構成
- **フロントエンド**: HTML + JavaScript
- **バックエンド**: PHP 8.2
- **データベース**: MySQL 8.0
- **Webサーバー**: Apache 2.4
- **コンテナ**: Docker + Docker Compose

## 起動方法

### 開発環境での起動
```bash
# プロジェクトディレクトリに移動
cd /path/to/finance-system

# Docker環境を起動
docker-compose up -d

# 起動確認
docker-compose ps
```

### 本番環境での起動
```bash
# 本番環境用設定ファイルをコピー
cp env.production.example .env

# 本番環境用Docker Composeで起動
docker-compose -f docker-compose.prod.yml up -d
```

## アクセス方法

### ログイン
- **URL**: http://localhost:8080/pages/login.html
- **役員**: OFFICER / officer123
- **財務**: FINANCE / finance123
- **管理者**: ADMIN / admin123

### 各画面へのアクセス
- **役員画面**: http://localhost:8080/pages/officer.html
- **財務画面**: http://localhost:8080/pages/finance.html
- **管理者画面**: http://localhost:8080/pages/admin.html

## 主要機能

### 役員画面
- 経費申請の作成・提出
- 申請履歴の確認
- レシートのアップロード

### 財務画面
- 申請の受理・却下
- 現金渡し・振込処理
- レシートの確認・処理
- 処理済み額の管理

### 管理者画面
- マスターデータの管理（部署、メンバー、イベント）
- バックアップの管理
- パスワード変更
- システム全体の管理

## バックアップ

### 手動バックアップ
```bash
# コンテナ内でバックアップ実行
docker-compose exec app php daily_backup.php
```

### 自動バックアップ設定
```bash
# crontabに追加（毎日午前2時に実行）
0 2 * * * cd /path/to/finance-system && docker-compose exec app php daily_backup.php
```

### バックアップファイルの場所
- **ローカル**: `./backups/`
- **ファイル名形式**: `lotus_full_YYYYMMDD_HHMMSS.sql`

## トラブルシューティング

### よくある問題と解決方法

#### 1. ログインできない
- パスワードを確認
- ブラウザのキャッシュをクリア
- セッションクッキーを削除

#### 2. APIエラー（404 Not Found）
- Docker環境が起動しているか確認
- ブラウザで強制リロード（Ctrl+F5）
- 開発者ツールでネットワークエラーを確認

#### 3. データベース接続エラー
```bash
# データベースコンテナの状態確認
docker-compose ps

# データベースに接続テスト
docker-compose exec db mysql -u finance -pfinancepass finance_db -e "SHOW TABLES;"
```

#### 4. ファイルアップロードエラー
- ファイルサイズ制限（10MB以下）
- 対応ファイル形式（JPEG, PNG, GIF, Excel）
- アップロードディレクトリの権限確認

### ログの確認
```bash
# アプリケーションログ
docker-compose logs app

# データベースログ
docker-compose logs db

# リアルタイムログ監視
docker-compose logs -f
```

## メンテナンス

### 定期メンテナンス
1. **週次**: バックアップファイルの確認
2. **月次**: ログファイルの整理
3. **四半期**: セキュリティアップデート

### データベースメンテナンス
```bash
# データベースの最適化
docker-compose exec db mysql -u finance -pfinancepass finance_db -e "OPTIMIZE TABLE requests, receipts, members, departments;"

# 古いバックアップファイルの削除（30日以上古いもの）
find ./backups -name "*.sql" -mtime +30 -delete
```

## セキュリティ

### パスワード管理
- 定期的なパスワード変更を推奨
- 強力なパスワードの使用
- パスワードの共有禁止

### アクセス制御
- 各役割に応じた権限設定
- セッションタイムアウト（1時間）
- 不正アクセスの監視

## サポート

### 緊急時の連絡先
- システム管理者: [連絡先]
- 技術サポート: [連絡先]

### バグ報告
- 問題の詳細な説明
- エラーメッセージの記録
- 再現手順の提供

## 更新履歴
- 2025-10-03: 初版作成
- システムバージョン: 1.0.0
