---
name: apkk-laravel-error-monitor-setup
description: LaravelプロジェクトにLaravel Error Monitorと任意のGitHub・XServerプラグインを導入し、既存設定を保持してセットアップと検証を行う。導入、初期設定、GitHub Issue連携の設定を依頼されたときに使う。
---

# Laravel Error Monitorセットアップ

対象Laravelアプリを確認し、既存設定を調べて不足項目だけ補う。本体へAI API呼び出しを追加しない。日々の収集・発行はLaravelの定期実行に任せる。

## 調査と確認

- 作業パス、Git状態、PHP・Laravel・パッケージのバージョン、既存のログ設定・スケジュール・設定キャッシュを確認する。アプリとパッケージ開発checkoutを混同しない。
- `.env`の全文や認証情報を出力しない。対象キーの有無とトークンの設定済み／未設定だけを調べる。Git remoteに認証情報が含まれる場合も出力しない。
- 必要な選択はIssueの送信先、XServerログも対象か、実行環境・時刻。利用者の指定があれば再質問しない。ローカル設定から本番の値を推測しない。
- GitHub・XServerは本体にそれぞれ依存し、互いには依存しない。LaravelログとGitHub発行だけなら本体＋GitHubでよい。
- 実装checkoutで使えるコマンドが公開版に含まれるとは限らない。`artisan list`とインストール済みバージョンを確認する。新コマンドがなければREADMEの手動導入手順を使い、利用者の指定なしに開発版へ切り替えない。

## 導入とプレビュー

導入依頼の範囲で依存追加とローカル設定を進める。変更するファイルと不足設定を提示する。本番のマイグレーション、配置、cron変更、実ログの外部送信は、その環境への実行許可がある場合に行う。既存の許可は再確認しない。

導入先アプリに必要なパッケージだけ追加する。既存のComposer制約・lockfileを保持し、解決による変更を確認する。

```bash
composer require ashita-planning/laravel-error-monitor ashita-planning/laravel-error-monitor-github
php artisan error-monitor:setup --dry-run --json
php artisan error-monitor:github-setup --repository=OWNER/REPOSITORY --enable --dry-run --json
```

XServerを使うときだけパッケージを追加し、確定したアカウントとドメインでプレビューする。

```bash
php artisan error-monitor:xserver-setup --server-id=SERVER_ID --domain=DOMAIN --enable --dry-run --json
```

## 適用

- 同じコマンドから`--dry-run`を外して適用する。コマンドは未存在の設定ファイルと不足する環境変数だけを作成する。既存値が空や`false`でも上書きしない。
- 既存値の変更が必要なら理由と差分を確認し、依頼範囲で対象キーだけ変更する。設定ファイルの`vendor:publish --force`は使わない。
- トークンは本番側の環境変数・secret管理経由で設定する。チャットに貼らせず、CLI引数に含めず、ローカルの`gh`認証を本番へコピーしない。GitHub送信先へのIssues権限を確認する。
- スケジュールは既存のLaravel形式に合わせ、重複登録を避ける。XServerはログ生成後の時刻にする。送信先が他アプリと同じ場合は、環境名の衝突を確認する。
- 設定キャッシュ更新とマイグレーションはセットアップコマンドが実行しない。対象環境の既存デプロイ手順を使う。`migrate:status`で未適用を確認し、許可された環境で適用する。
- 複数サーバーで実行する場合は共有ロック可能なキャッシュが必要。cronのPHP CLI・作業ディレクトリ・タイムゾーンを確認する。

## 検証と完了報告

```bash
php artisan error-monitor:doctor --json
php artisan migrate:status
php artisan error-monitor:run --date=yesterday --dry-run --json
php artisan error-monitor:github-status
php artisan schedule:list
```

必要かつ許可された場合に`github-status --check-connection`で通信を確認する。XServer利用時は`xserver-status --date=yesterday`で対象日と翌朝の期待ファイルを確認する。

`doctor`は有効な設定と3テーブルの存在を調べるが、cron実行・全マイグレーション・外部発行の成功は保証しない。dry-runはIssueを発行しない。実発行は許可された本番実行で確認し、終了コードに加えてJSONの`warnings`とIssueを確認する。再実行に`--force`を使わない。

最後に変更したファイル、設定したキー名、実行した検証、本番反映・実発行の実施状況と残りの手順を報告する。Git操作、公開、デプロイは導入設定の依頼だけから推定しない。
