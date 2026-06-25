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

表示されたURLをブラウザで開き、Gmailアクセスを許可してください。認可が完了すると `credentials/gmail_token.json` が作成されます。このバッチは処理済みメールへGmailラベルを付けるため、OAuthスコープは `gmail.modify` を使用します。

`gmail_token.json` には `refresh_token` が含まれます。バッチ実行時に `access_token` が期限切れの場合は自動更新します。

ローカル実行で `unable to get local issuer certificate` が出る場合は、PHP/cURL がCA証明書バンドルを見つけられていません。Git for Windowsなどに含まれる `ca-bundle.crt` のパスを `.env` に設定してください。

```env
CURL_CA_BUNDLE=D:\Program Files\Git\mingw64\etc\ssl\certs\ca-bundle.crt
```


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
- 正常に反映できた注文確認メール・注文受付メール、またはNotion側の状態から既に処理済みと判定できたメールにはGmailの処理済みラベルを付け、次回以降の検索ではそのラベル付きメールを除外します。
- チケット番号はフォーム回答欄に記載された値をそのまま使用します。`B13495` と数字4桁の `1234` の両方に対応します。
- 注文確認メールは、対象日の状況が `注文済` または `受付済` ならスキップします。
- 注文受付メールは、日付で対象レコードを探して `受付済` と受付確認メールURLを更新します。
- 1件のメールでエラーが出ても、他のメール処理は継続します。

## メール解析設定

Gmail検索条件とGoogleフォーム回答欄の質問文は `.env` で変更できます。未設定の場合は現在の松屋お弁当フォーム向けの既定値を使用します。

```env
MAIL_ORDER_FROM=forms-receipts-noreply@google.com
MAIL_ORDER_SUBJECT=フォームにご記入いただきありがとうございます
MAIL_RECEIPT_SUBJECT=【松屋】お弁当注文受付確認
GMAIL_PROCESSED_LABEL_NAME=order-lunch-status-processed
MAIL_FIELD_DATE_LABELS=お子様がお弁当を召し上がる日付を記載してください|お弁当を召し上がる日付
MAIL_FIELD_TICKET_LABELS=お手持ちのお弁当券に記載してある数字4ケタのお弁当ナンバー|お弁当ナンバー|お弁当番号
MAIL_FIELD_ITEM_LABELS=品名|注文したお弁当|お弁当の種類|メニュー|アレルギー物質
MAIL_FIELD_SIZE_LABELS=ライスの量|ご飯の量|サイズ
MAIL_FIELD_NOTE_LABELS=備考|ご要望
MAIL_FIELD_NOTE_APPEND_LABELS=カレーの種類|ソースの種類
MAIL_KNOWN_ITEMS=牛めし（A券：牛めし）|キムチ牛めし（B券：定食・丼）|唐揚げ定食（B券：定食・丼）|ふわ玉あんかけ牛めし（B券：定食・丼）|ふわとろあんかけ牛めし（B券：定食・丼）|チキンかつカレー（B券：定食・丼）|ソース（味噌）かつ定食（B券：定食・丼）
MAIL_SETTINGS_PASSWORD_HASH=
MAIL_NOTION_PROPERTY_MAPPINGS_JSON=[]
MAIL_NOTION_PROPERTY_MAPPINGS_PATH=
```

複数の質問文や品名候補は `|` 区切りで指定します。`MAIL_FIELD_NOTE_APPEND_LABELS` に指定した質問項目は、回答がある場合に `質問項目: 回答` の形式で備考へ追記します。`MAIL_ORDER_FROM` を空にすると、注文確認メール検索では送信元条件を付けずに件名と `LOOKBACK_DAYS` だけで検索します。`GMAIL_PROCESSED_LABEL_NAME` は処理済みメールへ付けるGmailラベル名です。空にするとラベル付与と検索除外を無効化します。

`GMAIL_PROCESSED_LABEL_NAME` のラベルがGmailに存在しない場合は、初回のラベル付与時に自動作成します。既存の `gmail.readonly` トークンではラベル付与できないため、古い `credentials/gmail_token.json` を削除し、`php gmail_auth.php` を再実行して `gmail.modify` の権限でトークンを作り直してください。

追加のGoogleフォーム回答をNotionプロパティへ反映する場合は、`MAIL_NOTION_PROPERTY_MAPPINGS_JSON` または `MAIL_NOTION_PROPERTY_MAPPINGS_PATH` でJSON配列を指定します。既存の注文更新payloadは固定のまま維持し、ここで指定した追加プロパティだけを更新に加えます。既存payloadと同じNotionプロパティ名を指定した場合は既存payloadを優先します。

```json
[
  {
    "key": "curry_type",
    "mail_labels": ["カレーの種類"],
    "notion_property": "カレーの種類",
    "notion_type": "select"
  }
]
```

`notion_type` は `rich_text`, `select`, `title`, `url`, `number`, `checkbox` を指定できます。未指定時は `rich_text` です。

### メール解析設定のWeb編集

`mail_settings.php` をブラウザで開くと、上記のメール解析設定をWeb画面から編集できます。リスト項目は1行1項目で入力し、保存時に `.env` へ `|` 区切りで書き戻します。その他の `.env` 項目は保持します。

公開環境で使用する場合は、必ず `.env` に `MAIL_SETTINGS_PASSWORD_HASH` を設定してください。未設定の場合、`mail_settings.php` は `localhost` からのアクセスだけを許可します。

パスワードハッシュは以下のように生成できます。入力したパスワードそのものは `.env` に保存せず、出力されたハッシュ値だけを `MAIL_SETTINGS_PASSWORD_HASH` に設定します。

```powershell
php -r 'fwrite(STDERR, "Password: "); $p = trim(fgets(STDIN)); echo password_hash($p, PASSWORD_DEFAULT), PHP_EOL;'
```

```bash
php -r 'fwrite(STDERR, "Password: "); $p = trim(fgets(STDIN)); echo password_hash($p, PASSWORD_DEFAULT), PHP_EOL;'
```


## ログ

ログの出力単位は `.env` の `LOG_OUTPUT_UNIT` で制御できます。既定値は `daily` です。

```text
LOG_OUTPUT_UNIT=daily    # logs/lunch_batch_YYYYMMDD.log
LOG_OUTPUT_UNIT=monthly  # logs/lunch_batch_YYYYMM.log
LOG_OUTPUT_UNIT=single   # logs/lunch_batch.log
```

処理件数、スキップ件数、エラー件数、各エラー詳細を記録します。

## 実行時間制御

Hostinger側のcronは毎時起動にし、PHP側で実行してよい時間帯を制御します。既定では `TIMEZONE` の時刻で9時から23時までだけ本処理を実行し、それ以外の時間帯はログを出して正常終了します。

```env
RUN_WINDOW_ENABLED=true
RUN_WINDOW_START_HOUR=9
RUN_WINDOW_END_HOUR=23
```

## Slack通知

処理結果をSlack Incoming Webhookに通知できます。既定では無効です。

```env
SLACK_NOTIFICATION_ENABLED=false
SLACK_WEBHOOK_URL=
```

通知する場合は `SLACK_NOTIFICATION_ENABLED=true` に変更し、`SLACK_WEBHOOK_URL` にIncoming Webhook URLを設定してください。

## cron例

```cron
0 * * * * cd /path/to/project && /usr/bin/php batch_lunch_order.php
```
