#!/bin/sh
# 案件にアップロードする zip を作る。
#   sh bin/zip.sh  →  dist/private-page-magic-link-<版>.zip
# 中身はコミット済みのファイルだけ（git archive）。.git・.gitignore・bin/ などは
# .gitattributes の export-ignore で外れる。zip の中は private-page-magic-link/ フォルダ1つなので、
# 管理画面の「プラグインを追加 → プラグインのアップロード」にそのまま渡せる。
set -e
cd "$(dirname "$0")/.."
slug=private-page-magic-link
ver=$(sed -n 's/^ \* Version: *//p' "$slug.php" | head -1)
if [ -n "$(git status --porcelain)" ]; then
	echo "注意: コミットしていない変更があります。zip に入るのはコミット済みの内容だけです。" >&2
fi
mkdir -p dist
out="dist/$slug-$ver.zip"
rm -f "$out"
git archive --format=zip --prefix="$slug/" -o "$out" HEAD
echo "$out"
