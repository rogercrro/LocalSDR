<?php 
ini_set('display_errors', 1);
error_reporting(E_ALL);
// ============================================================
// LOCALSDR — Sistema de recepció de ràdio via web
// ============================================================
session_start();

define('SITE_NAME', 'LOCALSDR');

// ── Freqüències presets ──────────────────────────────────────
$presets = [
    ['id'=>'fm1',  'name'=>'Ràdio Girona',          'freq'=>'91.70',  'unit'=>'MHz','mode'=>'WFM','signal'=>82,'desc'=>'Cadena SER - Girona'],
    ['id'=>'fm2',  'name'=>'Catalunya Ràdio',        'freq'=>'95.60',  'unit'=>'MHz','mode'=>'WFM','signal'=>76,'desc'=>'Ràdio pública catalana'],
    ['id'=>'fm3',  'name'=>'Ràdio Nacional',         'freq'=>'88.90',  'unit'=>'MHz','mode'=>'WFM','signal'=>68,'desc'=>'RNE Radio 1'],
    ['id'=>'avi',  'name'=>'Empuriabrava Aeròdrom',  'freq'=>'122.000','unit'=>'MHz','mode'=>'AM', 'signal'=>54,'desc'=>'CTR Empuriabrava'],
    ['id'=>'mar',  'name'=>'Canal 16 Marítim',       'freq'=>'156.800','unit'=>'MHz','mode'=>'NFM','signal'=>47,'desc'=>'Canal de distress VHF'],
    ['id'=>'aprs', 'name'=>'APRS Regional',          'freq'=>'144.800','unit'=>'MHz','mode'=>'NFM','signal'=>39,'desc'=>'Telemetria aficionat'],
    ['id'=>'pmr',  'name'=>'PMR446 Canal 1',         'freq'=>'446.006','unit'=>'MHz','mode'=>'NFM','signal'=>61,'desc'=>'Ràdio civil lliure'],
    ['id'=>'noaa', 'name'=>'NOAA-15 Satèl·lit',      'freq'=>'137.620','unit'=>'MHz','mode'=>'NFM','signal'=>44,'desc'=>'Imatges meteorològiques'],
    ['id'=>'ism',  'name'=>'ISM 433 Sensors',        'freq'=>'433.920','unit'=>'MHz','mode'=>'NFM','signal'=>55,'desc'=>'Sensors domèstics IoT'],
    ['id'=>'cb',   'name'=>'CB Canal 19',            'freq'=>'27.185', 'unit'=>'MHz','mode'=>'AM', 'signal'=>33,'desc'=>'Banda ciutadana HF'],
];

// ── Paràmetres actuals (via GET/POST) ───────────────────────
$active_id  = $_GET['preset'] ?? 'fm1';
$custom_mhz = isset($_GET['custom_mhz']) && is_numeric($_GET['custom_mhz'])
              ? (float)$_GET['custom_mhz'] : null;
$volume     = isset($_GET['vol']) && is_numeric($_GET['vol'])
              ? max(0, min(100, (int)$_GET['vol'])) : 75;
$squelch    = isset($_GET['sql']) && is_numeric($_GET['sql'])
              ? max(0, min(100, (int)$_GET['sql'])) : 30;
$active_mode= $_GET['mode'] ?? null;

// Resolem la freqüència activa
if ($custom_mhz !== null) {
    $active_preset = [
        'id'=>'custom','name'=>'Freqüència manual',
        'freq'=>number_format($custom_mhz,3,'.',''),
        'unit'=>'MHz','mode'=>$active_mode ?? 'NFM',
        'signal'=>rand(15,70),'desc'=>'Entrada manual de l\'usuari'
    ];
    $active_id = 'custom';
} else {
   $found = array_filter($presets, function($p) use ($active_id) {
    return $p['id'] === $active_id;
});
    $active_preset = $found ? array_values($found)[0] : $presets[0];
    if ($active_mode) $active_preset['mode'] = $active_mode;
}

// ── Formulari de contacte ────────────────────────────────────
$contact_ok = $contact_err = '';
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='contact') {
    $n = trim($_POST['name']??'');
    $e = filter_var(trim($_POST['email']??''), FILTER_VALIDATE_EMAIL);
    $s = trim($_POST['subject']??'');
    $m = trim($_POST['msg']??'');
    if (!$n||!$e||!$s||!$m) $contact_err='Tots els camps són obligatoris.';
    else $contact_ok="Gràcies, ".htmlspecialchars($n)."! El teu missatge s'ha enviat correctament.";
}

// ── Secció activa ────────────────────────────────────────────
$sections = ['inici','escoltar','qui-som','faq','contacte'];
$sec = $_GET['sec'] ?? 'inici';
if (!in_array($sec,$sections)) $sec='inici';

// ── Helpers ──────────────────────────────────────────────────
function url(array $extra=[], array $remove=[]): string {
    $p = $_GET;
    foreach ($remove as $k) unset($p[$k]);
    foreach ($extra as $k=>$v) $p[$k]=$v;
    return '?'.http_build_query($p);
}

function sigColor(int $s): string {
    if ($s>=70) return '#39ff14';
    if ($s>=45) return '#ffd600';
    return '#ff6b35';
}

function sMeter(int $signal): string {
    $level = (int)round($signal/10);
    $out   = '';
    for ($i=1;$i<=10;$i++) {
        $col = $i<=7 ? '#39ff14' : ($i<=9?'#ffd600':'#ff3b3b');
        $active = $i<=$level ? 'opacity:1' : 'opacity:0.12';
        $out .= "<span style='display:inline-block;width:8px;height:".($i*2+6)."px;background:{$col};margin-right:2px;vertical-align:bottom;border-radius:1px;{$active}'></span>";
    }
    return $out;
}

$freq_display = $active_preset['freq'];
$signal       = $active_preset['signal'];
?>
<!DOCTYPE html>
<html lang="ca">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?=SITE_NAME?> — Receptor SDR en Directe</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Share+Tech+Mono&family=Barlow+Condensed:wght@300;600;800&display=swap" rel="stylesheet">
<style>
:root{
  --bg:     #04090f;
  --bg2:    #080f1c;
  --bg3:    #0c1828;
  --panel:  #0a1422;
  --accent: #00e5ff;
  --green:  #39ff14;
  --amber:  #ffd600;
  --orange: #ff6b35;
  --red:    #ff3b3b;
  --dim:    #3a5a7a;
  --text:   #b8cfe0;
  --white:  #e8f4ff;
  --mono:   'Share Tech Mono',monospace;
  --sans:   'Barlow Condensed',sans-serif;
  --border: rgba(0,229,255,0.12);
  --glow:   0 0 24px rgba(0,229,255,0.25);
}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
html{scroll-behavior:smooth}
body{
  background:var(--bg);color:var(--text);
  font-family:var(--sans);font-weight:300;
  min-height:100vh;overflow-x:hidden;
}
body::before{
  content:'';position:fixed;inset:0;z-index:0;
  background:
    linear-gradient(rgba(0,229,255,0.025) 1px,transparent 1px),
    linear-gradient(90deg,rgba(0,229,255,0.025) 1px,transparent 1px);
  background-size:36px 36px;pointer-events:none;
}

/* ── Header ─── */
header{
  position:sticky;top:0;z-index:200;
  background:rgba(4,9,15,0.95);backdrop-filter:blur(16px);
  border-bottom:1px solid var(--border);
  display:flex;align-items:center;justify-content:space-between;
  padding:0 2rem;height:60px;
}
.logo{display:flex;align-items:center;gap:10px;text-decoration:none}
.logo-pulse{
  width:32px;height:32px;border-radius:50%;
  border:2px solid var(--accent);
  display:flex;align-items:center;justify-content:center;
  position:relative;box-shadow:var(--glow);
}
.logo-pulse::after{
  content:'';position:absolute;
  width:8px;height:8px;background:var(--accent);border-radius:50%;
  animation:pulse 1.6s ease-in-out infinite;
}
@keyframes pulse{
  0%,100%{transform:scale(1);box-shadow:0 0 0 0 rgba(0,229,255,.6)}
  50%{transform:scale(1.4);box-shadow:0 0 0 8px rgba(0,229,255,0)}
}
.logo-name{font-family:var(--mono);font-size:1.1rem;color:var(--accent);letter-spacing:3px}
.logo-sub{font-size:.6rem;color:var(--dim);letter-spacing:2px;text-transform:uppercase;display:block;margin-top:-3px}
nav{display:flex;gap:.15rem}
nav a{
  font-family:var(--mono);font-size:.72rem;color:var(--dim);text-decoration:none;
  padding:6px 13px;border-radius:4px;letter-spacing:1.5px;text-transform:uppercase;
  border:1px solid transparent;transition:color .2s,border-color .2s,background .2s;
}
nav a:hover,nav a.on{color:var(--accent);border-color:var(--border);background:rgba(0,229,255,.05)}
.hd-status{font-family:var(--mono);font-size:.7rem;color:var(--dim);display:flex;align-items:center;gap:14px}
.dot-g{
  display:inline-block;width:7px;height:7px;border-radius:50%;margin-right:5px;
  background:var(--green);box-shadow:0 0 6px var(--green);animation:pulse 2s infinite;
}

/* ── Main ─── */
main{
  position:relative;z-index:1;max-width:1240px;margin:0 auto;
  padding:3rem 2rem 6rem;min-height:calc(100vh - 60px);
}
section{display:none}
section.active{display:block}

/* ── Hero ─── */
.hero{text-align:center;padding:5rem 0 4rem}
.hero-tag{
  font-family:var(--mono);font-size:.7rem;color:var(--orange);
  letter-spacing:4px;text-transform:uppercase;margin-bottom:1.5rem;display:block;
}
.hero h1{font-size:clamp(3rem,7vw,6rem);font-weight:800;line-height:1;color:var(--white)}
.hero h1 em{color:var(--accent);font-style:normal;text-shadow:0 0 30px rgba(0,229,255,.5)}
.hero-p{max-width:580px;margin:1.5rem auto;color:var(--dim);font-size:1.15rem;line-height:1.7}
.btn{
  display:inline-block;font-family:var(--mono);font-size:.78rem;
  letter-spacing:2px;text-transform:uppercase;padding:13px 30px;
  border-radius:4px;text-decoration:none;cursor:pointer;
  transition:transform .2s,box-shadow .2s;border:none;
}
.btn-a{
  background:var(--accent);color:var(--bg);font-weight:700;
  box-shadow:0 0 20px rgba(0,229,255,.3);
}
.btn-a:hover{transform:translateY(-3px);box-shadow:0 0 40px rgba(0,229,255,.5)}
.btn-b{
  background:transparent;color:var(--accent);
  border:1px solid var(--border);margin-left:.75rem;
}
.btn-b:hover{border-color:var(--accent);background:rgba(0,229,255,.05);transform:translateY(-3px)}

/* Stat cards */
.stat-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:1rem;margin:4rem 0}
.stat-card{
  background:var(--bg2);border:1px solid var(--border);border-radius:8px;
  padding:1.5rem;text-align:center;transition:border-color .3s,box-shadow .3s;
}
.stat-card:hover{border-color:var(--accent);box-shadow:var(--glow)}
.sv{font-family:var(--mono);font-size:1.7rem;color:var(--accent);display:block;text-shadow:0 0 10px rgba(0,229,255,.35)}
.sl{font-size:.68rem;color:var(--dim);letter-spacing:2px;text-transform:uppercase;margin-top:.35rem;display:block}

/* Feature grid */
.feat-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:1.25rem}
.feat{
  background:var(--bg2);border:1px solid var(--border);border-radius:10px;
  padding:2rem;position:relative;overflow:hidden;transition:transform .3s,box-shadow .3s;
}
.feat::after{
  content:'';position:absolute;top:0;left:0;right:0;height:2px;
  background:linear-gradient(90deg,transparent,var(--accent),transparent);
  opacity:0;transition:opacity .3s;
}
.feat:hover{transform:translateY(-4px);box-shadow:0 8px 40px rgba(0,229,255,.08)}
.feat:hover::after{opacity:1}
.feat-icon{font-size:2.2rem;margin-bottom:1rem;display:block}
.feat h3{color:var(--white);font-size:1.05rem;font-weight:600;margin-bottom:.6rem}
.feat p{color:var(--dim);font-size:.88rem;line-height:1.7}

/* ── Sec header ─── */
.sec-tag{
  font-family:var(--mono);font-size:.65rem;letter-spacing:3px;text-transform:uppercase;
  color:var(--accent);background:rgba(0,229,255,.08);border:1px solid rgba(0,229,255,.2);
  padding:4px 12px;border-radius:3px;display:inline-block;margin-bottom:1.2rem;
}
.sec-title{font-size:clamp(1.8rem,4vw,2.8rem);font-weight:800;color:var(--white);margin-bottom:.5rem}
.sec-title span{color:var(--accent)}
.sec-lead{color:var(--dim);max-width:580px;line-height:1.8;margin-bottom:2.5rem;font-size:.95rem}

/* ══════════════════════════════════════════════
   RECEPTOR SDR
══════════════════════════════════════════════ */
.receiver{
  background:var(--panel);border:1px solid rgba(0,229,255,.2);border-radius:14px;
  overflow:hidden;box-shadow:0 0 60px rgba(0,0,0,.6);margin-bottom:2rem;
}
.rx-top{
  background:linear-gradient(135deg,#060e1a 0%,#0a1828 100%);
  border-bottom:1px solid var(--border);
  padding:1.25rem 1.75rem;
  display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:1rem;
}
.rx-label{font-family:var(--mono);font-size:.65rem;letter-spacing:3px;text-transform:uppercase;color:var(--dim)}
.rx-status{font-family:var(--mono);font-size:.7rem;color:var(--green);display:flex;align-items:center;gap:6px}

.rx-display{
  padding:2rem 1.75rem 1.5rem;border-bottom:1px solid var(--border);
  display:flex;align-items:flex-end;gap:2rem;flex-wrap:wrap;
}
.freq-big{
  font-family:var(--mono);font-size:clamp(2.8rem,5vw,4.5rem);
  color:var(--green);text-shadow:0 0 20px rgba(57,255,20,.4);
  letter-spacing:2px;line-height:1;
}
.freq-unit{font-family:var(--mono);font-size:1.2rem;color:var(--dim);margin-bottom:.6rem}
.mode-badge{
  font-family:var(--mono);font-size:.75rem;
  background:rgba(0,229,255,.1);border:1px solid rgba(0,229,255,.3);
  color:var(--accent);padding:4px 12px;border-radius:4px;letter-spacing:2px;margin-bottom:.6rem;
}
.rx-name-label{color:var(--dim);font-size:.8rem;font-family:var(--mono)}
.rx-name-val{color:var(--white);font-size:1.1rem;font-weight:600}

.smeter-row{
  padding:1rem 1.75rem;border-bottom:1px solid var(--border);
  display:flex;align-items:center;gap:1.5rem;flex-wrap:wrap;
}
.smeter-label{font-family:var(--mono);font-size:.6rem;color:var(--dim);letter-spacing:2px}
.sig-num{font-family:var(--mono);font-size:.8rem;margin-left:.5rem}

/* Waterfall */
.waterfall{height:120px;overflow:hidden;position:relative;border-bottom:1px solid var(--border)}
.wf-row{height:4px;display:block;width:100%}

/* Controls */
.rx-controls{
  padding:1.5rem 1.75rem;
  display:grid;grid-template-columns:1fr 1fr;gap:1.5rem;
}
@media(max-width:640px){.rx-controls{grid-template-columns:1fr}}
.ctrl-group{display:flex;flex-direction:column;gap:.5rem}
.ctrl-label{font-family:var(--mono);font-size:.62rem;letter-spacing:2px;text-transform:uppercase;color:var(--dim)}
.slider-wrap{display:flex;align-items:center;gap:.75rem}
input[type=range]{
  -webkit-appearance:none;appearance:none;flex:1;height:4px;border-radius:2px;
  background:var(--bg3);border:none;outline:none;cursor:pointer;
}
input[type=range]::-webkit-slider-thumb{
  -webkit-appearance:none;width:16px;height:16px;border-radius:50%;
  background:var(--accent);border:2px solid var(--bg);box-shadow:0 0 8px rgba(0,229,255,.5);cursor:pointer;
}
input[type=range]::-moz-range-thumb{
  width:16px;height:16px;border-radius:50%;
  background:var(--accent);border:2px solid var(--bg);box-shadow:0 0 8px rgba(0,229,255,.5);cursor:pointer;
}
.slider-val{font-family:var(--mono);font-size:.78rem;color:var(--accent);min-width:36px;text-align:right}
.mode-btns{display:flex;gap:.4rem;flex-wrap:wrap}
.mode-btn{
  font-family:var(--mono);font-size:.65rem;letter-spacing:1px;text-transform:uppercase;
  padding:5px 10px;border-radius:3px;border:1px solid var(--border);color:var(--dim);
  text-decoration:none;transition:.2s;
}
.mode-btn:hover,.mode-btn.am{border-color:var(--accent);color:var(--accent);background:rgba(0,229,255,.07)}

/* Manual form */
.manual-form{
  background:var(--bg2);border:1px solid var(--border);border-radius:10px;
  padding:1.5rem;margin-bottom:2rem;
  display:flex;align-items:flex-end;gap:1rem;flex-wrap:wrap;
}
.manual-form .fg{display:flex;flex-direction:column;gap:.4rem}
.manual-form label{font-family:var(--mono);font-size:.62rem;letter-spacing:2px;text-transform:uppercase;color:var(--dim)}
.manual-form input[type=number],.manual-form select{
  background:var(--bg3);border:1px solid var(--border);color:var(--white);
  font-family:var(--mono);font-size:1rem;padding:9px 12px;border-radius:5px;
  outline:none;-webkit-appearance:none;appearance:none;
}
.manual-form input[type=number]:focus,.manual-form select:focus{border-color:var(--accent);box-shadow:0 0 0 3px rgba(0,229,255,.1)}
.manual-form select option{background:var(--bg)}
.manual-form input[type=number]{width:160px}

/* Preset grid */
.preset-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:.75rem}
.preset-card{
  background:var(--bg2);border:1px solid var(--border);border-radius:8px;
  padding:1rem 1.25rem;text-decoration:none;
  display:flex;align-items:center;gap:.9rem;
  transition:border-color .2s,background .2s,transform .2s;
}
.preset-card:hover,.preset-card.pc-active{border-color:var(--accent);background:var(--bg3)}
.preset-card.pc-active{border-color:var(--green);box-shadow:0 0 12px rgba(57,255,20,.12)}
.preset-card:hover{transform:translateX(4px)}
.pc-left{flex:1}
.pc-freq{font-family:var(--mono);font-size:1rem;color:var(--accent)}
.pc-freq.af{color:var(--green);text-shadow:0 0 8px rgba(57,255,20,.4)}
.pc-name{color:var(--white);font-size:.85rem;font-weight:600;margin-bottom:1px}
.pc-desc{color:var(--dim);font-size:.72rem}
.pc-mode{
  font-family:var(--mono);font-size:.6rem;color:var(--orange);
  background:rgba(255,107,53,.1);border:1px solid rgba(255,107,53,.25);
  padding:2px 7px;border-radius:3px;letter-spacing:1px;flex-shrink:0;
}
.pc-sig{font-family:var(--mono);font-size:.65rem;margin-top:3px}

/* ── Qui som ─── */
.two-col{display:grid;grid-template-columns:1fr 1fr;gap:2.5rem;align-items:start}
@media(max-width:760px){.two-col{grid-template-columns:1fr}}
.card{background:var(--bg2);border:1px solid var(--border);border-radius:10px;padding:2rem;margin-bottom:1.5rem}
.card h3{font-family:var(--mono);font-size:.85rem;color:var(--accent);letter-spacing:2px;margin-bottom:1.2rem;border-bottom:1px solid var(--border);padding-bottom:.75rem}
.card p{color:var(--dim);line-height:1.8;font-size:.93rem;margin-bottom:.9rem}
.card p:last-child{margin-bottom:0}
.tl-item{
  display:flex;gap:1.2rem;padding:1rem 0 1rem 1.5rem;
  border-left:1px solid var(--border);margin-left:.4rem;position:relative;
}
.tl-item::before{
  content:'';position:absolute;left:-5px;top:1.4rem;
  width:9px;height:9px;border-radius:50%;
  background:var(--accent);box-shadow:0 0 8px rgba(0,229,255,.5);
}
.tl-step{font-family:var(--mono);font-size:.62rem;color:var(--orange);letter-spacing:2px;text-transform:uppercase;min-width:70px;margin-top:2px}
.tl-h{color:var(--white);font-size:.9rem;font-weight:600;margin-bottom:.2rem}
.tl-p{color:var(--dim);font-size:.8rem;line-height:1.6}
.hw-table{width:100%;border-collapse:collapse;font-size:.85rem;margin-top:.5rem}
.hw-table tr{border-bottom:1px solid rgba(0,229,255,.07)}
.hw-table td{padding:.55rem 0;color:var(--dim)}
.hw-table td:last-child{color:var(--text);font-family:var(--mono);font-size:.78rem}

/* ── FAQ CSS accordion ─── */
.faq-item{background:var(--bg2);border:1px solid var(--border);border-radius:8px;margin-bottom:.7rem;overflow:hidden}
.faq-item input[type=checkbox]{position:absolute;opacity:0;pointer-events:none}
.faq-q{
  display:flex;align-items:center;justify-content:space-between;gap:1rem;
  padding:1.15rem 1.5rem;cursor:pointer;
  color:var(--white);font-size:.95rem;font-weight:600;
  user-select:none;transition:background .2s;
}
.faq-q:hover{background:var(--bg3)}
.faq-arrow{font-family:var(--mono);color:var(--accent);flex-shrink:0;font-size:.9rem;display:inline-block;transition:transform .35s}
input[type=checkbox]:checked ~ label .faq-arrow{transform:rotate(90deg)}
.faq-a{max-height:0;overflow:hidden;transition:max-height .4s ease}
input[type=checkbox]:checked ~ .faq-a{max-height:400px}
.faq-a-in{padding:.2rem 1.5rem 1.25rem;border-top:1px solid var(--border);color:var(--dim);font-size:.88rem;line-height:1.85}
.faq-a-in code{
  background:rgba(0,229,255,.07);border:1px solid var(--border);
  padding:1px 6px;border-radius:3px;font-family:var(--mono);font-size:.83em;color:var(--accent);
}

/* ── Contacte ─── */
.contact-layout{display:grid;grid-template-columns:1fr 1.8fr;gap:3rem;align-items:start}
@media(max-width:760px){.contact-layout{grid-template-columns:1fr}}
.contact-info h3{color:var(--white);font-size:1.3rem;font-weight:700;margin-bottom:.8rem}
.contact-info p{color:var(--dim);line-height:1.8;font-size:.9rem;margin-bottom:1.8rem}
.ci{display:flex;gap:.9rem;align-items:flex-start;margin-bottom:1.15rem}
.ci-icon{
  width:38px;height:38px;border-radius:7px;flex-shrink:0;
  background:rgba(0,229,255,.07);border:1px solid var(--border);
  display:flex;align-items:center;justify-content:center;font-size:1.1rem;
}
.ci-l{font-size:.62rem;color:var(--dim);letter-spacing:1.5px;text-transform:uppercase;font-family:var(--mono)}
.ci-v{color:var(--text);font-size:.88rem;margin-top:2px}
.cf{background:var(--bg2);border:1px solid var(--border);border-radius:12px;padding:2.5rem}
.fg{margin-bottom:1.15rem}
.fg2{display:grid;grid-template-columns:1fr 1fr;gap:1rem}
@media(max-width:480px){.fg2{grid-template-columns:1fr}}
.cf label{display:block;font-family:var(--mono);font-size:.62rem;letter-spacing:2px;text-transform:uppercase;color:var(--dim);margin-bottom:.45rem}
.cf input,.cf textarea,.cf select{
  width:100%;background:var(--bg3);border:1px solid var(--border);
  color:var(--white);font-family:var(--sans);font-size:.95rem;
  padding:10px 13px;border-radius:5px;outline:none;-webkit-appearance:none;
  transition:border-color .2s,box-shadow .2s;
}
.cf input:focus,.cf textarea:focus,.cf select:focus{border-color:var(--accent);box-shadow:0 0 0 3px rgba(0,229,255,.1)}
.cf textarea{resize:vertical;min-height:120px}
.cf select option{background:var(--bg)}
.form-ok{background:rgba(57,255,20,.08);border:1px solid rgba(57,255,20,.3);color:var(--green);padding:.85rem 1.1rem;border-radius:6px;font-size:.88rem;margin-bottom:1rem}
.form-err{background:rgba(255,59,59,.08);border:1px solid rgba(255,59,59,.3);color:var(--red);padding:.85rem 1.1rem;border-radius:6px;font-size:.88rem;margin-bottom:1rem}
.btn-submit{
  width:100%;background:var(--accent);color:var(--bg);
  font-family:var(--mono);font-size:.78rem;letter-spacing:2px;text-transform:uppercase;
  padding:13px;border-radius:5px;border:none;cursor:pointer;font-weight:700;
  box-shadow:0 0 18px rgba(0,229,255,.25);transition:box-shadow .2s,transform .2s;
}
.btn-submit:hover{box-shadow:0 0 36px rgba(0,229,255,.45);transform:translateY(-2px)}

/* ── Footer ─── */
footer{position:relative;z-index:1;background:var(--bg2);border-top:1px solid var(--border);padding:3rem 2rem 1.8rem}
.ft-inner{max-width:1240px;margin:0 auto;display:grid;grid-template-columns:2fr 1fr 1fr;gap:3rem}
@media(max-width:640px){.ft-inner{grid-template-columns:1fr;gap:2rem}}
.ft-brand p{color:var(--dim);font-size:.83rem;line-height:1.8;margin-top:.7rem;max-width:300px}
footer h4{font-family:var(--mono);font-size:.62rem;letter-spacing:3px;text-transform:uppercase;color:var(--accent);margin-bottom:1rem}
footer ul{list-style:none}
footer li{margin-bottom:.5rem}
footer a{color:var(--dim);text-decoration:none;font-size:.86rem;transition:color .2s}
footer a:hover{color:var(--accent)}
.ft-bottom{max-width:1240px;margin:2rem auto 0;padding-top:1.5rem;border-top:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:.75rem}
.ft-bottom p{font-family:var(--mono);font-size:.67rem;color:var(--dim)}
</style>
</head>
<body>

<header>
  <a href="?sec=inici" class="logo">
    <div class="logo-pulse"></div>
    <div>
      <span class="logo-name"><?=SITE_NAME?></span>
      <span class="logo-sub">SDR · Baix Empordà</span>
    </div>
  </a>
  <nav>
    <a href="?sec=inici"    class="<?=$sec==='inici'   ?'on':''?>">Inici</a>
    <a href="?sec=escoltar" class="<?=$sec==='escoltar'?'on':''?>">Escoltar</a>
    <a href="?sec=qui-som"  class="<?=$sec==='qui-som' ?'on':''?>">Qui som</a>
    <a href="?sec=faq"      class="<?=$sec==='faq'     ?'on':''?>">FAQ</a>
    <a href="?sec=contacte" class="<?=$sec==='contacte'?'on':''?>">Contacte</a>
  </nav>
  <div class="hd-status">
    <span><span class="dot-g"></span>SDR EN LÍNIA</span>
    <span>👥 <?=rand(2,9)?> escoltant</span>
  </div>
</header>

<main>

<!-- INICI -->
<section class="<?=$sec==='inici'?'active':''?>">
  <div class="hero">
    <span class="hero-tag">⚡ Baix Empordà · En directe</span>
    <h1>Escolta l'<em>èter</em><br>des d'arreu</h1>
    <p class="hero-p">Un receptor RTL-SDR connectat a una Raspberry Pi capta senyals de ràdio de la comarca en temps real. Accedeix remotament des de qualsevol navegador.</p>
    <a href="?sec=escoltar" class="btn btn-a">▶ Escoltar ara</a>
    <a href="?sec=qui-som"  class="btn btn-b">Com funciona →</a>
  </div>
  <div class="stat-grid">
    <div class="stat-card"><span class="sv" style="color:var(--green)">● EN LÍNIA</span><span class="sl">Estat del receptor</span></div>
    <div class="stat-card"><span class="sv">3d 14h</span><span class="sl">Temps en marxa</span></div>
    <div class="stat-card"><span class="sv"><?=rand(2,9)?></span><span class="sl">Usuaris connectats</span></div>
    <div class="stat-card"><span class="sv">2.4 MSPS</span><span class="sl">Taxa de mostreig</span></div>
    <div class="stat-card"><span class="sv">42°C</span><span class="sl">Temp. RPi</span></div>
    <div class="stat-card"><span class="sv"><?=count($presets)?></span><span class="sl">Freqüències guardades</span></div>
  </div>
  <div class="feat-grid">
    <div class="feat"><span class="feat-icon">📡</span><h3>Receptor RTL-SDR</h3><p>Dispositiu RTL-SDR V4 cobreix de 500 kHz fins a 1,75 GHz. Antena dipol omnidireccional instal·lada en punt elevat per màxima cobertura de la comarca.</p></div>
    <div class="feat"><span class="feat-icon">🌐</span><h3>Accés 100% web</h3><p>Gràcies a OpenWebRX allotjat a la Raspberry Pi i exposat via túnel segur, pots sintonitzar qualsevol freqüència des del navegador sense cap instal·lació.</p></div>
    <div class="feat"><span class="feat-icon">📊</span><h3>Espectre en temps real</h3><p>Visualitza el waterfall i l'espectre de freqüències en viu. Identifica senyals per forma i amplada de banda sense cap software addicional.</p></div>
    <div class="feat"><span class="feat-icon">🔒</span><h3>Infraestructura segura</h3><p>Comunicació xifrada HTTPS, accés SSH tancat a l'exterior i arquitectura de túnel que protegeix la Raspberry Pi de connexions no autoritzades.</p></div>
    <div class="feat"><span class="feat-icon">🗺️</span><h3>Freqüències locals</h3><p>Base de dades de freqüències d'interès del Baix Empordà: aviació, ràdio marítima, FM local, satèl·lits meteorològics i comunicacions d'aficionat.</p></div>
    <div class="feat"><span class="feat-icon">🎓</span><h3>Projecte SMX</h3><p>Treball intermodular de CFGS en Sistemes Microinformàtics i Xarxes que integra telecomunicacions, sistemes Linux, xarxes i programació web.</p></div>
  </div>
</section>

<!-- ESCOLTAR -->
<section class="<?=$sec==='escoltar'?'active':''?>">
  <div class="sec-tag">📻 Receptor SDR en directe</div>
  <h2 class="sec-title">Sintonitzar <span>freqüència</span></h2>
  <p class="sec-lead">Selecciona un canal de la llista o introdueix una freqüència manualment. Ajusta el volum i el squelch amb els controls del receptor.</p>

  <!-- Receptor -->
  <div class="receiver">
    <div class="rx-top">
      <span class="rx-label">⬡ SDR GIRONA — RECEPTOR RTL-SDR V4</span>
      <span class="rx-status">
        <span style="display:inline-block;width:8px;height:8px;border-radius:50%;background:var(--green);box-shadow:0 0 6px var(--green);animation:pulse 1.5s infinite"></span>
        EN LÍNIA · ANTENA ACTIVA
      </span>
    </div>

    <div class="rx-display">
      <div>
        <div class="rx-label" style="margin-bottom:.4rem">FREQÜÈNCIA SINTONITZADA</div>
        <div style="display:flex;align-items:baseline;gap:.5rem">
          <span class="freq-big"><?=htmlspecialchars($freq_display)?></span>
          <span class="freq-unit"><?=htmlspecialchars($active_preset['unit'])?></span>
        </div>
      </div>
      <div style="margin-bottom:.5rem">
        <div class="rx-label" style="margin-bottom:.5rem">MODE</div>
        <span class="mode-badge"><?=htmlspecialchars($active_preset['mode'])?></span>
      </div>
      <div style="margin-bottom:.5rem">
        <div class="rx-label" style="margin-bottom:.3rem">CANAL</div>
        <div class="rx-name-val"><?=htmlspecialchars($active_preset['name'])?></div>
        <div class="rx-name-label"><?=htmlspecialchars($active_preset['desc'])?></div>
      </div>
    </div>

    <!-- S-Meter -->
    <div class="smeter-row">
      <div>
        <div class="smeter-label" style="margin-bottom:.35rem">S-METER · NIVELL DE SENYAL</div>
        <div style="line-height:1">
          <?=sMeter($signal)?>
          <span class="sig-num" style="color:<?=sigColor($signal)?>">
            <?php $sv=round($signal/10); echo $signal>=90?"S9+":"S{$sv}"; ?> &nbsp; <?=$signal?> dBm
          </span>
        </div>
      </div>
    </div>

    <!-- Waterfall generat amb PHP -->
    <div class="waterfall">
   <?php
srand(crc32($active_preset['freq']));

for ($row = 0; $row < 30; $row++) {

    $stops = '';
    $n = 80;

    for ($i = 0; $i < $n; $i++) {

        $pct = round($i / $n * 100);

        $base = max(0, min(255, (int)(
            rand(0,25)
            + ($i > 35 && $i < 45 ? $signal * 1.9 : 0)
            + ($i > 18 && $i < 22 ? $signal * 0.7 : 0)
            + ($i > 60 && $i < 64 ? $signal * 1.1 : 0)
            + ($i > 70 && $i < 72 ? $signal * 0.5 : 0)
        )));

        $r = (int)min(255, $base * 0.55);
        $g = (int)min(255, $base * 0.35);
        $b = (int)min(255, 255 - $base * 0.5);

        $stops .= "rgb($r,$g,$b) $pct%,";
    }

    echo "<span class='wf-row' style='background:linear-gradient(90deg," . rtrim($stops, ',') . ")'></span>";
}
?>
    </div>

    <!-- Controls -->
    <form method="GET" action="">
      <input type="hidden" name="sec" value="escoltar">
      <input type="hidden" name="preset" value="<?=htmlspecialchars($active_id)?>">
      <?php if($custom_mhz!==null):?><input type="hidden" name="custom_mhz" value="<?=htmlspecialchars($custom_mhz)?>"><?php endif;?>
      <div class="rx-controls">
        <div class="ctrl-group">
          <div class="ctrl-label">🔊 Volum — <?=$volume?>%</div>
          <div class="slider-wrap">
            <input type="range" name="vol" min="0" max="100" value="<?=$volume?>" step="5">
            <span class="slider-val"><?=$volume?>%</span>
          </div>
        </div>
        <div class="ctrl-group">
          <div class="ctrl-label">🎚 Squelch — <?=$squelch?>%</div>
          <div class="slider-wrap">
            <input type="range" name="sql" min="0" max="100" value="<?=$squelch?>" step="5">
            <span class="slider-val"><?=$squelch?>%</span>
          </div>
        </div>
        <div class="ctrl-group">
          <div class="ctrl-label">Mode de demodulació</div>
          <div class="mode-btns">
            <?php foreach(['WFM','NFM','AM','USB','LSB','CW'] as $m):?>
            <a href="<?=url(['mode'=>$m])?>" class="mode-btn <?=$active_preset['mode']===$m?'am':''?>"><?=$m?></a>
            <?php endforeach;?>
          </div>
        </div>
        <div class="ctrl-group" style="justify-content:flex-end">
          <button type="submit" class="btn btn-a" style="padding:10px 24px;font-size:.75rem">Aplicar canvis →</button>
        </div>
      </div>
    </form>
  </div>

  <!-- Entrada manual -->
  <form method="GET" action="" class="manual-form">
    <input type="hidden" name="sec" value="escoltar">
    <input type="hidden" name="vol" value="<?=$volume?>">
    <input type="hidden" name="sql" value="<?=$squelch?>">
    <div class="fg">
      <label for="cmhz">Freqüència (MHz)</label>
      <input type="number" id="cmhz" name="custom_mhz" min="0.1" max="1750" step="0.001"
             placeholder="p.ex. 144.800"
             value="<?=$custom_mhz!==null?htmlspecialchars(number_format($custom_mhz,3,'.','')):'';?>">
    </div>
    <div class="fg">
      <label for="cmode">Mode</label>
      <select id="cmode" name="mode">
        <?php foreach(['WFM','NFM','AM','USB','LSB','CW'] as $m):?>
        <option value="<?=$m?>" <?=($active_preset['mode']===$m&&$active_id==='custom')?'selected':''?>><?=$m?></option>
        <?php endforeach;?>
      </select>
    </div>
    <button type="submit" class="btn btn-a" style="padding:11px 24px;font-size:.75rem">Sintonitzar →</button>
  </form>
  <!-- Presets -->
  <h3 style="color:var(--white);font-size:1.05rem;font-weight:600;margin-bottom:1.1rem">Freqüències d'interès — Baix Empordà</h3>
  <div class="preset-grid">
    <?php foreach($presets as $p):$ia=$p['id']===$active_id;?>
    <a class="preset-card <?=$ia?'pc-active':''?>"
       href="<?=url(['preset'=>$p['id'],'sec'=>'escoltar'],['custom_mhz'])?>">
      <div class="pc-left">
        <div class="pc-name"><?=htmlspecialchars($p['name'])?></div>
        <div class="pc-freq <?=$ia?'af':''?>"><?=htmlspecialchars($p['freq'])?> <?=htmlspecialchars($p['unit'])?></div>
        <div class="pc-desc"><?=htmlspecialchars($p['desc'])?></div>
        <div class="pc-sig" style="color:<?=sigColor($p['signal'])?>">
          <?=str_repeat('█',(int)round($p['signal']/10))?><?=str_repeat('░',10-(int)round($p['signal']/10))?> <?=$p['signal']?> dBm
        </div>
      </div>
      <span class="pc-mode"><?=htmlspecialchars($p['mode'])?></span>
    </a>
    <?php endforeach;?>
  </div>
</section>

<!-- QUI SOM -->
<section class="<?=$sec==='qui-som'?'active':''?>">
  <div class="sec-tag">ℹ️ Nosaltres</div>
  <h2 class="sec-title">Qui <span>som</span></h2>
  <p class="sec-lead">Projecte intermodular de CFGS en Sistemes Microinformàtics i Xarxes que integra telecomunicacions, xarxes, sistemes Linux i programació web.</p>
  <div class="two-col">
    <div>
      <div class="card">
        <h3>// El projecte</h3>
        <p>Aquest sistema de ràdio SDR combina maquinari econòmic —una Raspberry Pi 4 i un receptor RTL-SDR V4— amb programari de codi obert per crear una plataforma de recepció de ràdio accessible remotament via web, sense necessitat de cap equip especial per part de l'usuari.</p>
        <p>L'objectiu és demostrar que amb una inversió mínima es pot construir una infraestructura de telecomunicacions real, integrant múltiples disciplines del cicle formatiu en un sol producte funcional i escalable.</p>
      </div>
      <div class="card">
        <h3>// Arquitectura del sistema</h3>
        <div class="tl-item"><div class="tl-step">Capa 1</div><div><div class="tl-h">Antena + RTL-SDR V4</div><div class="tl-p">Captura senyals de 500 kHz a 1,75 GHz. Converteix RF en senyal digital I/Q via USB 2.0.</div></div></div>
        <div class="tl-item"><div class="tl-step">Capa 2</div><div><div class="tl-h">Raspberry Pi 4 (4 GB)</div><div class="tl-p">Raspberry Pi OS Lite 64-bit. Executa OpenWebRX per a processament DSP i streaming WebSocket.</div></div></div>
        <div class="tl-item"><div class="tl-step">Capa 3</div><div><div class="tl-h">Túnel segur / WireGuard VPN</div><div class="tl-p">Exposa el servei de forma segura sense exposar SSH ni ports interns directament a Internet.</div></div></div>
        <div class="tl-item"><div class="tl-step">Capa 4</div><div><div class="tl-h">Servidor web PHP</div><div class="tl-p">Aquesta interfície web allotjada al cloud agrupa l'accés, la informació i el receptor en una sola URL.</div></div></div>
      </div>
    </div>
    <div>
      <div class="card">
        <h3>// Maquinari</h3>
        <table class="hw-table">
          <?php foreach([
            ['Receptor','RTL-SDR Blog V4'],
            ['CPU','Raspberry Pi 4 Model B (4 GB RAM)'],
            ['Antena','Dipol vertical 50Ω (kit RTL-SDR)'],
            ['Sistema operatiu','Raspberry Pi OS Lite 64-bit'],
            ['Programari SDR','OpenWebRX + GNU Radio'],
            ['Connexió','Ethernet 1 Gbps (PoE)'],
            ['Alimentació','5V 3A USB-C oficial'],
            ['Refrigeració','Dissipador passiu + caixa alumini'],
            ['Emmagatzematge','microSD 32 GB Class 10'],
          ] as $r):?>
          <tr><td><?=$r[0]?></td><td><?=$r[1]?></td></tr>
          <?php endforeach;?>
        </table>
      </div>
      <div class="card">
        <h3>// Cobertura freqüencial</h3>
        <?php foreach([
          ['Ona curta HF','0.5 – 30 MHz','AM / SSB / CW'],
          ['Banda VHF','30 – 300 MHz','WFM / NFM / AM'],
          ['Banda UHF','300 – 900 MHz','NFM / DMR / APRS'],
          ['L-Band','900 – 1750 MHz','GPS / Satèl·lit'],
        ] as $r):?>
        <div style="display:flex;align-items:center;gap:1rem;padding:.6rem 0;border-bottom:1px solid rgba(0,229,255,.07)">
          <div style="min-width:120px;color:var(--white);font-size:.88rem"><?=$r[0]?></div>
          <div style="font-family:var(--mono);font-size:.75rem;color:var(--accent)"><?=$r[1]?></div>
          <div style="font-family:var(--mono);font-size:.65rem;color:var(--orange);margin-left:auto"><?=$r[2]?></div>
        </div>
        <?php endforeach;?>
      </div>
    </div>
  </div>
</section>

<!-- FAQ -->
<section class="<?=$sec==='faq'?'active':''?>">
  <div class="sec-tag">❓ Preguntes freqüents</div>
  <h2 class="sec-title">FAQ</h2>
  <p class="sec-lead">Les respostes als dubtes més habituals sobre el receptor SDR i el projecte.</p>
  <?php foreach([
    ['Necessito instal·lar algun programa per escoltar?','No. Tot funciona directament al navegador web via HTML5 Web Audio API. Compatible amb Chrome, Firefox, Safari i Edge des de qualsevol dispositiu —ordinador, tauleta o mòbil— sense cap plugin ni aplicació addicional.'],
    ['Quines freqüències puc escoltar?','El receptor RTL-SDR V4 cobreix de 500 kHz a 1.750 GHz (amb accés directe fins a 28 MHz per a HF). Pots escoltar FM comercial, aviació en AM (108–137 MHz), VHF marítima (156–174 MHz), PMR446, trunking digital, satèl·lits meteorològics NOAA, ISM i molt més.'],
    ['Quina qualitat d\'àudio puc esperar?','Per a FM comercial (WFM) la qualitat és molt bona, comparable a un receptor convencional. Per a modes de banda estreta NFM (PMR, marítim) i AM (aviació) la qualitat depèn de la intensitat del senyal i la distància a l\'emissor.'],
    ['Per què de vegades el receptor no és accessible?','El sistema pot estar en manteniment programat, la Raspberry Pi pot estar reiniciant-se per actualitzacions o pot haver-hi una interrupció temporal de la connexió a Internet. Si el problema dura més de 15 minuts, usa el formulari de contacte.'],
    ['Puc connectar-me des del mòbil?','Sí. La interfície és completament responsive. En dispositius mòbils funciona millor amb Chrome o Firefox. La reproducció d\'àudio pot requerir una interacció inicial (tocar la pantalla) per restriccions de seguretat dels navegadors mòbils.'],
    ['Com funciona la connexió remota a la Raspberry Pi?','La Raspberry Pi executa OpenWebRX, que exposa un servidor WebSocket amb l\'àudio i el waterfall. Un túnel WireGuard VPN connecta la Raspberry al servidor cloud on s\'allotja aquesta web. Tota la comunicació va xifrada d\'extrem a extrem.'],
    ['El sistema és segur?','El port SSH de la Raspberry Pi està bloquejat externament. L\'únic servei exposat és el receptor d\'àudio, protegit per contrasenya i accessible només via HTTPS. El servidor web PHP corre en un contenidor aïllat al cloud.'],
    ['Com proposo noves freqüències al llistat?','Usa el formulari de contacte indicant la freqüència en MHz, el mode de demodulació (WFM/NFM/AM/USB/LSB) i una breu descripció del servei. Afegirem regularment les freqüències d\'interès per a la comarca.'],
  ] as $i=>$faq):?>
  <div class="faq-item">
    <input type="checkbox" id="faq<?=$i?>">
    <label for="faq<?=$i?>" class="faq-q">
      <?=htmlspecialchars($faq[0])?>
      <span class="faq-arrow">▶</span>
    </label>
    <div class="faq-a">
      <div class="faq-a-in"><?=htmlspecialchars($faq[1])?></div>
    </div>
  </div>
  <?php endforeach;?>
</section>

<!-- CONTACTE -->
<section class="<?=$sec==='contacte'?'active':''?>">
  <div class="sec-tag">✉️ Contacte</div>
  <h2 class="sec-title">Posa't en <span>contacte</span></h2>
  <p class="sec-lead">Preguntes sobre el projecte, col·laboracions o suggeriments de noves freqüències. T'informem en menys de 48h.</p>
  <div class="contact-layout">
    <div class="contact-info">
      <h3>Parla amb nosaltres</h3>
      <p>Som estudiants de CFGS SMX apassionats per les telecomunicacions. Estem oberts a qualsevol pregunta tècnica, proposta de col·laboració amb altres centres o entitats, i suggeriments per millorar la plataforma.</p>
      <div class="ci"><div class="ci-icon">📍</div><div><div class="ci-l">Ubicació</div><div class="ci-v">Baix Empordà, Girona — Catalunya</div></div></div>
      <div class="ci"><div class="ci-icon">📧</div><div><div class="ci-l">Correu</div><div class="ci-v">sdr@exemple.cat</div></div></div>
      <div class="ci"><div class="ci-icon">📡</div><div><div class="ci-l">Indicatiu amateur</div><div class="ci-v">EA3XXX</div></div></div>
      <div class="ci"><div class="ci-icon">🕐</div><div><div class="ci-l">Resposta</div><div class="ci-v">Normalment en 24–48h lectius</div></div></div>
    </div>
    <div class="cf">
      <?php if($contact_ok):?><div class="form-ok">✓ <?=htmlspecialchars($contact_ok)?></div><?php endif;?>
      <?php if($contact_err):?><div class="form-err">✗ <?=htmlspecialchars($contact_err)?></div><?php endif;?>
      <form method="POST" action="?sec=contacte">
        <input type="hidden" name="action" value="contact">
        <div class="fg2">
          <div class="fg"><label for="cn">Nom *</label><input type="text" id="cn" name="name" placeholder="El teu nom" required value="<?=htmlspecialchars($_POST['name']??'')?>"></div>
          <div class="fg"><label for="ce">Correu *</label><input type="email" id="ce" name="email" placeholder="correu@exemple.com" required value="<?=htmlspecialchars($_POST['email']??'')?>"></div>
        </div>
        <div class="fg"><label for="cs">Assumpte *</label>
          <select id="cs" name="subject" required>
            <option value="">Selecciona un assumpte...</option>
            <?php foreach(['Pregunta tècnica','Proposta de freqüència','Col·laboració','Problema d\'accés','Projecte educatiu','Altre'] as $opt):?>
            <option value="<?=$opt?>" <?=(($_POST['subject']??'')===$opt)?'selected':''?>><?=$opt?></option>
            <?php endforeach;?>
          </select>
        </div>
        <div class="fg"><label for="cm">Missatge *</label><textarea id="cm" name="msg" placeholder="Escriu el teu missatge aquí..." required><?=htmlspecialchars($_POST['msg']??'')?></textarea></div>
        <button type="submit" class="btn-submit">Enviar missatge →</button>
      </form>
    </div>
  </div>
</section>

</main>

<footer>
  <div class="ft-inner">
    <div class="ft-brand">
      <div style="display:flex;align-items:center;gap:10px">
        <div class="logo-pulse" style="width:26px;height:26px"></div>
        <span class="logo-name" style="font-size:.95rem"><?=SITE_NAME?></span>
      </div>
      <p>Sistema de recepció SDR basat en Raspberry Pi per a la comarca del Baix Empordà. Projecte intermodular CFGS SMX.</p>
    </div>
    <div>
      <h4>Seccions</h4>
      <ul>
        <li><a href="?sec=inici">Inici</a></li>
        <li><a href="?sec=escoltar">Escoltar</a></li>
        <li><a href="?sec=qui-som">Qui som</a></li>
        <li><a href="?sec=faq">FAQ</a></li>
        <li><a href="?sec=contacte">Contacte</a></li>
      </ul>
    </div>
    <div>
      <h4>Recursos</h4>
      <ul>
        <li><a href="https://www.rtl-sdr.com" target="_blank" rel="noopener">RTL-SDR.com</a></li>
        <li><a href="https://www.openwebrx.de" target="_blank" rel="noopener">OpenWebRX</a></li>
        <li><a href="https://www.raspberrypi.com" target="_blank" rel="noopener">Raspberry Pi</a></li>
        <li><a href="https://www.gnu.org/software/gnuradio/" target="_blank" rel="noopener">GNU Radio</a></li>
      </ul>
    </div>
  </div>
  <div class="ft-bottom">
    <p>© <?=date('Y')?> <?=SITE_NAME?> — Projecte educatiu CFGS SMX · PHP <?=PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION?></p>
    <p><?=date('D, d M Y H:i T')?> · SDR EN LÍNIA</p>
  </div>
</footer>

</body>
</html>
