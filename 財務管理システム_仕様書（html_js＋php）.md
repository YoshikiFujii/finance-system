# 財務管理システム 仕様書（HTML/JavaScript＋PHP）

最終更新: 2025-09-12 / 作成対象: 組織内財務管理（購入希望届受付〜精算）

---

## 1. 目的
役員から提出される「購入希望届（Excel）」を起点に、現金手渡し/ネット購入（振込・コンビニ払い）の支出プロセスを一元管理し、**授受金額＝レシート合計＋返却額**の検証をシステムで確実化する。

---

## 2. 用語
- **購入希望届**: 役員が提出するExcel（金額と使用目的のリスト）。
- **受理**: 財務が内容を確認し、処理対象として登録する行為。
- **渡し額**: 財務が手渡し用に下ろして渡した現金額（余裕分含む）。
- **ネット購入**: 提出者に資金を渡さない。財務が「振込」または「コンビニ払い」を行う。
- **レシート処理**: 領収書/レシート画像と残額（お釣り・未使用分）を登録し、差異を検算して確定する工程。

---

## 3. 役割と認証
- **提出ページ(役員)**: 共有パスワードでログイン。氏名・部署はプルダウンから選択。
- **財務ページ(財務担当)**: 財務用パスワードでログイン。受理・状態遷移・精算処理。
- **管理者ページ(管理者)**: 管理者パスワードでログイン。組織/役員マスタの編集、リスト編集、全権操作。
- **ログインページ**: アクセス先ページを選択し、該当パスワードを入力して遷移。一定時間でタイムアウト。

> 共有パスワードは要件通り実装。将来的拡張で**個別アカウント＋二要素**を推奨（§15）。

---

## 4. 業務フロー（状態遷移）

### 4.1 共通
1) 役員が提出ページでExcel＋氏名・部署・概要を送信 → **下書き/新規受付**
2) 財務が内容を検閲 → **受理**（不備は**受理拒否**）

### 4.2 現金手渡し（ネット購入でない）
- **受理 → 渡し済み → 回収済み → レシート処理完了**
  - 「渡し済み」: 渡し額を記録。
  - 「回収済み」: レシート画像/PDFの仮登録、返却額（お釣り）を記録。
  - 「レシート処理完了」: 計算検証（§10）をパスし確定。

### 4.3 ネット購入（コンビニ払い）
- **受理 → 下ろし済み → 支払い済み → レシート処理完了**
  - 「下ろし済み」: 財務が必要額を引き出し（手元資金として記録）。
  - 「支払い済み」: コンビニ払い実行・レシート登録。

### 4.4 ネット購入（振込）
- **受理 → 振込済み → レシート処理完了**
  - 「振込済み」: 振込記録（振込先、日時、金額、控えファイル）を登録。

### 4.5 受理拒否
- 不備または差異解消不可の場合に**受理拒否**でクローズ（理由必須）。

---

## 5. 画面要件

### 5.1 ログインページ
- ページ選択: 「提出/財務/管理者」
- フィールド: パスワード
- 機能: タイムアウト（例: 20分操作なしでセッション失効）

### 5.2 提出ページ（役員）
- 必須入力:
  - 購入希望届（Excel, .xlsx/.xls）
  - 氏名（プルダウン: 役員マスタ）
  - 部署（プルダウン: 部署マスタ）
  - 概要（テキスト、100〜200文字目安）
  - ネット購入フラグ（チェック: コンビニ/振込/なし）
- 送信後: 受付番号を表示、メール/Slack等通知（任意: §13）

### 5.3 財務ページ
- リスト表示: 受付番号、提出者、部署、提出日時、希望額合計（Excel自動集計）、渡し額、概要、状態、ネット区分、添付
- 詳細画面:
  - 受理操作（受理/却下＋理由）
  - 状態遷移（コンボ）
  - 渡し額入力（現金/下ろし済み）
  - レシート/控えアップロード（画像/PDF複数可）
  - 返却額入力（現金お釣り・未使用分）
  - 自動検算結果（OK/差異±金額）
  - メモ/内部コメント
  - 監査ログ閲覧

### 5.4 管理者ページ
- 財務ページ機能＋以下:
  - マスタ管理: 組織、部署、役員（氏名/所属/有効フラグ）
  - リスト内容の編集（誤登録修正、状態巻き戻し）
  - パスワード変更（提出/財務/管理者）
  - エクスポート（CSV/Excel、期間・状態フィルタ）
  - バックアップ/リストア（アプリデータ・添付）

---

## 6. データモデル（ER 概要）
- **requests**（購入希望届）1 — n **request_items**（Excel行展開, 任意）
- **requests** 1 — n **receipts**（レシート/控え）
- **requests** n — 1 **members**（提出者）
- **requests** n — 1 **departments**
- **audit_logs**（全操作履歴）
- **masters**（組織/部署/役員など）

---

## 7. テーブル定義（MySQL想定）
```sql
CREATE TABLE departments (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

CREATE TABLE members (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL,
  department_id INT NOT NULL,
  is_officer TINYINT(1) NOT NULL DEFAULT 1,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  FOREIGN KEY (department_id) REFERENCES departments(id)
);

CREATE TABLE requests (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  request_no VARCHAR(30) UNIQUE,
  member_id INT NOT NULL,
  department_id INT NOT NULL,
  submitted_at DATETIME NOT NULL,
  summary VARCHAR(255) NOT NULL,
  expects_network ENUM('NONE','CONVENIENCE','BANK_TRANSFER') NOT NULL DEFAULT 'NONE',
  state ENUM(
    'NEW','ACCEPTED','REJECTED',
    'CASH_GIVEN','COLLECTED','RECEIPT_DONE',
    'CASH_WITHDRAWN','PAID',
    'TRANSFERRED'
  ) NOT NULL DEFAULT 'NEW',
  expected_total DECIMAL(12,2) DEFAULT NULL,  -- Excel自動集計
  cash_given DECIMAL(12,2) DEFAULT NULL,      -- 手渡し/下ろし済み額
  diff_amount DECIMAL(12,2) DEFAULT NULL,     -- 検算差額（+過不足）
  excel_path VARCHAR(255) NOT NULL,
  notes TEXT,
  rejected_reason VARCHAR(255),
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (member_id) REFERENCES members(id),
  FOREIGN KEY (department_id) REFERENCES departments(id)
);

CREATE TABLE request_items ( -- 任意：Excelを行ごとに展開
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  request_id BIGINT NOT NULL,
  purpose VARCHAR(255) NOT NULL,
  amount DECIMAL(12,2) NOT NULL,
  FOREIGN KEY (request_id) REFERENCES requests(id) ON DELETE CASCADE
);

CREATE TABLE receipts (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  request_id BIGINT NOT NULL,
  kind ENUM('RECEIPT','INVOICE','TRANSFER_SLIP','PAYMENT_SLIP') NOT NULL,
  total DECIMAL(12,2) NOT NULL,
  change_returned DECIMAL(12,2) DEFAULT 0.00, -- 現金お釣り/未使用分
  file_path VARCHAR(255) NOT NULL,
  taken_at DATETIME NOT NULL,
  memo VARCHAR(255),
  FOREIGN KEY (request_id) REFERENCES requests(id) ON DELETE CASCADE
);

CREATE TABLE audit_logs (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  request_id BIGINT,
  actor VARCHAR(50) NOT NULL,             -- role: officer/finance/admin
  action VARCHAR(50) NOT NULL,            -- ACCEPT, REJECT, STATE_CHANGE, EDIT, UPLOAD, ...
  detail TEXT,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX(req_actor_idx) (request_id, actor)
);

CREATE TABLE passwords (
  role ENUM('OFFICER','FINANCE','ADMIN') PRIMARY KEY,
  pass_hash VARCHAR(255) NOT NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);
```

---

## 8. ステータス遷移ルール（厳格）
- 現金手渡し: `ACCEPTED → CASH_GIVEN → COLLECTED → RECEIPT_DONE`
- ネット/コンビニ: `ACCEPTED → CASH_WITHDRAWN → PAID → RECEIPT_DONE`
- ネット/振込: `ACCEPTED → TRANSFERRED → RECEIPT_DONE`
- いずれも `NEW → ACCEPTED` は財務のみ。`REJECTED` は `NEW/ACCEPTED` から遷移可（理由必須）。
- 管理者のみ**巻き戻し**可（監査ログ必須）。

---

## 9. 入出力・ファイル取扱
- アップロード: Excel（.xlsx/.xls, 最大10MB）。
- レシート/控え: 画像（.jpg/.png）/PDF（最大10MB/点）。複数添付。
- 保存先: `/uploads/{yyyy}/{mm}/{request_no}/`
- ファイル名: `originalName_hhmmss_random.ext`
- ウイルススキャン（任意）・拡張子/実体MIME検証必須。

---

## 10. 自動検算ロジック
- **現金手渡し系**: `cash_given ＝ Σ(receipts.total) ＋ Σ(receipts.change_returned)` を判定。
  - 成立: `diff_amount = 0` → `RECEIPT_DONE` へ遷移可。
  - 不成立: `diff_amount = cash_given - (sum_total + sum_change)` を表示、是正要求。
- **ネット購入（振込/コンビニ）**:
  - 原則 `cash_given` は使用しない（※コンビニの「下ろし済み」は管理用の内部現金）。
  - `expected_total`, 実支払合計と一致するかを確認。

---

## 11. バリデーション
- 概要: 必須、最大255文字。
- 金額: 0 < 金額 ≤ 10,000,000 / 小数は2桁まで。
- 日時: 未来日禁止（支払・振込・領収日時）。
- ファイル: 拡張子＋MIME厳密チェック。サイズ上限。
- 状態操作: 直前状態のみ許可。ロール権限をチェック。

---

## 12. UI/UX
- リスト: 状態・期間・ネット区分・部署でフィルタ、提出者で検索。
- 並び替え: 提出日時降順（既定）。
- 詳細: 右ペイン編集/左リスト。保存時にトーストで結果表示。
- 色分け: 状態ごとにタグ色（NEW灰, ACCEPTED青, 進行中橙, 完了緑, REJECTED赤）。

---

## 13. 通知（任意導入）
- 新規受付/受理/却下/完了をメールまたはSlack Webhookで通知。
- 設定は管理者ページでON/OFFとWebhook URL。

---

## 14. セキュリティ
- 共有パスワードは `passwords` テーブルでBCrypt(>=10)ハッシュ管理。
- セッション固定化対策・CSRFトークン（フォーム毎）・XSS/SQLi対策（PDO＋プリペアド、エスケープ）。
- セッションタイムアウト: 20分（管理者/財務）、提出ページは10分。再ログイン必須。
- ファイルはアプリ直下でなく**別ディレクトリ** + PHP経由配信（認可チェック）。
- レート制限（ログイン/アップロード）。

---

## 15. 将来拡張（推奨）
- 個別アカウント＋二要素（TOTP）/SAML。
- 仕訳エクスポート（会計ソフトCSV連携）。
- 予算枠・科目別管理、年度締め処理。
- Excel自動取込（期待合計の算出と`request_items`生成）。

---

## 16. API設計（PHP, JSON）
ベースURL例: `/api`

### 認証
- `POST /auth/login` {role, password}
- `POST /auth/logout`

### マスタ
- `GET /masters/departments`
- `GET /masters/members?active=1`
- `POST /masters/...`（管理者のみ）

### 購入希望届
- `POST /requests` (提出: multipart form-data)
- `GET /requests?state&dept&network&from&to&q&page`
- `GET /requests/{id}`
- `POST /requests/{id}/accept`  /  `POST /requests/{id}/reject`
- `POST /requests/{id}/state`  {next_state}
- `POST /requests/{id}/cash`   {cash_given}
- `POST /requests/{id}/receipt` (ファイル, total, change_returned, kind, taken_at)
- `GET /requests/{id}/recalc` → {sum_total, sum_change, diff}
- `GET /requests/{id}/files/{file_id}`（認可配信）

### 監査
- `GET /requests/{id}/logs`

**レスポンス共通**: `{ ok: true|false, message?, data? }`

---

## 17. 画面遷移（概要）
- Login → Home(選択: 提出/財務/管理) → 各ダッシュボード
- 財務/管理: リスト → 詳細（サイドパネル） → モーダルで状態操作

---

## 18. バックエンド構成（PHP）
```
/public
  index.php             # ルーティング入口
  /assets               # js, css
/app
  /controllers          # AuthController, RequestController, MasterController
  /models               # Request.php, Receipt.php, Member.php, ...
  /services             # ExcelReader.php, ReceiptService.php, AuthService.php
  /views                # （必要に応じて）
  /lib                  # DB接続, CSRF, Validator
/storage
  /uploads/{yyyy}/{mm}/{request_no}/
```
- DB: PDO（例: MySQL 8）。
- Excel読取: PhpSpreadsheet（サーバ設置時に導入, 任意）。

---

## 19. フロントエンド構成（HTML/JS）
- 素のHTML＋Fetch API。
- 入力マスク（金額/日付）、フォームバリデーション。
- 状態タグ/トースト通知用の軽量コンポーネント。

---

## 20. ログ・監査
- すべての状態変更・金額入力・ファイル操作を`audit_logs`へ記録（actor, action, detail）。
- エラーはサーバログにスタックトレース。PIIは出力しない。

---

## 21. バックアップ/復旧
- DB: 1日1回スナップショット（保持30日）。
- 添付: オブジェクトストレージに日次同期。
- 復旧手順を管理者ページに記載（権限者のみ閲覧）。

---

## 22. 例外・エラー方針
- バリデーション失敗: 400（メッセージ詳細）
- 認証失敗: 401 / 認可失敗: 403
- 不正遷移: 409（現在状態を同梱）
- サーバエラー: 500（問い合わせID）

---

## 23. 受入基準（抜粋）
- [ ] 提出ページで必須入力がないと送信不可。
- [ ] Excel合計が`expected_total`に反映される（任意導入時）。
- [ ] 各フローの遷移制御が仕様通り。
- [ ] 検算ロジックで差額が0のときのみ完了可。
- [ ] ファイルは認可後にのみダウンロード可能。
- [ ] タイムアウト時、再認証を要求。

---

## 24. テスト観点（要約）
- 単体: バリデーション、遷移、検算、ファイル検証。
- 結合: 提出→受理→完了までの通し、3フロー各系統。
- 非機能: 認証・セッション、レート制限、最大ファイル、DB競合。

---

## 25. サンプルSQL（初期データ）
```sql
INSERT INTO departments(name) VALUES ('総務'),('財務'),('広報');
INSERT INTO members(name, department_id) VALUES ('山田太郎',1),('佐藤花子',2),('鈴木一郎',3);
INSERT INTO passwords(role, pass_hash) VALUES
 ('OFFICER', '$2y$10$...'),
 ('FINANCE', '$2y$10$...'),
 ('ADMIN',   '$2y$10$...');
```

---

## 26. 擬似コード（検算）
```php
function recalc($requestId){
  $r = getRequest($requestId);
  $sumTotal = sumReceipts($requestId, 'total');
  $sumChange = sumReceipts($requestId, 'change_returned');
  if($r['expects_network'] === 'NONE'){
    $diff = round(($r['cash_given'] ?? 0) - ($sumTotal + $sumChange), 2);
  } else {
    $diff = round(($r['expected_total'] ?? 0) - $sumTotal, 2);
  }
  updateRequest($requestId, ['diff_amount'=>$diff]);
  return ['sum_total'=>$sumTotal,'sum_change'=>$sumChange,'diff'=>$diff];
}
```

---

## 27. 権限マトリクス（抜粋）
| 機能 | 提出 | 財務 | 管理者 |
|---|---|---|---|
| 受付登録 | 可 | - | - |
| 受理/却下 | - | 可 | 可 |
| 状態遷移 | - | 可 | 可（巻戻し可）|
| マスタ編集 | - | - | 可 |
| パス変更 | - | - | 可 |
| エクスポート/バックアップ | - | 一部 | 可 |

---

## 28. 既知のリスクと対策
- 共有PWの漏洩: 定期変更・レート制限・IP制限（任意）。
- Excel依存: 読取失敗時は手入力フォールバック（`request_items`省略可）。
- 添付の肥大: 期間でアーカイブ、サムネイル生成。

---

## 29. 導入・運用
- PHP 8.2 / Nginx or Apache / MySQL 8 / Composer（任意）。
- .env でDB接続やアップロードパスを管理。
- 本番はHTTPS必須、`X-Frame-Options` 等のヘッダ設定。

---

## 30. 変更履歴
- v1.0 初版作成。

