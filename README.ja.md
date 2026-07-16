# WPPack Sanitize Characters

[![CI](https://img.shields.io/github/actions/workflow/status/wppack-io/sanitize-characters-plugin/ci.yml?branch=1.x)](https://github.com/wppack-io/sanitize-characters-plugin/actions/workflows/ci.yml)
[![License: MIT](https://img.shields.io/badge/License-MIT-blue.svg)](LICENSE)
[![PHP Version](https://img.shields.io/badge/PHP-8.2%2B-777BB4.svg)](https://php.net)
[![WordPress](https://img.shields.io/badge/WordPress-6.7%2B-21759B.svg)](https://wordpress.org)

[English README](README.md)

不可視文字・制御文字を、保存時のコンテンツと検索時の検索語から
除去します。設定画面なし、データベースにオプションも書き込みません。

## 解決する問題

Google ドキュメントや Notion などの原稿作成ツールは、改行位置のヒントと
してゼロ幅文字を文章に挿入します。PDF やターミナルからのコピーには制御
文字や特殊な区切り文字が紛れ込みます。WordPress に貼り付けるとタイトル・
本文・検索欄の中に見えないまま残り、画面上は同一に見える文字列で検索が
一致しなくなります。隠れた U+200B を含むタイトル `ブロード​ウェイ` は
手打ちの `ブロードウェイ` に決してマッチせず、末尾にゼロ幅スペースが
付いたコピペ検索語は何にもヒットしません — 管理画面の検索でも、ACF
リレーションシップフィールドでも、フロントの `?s=` 検索でも同じです。
さらに MySQL の utf8mb4 照合順序はこれらの文字を無視可能文字として扱う
ため、SQL からも汚染が見えません。

## 対象文字

| 文字 | 処理 |
|---|---|
| C0 制御文字（タブ・LF・CR を除く）、DEL、C1 制御文字（U+0000–U+001F、U+007F–U+009F） | 除去 |
| U+00AD ソフトハイフン | 除去 |
| ゼロ幅: U+200B、U+2060、U+FEFF（BOM） | 除去 |
| 双方向制御文字: U+061C、U+200E/U+200F、U+202A–U+202E、U+2066–U+2069（trojan source の温床） | 除去 |
| U+2028 行区切り、U+2029 段落区切り | `\n` に置換 |

意図的に残すもの: タブ・LF・CR、U+200C ZWNJ と U+200D ZWJ（絵文字の
合成 — 👨‍👩‍👧 — や複数の文字体系の字形制御に使われます）、U+00A0 NBSP、
異体字セレクタ。RTL 混在テキストで双方向制御文字に依存しているサイトでは
このプラグインを使わないでください。

## 機能

**保存時**

- `wp_insert_post_data` — 投稿タイトル・本文・抜粋を浄化。すべての保存
  経路（エディタ、REST、XML-RPC、WP-CLI）に効きます。
- `acf/update_value` — ACF フィールド値を浄化（配列は再帰処理）。
  無条件に登録され、ACF が無い環境では単に発火しません。

**検索時**

- `pre_get_posts` — すべての `WP_Query` の `s` クエリ変数を正規化。
  管理画面の検索・ACF リレーションシップの検索・フロントの検索に
  効きます。

**既存データ**

- `wp sanitize-characters` — `wp_posts`（タイトル・本文・抜粋。
  リビジョンは除外）と `wp_postmeta` をスキャン。デフォルトは dry-run、
  `--apply` で反映。投稿はその場で UPDATE し、`post_modified` は変更
  されずリビジョンも作られません。シリアライズ済みメタは展開して再構築、
  オブジェクト値はスキップします。リビジョンを除外するのは意図的です —
  復元時は `wp_insert_post_data` を通るため、保存フィルタが改めて浄化
  します。

## WP-CLI の使い方

```
# 対象行の一覧だけ表示（デフォルトが dry-run。何も変更しません）
$ wp sanitize-characters
post 42 [post] post_title,post_content: サンプル記事タイトル
meta 137 (post 42, subtitle)
Success: Would clean 2 posts and 1 meta rows (dry-run; pass --apply to persist).

# 反映する
$ wp sanitize-characters --apply
Success: Cleaned 2 posts and 1 meta rows.

# 確認: もう一度実行すると 0 件になる
$ wp sanitize-characters
Success: Would clean 0 posts and 0 meta rows (dry-run; pass --apply to persist).
```

対象行ごとに 1 行出力されます — 投稿は `post <ID> [<type>] <列>: <タイトル>`、
メタは `meta <meta_id> (post <ID>, <キー>)`。シリアライズされたオブジェクト
値のメタは報告のうえスキップされます。`wp help sanitize-characters` で
完全なシノプシスを確認できます。定期実行しても無害です — 保存時フィルタが
新規コンテンツをクリーンに保つため、このコマンドが見つけるのはプラグイン
無効時に書き込まれた行だけです。

## 背景・関連リンク

- WordPress コアが不可視文字を除去するのは、パーマリンク生成時の
  **スラッグだけ**です（[Trac #47912](https://core.trac.wordpress.org/ticket/47912)、
  [Trac #42951](https://core.trac.wordpress.org/ticket/42951)）。タイトル・
  本文・検索語は対象外 — このプラグインはその隙間を埋めます。
- [invisible-characters.com](https://invisible-characters.com/) — 対象と
  なる文字のリファレンス一覧。
- [Insert Special Characters](https://wordpress.org/plugins/insert-special-characters/)
  は逆の用途（特殊文字を意図的に挿入する）のプラグインです。

## 動作要件

PHP 8.2+、WordPress 6.7+。

## ライセンス

MIT
