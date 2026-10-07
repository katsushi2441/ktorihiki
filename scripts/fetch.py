#!/usr/bin/env python3
"""国税庁の公表データ（法人番号・インボイス登録）の全件ファイルを取る（月に1回）。

  /usr/bin/python3 scripts/fetch.py houjin 23     # 法人番号：愛知県（CSV・Unicode）
  /usr/bin/python3 scripts/fetch.py invoice       # インボイス：法人分の全件（CSV）

どちらもページのフォーム（トークン付き）にファイル番号を入れて送る作り。ファイル番号は毎月変わるので、ページの表から都道府県名で引く。
出典の記載（公共データ利用規約 第1.0版）：「国税庁法人番号公表サイト（国税庁）」「国税庁適格請求書発行事業者公表サイト（国税庁）」を加工して作成。
"""
import html
import http.cookiejar
import os
import re
import sys
import urllib.parse
import urllib.request

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
RAW = os.path.join(ROOT, "data", "raw")
UA = "Mozilla/5.0 (ktorihiki; +https://kurage.exbridge.jp/ktorihiki.php/about)"
PREF = {"23": "愛知県"}


def opener():
    cj = http.cookiejar.CookieJar()
    o = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(cj))
    o.addheaders = [("User-Agent", UA)]
    return o


def rows(page: str):
    """（形式, 行の文字, [ファイル番号]）を表の順に返す"""
    fmt = None
    for t in re.finditer(r"(CSV形式・Shift-JIS|CSV形式・Unicode|XML形式・Unicode|CSV形式|XML形式|JSON形式)|<tr.*?</tr>", page, re.S):
        if t.group(1):
            fmt = t.group(1)
            continue
        nums = re.findall(r"doDownload\((\d+)\)", t.group(0))
        if nums:
            yield fmt, re.sub(r"\s+", " ", html.unescape(re.sub(r"<[^>]+>", " ", t.group(0)))).strip(), nums


def download(o, url: str, file_no: str, token_page: str, out: str) -> None:
    tk = re.search(r'name="([^"]*CNSFWTokenProcessor\.request\.token)" value="([^"]+)"', token_page)
    form = {"event": "download", "selDlFileNo": file_no}
    if tk:
        form[tk.group(1)] = tk.group(2)
    data = urllib.parse.urlencode(form).encode()
    with o.open(urllib.request.Request(url, data=data), timeout=600) as r, open(out, "wb") as f:
        while True:
            b = r.read(1 << 20)
            if not b:
                break
            f.write(b)
    print("取得", out, os.path.getsize(out) // 1024, "KB")


def houjin(pref: str) -> None:
    url = "https://www.houjin-bangou.nta.go.jp/download/zenken/index.html"
    o = opener()
    page = o.open(url, timeout=60).read().decode("utf-8", "replace")
    name = PREF[pref]
    for fmt, txt, nums in rows(page):
        if fmt != "CSV形式・Unicode" or name not in txt:
            continue
        # 1行に複数県が並ぶ：県名の出てくる順番でファイル番号を対応させる
        names = re.findall(r"(北海道|\S{2,3}[都府県])\s+zip", txt)
        no = nums[names.index(name)]
        download(o, url, no, page, os.path.join(RAW, f"houjin_{pref}.zip"))
        return
    raise SystemExit("ファイル番号が見つからない")


def invoice() -> None:
    """法人分（jinkakukbn=2）の CSV（type=01）を全部。都道府県の5グループに分かれているので全部取る（計 約90MB）"""
    url = "https://www.invoice-kohyo.nta.go.jp/download/zenken"
    o = opener()
    page = o.open(url, timeout=60).read().decode("utf-8", "replace")
    nos = re.findall(r"doDownload\('(\d+)','2','01'\)", page)
    if not nos:
        raise SystemExit("法人分のファイル番号が見つからない")
    for i, no in enumerate(nos, 1):
        q = urllib.parse.urlencode({"dlFilKanriNo": no, "jinkakukbn": "2", "type": "01"})
        out = os.path.join(RAW, f"invoice_corp_{i}.zip")
        with o.open(url + "/dlfile?" + q, timeout=600) as r, open(out, "wb") as f:
            while True:
                b = r.read(1 << 20)
                if not b:
                    break
                f.write(b)
        print("取得", out, os.path.getsize(out) // 1024, "KB")


if __name__ == "__main__":
    os.makedirs(RAW, exist_ok=True)
    if sys.argv[1] == "houjin":
        houjin(sys.argv[2])
    else:
        invoice()
