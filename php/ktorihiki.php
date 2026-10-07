<?php
/**
 * Kurage 取引先チェック（ktorihiki）― 1ファイルPHP＋SQLite。
 *
 * 取引先の一覧（法人番号・インボイスの登録番号・会社名のどれでも、1行に1社）を貼ると、
 * 国税庁の公表データから、商号・所在地・登記の閉鎖・インボイスの登録・取消・失効をまとめて照合する。
 * 対象は愛知県に本店のある法人（前月末時点）。個人事業者は扱わない。
 *
 *   /ktorihiki.php/        一括チェック（POST で照合。結果は検索エンジンに載せない）
 *   /ktorihiki.php/about   データの出どころ・範囲・照合のしかた
 *   /ktorihiki.php/sitemap.xml /robots.txt /llms.txt
 *
 * データは同じ場所の ktorihiki_data/ktorihiki.sqlite（scripts/build_db.py が毎月組む。フォルダの .htaccess で外から読めない）。
 * 出典：国税庁法人番号公表サイト（国税庁）・国税庁適格請求書発行事業者公表サイト（国税庁）を加工して作成（公共データ利用規約 第1.0版）。
 */
declare(strict_types=1);
mb_internal_encoding('UTF-8');
date_default_timezone_set('Asia/Tokyo');

const SITE = 'https://kurage.exbridge.jp';
const BASE = SITE . '/ktorihiki.php';
const NAME = 'Kurage 取引先チェック';
const BUY = 'https://kappstore.exbridge.jp/app.php?id=3925790d5cdbe66e';
const MAX_LINES = 200;

function h(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function u(string $p): string { return BASE . $p; }
function jd(?string $ymd): string { if (!$ymd || !preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $ymd, $m)) return ''; return (int)$m[1] . '年' . (int)$m[2] . '月' . (int)$m[3] . '日'; }
function ours(): bool { return ($_SERVER['HTTP_HOST'] ?? '') === 'kurage.exbridge.jp'; }
function db(): PDO {
    static $p = null;
    if ($p === null) { $p = new PDO('sqlite:' . __DIR__ . '/ktorihiki_data/ktorihiki.sqlite', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]); }
    return $p;
}
function q(string $sql, array $a = []): array { $s = db()->prepare($sql); $s->execute($a); return $s->fetchAll(); }
function meta(string $k): string { $r = q('SELECT v FROM meta WHERE k=?', [$k]); return (string)($r[0]['v'] ?? ''); }

/** 全角英数を半角に、空白をそろえる */
function norm(string $s): string { return trim(preg_replace('/[\s　]+/u', ' ', mb_convert_kana($s, 'as'))); }

/** 1行を照合する。戻り値: [種類, 候補の配列] */
function lookup(string $line): array {
    $t = norm($line);
    $digits = preg_replace('/[^0-9]/', '', $t);
    if (preg_match('/^T?\s*\d{13}$/i', str_replace([' ', '-'], '', $t))) {
        $no = substr($digits, -13);
        return ['番号', q('SELECT * FROM corps WHERE no=?', [$no])];
    }
    $exact = q('SELECT * FROM corps WHERE name=? ORDER BY close_date<>\'\', no LIMIT 6', [$t]);
    if ($exact) return ['名称', $exact];
    $like = q('SELECT * FROM corps WHERE name LIKE ? ORDER BY close_date<>\'\', length(name) LIMIT 6', ['%' . $t . '%']);
    return ['名称（部分一致）', $like];
}

/** 1社の状態：[ラベル, 重さ（0=問題なし 1=確認 2=要注意）, 説明] */
function status(array $c): array {
    if ($c['close_date']) return ['登記が閉鎖', 2, jd($c['close_date']) . '・' . $c['close_cause']];
    if ($c['inv_disposal']) return ['インボイス取消', 2, jd($c['inv_disposal']) . 'に取消'];
    if ($c['inv_expire']) return ['インボイス失効', 2, jd($c['inv_expire']) . 'に失効'];
    if (!$c['inv_no']) return ['インボイス未登録', 1, '登録番号なし（免税事業者の可能性）'];
    return ['登録あり', 0, jd($c['inv_reg']) . '登録'];
}

// ---------------- 画面の骨格 ----------------
function page(string $title, string $desc, string $url, string $body, bool $noindex = false, array $ld = []): void {
    $ld[] = ['@context' => 'https://schema.org', '@type' => 'WebSite', 'name' => NAME, 'url' => BASE . '/', 'publisher' => ['@type' => 'Organization', 'name' => '株式会社エクスブリッジ', 'url' => 'https://exbridge.jp/']];
    header('Content-Type: text/html; charset=UTF-8');
    echo '<!DOCTYPE html><html lang="ja"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">';
    echo '<title>' . h($title) . '</title><meta name="description" content="' . h($desc) . '"><link rel="canonical" href="' . h($url) . '">' . ($noindex ? '<meta name="robots" content="noindex">' : '');
    echo '<meta property="og:type" content="website"><meta property="og:site_name" content="' . h(NAME) . '"><meta property="og:title" content="' . h($title) . '"><meta property="og:description" content="' . h($desc) . '"><meta property="og:url" content="' . h($url) . '"><meta property="og:image" content="' . SITE . '/images/ogp/ktorihiki.png"><meta property="og:locale" content="ja_JP"><meta name="twitter:card" content="summary_large_image"><meta name="color-scheme" content="light">';
    echo '<link rel="icon" href="https://exbridge.jp/images/logo-mark-128.png">';
    foreach ($ld as $j) { echo '<script type="application/ld+json">' . json_encode($j, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . '</script>'; }
    echo '<style>
:root{--navy:#1b2a3a;--blue:#0f5f8c;--ink:#1f2933;--sub:#5b6676;--line:#dde3ea;--bg:#f6f8fa;--acc:#5b4a9e;--ok:#047857;--ok-l:#e7f6ef;--ng:#b42318;--ng-l:#fdecea;--wa:#9a6700;--wa-l:#fff6e0}
*{box-sizing:border-box}html{color-scheme:light}body{margin:0;background:#fff;color:var(--ink);font:15.5px/1.75 "Noto Sans JP","Hiragino Sans","Yu Gothic",system-ui,sans-serif}
a{color:var(--blue);overflow-wrap:anywhere}.wrap{max-width:1040px;margin:0 auto;padding:0 16px;min-width:0}
header.top{border-bottom:1px solid var(--line)}header.top .wrap{display:flex;align-items:center;gap:6px 16px;flex-wrap:wrap;min-height:58px;padding-top:6px;padding-bottom:6px}
.brand{display:flex;align-items:center;gap:10px;color:var(--navy);text-decoration:none;font-weight:800;font-size:16px;line-height:1.3}.brand img{width:34px;height:34px;object-fit:contain;flex:none}.brand small{display:block;color:var(--acc);font-size:11.5px;font-weight:700}
nav.menu{display:flex;flex-wrap:wrap;gap:2px 14px;font-size:14px;font-weight:700}nav.menu a{color:var(--sub);text-decoration:none}
.promo{margin-left:auto;display:flex;flex-wrap:wrap;gap:2px 12px;font-size:13px;font-weight:800}.promo a{color:#b45309;text-decoration:none}
main{padding:10px 0 40px}h1{font-size:clamp(22px,4vw,30px);line-height:1.4;color:var(--navy);margin:18px 0 8px;text-wrap:balance}
h2{font-size:19px;color:var(--navy);margin:30px 0 10px;border-left:5px solid var(--acc);padding-left:10px}
.lead{color:var(--sub);margin:0 0 14px}.panel{background:var(--bg);border:1px solid var(--line);border-radius:12px;padding:14px 16px;margin:12px 0}
.big{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,180px),1fr));gap:10px;margin:12px 0}.big div{border:1px solid var(--line);border-radius:12px;padding:10px 14px}.big .k{display:block;font-size:12.5px;color:var(--sub);font-weight:700}.big .v{display:block;font-size:24px;font-weight:800;color:var(--navy);font-variant-numeric:tabular-nums}.big .v.ng{color:var(--ng)}.big .v.wa{color:var(--wa)}
textarea{width:100%;min-height:170px;font:inherit;font-size:16px;padding:10px 12px;border:2px solid var(--line);border-radius:10px}
.btn{display:inline-block;background:var(--acc);color:#fff;border:0;border-radius:10px;padding:10px 18px;font-weight:700;text-decoration:none;cursor:pointer;font-size:15px;font-family:inherit}.btn.sub{background:#fff;color:var(--acc);border:2px solid var(--acc)}
.src{font-size:12.5px;color:var(--sub)}.note{background:var(--wa-l);border-left:4px solid var(--wa);border-radius:8px;padding:10px 12px;font-size:14px;margin:10px 0}
.tbl{overflow-x:auto}table{border-collapse:collapse;width:100%;font-size:14px;min-width:760px}th,td{border-bottom:1px solid var(--line);padding:7px 8px;text-align:left;vertical-align:top}th{color:var(--sub);white-space:nowrap}
.tag{display:inline-block;border-radius:999px;padding:1px 9px;font-size:12.5px;font-weight:800;white-space:nowrap}.t2{background:var(--ng-l);color:var(--ng)}.t1{background:var(--wa-l);color:var(--wa)}.t0{background:var(--ok-l);color:var(--ok)}.tx{background:#eef1f4;color:var(--sub)}
details{border-bottom:1px solid var(--line);padding:8px 0}summary{cursor:pointer;font-weight:700}
footer{margin-top:40px;border-top:1px solid var(--line);padding:18px 0;font-size:13px;color:var(--sub)}footer p{margin:6px 0}
</style></head><body>';
    echo '<header class="top"><div class="wrap"><a class="brand" href="' . u('/') . '"><img src="https://exbridge.jp/images/logo-mark-128.png" width="34" height="34" alt="株式会社エクスブリッジ"><span>' . h(NAME) . '<small>取引先のインボイス登録と登記をまとめて確認</small></span></a>';
    echo '<nav class="menu"><a href="' . u('/') . '">チェックする</a><a href="' . u('/about') . '">データと照合のしかた</a></nav>';
    if (ours()) echo '<!--kurage-only--><span class="promo"><a href="https://exbridge.jp/ai-it-komon.html?ref=ktorihiki-head-komon" target="_blank" rel="noopener">AI-IT顧問</a><a href="https://kurage.exbridge.jp/reseller.html?ref=ktorihiki-head-reseller" target="_blank" rel="noopener">販売代理店募集</a></span><!--/kurage-only-->';
    echo '</div></header><main class="wrap">' . $body . '</main><footer><div class="wrap">';
    echo '<p>出典：国税庁法人番号公表サイト（国税庁）・国税庁適格請求書発行事業者公表サイト（国税庁）の全件データ（' . h(jd(meta('houjin_asof'))) . '時点）を加工して作成。対象は愛知県に本店のある法人だけで、個人事業者は扱いません。最新の状態は国税庁の公表サイトで確認してください。入力した一覧は照合のためだけに使い、保存しません。</p>';
    echo '<p><a href="https://exbridge.jp/?ref=ktorihiki">株式会社エクスブリッジ</a>（名古屋・業務システム開発）・<a href="' . SITE . '/knyusatsu.php/?ref=ktorihiki">Kurage 入札情報ナビ</a>・<a href="' . SITE . '/ktoriteki.php/?ref=ktorihiki">Kurage 取適法チェック</a>・<a href="' . SITE . '/kseidocal.php/?ref=ktorihiki">経営者の制度カレンダー</a></p>';
    echo '</div></footer>';
    if (ours()) {
        echo '<img src="' . SITE . '/simpletrack.php?t=img&url=' . rawurlencode($url) . '&ref=' . rawurlencode((string)($_GET['ref'] ?? '')) . '" width="1" height="1" alt="" aria-hidden="true" style="position:absolute;left:-9999px">';
        echo '<script src="https://kurage.exbridge.jp/partner-bar.js" defer></script>';
    }
    echo '</body></html>';
}

$SAMPLE = "株式会社エクスブリッジ\nT1000020230006\n4180001056508\nトヨタ自動車株式会社";

// ---------------- ルーティング ----------------
$path = $_SERVER['PATH_INFO'] ?? '';
if ($path === '' && !str_ends_with((string)($_SERVER['REQUEST_URI'] ?? ''), '/') && !str_contains((string)($_SERVER['REQUEST_URI'] ?? ''), '?')) { header('Location: ' . BASE . '/', true, 302); exit; }
if ($path === '/robots.txt') { header('Content-Type: text/plain; charset=UTF-8'); echo "User-agent: *\nAllow: /\nSitemap: " . u('/sitemap.xml') . "\n"; exit; }
if ($path === '/sitemap.xml') { header('Content-Type: application/xml; charset=UTF-8'); $lm = meta('houjin_asof'); echo '<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"><url><loc>' . u('/') . '</loc><lastmod>' . h($lm) . '</lastmod></url><url><loc>' . u('/about') . '</loc><lastmod>' . h($lm) . '</lastmod></url></urlset>'; exit; }
if ($path === '/llms.txt') {
    header('Content-Type: text/plain; charset=UTF-8');
    echo '# ' . NAME . "\n\n> 取引先の一覧（法人番号・インボイスの登録番号・会社名のどれでも1行に1社）を貼ると、国税庁の公表データ（法人番号・適格請求書発行事業者）から、商号・所在地・登記の閉鎖・インボイスの登録・取消・失効をまとめて照合するサイト。対象は愛知県に本店のある法人 " . meta('houjin_n') . '社（うちインボイス登録あり ' . meta('invoice_n') . "社・前月末時点）。個人事業者は扱わない。株式会社エクスブリッジ製。\n\n## ページ\n- 一括チェック: " . u('/') . "\n- データと照合のしかた: " . u('/about') . "\n";
    exit;
}

if ($path === '/about') {
    $url = u('/about');
    $body = '<h1>データと照合のしかた</h1><div class="panel"><p>国税庁の2つの公表データの全件ファイル（毎月、前月末時点で公開）を、法人番号でつないでいます。</p><ul>'
        . '<li><b>法人番号公表サイト</b>：商号・所在地・法人の種類・登記の閉鎖（清算の結了・合併による解散など）・承継先・変更日</li>'
        . '<li><b>適格請求書発行事業者公表サイト</b>：インボイスの登録番号（T＋法人番号）・登録日・取消日・失効日</li></ul>'
        . '<p>いま載っているのは、愛知県に本店のある法人 <b>' . number_format((int)meta('houjin_n')) . '社</b>、うちインボイスの登録がある <b>' . number_format((int)meta('invoice_n')) . '社</b>です（' . h(jd(meta('houjin_asof'))) . '時点）。</p></div>'
        . '<h2>照合のしかた</h2><ul><li>13桁の数字か「T＋13桁」は、法人番号として1社に決まります。</li><li>会社名は、まず完全に一致する名称を探し、無ければ名称の一部で探します。同じ名前の法人が複数あるときは、候補を並べます（所在地で見分けてください）。</li><li>状態は、登記の閉鎖 → インボイスの取消 → 失効 → 未登録 → 登録あり、の順に判定します。</li></ul>'
        . '<h2>載っていないもの</h2><ul><li>愛知県以外に本店のある法人</li><li>個人事業者（国税庁が「本人の同意なく公表すると個人情報保護法に抵触するおそれ」と明記しているため扱いません）</li><li>今月に入ってからの変更（次の月の全件データで反映します）</li></ul>'
        . '<h2>自社で使う</h2><p>取引先マスタを毎月自動で照合し、登録の取消や商号・所在地の変更を知らせる仕組みを、自社のサーバーに置く形で開発しています。導入のご相談は<a href="' . BUY . '&amp;ref=ktorihiki-about">こちら</a>。</p>';
    page('データと照合のしかた｜' . NAME, '国税庁の法人番号公表サイトと適格請求書発行事業者公表サイトの全件データ（愛知県の法人・前月末時点）を法人番号でつなぎ、取引先を照合するしかた。', $url, $body);
    exit;
}
if ($path !== '' && $path !== '/') { http_response_code(404); page('見つかりません｜' . NAME, '', BASE . '/', '<h1>見つかりません</h1>', true); exit; }

// トップ（一括チェック）
$input = $_SERVER['REQUEST_METHOD'] === 'POST' ? (string)($_POST['list'] ?? '') : '';
$lines = array_slice(array_values(array_filter(array_map('trim', preg_split('/\r\n|\n|\r/', $input)), fn($x) => $x !== '')), 0, MAX_LINES);
$res = [];
foreach ($lines as $l) { [$how, $cs] = lookup($l); $res[] = ['in' => $l, 'how' => $how, 'cs' => $cs]; }
$hn = number_format((int)meta('houjin_n')); $in = number_format((int)meta('invoice_n'));
$title = 'インボイス登録番号を取引先ごとにまとめて確認｜愛知県の法人' . $hn . '社';
$desc = '取引先の会社名・法人番号・T番号を貼るだけで、インボイスの登録・取消・失効と、登記の閉鎖・商号の変更をまとめて照合。愛知県に本店のある法人' . $hn . '社（国税庁の公表データ・前月末時点）。入力は保存しません。';
$body = '<h1>取引先のインボイス登録を、まとめて確認する</h1><p class="lead">取引先の一覧を貼ると、国税庁の公表データから<b>インボイスの登録・取消・失効</b>と<b>登記の閉鎖</b>をまとめて照合し、確認が要る取引先を上に並べます。愛知県に本店のある法人 ' . $hn . '社（うちインボイス登録あり ' . $in . '社）が対象です。</p>';
$body .= '<form method="post" class="panel" action="' . u('/') . '"><label for="list"><b>取引先を1行に1社</b>（会社名・13桁の法人番号・T＋13桁の登録番号のどれでも。最大' . MAX_LINES . '行）</label><textarea id="list" name="list" spellcheck="false">' . h($input !== '' ? $input : $SAMPLE) . '</textarea><p><button class="btn">まとめて確認する</button> <span class="src">入力した一覧は照合のためだけに使い、保存しません。</span></p></form>';
if ($res) {
    $rows = []; $cnt = [0, 0, 0]; $none = 0; $multi = 0;
    foreach ($res as $r) {
        if (!$r['cs']) { $none++; $rows[] = [3, '<tr><td>' . h($r['in']) . '</td><td colspan="4"><span class="tag tx">見つからない</span> 愛知県の法人に該当なし（県外の本店・個人事業者・名称の違いなど）</td></tr>']; continue; }
        if (count($r['cs']) > 1) $multi++;
        foreach ($r['cs'] as $k => $c) {
            [$lab, $w, $note] = status($c);
            if ($k === 0) $cnt[$w]++;
            $rows[] = [$k === 0 ? (2 - $w) : 9, '<tr><td>' . ($k === 0 ? h($r['in']) . '<br><span class="src">' . h($r['how']) . (count($r['cs']) > 1 ? '・候補' . count($r['cs']) . '件' : '') . '</span>' : '<span class="src">　同名の候補</span>') . '</td>'
                . '<td><b>' . h($c['name']) . '</b><br><span class="src">' . h($c['no']) . '・' . h($c['kind']) . '</span></td>'
                . '<td>' . h($c['city'] . $c['street']) . '</td>'
                . '<td><span class="tag t' . $w . '">' . h($lab) . '</span><br><span class="src">' . h($note) . '</span></td>'
                . '<td>' . ($c['inv_no'] ? h($c['inv_no']) : '—') . ($c['change_date'] && $c['change_date'] > $c['assigned'] ? '<br><span class="src">商号・所在地の変更 ' . jd($c['change_date']) . '</span>' : '') . '</td></tr>'];
        }
    }
    $body .= '<h2>結果（' . count($res) . '社）</h2><div class="big"><div><span class="k">要注意（閉鎖・取消・失効）</span><span class="v' . ($cnt[2] ? ' ng' : '') . '">' . $cnt[2] . '社</span></div><div><span class="k">インボイス未登録</span><span class="v' . ($cnt[1] ? ' wa' : '') . '">' . $cnt[1] . '社</span></div><div><span class="k">登録あり</span><span class="v">' . $cnt[0] . '社</span></div><div><span class="k">見つからない／同名が複数</span><span class="v">' . $none . '／' . $multi . '</span></div></div>';
    $body .= '<div class="tbl"><table><tr><th>入力</th><th>法人</th><th>所在地</th><th>状態</th><th>インボイス登録番号</th></tr>' . implode('', array_map(fn($x) => $x[1], $rows)) . '</table></div>';
    $body .= '<p class="src">状態は国税庁の全件データ（' . h(jd(meta('houjin_asof'))) . '時点）によります。取引の前には国税庁の公表サイトで最新の状態を確認してください。</p>';
}
[$fh, $fl] = [
    '', ['@context' => 'https://schema.org', '@type' => 'FAQPage', 'mainEntity' => []]];
foreach ([
    ['インボイスの登録番号はどこで確認できますか？', '国税庁の適格請求書発行事業者公表サイトで、登録番号（T＋13桁）を入れて1件ずつ確認できます。このページでは、取引先の一覧を貼って、まとめて確認できます（愛知県に本店のある法人）。'],
    ['法人のインボイス登録番号は、法人番号とどう違いますか？', '法人の登録番号は「T」に13桁の法人番号を付けたものです。法人番号が分かれば、登録番号の形も決まります。ただし、登録しているかどうかは公表サイトで確かめる必要があります。'],
    ['取引先のインボイスが取消・失効になると、どうなりますか？', '取消や失効の後に受け取った請求書は、適格請求書として仕入税額控除に使えません。取引先の登録の状態は、定期的に確かめておくと安全です。'],
    ['個人事業者の取引先も確認できますか？', 'このページでは扱いません。個人事業者の登録情報は、国税庁の公表サイトで登録番号から確認してください。'],
] as [$qq, $aa]) { $fh .= '<details><summary>' . h($qq) . '</summary><p>' . h($aa) . '</p></details>'; $fl['mainEntity'][] = ['@type' => 'Question', 'name' => $qq, 'acceptedAnswer' => ['@type' => 'Answer', 'text' => $aa]]; }
$body .= '<h2>よくある質問</h2>' . $fh;
$body .= '<h2>取引先マスタを毎月自動で照合する（開発中）</h2><p>取引先マスタを毎月照合し、インボイスの取消や商号・所在地の変更を知らせる仕組みを、自社のサーバーに置く形で開発しています。導入のご相談は<a href="' . BUY . '&amp;ref=ktorihiki-top">こちら</a>。</p>';
page($title, $desc, BASE . '/', $body, (bool)$res, [$fl]);
