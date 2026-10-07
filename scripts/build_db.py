#!/usr/bin/env python3
"""法人番号（都道府県の全件）とインボイス登録（法人分の全件）を法人番号でつなぎ、公開用の SQLite を組む。

  /usr/bin/python3 scripts/build_db.py 23      # 愛知県 → php/ktorihiki_data/ktorihiki.sqlite

- 法人番号 CSV（Unicode）の列: 0連番 1法人番号 2処理区分 3訂正区分 4更新年月日 5変更年月日 6商号又は名称 8法人種別 9都道府県 10市区町村
  11丁目番地 13都道府県コード 14市区町村コード 15郵便番号 18登記記録の閉鎖等年月日 19閉鎖の事由 20承継先法人番号 21変更事由の詳細
  22法人番号指定年月日 23最新履歴 28フリガナ 29検索対象除外
- インボイス CSV の列: 1登録番号（T＋13桁） 6最新履歴 7登録年月日 8更新年月日 9取消年月日 10失効年月日 11本店所在地 18名称
- 個人事業者のインボイス情報は使わない（本人の同意なく公表すると個人情報保護法に抵触するおそれがある、と国税庁が明記）。
- 出典：国税庁法人番号公表サイト（国税庁）・国税庁適格請求書発行事業者公表サイト（国税庁）を加工して作成（公共データ利用規約 第1.0版）。
"""
import csv
import glob
import io
import os
import sqlite3
import sys
import zipfile

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
RAW = os.path.join(ROOT, "data", "raw")
OUT = os.path.join(ROOT, "php", "ktorihiki_data", "ktorihiki.sqlite")
KIND = {"101": "国の機関", "201": "地方公共団体", "301": "株式会社", "302": "有限会社", "303": "合名会社", "304": "合資会社", "305": "合同会社",
        "399": "その他の設立登記法人", "401": "外国会社等", "499": "その他"}
CLOSE = {"01": "清算の結了等", "11": "合併による解散等", "21": "登記官による閉鎖", "31": "その他の清算の結了等"}


def csv_rows(zpath: str):
    with zipfile.ZipFile(zpath) as z:
        name = [n for n in z.namelist() if n.endswith(".csv")][0]
        with z.open(name) as f:
            yield from csv.reader(io.TextIOWrapper(f, encoding="utf-8", newline=""))


def main(pref: str) -> None:
    os.makedirs(os.path.dirname(OUT), exist_ok=True)
    tmp = OUT + ".tmp"
    if os.path.exists(tmp):
        os.remove(tmp)
    c = sqlite3.connect(tmp)
    c.executescript("""
CREATE TABLE corps(no TEXT PRIMARY KEY, name TEXT, kana TEXT, kind TEXT, pref TEXT, city TEXT, street TEXT, post TEXT,
  close_date TEXT, close_cause TEXT, successor TEXT, change_date TEXT, assigned TEXT, updated TEXT,
  inv_no TEXT, inv_reg TEXT, inv_update TEXT, inv_disposal TEXT, inv_expire TEXT, inv_name TEXT);
CREATE TABLE meta(k TEXT PRIMARY KEY, v TEXT);
""")
    n = 0
    for r in csv_rows(os.path.join(RAW, f"houjin_{pref}.zip")):
        if len(r) < 30 or r[23] != "1" or r[29] == "1":   # 最新履歴だけ・検索対象除外は入れない
            continue
        c.execute("INSERT OR REPLACE INTO corps(no,name,kana,kind,pref,city,street,post,close_date,close_cause,successor,change_date,assigned,updated) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?)",
                  (r[1], r[6], r[28], KIND.get(r[8], r[8]), r[9], r[10], r[11], r[15], r[18], CLOSE.get(r[19], r[19]), r[20], r[5], r[22], r[4]))
        n += 1
    m = 0
    for z in sorted(glob.glob(os.path.join(RAW, "invoice_corp_*.zip"))):
        for r in csv_rows(z):
            if len(r) < 19 or r[6] != "1" or not r[1].startswith("T"):
                continue
            cur = c.execute("UPDATE corps SET inv_no=?, inv_reg=?, inv_update=?, inv_disposal=?, inv_expire=?, inv_name=? WHERE no=?",
                            (r[1], r[7], r[8], r[9], r[10], r[18], r[1][1:]))
            m += cur.rowcount
    c.executescript("CREATE INDEX c_name ON corps(name); CREATE INDEX c_kana ON corps(kana); CREATE INDEX c_inv ON corps(inv_no);")
    src = [os.path.basename(p) for p in [os.path.join(RAW, f"houjin_{pref}.zip")] + sorted(glob.glob(os.path.join(RAW, "invoice_corp_*.zip")))]
    for k, v in (("pref", pref), ("houjin_n", str(n)), ("invoice_n", str(m)),
                 ("houjin_asof", max(r[0] for r in c.execute("SELECT updated FROM corps WHERE updated<>''"))),
                 ("sources", ",".join(src))):
        c.execute("INSERT INTO meta VALUES(?,?)", (k, v))
    c.commit()
    c.execute("VACUUM")
    c.close()
    os.replace(tmp, OUT)
    print(f"法人 {n}件・うちインボイス登録あり {m}件 → {OUT}（{os.path.getsize(OUT)//1024//1024}MB）")


if __name__ == "__main__":
    main(sys.argv[1] if len(sys.argv) > 1 else "23")
