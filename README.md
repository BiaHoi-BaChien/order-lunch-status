# お弁当注文状況 Gmail解析バッチ

Gmailから松屋のお弁当注文確認メール・注文受付メールを検索し、Notionの「お弁当注文状況」データソースと「お弁当購入チケット管理」データソースを更新するPHP CLIバッチです。

## 実行

```powershell
Copy-Item .env.example .env
php batch_lunch_order.php
```

`.env` に `NOTION_API_KEY`、`NOTION_ORDER_DATA_SOURCE_ID`、`NOTION_TICKET_DATA_SOURCE_ID` を設定し、Gmail OAuth用のクライアントJSONを `credentials/gmail_credentials.json` に配置してください。

初回のみ、以下でGmail OAuthトークンを作成します。

```powershell
php gmail_auth.php
```

表示されたURLをブラウザで開き、Gmailアクセスを許可してください。認可が完了すると `credentials/gmail_token.json` が作成されます。

`gmail_token.json` には `refresh_token` が含まれます。バッチ実行時に `access_token` が期限切れの場合は自動更新します。


## Notion設定

Notion APIは `Notion-Version: 2026-03-11` を使用します。このバージョンではDB行の検索・ページ作成はデータベースIDではなくデータソースIDを指定します。

```env
NOTION_ORDER_DATA_SOURCE_ID=
NOTION_TICKET_DATA_SOURCE_ID=
```

既存の `NOTION_ORDER_DB_ID` / `NOTION_TICKET_DB_ID` は使用しません。
## 処理内容

- 起動日から30日後までのNotion初期レコードを日付キーで確認し、不足分だけ作成します。
- 土日は `利用しない`、平日は `未注文` にします。
- 過去7日分のGoogleフォーム回答メールを解析し、注文日付・チケット番号・品名・サイズ・備考を抽出します。
- チケット番号はフォーム回答欄に記載された値をそのまま使用します。`B13495` と数字4桁の `1234` の両方に対応します。
- 注文確認メールは、対象日の状況が `注文済` または `受付済` ならスキップします。
- 注文受付メールは、日付で対象レコードを探して `受付済` と受付確認メールURLを更新します。
- 1件のメールでエラーが出ても、他のメール処理は継続します。

## ログ

ログの出力単位は `.env` の `LOG_OUTPUT_UNIT` で制御できます。既定値は `daily` です。

```text
LOG_OUTPUT_UNIT=daily    # logs/lunch_batch_YYYYMMDD.log
LOG_OUTPUT_UNIT=monthly  # logs/lunch_batch_YYYYMM.log
LOG_OUTPUT_UNIT=single   # logs/lunch_batch.log
```

処理件数、スキップ件数、エラー件数、各エラー詳細を記録します。

## cron例

```cron
0 6 * * * cd /path/to/project && /usr/bin/php batch_lunch_order.php
```