# MD Gateway for WordPress

[English](README.md) | 日本語

## これは何？

WordPress サイトを AI と共有するための、シンプルな Web アプリケーションです。サイトの全体像が分かる投稿一覧を表示した上で、各投稿を Markdown として `Content-Type: text/plain` で出力します。

`mdgw` = **m**ark**d**own **g**ate**w**ay。

プラグインではありません。

### 既存ツールでは難しいこと

「WordPress を Markdown にして AI に渡す」ためのツールは、すでにいくつも存在しています。記事 URL に `.md` を付ける、`Accept: text/markdown` で内容交渉する、`llms.txt` を生成するなど、様々な実装があります。ただ、それらは総じて **WordPress にプラグインとして組み込み、AI にページを読み込ませる** という構造になっていて、共通の課題を抱えています。

- WordPress にプラグインを追加する必要があります。
- AI に「サイトの全体像」を伝えることは困難です。

### MD Gateway で実現できること

MD Gateway では、**WordPress の設定や動作を変更することなく、AI にサイトを見せる**ことができます。

- **外部アプリです。** WordPress の標準機能である WP REST API を利用しているため、プラグインを入れられない／入れたくないサイトや、自分が所有していない第三者のサイトにも対応できます。AI のクローラをブロックしているサーバーや、日本語（IDN）ドメインで fetch が上手く動作しないサイトにも対応できます。
- **AI がページを発見できます。** トップ（`/`）が全記事一覧になっているため、それだけでサイトの全体像がわかります。リンク先の各記事は Markdown で軽く、AI のコンテキストを圧迫しません。AI がサイトをクロールしやすく、ページを発見しやすい構造になっています。
- **AI に見せる範囲を設定できます。** `config.php` の除外設定で、特定の投稿タイプや記事を非表示にすることができます。サイトの公開範囲（人間向け・SEO）はそのままに、AI に渡す範囲だけを別に調整できます。

要するに、AI に内容を「教える／与える」のではなく、サイトを丸ごと「共有」して AI に探索させるためのアプリです。

## 動作環境

- PHP 8.2 以上
- PHP 拡張: `curl` / `dom` / `intl` / `libxml` / `mbstring`
- [league/html-to-markdown](https://github.com/thephpleague/html-to-markdown)（Composer で導入）
- Apache（`.htaccess` / mod_rewrite を使用）

## 導入

```sh
git clone https://github.com/sakilabo/mdgw-wp-php.git mdgate
cd mdgate
composer install
cp config.sample.php config.php   # config.php を環境に合わせて編集
```

公開ディレクトリ（例: `https://example.com/mdgate/`）に配置してください。
`config.php` はリポジトリに含めません（`.gitignore` 済み）。

## 設定（config.php）

| キー | 説明 |
| --- | --- |
| `wp_site` | 対象 WordPress のサイト URL（例: `https://example.com`）。IDN は Unicode でも punycode でも可。 |
| `loopback` | `true` で同一サーバーの WordPress へループバック（127.0.0.1）アクセスし SSL 検証を省略。別サーバーなら `false`。 |
| `timeout` | REST API への接続タイムアウト（秒）。省略時 15。 |
| `concurrency` | 並列取得する REST API リクエストの最大数。省略時 4（1〜8 にクランプ）。 |
| `timezone` | 日時表示に使うタイムゾーン。IANA 名・略称・オフセット可（例: `Asia/Tokyo` / `JST` / `+0900`）。省略時はサーバーのタイムゾーン。 |
| `font_family` | 一覧ページのフォント（CSS `font-family`）を配列で指定。省略時は `sans-serif`。 |
| `exclude_type_slugs` | 一覧に出さない投稿タイプの slug。 |
| `exclude_type_names` | 一覧に出さない投稿タイプ名。 |
| `exclude_titles` | 一覧に出さない投稿タイトル。 |
| `exclude_ids` | 一覧に出さない投稿 ID。 |
| `show_date` | 一覧で各タイトルの後ろに表示する日時。`'full'`（日時）／`'date-only'`（日付のみ）／`'none'`（非表示、既定）。 |
| `show_api_endpoint` | Markdown のフロントマターに REST API エンドポイント URL を出力するか。`true` で含める、`false` で省略（既定）。 |
| `form_handling` | 投稿本文中の `<form>` の扱い。`'keep'` でフォームの前後に `<form>` / `</form>` タグを出力（既定）、`'remove'` で中身を削除し自己完結タグ `<form />` を出力。 |

`exclude_*` の各要素は「デリミタ付きの正規表現（例: `/^wp_/`）」または「完全一致の文字列（例: `attachment`）」で指定します。
部分一致させたい場合はアンカーなしの正規表現で書きます（例: `/お知らせ/`）。
ただし `exclude_ids` は例外で、WordPress の投稿 ID は常に正の整数のため、完全一致の整数で指定します（例: `[12, 34]`）。
詳細は [config.sample.php](config.sample.php) を参照してください。

## 使い方

- `/` — 投稿タイプと投稿の一覧
- `/<rest_base>/<id>` — 指定した投稿を Markdown 表示（例: `/posts/123`）
- `/page?url=<記事のURL>` — 記事の URL から投稿を特定して Markdown 表示（同一サイトの URL のみ）
- 末尾に `?raw` を付けると、Markdown 変換せず元の HTML を出力

## 制限事項

- 公開された WP REST API を持つ WordPress サイトでのみ動作します。REST API を無効化したサイトには使えません。
- 1 つの配置につき 1 サイト（`config.php` で固定）を対象とします。任意の URL を取得する汎用プロキシではありません。
- Markdown 出力は読み取り専用です。WordPress 側に書き込むことはできません。

## ライセンス

[UPL-1.0](LICENSE)

## 作者

[株式会社さきラボ](https://さきラボ.jp)
