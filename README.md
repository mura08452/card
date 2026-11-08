> GitHub からの更新方法は [deploy/README.md](deploy/README.md) を参照してください。

# 今回の更新：仮アカウント＋任意の外部連携

Google／Xの設定が空欄でも名刺作成・交換を利用できます。Cookieには256bitのランダムトークン、DBにはそのSHA-256ハッシュだけを保存します。端末Cookieは180日で失効し、ブラウザー側の削除でも利用できなくなります。長期保管・別端末での復帰には設定から外部アカウントを連携してください。

## すでに設置済みの場合

SQLite版からの移行は [MySQLへの切り替え手順](docs/mysql.md) を参照してください。

未連携でログアウトすると、元の名刺へ原則復帰できません。ログアウト後の再アクセスでは新しい仮アカウントを作成します。「端末データ削除」はオフライン保存だけを削除し、認証Cookieは残す動作です。外部アカウントへの切り替えは今の名刺や会った記録を統合しません。未同期の変更がある場合は切り替えを停止します。

仮アカウントの自動削除・発行レート制限は未実装です。公開規模が大きくなる場合は容量と発行数を監視してください。Google／Xの本番接続確認は別途必要です。

---

# TRAIL CARD — さくら向け PHP / MySQL 版

PHP 8.2 以上を対象（PHP 8.3 CLIで検証）。フレームワーク・Composer・Node.jsは本番サーバーに不要です。
既存のSites版とは別の配布版です。SitesでPHPを実行するものではありません。

## 構成

- `public/`：Web公開するファイルだけ。既存の画面、テンプレート、QR、PWA、PHP入口。
- `private/`：公開ディレクトリーの外。設定、API、画像、セッション、バックアップ。
- MySQLはPDO経由。画像は `private/data/images/`。データファイルをURLで直接配信しません。
- Google / X の認証後、プロバイダーとユーザーIDをHMAC化して保存。メール、本名、パスワード、外部アクセストークンは永続保存しません。手入力した名刺・GPS・メモは保存対象です。
- PHPセッションはSecure/HttpOnly/SameSite=Lax、Cookie Pathは `/card/`。セッションは最大7日、端末認証Cookieは発行から180日。セッション失効後も有効な端末Cookieから復帰します。ログアウト時にはこの設置先専用の端末データとキャッシュだけを削除します。Service Workerの制御範囲も `/card/` 内です。
- MySQLのInnoDB行ロックを使用します。競合は409で検知し上書きを防ぎます。ロック待ち超過は再試行可能な503。

## 1. サーバーの準備

1. PHP 8.2以上を選択。`pdo_mysql`、`curl`、`fileinfo` が必要です。Google認証には `openssl` も必要です。
2. 既存の `over.rulez.jp` のWeb公開フォルダー内に `card` フォルダーを作成します。既存サイトのWeb公開フォルダー設定は変更しません。さくらの共有SSLでHTTPSが有効であることを確認します。
3. 配布物の `public/` の**中身**（`.htaccess`含む）を作成した **`card/` フォルダー内**へアップロード。
4. `private/` は `/home/ACCOUNT/trail-card-private` へアップロード。**www の中には置かないでください。**
5. 公開側 `index.php` の `$private` を `/home/ACCOUNT/trail-card-private` に変更。
6. `private/config.example.php` を `private/config.php` にコピーして設定。

この版の設置先は **`https://over.rulez.jp/card/`** です。`over.rulez.jp` のWeb公開フォルダーが `/home/ACCOUNT/www` の場合、配置先は `/home/ACCOUNT/www/card` です。公開フォルダーが別の場所なら、その中に `card` を作成してください。ルートの `index.php` や `.htaccess` は上書きせず、配布物の `.htaccess` は `card/` 内だけに配置します。ルート側に既存のrewrite設定がある場合、実サーバーで `/card/` が既存アプリへ転送されないことを確認してください。

## 2. 設定

`base_url` は **`https://over.rulez.jp/card`** にします（末尾スラッシュなし）。`data_dir` は公開フォルダーの外を指定。

[設定例](private/config.example.php) の `mysql` に専用DBの接続情報を設定します。SSH接続後、サーバーで利用可能なPHPコマンドを使って実行します（コマンド名は契約環境に合わせてください）。

```sh
php -v
php -m
php -r 'echo bin2hex(random_bytes(32)), PHP_EOL;'
```

生成した64桁の値を `identity_key` に設定。このキーを変更すると同じ外部アカウントを識別できなくなるため、DBと一緒に厳重に保管します。キーやClient Secretをチャットへ貼らないでください。

```sh
chmod 700 /home/ACCOUNT/trail-card-private
chmod 600 /home/ACCOUNT/trail-card-private/config.php
php /home/ACCOUNT/trail-card-private/bin/init.php
```

初期化はCLI専用です。SSHが使えないプランでは、契約環境に応じた初期化手順を別途用意してください。ブラウザから実行できる初期化ページは置きません。

## 3. Google / X のアプリ登録

Google認証の具体的な設定手順は [docs/google-auth.md](docs/google-auth.md) を参照してください。

利用するプロバイダーだけ `client_id` / `client_secret` を設定。未設定のログイン方法は画面に出ません。

| 項目 | Google | X |
| --- | --- | --- |
| アプリ種別 | OAuth Webアプリケーション | OAuth 2.0 Web App（Confidential） |
| コールバックURL | `https://over.rulez.jp/card/auth/callback?provider=google` | `https://over.rulez.jp/card/auth/callback?provider=x` |
| 要求スコープ | `openid` | `users.read tweet.read` |

コールバックURLは完全一致で登録します。Google同意画面の公開/テストユーザー設定、X APIの利用条件・アクセス権を各管理画面で確認してください。Xの投稿権限、メール、DM、refresh tokenは要求しません。`tweet.read` は認証スコープとして要求しますが投稿取得処理はありません。

認証フロー：state照合・10分の期限・PKCE S256 → サーバーでコード交換 → Googleは署名・発行元・宛先・期限・nonceを検証したIDトークンで本人確認、Xは固定userinfo APIでID確認 → セッションID再生成。トークンは保存しません。

**初回アクセスで仮アカウントが作成されます。設定からGoogleとXを同じ名刺へ連携できます。別アカウントに連携済みの場合は自動統合せず拒否します。「以前のアカウントを使う」は確認後に切り替えます。** Apple認証はこの配布版では未実装です。

参考（実装時確認）：
- https://developers.google.com/identity/openid-connect/openid-connect
- https://docs.x.com/fundamentals/authentication/oauth-2-0/authorization-code

## 4. 設置後の確認

- HTTPSで開き、仮アカウント自動作成 → 名刺登録 → テンプレート/アップロード画像 → 再読込。
- 異なる2アカウントで、QR読取 → 申請 → 相手による承認 → 名刺帳登録。
- 双方でメモ/GPSを入力し、相手には見えないことを確認。
- 一度オンラインで開いた後、圏外で再度開き、キャッシュ済み名刺の閲覧と記録保存。通信復帰後に同期。
- セッション切れ時はオンラインで再ログイン。ログアウト後のオフライン利用はできません。
- iPhone Safari / Android Chrome のカメラ・GPS・ホーム画面追加を確認。
- 画面でJSON書出し。サーバーのDBと画像は下記手順でもバックアップ。

仮アカウントの初回発行・外部アカウント連携・復帰・交換申請/承認はオンライン必須。QRは15分で期限切れになります。**双方がQRを読む圏外交換は今回追加していません。** 端末のストレージが消えると未同期データは復元できません。バックグラウンド同期は保証せず、アプリを開いて通信復帰した際に同期します。

`.htaccess` のrewrite、HTTPSリダイレクト、PHP実行権限は実サーバーで確認してください。DB・画像フォルダー・設定・バックアップは公開先にアップロードしないでください。Webサーバーのアクセスログは別途保持される可能性があります。

## 5. バックアップ / 復旧

```sh
php /home/ACCOUNT/trail-card-private/bin/backup.php
```

非公開 `data/backups/日時-乱数/` に、MySQLの一貫したSQLダンプ、DBから参照中の画像、設定を保存します。画像は更新時に別ファイルとして作成し、現在は自動削除しません。バックアップには秘密情報が含まれます。必要に応じてcron登録し、暗号化した別の場所にも保管してください。世代削除は自動化していません。

復元は [MySQLへの切り替え手順](docs/mysql.md) を参照してください。

## 6. 現在のSites版からの移行

この配布物には実ユーザーデータや秘密情報を含めていません。SitesのDB/R2からの自動移行は実行していません。

ChatGPT認証とGoogle/X認証のIDは異なります。メール等からの自動名寄せはしません。Sites版から移行する際は、本人確認済みのアカウント対応表、DBと画像の書出し、接続関係と会った記録を含む変換・検証が別途必要です。現在のJSON書出しは保管用で、全履歴を復元するインポートは未実装です。新しい設置先では新規登録から始まります。

## 7. 検証・未対応

開発用MySQLの接続先を `MYSQL_HOST`、`MYSQL_USER`、`MYSQL_PWD` に設定し、`sh tests/run.sh` を実行します。一時DBの作成・削除権限が必要です。

APIテストは一時MySQLで認可、交換の冪等性、私的記録、更新競合、画像検査・所有権、期限切れ、CSRFを確認します。認証テストはstate/PKCE/nonce生成、コールバック拒否、Googleトークン署名・各claim検証、連携時の名刺維持を確認します。Google/Xの実ログイン、さくら実機、スマホ実機は設置後の確認が必要です。

公開プロフィール、アカウント削除、通報/ブロック、画像の自動掃除、外部アカウントの連携解除・アカウント統合は未実装です。

## 更新時

`public/sw.js` のSHELL/IMAGESキャッシュバージョンを必要に応じて更新し、新しい画面資産を読み込ませてください。`private/config.php` と `private/data/` は上書き対象から除外します。

## サブフォルダー版の補足

画面資産、API、画像、QR交換URL、認証コールバック、ログアウト後の戻り先が `/card/` に対応しています。CSRFのOrigin検証は `https://over.rulez.jp` を照合します。

別パスへ設置する場合は `base_url` と `public/.htaccess` の `RewriteBase` を合わせて変更してください。以前のルート設置版の保存データをこの版へ自動移行する処理はありません。

## ソースの読みやすさ

PHPは文ごとの改行とブロックの字下げを行っています。整形前後のPHPトークン列（空白を除く）が同一であることを照合しました。SQL・文字列・演算・分岐は変更していません。

フロントエンドの読みやすい参照版は `readable/` にあります。実配信する `public/` のJavaScript・CSS・HTML等は転送量を増やさないため変更していません。参照版だけを編集しても本番には反映されません。詳細は `readable/README.md` を参照してください。

今回の整形ではJavaScriptの構文チェックと既存の通知・サブパステストを通過しています。PHP実行環境の再取得ができなかったため、PHP実行テストは再実施せず、PHPパーサーによる構文確認とトークン同一性で検証しました。実環境での性能測定は行っていません。
