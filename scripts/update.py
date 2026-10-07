#!/usr/bin/env python3
"""毎日1回：国税庁の全件データの「更新日」を見て、新しくなったときだけ取り直して組み直し、heteml に置く。

  /usr/bin/python3 scripts/update.py

全件データは月に1回（前月末時点）なので、ほとんどの日はページを2つ読むだけで終わる。
置き方は一時名で送ってから rename（送っている途中のファイルを PHP が読まないように）。FTPS は1接続。
"""
import ftplib
import json
import os
import re
import subprocess
import urllib.request

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
STATE = os.path.join(ROOT, "data", "asof.json")
# 置き先（FTP の接続先と、サーバー上のデータのフォルダ）は環境変数か、このリポジトリ直下の .env で渡す:
#   FTP_HOST / FTP_USER / FTP_PASS / REMOTE_DIR（例 /web/example_com/<製品>_data）。ENV_FILE で .env の場所を変えられる
REMOTE = os.environ.get("REMOTE_DIR", "")
UA = "Mozilla/5.0 (ktorihiki; +https://kurage.exbridge.jp/ktorihiki.php/about)"
PAGES = {"houjin": "https://www.houjin-bangou.nta.go.jp/download/zenken/index.html",
         "invoice": "https://www.invoice-kohyo.nta.go.jp/download/zenken"}


def env():
    e = {k: v for k, v in os.environ.items() if k.startswith("FTP_") or k == "REMOTE_DIR"}
    path = os.environ.get("ENV_FILE", os.path.join(ROOT, ".env"))
    if os.path.exists(path):
        for ln in open(path, encoding="utf-8"):
            if (ln.startswith("FTP_") or ln.startswith("REMOTE_DIR")) and "=" in ln:
                k, v = ln.rstrip("\n").split("=", 1)
                e.setdefault(k, v.strip().strip('"').strip("'"))
    return e


def asof(url: str) -> str:
    page = urllib.request.urlopen(urllib.request.Request(url, headers={"User-Agent": UA}), timeout=60).read().decode("utf-8", "replace")
    m = re.search(r"令和\s*(\d+)\s*年\s*(\d+)\s*月\s*(\d+)\s*日\s*更新", page)
    return f"R{m.group(1)}-{int(m.group(2)):02d}-{int(m.group(3)):02d}" if m else ""


def main() -> dict:
    now = {k: asof(u) for k, u in PAGES.items()}
    old = json.load(open(STATE)) if os.path.exists(STATE) else {}
    if now == old or not all(now.values()):
        return {"changed": False, "asof": now}
    py = "/usr/bin/python3"
    if now["houjin"] != old.get("houjin"):
        subprocess.run([py, f"{ROOT}/scripts/fetch.py", "houjin", "23"], check=True, timeout=1800, capture_output=True)
    if now["invoice"] != old.get("invoice"):
        subprocess.run([py, f"{ROOT}/scripts/fetch.py", "invoice"], check=True, timeout=1800, capture_output=True)
    subprocess.run([py, f"{ROOT}/scripts/build_db.py", "23"], check=True, timeout=1800, capture_output=True)
    e = env()
    rd = e.get("REMOTE_DIR") or REMOTE
    if not rd:
        raise SystemExit("REMOTE_DIR（サーバー上のデータのフォルダ）を環境変数か .env で指定してください")
    f = ftplib.FTP_TLS(e["FTP_HOST"], timeout=900)
    f.login(e["FTP_USER"], e["FTP_PASS"])
    f.prot_p()
    with open(os.path.join(ROOT, "php", "ktorihiki_data", "ktorihiki.sqlite"), "rb") as fh:
        f.storbinary(f"STOR {rd}/ktorihiki.sqlite.tmp", fh, blocksize=1 << 18)
    f.rename(f"{rd}/ktorihiki.sqlite.tmp", f"{rd}/ktorihiki.sqlite")
    f.quit()
    json.dump(now, open(STATE, "w"))
    return {"changed": True, "asof": now, "deployed": True}


if __name__ == "__main__":
    print(json.dumps(main(), ensure_ascii=False))
