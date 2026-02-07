# 金融システム (Finance System) - 環境構築手順

このドキュメントでは、InfinityFree (レンタルサーバー) 環境における、本システムのセットアップ手順を説明します。

## 1. サーバーへのファイルアップロード

FTPクライアント（FileZillaなど）を使用して、ローカルのプロジェクトファイルをサーバーの `htdocs` フォルダにアップロードします。

### アップロードするファイル・フォルダ
- `app/` (ディレクトリ全体)
  - **重要**: `app/lib/env.php` は最新版（強制上書き修正済み）を使用してください。
- `pages/` (ディレクトリ全体)
  - **重要**: `admin.html`, `finance.html` などは、ハードコードされたURLが除去された最新版を使用してください。
- `public/` (ディレクトリ全体)
- `index.php`
- `.htaccess`
- `schema.sql` (データベース初期化用)

### アップロード不要（または削除推奨）
- `.git/`
- `.gitignore`

## 2. データベースの作成

InfinityFreeのコントロールパネルから MySQL データベースを作成します。

1. **MySQL Databases** に移動します。
2. 新しいデータベースを作成します（例: `lotustest`）。
3. 表示される **Database Name**, **MySQL User Name**, **MySQL Password**, **MySQL Host Name** をメモします。

## 3. 環境変数 (.env) の設定

サーバー上の `htdocs` 直下に `.env` ファイルを作成します。このファイルはデータベース接続情報を含みます。

**注意**: Windowsのエクスプローラーなどで作成しにくい場合は、ローカルでテキストファイルとして作成し、アップロード後にリネームするか、FTPクライアントの機能で作成してください。

`.env` の内容:
```ini
DB_HOST=
DB_PORT=
DB_DATABASE=
DB_USERNAME=
DB_PASSWORD=

# アップロードディレクトリなどの設定
UPLOAD_DIR=~~/htdocs/storage/uploads
SESSION_NAME=finance_sid
SESSION_LIFETIME=1200
MAX_UPLOAD_SIZE=5242880
ALLOWED_FILE_TYPES=jpg,jpeg,png,xlsx,xls
```
*値は実際の環境に合わせて書き換えてください。*

## 4. データベースの初期化

### ステップ 4-1: テーブルの作成
phpMyAdmin を使用して、データベースのスキーマ（構造）を作成します。

1. コントロールパネルから **phpMyAdmin** を開きます。
2. 作成したデータベースを選択します。
3. **Import** タブをクリックします。
4. ファイル選択で `schema.sql` を選び、実行（Go）します。
5. ファイル選択で `seed_passwords.sql` を選び、実行（Go）します。

**初期アカウント情報:**
- OFFICER / password
- FINANCE / password
- ADMIN / password
- AUDIT / password

## 5. 動作確認

1. ブラウザでトップページ (`https://あなたのドメイン/`) にアクセスします。
   - ログイン画面が表示されることを確認します。
2. 初期アカウント（例: ADMIN / fusmin101）でログインできることを確認します。
3. ログイン後、財務ページなどがエラーなく表示されるか確認します。

## 6. クリーンアップ (セキュリティ対策)

セットアップが完了したら、セキュリティのために以下のファイルをサーバーから**削除**してください。

- `seed_passwords.sql` (もしアップロードしていれば)
- `schema.sql`

以上で環境構築は完了です。
