# GitHub からさくらへ反映する

対象は既存の `https://over.rulez.jp/card/` です。初回設置はルートの README を参照してください。
通常の push / PR ではテストだけ実行します。本番反映は Actions の **Deploy to Sakura → Run workflow → main** から手動起動します。テスト成功後だけ反映します。

## 最初に設定するもの

GitHub の Settings → Secrets and variables → Actions で以下を登録します。

Variables:

| 名前 | 設定値 |
| --- | --- |
| `SAKURA_HOST` | SSH 接続に使用するさくらのサーバー名 |
| `SAKURA_USER` | さくらのアカウント名 |
| `SAKURA_PUBLIC_DIR` | `/home/実アカウント名/www/card` |
| `SAKURA_PRIVATE_DIR` | `/home/実アカウント名/trail-card-private` など既存 private の絶対パス |
| `SAKURA_PHP` | PHP 8.2 以上の CLI の絶対パス。未指定時 `/usr/local/bin/php` |

Secrets:

| 名前 | 設定値 |
| --- | --- |
| `SAKURA_SSH_KEY` | デプロイ専用 SSH 秘密鍵の全文（パスフレーズなし） |
| `SAKURA_KNOWN_HOSTS` | 接続先のホスト鍵を検証した known_hosts の該当行 |

公開鍵をさくら側の `~/.ssh/authorized_keys` に登録してください。秘密鍵をチャットやリポジトリには貼り付けないでください。
ホスト鍵の指紋は、既存の信頼できる SSH 接続またはさくら側の案内で照合します。`ssh-keyscan` の結果だけを無条件で信頼しないでください。
SSH はポート22を使用します。ウェブの PHP 設定とは別に、CLI のバージョンと `pdo_sqlite` / `curl` / `fileinfo` が利用できることを確認してください。

## 反映時の処理

1. テストと構文検査。
2. 非公開の `~/.trail-card-deploy/実行ID-試行回数` へ転送。
3. 設置先と設定の検証、二重反映のロック。
4. 現在のコードを private の `deploy-backups` へ保存。
5. `/card/` のみ一時的に HTTP 503 にし、SQLite・参照画像・設定をバックアップ。
6. private の src/bin/schema と公開ファイルを更新し、DB マイグレーション・整合性検査。
7. `index.php` の private パスを設定値に合わせて自動生成し、公開を再開。

既存 `config.php`、SQLite、画像、セッションを上書き・削除しません。ほかのサイトや www 直下の .htaccess は変更しません。既存の未知の公開ファイルも削除しません。
反映中は短いメンテナンス時間が発生します。すでに処理中のリクエストを強制終了する仕組みはないため、利用が少ない時間帯に実行してください。
バックアップ・ステージングは自動削除しません。容量を定期確認し、復旧用の世代を残して手動整理してください。
初回はスマホで名刺表示・保存・交換を確認してください。SSH 上の成功だけではウェブ側の PHP 設定や Apache 動作まで保証しません。

## 失敗時

事前検査の失敗なら公開ファイルは変更しません。メンテナンス開始後の失敗なら、混在したコードを公開しないよう HTTP 503 のまま停止します。
`private/.deploy-pending` に、今回の変更前コードのバックアップ先が記録されています。失敗原因を調べるまではこのファイルを削除しないでください。

今回の移行はテーブル追加型です。コードだけ戻す場合は SSH で次を行います（パスは実環境に合わせて変更）。

```sh
PRIVATE=/home/ACCOUNT/trail-card-private
PUBLIC=/home/ACCOUNT/www/card
BACKUP=$(cat "$PRIVATE/.deploy-pending")
# まず BACKUP が今回のバックアップ先であることを確認する
printf '%s\n' "$BACKUP"
cp -Rp "$BACKUP/private/src/." "$PRIVATE/src/"
cp -Rp "$BACKUP/private/bin/." "$PRIVATE/bin/"
cp -p "$BACKUP/private/schema.sql" "$PRIVATE/schema.sql"

```

公開ファイルは SFTP などで `BACKUP/public/` の内容を `PUBLIC/` に戻し、ディレクトリー755・ファイル644に設定してください。**.htaccess は最後に戻して**公開を再開します。復旧確認後に `.deploy-pending` を削除します。
DB 復元はコードの巻き戻しとは別作業です。DB を戻すとバックアップ後の記録を失うため、自動復元はしません。将来、破壊的なスキーマ変更を追加する場合は別途復旧計画を用意してください。
