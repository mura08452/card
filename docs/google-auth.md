# Googleアカウント連携を有効にする

通常は仮アカウントのまま名刺を利用し、設定からGoogleを任意で連携します。
Google連携は今の名刺IDを維持します。別端末では「Googleで以前のアカウントを使う」から戻れます。

## 1. Google CloudでOAuthクライアントを作成

1. https://console.cloud.google.com/ で、このアプリ用のプロジェクトを作成または選択します。
2. Google Auth Platform の Branding（ブランディング）で、アプリ名・サポート用メール・開発者連絡先を登録します。
3. Audience（対象）を External（外部）にします。まず Testing（テスト）で自分のGoogleアカウントをテストユーザーに登録して試します。
4. Clients（クライアント）から OAuth クライアントを作成し、種類を **ウェブアプリケーション** にします。
5. 承認済みのリダイレクトURIに、次のURLを完全一致で登録します。

```text
https://over.rulez.jp/card/auth/callback?provider=google
```

末尾の `/` を追加しないでください。`?provider=google` も含みます。
この実装はサーバー側のリダイレクト方式です。JavaScript SDKは使わないため、承認済みJavaScript生成元は必須ではありません。設定する場合は `https://over.rulez.jp` です。
Data Access（データアクセス）は `openid` のみを使用します。氏名・メール・プロフィール・Drive等の権限を追加しないでください。
一般公開前にAudienceを本番公開に変更し、Googleが要求するホームページ・プライバシーポリシー・ドメイン確認等を完了してください。Google側の表示や審査条件によって追加対応が必要になる場合があります。

## 2. サーバーの設定に追記

既存の `/home/gourd/trail-card-private/config.php` の `providers.google` だけを編集します。

```php
'providers' => [
    'google' => [
        'client_id' => '取得したクライアントID.apps.googleusercontent.com',
        'client_secret' => '取得したクライアントシークレット',
    ],
    'x' => ['client_id' => '', 'client_secret' => ''],
],
```

既存の `base_url`・`data_dir`・`identity_key` を変更しないでください。設定ファイルを配布版で置き換えないでください。
Client SecretをGitHubやチャットに貼らないでください。設定は非公開領域に置き、ファイル権限600を維持します。
設定値はGitHub Actions Secretsではなく、実際に動くPHPアプリの `config.php` に入れます。デプロイ処理は既存の設定を保持します。
サーバーのCLIとWeb用PHP双方で `openssl` と `curl` が利用可能であることを確認します。

## 3. コードを反映・確認

1. GitHub Actionsの「Deploy to Sakura」→「Run workflow」→ main で反映します。
2. https://over.rulez.jp/card/ を開き、名刺を登録した端末で「設定」へ進みます。
3. 「Googleと連携」→Googleアカウントを選択して許可します。
4. アプリに戻り、設定に「連携済み：Google」と表示され、名刺・記録が維持されていることを確認します。
5. 別ブラウザーや別端末を開き、設定の「Googleで以前のアカウントを使う」で同じGoogleアカウントを選択します。元の名刺・記録に戻ることを確認します。

「Googleと連携」は現在の名刺への紐付け、「Googleで以前のアカウントを使う」は以前の名刺への切り替えです。
同じGoogleアカウントが別の名刺に連携済みなら連携を拒否します。名刺や会った記録を自動統合しません。
切り替え前には未同期の変更を同期してください。現在の端末が未連携なら、切り替え後に元の名刺へ戻る手段がなくなる場合があります。

## 保存する情報

- GoogleのユーザーIDをプロバイダー名と合わせてHMAC化した連携キー、既存のアプリ内ID、連携先名。
- Googleのメールアドレス・本名・アイコン・パスワード・アクセストークン・IDトークンは永続保存しません。
- Googleの公開署名証明書を非公開data領域に最大5分キャッシュします。これはユーザー情報ではありません。
- 手入力した名刺・会った記録・GPS等は通常どおり保存します。Webサーバーのアクセスログは別管理です。

## エラー時の確認

| 症状 | 確認する内容 |
| --- | --- |
| Googleボタンが出ない | config.php のGoogle client_id / client_secretが両方設定されているか |
| redirect_uri_mismatch | 上記URLとGoogle側の承認済みリダイレクトURIが完全一致しているか |
| access_denied / テストユーザー以外使えない | Google側のテストユーザー設定と公開ステータス |
| 外部サービスでログインを確認できない | Client Secret、サーバーの外向きHTTPS通信、curlとCA証明書 |
| Googleの認証内容・署名を確認できない | Web側openssl、サーバー時刻、外向きHTTPS。認証を最初からやり直す |
| 別の名刺アカウントに連携済み | 「以前のアカウントを使う」を選ぶ。自動統合はしない |

Google画面でキャンセルした場合や10分経過した場合は、アプリに戻って連携を最初からやり直してください。
エラー調査でURLやログを共有する場合、認可コード・トークン・Client Secretを含めないでください。

## 実装上の検証

state・PKCE S256・nonce・10分の有効期限を使用し、コールバックでstateを消費します。
Googleの固定HTTPSエンドポイントでコード交換し、RS256署名・iss・aud/azp・exp/iat・nonce・subを検証します。
検証失敗時には連携を作成しません。Googleアカウント選択を毎回表示します。
IDトークンの署名検証はPHP OpenSSLを利用し、署名アルゴリズムを固定します。Google以外の鍵URLやJWTヘッダー指定のURLは参照しません。
自動テストに加え、実際のOAuthクライアントでのスマホ・別端末の確認が必要です。

公式資料: https://developers.google.com/identity/openid-connect/openid-connect
