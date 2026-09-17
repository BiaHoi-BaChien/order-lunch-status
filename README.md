# お弁当注文状況 Gmail解析バッチ

Gmailから松屋とRAMEN KIMURAのお弁当注文メールを検索し、Notionの「お弁当注文状況」データソースと「お弁当購入チケット管理」データソースを更新するPHP CLIバッチです。

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
- 松屋は従来どおり2通で処理します。注文確認メールで `注文済` に更新し、既に `注文済` または `受付済` ならスキップします。注文受付メールで日付に対応するレコードを `受付済` に更新し、受付確認メールURLを記録します。
- RAMEN KIMURAは「ご注文を承りました」メール1通で、日付・店舗・品名・合計金額を登録し、直接 `受付済` に更新します。同じメールのURLを `注文確認メール` と `受付確認メール` に記録します。QR画像があればページ本文へ追加し、なくても注文更新は継続します。別の注文が同日に登録済みの場合は上書きせずエラーにします。
- RAMEN KIMURAの処理件数は注文受付メールに集計します。松屋の注文確認メールを処理した後、残りの処理枠を松屋の受付メールとKIMURAの単一メールに割り当てます。
- 1件のメールでエラーが出ても、他のメール処理は継続します。
- 1回の起動で処理対象にするGmailメッセージは `GMAIL_MAX_MESSAGES_PER_RUN`（既定100件）までに制限します。
- 同じ設置先でバッチが重複起動した場合、後から起動した処理は安全にスキップします。

## メール解析設定

Gmail検索条件は `.env` で変更できます。松屋の注文確認メールは、新フォーマットの `[YYYY-MM-DD]`、`お弁当券ナンバー`、`メニュー`、`サイズ`、`カスタマイズ`、`その他の要望` を解析します。

RAMEN KIMURAの「ご注文を承りました」メールは、事前チャージ払いの `YYYY-MM-DD 氏名`・`商品名 × 数量 金額 VND`・`お支払い` 形式に対応します。品名に日付・数量・価格を含めず、支払額を備考へ記録します。数量が2以上の場合は備考に数量も記録します。1通に複数の日付・商品がある場合や、テキスト本文とHTML本文の注文内容が異なる場合は、誤登録を避けるためエラーにします。本文の `注文日`・`メニュー`・`合計金額` 形式の解析も引き続き利用できます。

```env
MAIL_MATSUYA_ORDER_FROM=forms-receipts-noreply@google.com
MAIL_MATSUYA_ORDER_SUBJECT=フォームにご記入いただきありがとうございます
MAIL_MATSUYA_RECEIPT_FROM=送信元アドレス1|送信元アドレス2
MAIL_MATSUYA_RECEIPT_SUBJECT=【松屋】お弁当注文受付確認
MAIL_RAMEN_KIMURA_ORDER_FROM=tobe.kimura@gmail.com
MAIL_RAMEN_KIMURA_ORDER_SUBJECT=ご注文を承りました
GMAIL_PROCESSED_LABEL_NAME=order-lunch-status-processed
MAIL_SETTINGS_PASSWORD_HASH=
MAIL_MATSUYA_NOTION_PROPERTY_MAPPINGS_JSON=[]
MAIL_MATSUYA_NOTION_PROPERTY_MAPPINGS_PATH=
```

各FROM設定の送信元アドレスは `|` 区切りで複数指定できます。複数指定した場合はOR条件で検索・照合します（全角の `｜` も使用できます）。松屋とRAMEN KIMURAの各FROM設定は、Gmail検索だけでなく `From` ヘッダーとGmailのDMARC/DKIM認証結果、または送信元アドレスと完全一致するSPF認証結果の検証にも使用します。`GMAIL_PROCESSED_LABEL_NAME` は処理済みメールへ付けるGmailラベル名です。空にするとラベル付与と検索除外を無効化します。

RAMEN KIMURAの送信元は既存の `MAIL_RAMEN_KIMURA_ORDER_FROM` を引き継ぎます。`MAIL_RAMEN_KIMURA_ORDER_SUBJECT` が未設定・空、または旧既定値 `【お弁当注文確認】` の場合は、新件名 `ご注文を承りました` を使用します。それ以外の独自設定は保持するため、必要に応じてメール設定画面で新件名へ変更してください。件名の前後の空白は除去します。旧 `MAIL_RAMEN_KIMURA_RECEIPT_FROM` / `MAIL_RAMEN_KIMURA_RECEIPT_SUBJECT` は使用しません。

既に処理済みラベルが付いたメールは自動では再検索しません。旧処理で `注文済` になった「ご注文を承りました」メールを再処理する場合は、そのメールだけ処理済みラベルを外し、`LOOKBACK_DAYS` の検索期間内でバッチを実行してください。同じメールURLなら `受付済` まで更新し、QR画像を重複追加しません。

`GMAIL_PROCESSED_LABEL_NAME` のラベルがGmailに存在しない場合は、初回のラベル付与時に自動作成します。既存の `gmail.readonly` トークンではラベル付与できないため、古い `credentials/gmail_token.json` を削除し、`php gmail_auth.php` を再実行して `gmail.modify` の権限でトークンを作り直してください。

松屋の新しい注文確認メールに含まれる追加項目をNotionプロパティへ反映する場合は、`MAIL_MATSUYA_NOTION_PROPERTY_MAPPINGS_JSON` または `MAIL_MATSUYA_NOTION_PROPERTY_MAPPINGS_PATH` でJSON配列を指定します。既存の注文更新payloadは固定のまま維持し、ここで指定した追加プロパティだけを更新に加えます。既存payloadと同じNotionプロパティ名を指定した場合は既存payloadを優先します。

```json
[
  {
    "key": "customization",
    "mail_labels": ["カスタマイズ"],
    "notion_property": "カスタマイズ",
    "notion_type": "select"
  }
]
```

`notion_type` は `rich_text`, `select`, `title`, `url`, `number`, `checkbox` を指定できます。未指定時は `rich_text` です。

### メール解析設定のWeb編集

`mail_settings.php` をブラウザで開くと、メールの送信元と件名を松屋とRAMEN KIMURAの店舗別に編集できます。その他の `.env` 項目は保持します。

`mail_settings.php` を使用する場合は、必ず `.env` に `MAIL_SETTINGS_PASSWORD_HASH` を設定してください。未設定の場合はlocalhostを含むすべてのアクセスを拒否します。

このプロジェクトをWebサーバーのDocumentRoot配下に設置する場合は、検索エンジンやAIクローラーに発見されにくくするため、同梱の `.htaccess` と `robots.txt` も配置してください。`.htaccess` は `X-Robots-Tag: noindex, nofollow, noarchive, nosnippet, noimageindex` を返し、`robots.txt` は全クローラーに全パスのクロール拒否を通知します。これは公開URLを知っている利用者のアクセス制御ではないため、`mail_settings.php` には必ずパスワードを設定してください。

パスワードハッシュは以下のように生成できます。入力したパスワードそのものは `.env` に保存せず、出力されたハッシュ値だけを `MAIL_SETTINGS_PASSWORD_HASH` に設定します。

```powershell
php -r "fwrite(STDERR, 'Password: '); echo password_hash(trim(fgets(STDIN)), PASSWORD_DEFAULT), PHP_EOL;"
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

1回あたりのGmail処理件数は次で制限できます（1〜1000件）。大量の未処理メールがある場合も、次回以降の起動で続きを処理します。

```env
GMAIL_MAX_MESSAGES_PER_RUN=100
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
