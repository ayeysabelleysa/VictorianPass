<?php
declare(strict_types=1);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no" />
  <meta name="color-scheme" content="light dark" />
  <title>VHEcoPoint Station Display</title>
  <link rel="icon" type="image/svg+xml" href="../images/logo.svg" />
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600;700;800;900&display=swap" rel="stylesheet" />
  <style>
    :root{
      color-scheme: dark;
      --bg-0:#071a10;
      --bg-1:#0c2b1b;
      --panel:rgba(255,255,255,.06);
      --panel-2:rgba(255,255,255,.10);
      --stroke:rgba(255,255,255,.14);
      --text:#f8fafc;
      --muted:rgba(248,250,252,.72);
      --text-soft:rgba(248,250,252,.86);
      --glass-bg:rgba(0,0,0,.18);
      --glass-bg-2:rgba(0,0,0,.22);
      --glass-bg-3:rgba(0,0,0,.16);
      --glass-stroke:rgba(255,255,255,.12);
      --modal-panel-bg:rgba(10,22,15,.88);
      --overlay:rgba(0,0,0,.68);
      --green:#22c55e;
      --green-2:#16a34a;
      --gold:#f2c24f;
      --gold-2:#e5b84a;
      --danger:#ef4444;
      --shadow:0 18px 46px rgba(0,0,0,.50);
      --radius:26px;
      --radius-sm:18px;
      --bg-overlay-1:radial-gradient(1100px 700px at 50% -10%, rgba(242,194,79,.22), transparent 55%);
      --bg-overlay-2:radial-gradient(900px 600px at 20% 20%, rgba(34,197,94,.22), transparent 60%);
      --bg-overlay-3:radial-gradient(800px 600px at 80% 70%, rgba(34,197,94,.14), transparent 62%);
    }

    :root[data-theme="dark"]{ color-scheme: dark; }
    :root[data-theme="light"]{
      color-scheme: light;
      --bg-0:#f5f7f6;
      --bg-1:#e8efe9;
      --panel:rgba(255,255,255,.74);
      --panel-2:rgba(255,255,255,.92);
      --stroke:rgba(2, 26, 15, .12);
      --text:#0b1b12;
      --muted:rgba(11,27,18,.62);
      --text-soft:rgba(11,27,18,.78);
      --glass-bg:rgba(255,255,255,.62);
      --glass-bg-2:rgba(255,255,255,.74);
      --glass-bg-3:rgba(255,255,255,.62);
      --glass-stroke:rgba(2, 26, 15, .12);
      --modal-panel-bg:rgba(255,255,255,.92);
      --overlay:rgba(0,0,0,.55);
      --shadow:0 18px 46px rgba(0,0,0,.18);
      --bg-overlay-1:radial-gradient(1100px 700px at 50% -10%, rgba(242,194,79,.30), transparent 58%);
      --bg-overlay-2:radial-gradient(900px 600px at 20% 20%, rgba(34,197,94,.20), transparent 62%);
      --bg-overlay-3:radial-gradient(800px 600px at 80% 70%, rgba(34,197,94,.12), transparent 64%);
    }

    *{
      box-sizing:border-box;
      font-family:'Poppins', system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif;
    }
    html, body{height:100%}
    body{
      margin:0;
      background:
        var(--bg-overlay-1),
        var(--bg-overlay-2),
        var(--bg-overlay-3),
        linear-gradient(180deg, var(--bg-0), var(--bg-1));
      color:var(--text);
      overflow:hidden;
      font-size:20px;
      touch-action:manipulation;
    }

    .station{
      width:100vw;
      height:100vh;
      display:flex;
      flex-direction:column;
      align-items:stretch;
      justify-content:stretch;
      position:relative;
    }

    .topbar{
      display:flex;
      align-items:center;
      justify-content:center;
      padding:calc(18px + env(safe-area-inset-top)) calc(24px + env(safe-area-inset-right)) 0 calc(24px + env(safe-area-inset-left));
      min-height:84px;
      pointer-events:none;
    }

    .brand{
      display:flex;
      align-items:center;
      gap:16px;
      padding:14px 18px;
      border-radius:999px;
      background:var(--glass-bg);
      border:1px solid var(--glass-stroke);
      backdrop-filter:blur(10px);
      box-shadow:0 10px 26px rgba(0,0,0,.32);
    }
    .brand-icon{
      width:46px;
      height:46px;
      border-radius:18px;
      display:grid;
      place-items:center;
      background:linear-gradient(135deg, var(--gold), var(--gold-2));
      color:#062113;
      font-weight:900;
      font-size:28px;
      line-height:1;
      box-shadow:0 12px 26px rgba(0,0,0,.40);
    }
    .brand-title{
      display:flex;
      flex-direction:column;
      gap:2px;
      line-height:1.05;
    }
    .brand-title strong{font-size:24px;letter-spacing:.5px}
    .brand-title span{font-size:14px;color:var(--muted);letter-spacing:.6px}

    .stage{
      position:relative;
      flex:1;
      display:flex;
      align-items:center;
      justify-content:center;
      padding:18px calc(28px + env(safe-area-inset-right)) calc(22px + env(safe-area-inset-bottom)) calc(28px + env(safe-area-inset-left));
    }

    .screen{
      position:absolute;
      inset:0;
      display:flex;
      flex-direction:column;
      align-items:center;
      justify-content:center;
      gap:18px;
      padding:8px 0 18px;
      opacity:0;
      transform:translateY(16px);
      pointer-events:none;
      transition:opacity .35s ease, transform .35s ease;
    }
    .screen.is-active{
      opacity:1;
      transform:translateY(0);
      pointer-events:auto;
    }

    .card{
      width:min(1100px, 94vw);
      background:var(--panel);
      border:1px solid var(--stroke);
      border-radius:var(--radius);
      box-shadow:var(--shadow);
      backdrop-filter:blur(14px);
      padding:clamp(18px, 3.4vw, 34px) clamp(18px, 3.4vw, 34px) clamp(16px, 3vw, 30px);
    }

    .fit-root{
      --fit-scale: 1;
      transform:scale(var(--fit-scale));
      transform-origin:center center;
      width:100%;
      display:flex;
      flex-direction:column;
      align-items:center;
      justify-content:center;
      gap:clamp(12px, 2.2vw, 18px);
      will-change:transform;
    }
    .fit-root > *{width:100%}
    .fit-root .card{width:min(1100px, 94vw)}
    .fit-root .footer-actions{width:min(1100px, 94vw)}

    .station-emblem{
      width:clamp(92px, 18vw, 124px);
      height:clamp(92px, 18vw, 124px);
      border-radius:999px;
      margin:0 auto 16px;
      position:relative;
      display:grid;
      place-items:center;
      background:var(--glass-bg-2);
      border:1px solid var(--glass-stroke);
      box-shadow:0 18px 46px rgba(0,0,0,.46);
      overflow:hidden;
    }
    .station-emblem::before{
      content:"";
      position:absolute;
      inset:-18px;
      border-radius:999px;
      background:conic-gradient(from 90deg, rgba(34,197,94,.00), rgba(34,197,94,.32), rgba(242,194,79,.34), rgba(34,197,94,.00));
      animation:ring 2.8s linear infinite;
      opacity:.9;
      filter:blur(.2px);
    }
    @keyframes ring{
      0%{transform:rotate(0deg)}
      100%{transform:rotate(360deg)}
    }
    .station-emblem .station-icon{
      width:64px;
      height:64px;
      position:relative;
      z-index:1;
      display:grid;
      place-items:center;
      font-size:54px;
      line-height:1;
      color:var(--gold);
      filter:drop-shadow(0 12px 18px rgba(0,0,0,.55));
    }
    .station-emblem .recycle{
      position:absolute;
      right:10px;
      bottom:8px;
      width:38px;
      height:38px;
      border-radius:16px;
      display:grid;
      place-items:center;
      background:linear-gradient(135deg, var(--gold), var(--gold-2));
      color:#062113;
      font-weight:900;
      z-index:2;
      box-shadow:0 14px 28px rgba(0,0,0,.38);
    }

    .title{
      margin:0;
      text-align:center;
      font-size:clamp(34px, 5.8vw, 52px);
      letter-spacing:.6px;
      font-weight:900;
    }
    .subtitle{
      margin:10px 0 0;
      text-align:center;
      font-size:clamp(18px, 3.2vw, 26px);
      font-weight:700;
      color:var(--gold);
      letter-spacing:1px;
      text-transform:uppercase;
    }
    .tagline{
      margin:10px 0 0;
      text-align:center;
      font-size:clamp(16px, 2.8vw, 24px);
      color:var(--text-soft);
    }

    .center{
      display:flex;
      flex-direction:column;
      align-items:center;
      gap:18px;
      margin-top:22px;
    }

    .scan-wrap{
      display:flex;
      flex-direction:column;
      align-items:center;
      gap:14px;
      padding:clamp(16px, 3vw, 26px) clamp(16px, 3vw, 26px) clamp(14px, 2.8vw, 22px);
      border-radius:var(--radius);
      border:1px solid rgba(242,194,79,.25);
      background:linear-gradient(180deg, rgba(242,194,79,.10), rgba(34,197,94,.05));
      box-shadow:0 14px 36px rgba(0,0,0,.40);
    }

    .scan-icon{
      width:clamp(96px, 22vw, 140px);
      height:clamp(96px, 22vw, 140px);
      border-radius:36px;
      display:grid;
      place-items:center;
      position:relative;
      background:var(--glass-bg);
      border:1px solid var(--glass-stroke);
      cursor:pointer;
      user-select:none;
    }
    .scan-icon::before{
      content:"";
      position:absolute;
      inset:-10px;
      border-radius:36px;
      background:radial-gradient(circle at 50% 50%, rgba(242,194,79,.55), transparent 64%);
      filter:blur(0px);
      opacity:.75;
      animation:pulse 1.55s ease-in-out infinite;
      z-index:0;
    }
    .scan-icon svg{
      position:relative;
      z-index:1;
      width:86px;
      height:86px;
      stroke:var(--gold);
      filter:drop-shadow(0 12px 18px rgba(0,0,0,.52));
    }
    @keyframes pulse{
      0%{transform:scale(.94);opacity:.50}
      50%{transform:scale(1.04);opacity:.95}
      100%{transform:scale(.94);opacity:.50}
    }

    .scan-text{
      text-align:center;
      font-size:clamp(16px, 2.6vw, 22px);
      color:var(--text-soft);
      max-width:680px;
      line-height:1.35;
      margin:0;
    }

    .footer-actions{
      width:min(1100px, 94vw);
      display:flex;
      justify-content:center;
      margin-top:0;
    }

    .btn{
      border:none;
      border-radius:18px;
      padding:18px 22px;
      font-size:clamp(18px, 2.6vw, 22px);
      font-weight:800;
      letter-spacing:.4px;
      cursor:pointer;
      min-width:min(520px, 92vw);
      transition:transform .18s ease, box-shadow .18s ease, filter .18s ease;
      user-select:none;
    }
    .btn:active{transform:scale(.99)}
    .btn-primary{
      color:#062113;
      background:linear-gradient(135deg, var(--gold), var(--gold-2));
      box-shadow:0 18px 44px rgba(229,184,74,.22);
    }
    .btn-primary:hover{filter:brightness(1.03)}
    .btn-ghost{
      color:var(--text);
      background:var(--glass-bg-2);
      border:1px solid var(--glass-stroke);
      box-shadow:0 14px 34px rgba(0,0,0,.34);
    }
    .btn-ghost:hover{filter:brightness(1.08)}
    .btn-danger{
      color:#fff;
      background:linear-gradient(135deg, #ef4444, #f97316);
      box-shadow:0 16px 42px rgba(239,68,68,.20);
    }

    .stats{
      width:min(1100px, 94vw);
      display:grid;
      grid-template-columns:repeat(3, minmax(0, 1fr));
      gap:16px;
      margin-top:22px;
    }
    .stat{
      background:var(--panel);
      border:1px solid var(--stroke);
      border-radius:var(--radius-sm);
      padding:18px 18px 16px;
      backdrop-filter:blur(12px);
      box-shadow:0 12px 32px rgba(0,0,0,.38);
      min-height:118px;
      position:relative;
      overflow:hidden;
    }
    .stat::before{
      content:"";
      position:absolute;
      inset:0 0 auto 0;
      height:3px;
      background:linear-gradient(90deg, rgba(242,194,79,.0), rgba(242,194,79,.55), rgba(34,197,94,.45), rgba(242,194,79,.0));
      opacity:.85;
    }
    .stat .k{font-size:14px;letter-spacing:.8px;text-transform:uppercase;color:var(--muted);margin:0}
    .stat .v{font-size:clamp(26px, 4.2vw, 34px);font-weight:900;margin:10px 0 0}
    .stat .v small{font-size:18px;font-weight:800;color:var(--muted)}
    .stat-top{
      display:flex;
      align-items:center;
      gap:10px;
    }
    .stat-ic{
      width:40px;
      height:40px;
      border-radius:16px;
      display:grid;
      place-items:center;
      background:linear-gradient(135deg, var(--gold), var(--gold-2));
      color:#062113;
      font-weight:900;
      box-shadow:0 12px 26px rgba(0,0,0,.26);
      flex-shrink:0;
    }
    .stat-material{
      background:linear-gradient(180deg, rgba(242,194,79,.12), rgba(34,197,94,.06));
      border-color:rgba(242,194,79,.22);
    }
    .stat-material .v{
      font-size:clamp(28px, 4.2vw, 38px);
      color:var(--text);
    }

    .start-wrap{
      margin-top:22px;
      display:flex;
      justify-content:center;
    }
    .btn-start{
      min-width:min(520px, 90vw);
      padding:22px 26px;
      font-size:30px;
      border-radius:22px;
      background:linear-gradient(135deg, rgba(34,197,94,1), rgba(242,194,79,1));
      color:#052013;
      box-shadow:0 0 0 rgba(34,197,94,.0), 0 20px 52px rgba(0,0,0,.45);
      animation:glow 1.35s ease-in-out infinite;
    }
    @keyframes glow{
      0%{transform:translateY(0); box-shadow:0 0 0 rgba(34,197,94,.0), 0 20px 52px rgba(0,0,0,.45)}
      50%{transform:translateY(-1px); box-shadow:0 0 22px rgba(34,197,94,.26), 0 20px 52px rgba(0,0,0,.45)}
      100%{transform:translateY(0); box-shadow:0 0 0 rgba(34,197,94,.0), 0 20px 52px rgba(0,0,0,.45)}
    }

    .session-grid{
      width:min(1100px, 94vw);
      display:grid;
      grid-template-columns:repeat(3, minmax(0, 1fr));
      gap:clamp(12px, 2.2vw, 16px);
      margin-top:clamp(12px, 2vw, 16px);
    }
    .pill{
      display:inline-flex;
      align-items:center;
      gap:10px;
      padding:10px 14px;
      border-radius:999px;
      background:var(--glass-bg-2);
      border:1px solid var(--glass-stroke);
      color:var(--text-soft);
      font-weight:800;
      letter-spacing:.4px;
      font-size:18px;
    }
    .pill strong{color:var(--gold)}

    .active-card{
      display:flex;
      flex-direction:column;
      gap:clamp(14px, 2.3vw, 18px);
    }
    .active-header{
      display:flex;
      align-items:flex-start;
      justify-content:space-between;
      gap:14px;
      flex-wrap:wrap;
    }
    .active-header-left{
      min-width:min(720px, 100%);
    }
    .active-header-left .title{
      text-align:left;
      font-size:clamp(30px, 4.6vw, 44px);
      letter-spacing:.4px;
    }
    .active-header-left .tagline{
      text-align:left;
      margin:8px 0 0;
      max-width:880px;
      color:var(--text-soft);
    }
    .active-badges{
      display:flex;
      align-items:center;
      justify-content:flex-end;
      gap:10px;
      flex-wrap:wrap;
      min-width:min(340px, 100%);
    }
    .badge{
      display:inline-flex;
      align-items:center;
      gap:10px;
      padding:10px 14px;
      border-radius:999px;
      background:var(--glass-bg);
      border:1px solid var(--glass-stroke);
      box-shadow:0 12px 28px rgba(0,0,0,.22);
      font-weight:900;
      font-size:16px;
      letter-spacing:.3px;
      color:var(--text);
      white-space:nowrap;
    }
    .badge strong{color:var(--gold)}
    .badge .dot{
      width:10px;
      height:10px;
      border-radius:999px;
      background:var(--green);
      box-shadow:0 0 0 0 rgba(34,197,94,.0);
      animation:liveDot 1.1s ease-in-out infinite;
      flex-shrink:0;
    }
    @keyframes liveDot{
      0%{box-shadow:0 0 0 0 rgba(34,197,94,.0)}
      50%{box-shadow:0 0 0 10px rgba(34,197,94,.18)}
      100%{box-shadow:0 0 0 0 rgba(34,197,94,.0)}
    }
    .badge-time{
      background:linear-gradient(180deg, rgba(239,68,68,.10), rgba(0,0,0,.00));
      border-color:rgba(239,68,68,.22);
    }

    .row-actions{
      margin-top:clamp(12px, 2vw, 18px);
      display:grid;
      grid-template-columns:repeat(2, minmax(0, 1fr));
      gap:clamp(10px, 2vw, 14px);
      align-items:stretch;
    }
    .row-actions .btn{width:100%}
    .row-actions .btn-primary{box-shadow:0 22px 56px rgba(229,184,74,.22)}

    .check{
      width:110px;
      height:110px;
      border-radius:999px;
      background:rgba(34,197,94,.14);
      border:1px solid rgba(34,197,94,.32);
      display:grid;
      place-items:center;
      margin:0 auto 12px;
      position:relative;
      overflow:hidden;
      box-shadow:0 18px 46px rgba(0,0,0,.42);
    }
    .check::before{
      content:"";
      position:absolute;
      width:140px;
      height:140px;
      border-radius:999px;
      background:radial-gradient(circle at 50% 50%, rgba(34,197,94,.55), transparent 62%);
      animation:checkPulse 1.25s ease-in-out infinite;
      opacity:.75;
    }
    @keyframes checkPulse{
      0%{transform:scale(.92);opacity:.35}
      60%{transform:scale(1.04);opacity:.9}
      100%{transform:scale(.92);opacity:.35}
    }
    .check svg{width:58px;height:58px;stroke:var(--green);position:relative;z-index:1}

    .summary{
      width:min(920px, 92vw);
      margin:16px auto 0;
      display:grid;
      grid-template-columns:repeat(2, minmax(0, 1fr));
      gap:14px;
    }
    .summary .item{
      border-radius:18px;
      border:1px solid var(--glass-stroke);
      background:var(--glass-bg-3);
      padding:14px 16px;
    }
    .summary .item .k{margin:0;color:var(--muted);font-size:14px;text-transform:uppercase;letter-spacing:.8px}
    .summary .item .v{margin:8px 0 0;font-weight:900;font-size:28px}

    .modal{
      position:fixed;
      inset:0;
      display:none;
      align-items:center;
      justify-content:center;
      padding:24px;
      background:var(--overlay);
      z-index:50;
    }
    .modal.is-open{display:flex}
    .modal-panel{
      --fit-scale: 1;
      transform:scale(var(--fit-scale));
      transform-origin:center center;
      width:min(1200px, 96vw);
      max-height:none;
      overflow:hidden;
      border-radius:30px;
      background:var(--modal-panel-bg);
      border:1px solid var(--glass-stroke);
      box-shadow:0 26px 80px rgba(0,0,0,.62);
      backdrop-filter:blur(14px);
      padding:26px 26px 24px;
    }

    .modal-panel-inner{
      width:100%;
      will-change:transform;
    }
    .modal-title{
      margin:0;
      text-align:center;
      font-size:clamp(26px, 4.2vw, 40px);
      font-weight:900;
      letter-spacing:.6px;
    }
    .modal-sub{
      margin:10px auto 0;
      text-align:center;
      max-width:980px;
      color:var(--text-soft);
      line-height:1.55;
      font-size:clamp(16px, 2.6vw, 20px);
    }
    .info-grid{
      margin-top:18px;
      display:grid;
      grid-template-columns:repeat(2, minmax(0, 1fr));
      gap:16px;
    }
    .info-card{
      background:var(--panel);
      border:1px solid var(--stroke);
      border-radius:22px;
      padding:18px 18px 16px;
    }
    .info-card h3{
      margin:0 0 12px;
      font-size:22px;
      letter-spacing:.4px;
    }
    .list{
      margin:0;
      padding:0;
      list-style:none;
      display:flex;
      flex-direction:column;
      gap:10px;
      line-height:1.45;
      color:var(--text-soft);
    }
    .list li{
      display:flex;
      gap:10px;
      align-items:flex-start;
      padding:10px 12px;
      border-radius:18px;
      background:var(--glass-bg-2);
      border:1px solid var(--glass-stroke);
    }
    .list .icon{
      width:38px;
      height:38px;
      display:grid;
      place-items:center;
      border-radius:14px;
      background:linear-gradient(135deg, rgba(242,194,79,.95), rgba(229,184,74,.88));
      color:#062113;
      font-weight:900;
      flex-shrink:0;
      margin-top:1px;
    }
    .list .text strong{display:block;font-weight:900}
    .list .text span{display:block;color:var(--text-soft)}

    .modal-actions{
      display:flex;
      justify-content:center;
      margin-top:18px;
    }
    .modal-actions .btn{min-width:min(520px, 90vw); font-size:26px; padding:20px 24px}

    .modal-nav{
      display:none;
      align-items:center;
      justify-content:space-between;
      gap:10px;
      margin-top:14px;
    }
    .modal-nav .nav-btn{
      border:none;
      border-radius:16px;
      padding:14px 16px;
      font-weight:900;
      font-size:18px;
      cursor:pointer;
      background:var(--glass-bg-2);
      border:1px solid var(--glass-stroke);
      color:var(--text);
      min-width:140px;
    }
    .modal-nav .nav-btn[disabled]{
      opacity:.45;
      cursor:not-allowed;
      filter:saturate(.9);
    }
    .modal-dots{
      display:flex;
      gap:8px;
      align-items:center;
      justify-content:center;
      flex:1;
    }
    .modal-dot{
      width:10px;
      height:10px;
      border-radius:999px;
      background:rgba(242,194,79,.28);
      border:1px solid rgba(242,194,79,.38);
      cursor:pointer;
    }
    .modal-dot.is-active{
      background:var(--gold);
      border-color:rgba(242,194,79,.72);
      box-shadow:0 0 0 8px rgba(242,194,79,.12);
    }

    .mode-toggle{
      position:fixed;
      top:calc(14px + env(safe-area-inset-top));
      right:calc(14px + env(safe-area-inset-right));
      z-index:60;
      display:inline-flex;
      align-items:center;
      gap:10px;
      padding:12px 14px;
      border-radius:999px;
      background:var(--glass-bg);
      border:1px solid var(--glass-stroke);
      box-shadow:0 14px 34px rgba(0,0,0,.30);
      color:var(--text);
      cursor:pointer;
      user-select:none;
      font-weight:900;
      font-size:16px;
      letter-spacing:.4px;
    }
    .mode-toggle:active{transform:scale(.99)}
    .mode-toggle .ic{
      width:36px;
      height:36px;
      border-radius:16px;
      display:grid;
      place-items:center;
      background:linear-gradient(135deg, var(--gold), var(--gold-2));
      color:#062113;
      font-size:18px;
      box-shadow:0 12px 26px rgba(0,0,0,.30);
    }

    @media (max-width: 920px){
      body{font-size:18px}
      .stats{grid-template-columns:1fr}
      .session-grid{grid-template-columns:1fr}
      .summary{grid-template-columns:1fr}
      .info-grid{grid-template-columns:1fr}
      .active-header-left{min-width:100%}
      .active-badges{justify-content:flex-start}
      .row-actions{grid-template-columns:1fr}
    }

    @media (max-width: 520px){
      .topbar{min-height:72px}
      .brand{gap:12px;padding:12px 14px}
      .brand-title strong{font-size:20px}
      .brand-title span{font-size:12px}
      .mode-toggle{font-size:14px}
      .mode-toggle .ic{width:34px;height:34px}
      .row-actions{gap:10px}
      .modal{padding:14px}
      .modal-panel{padding:18px 18px 18px}
    }

    @media (max-height: 720px){
      .topbar{min-height:68px}
      .screen{gap:14px}
      .center{margin-top:14px}
      .stats{margin-top:16px}
    }

    @media (max-width: 640px), (max-height: 720px){
      .modal-panel{padding:18px 18px 18px}
      .modal-title{text-align:left}
      .modal-sub{text-align:left}
      .info-grid{margin-top:14px}
      .modal-nav{display:flex}
      .info-grid.paged .info-card{display:none}
      .info-grid.paged .info-card.is-active{display:block}
      .modal-actions{margin-top:14px}
      .modal-actions .btn{min-width:min(520px, 92vw); font-size:22px; padding:18px 20px}
    }
  </style>
</head>
<body>
  <button class="mode-toggle" id="modeToggle" type="button" aria-label="Toggle dark and light mode">
    <span class="ic" id="modeIcon" aria-hidden="true">☀</span>
    <span id="modeLabel">Light</span>
  </button>
  <div class="station" id="station">
    <div class="topbar" aria-hidden="true">
      <div class="brand">
        <div class="brand-icon">♻</div>
        <div class="brand-title">
          <strong>VHEcoPoint Station</strong>
          <span>Victorian Heights Subdivision</span>
        </div>
      </div>
    </div>

    <main class="stage" role="main">
      <section class="screen is-active" id="screen-idle" aria-label="Idle screen">
        <div class="fit-root">
          <div class="card">
            <div class="station-emblem" aria-hidden="true">
              <div class="station-icon">♻</div>
              <div class="recycle">♻</div>
            </div>
            <h1 class="title">VHEcoPoint</h1>
            <p class="subtitle">Smart Waste Segregation Station</p>
            <p class="tagline">Recycle. Earn. Redeem.</p>

            <div class="center">
              <div class="scan-wrap">
                <div class="scan-icon" id="scanIcon" role="button" tabindex="0" aria-label="Scan QR code">
                  <svg viewBox="0 0 24 24" fill="none" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <path d="M4 7V6a2 2 0 0 1 2-2h1" />
                    <path d="M20 7V6a2 2 0 0 0-2-2h-1" />
                    <path d="M4 17v1a2 2 0 0 0 2 2h1" />
                    <path d="M20 17v1a2 2 0 0 1-2 2h-1" />
                    <rect x="7" y="7" width="4" height="4" rx="1" />
                    <rect x="13" y="7" width="4" height="4" rx="1" />
                    <rect x="7" y="13" width="4" height="4" rx="1" />
                    <path d="M13 13h3v3h-3z" />
                  </svg>
                </div>
                <p class="scan-text">Scan your VictorianPass QR code to begin</p>
              </div>
            </div>
          </div>

          <div class="footer-actions">
            <button class="btn btn-ghost" id="learnBtn" type="button">Learn About VHEcoPoint</button>
          </div>
        </div>
      </section>

      <section class="screen" id="screen-verified" aria-label="Verified screen">
        <div class="fit-root">
          <div class="card">
            <h1 class="title">Welcome, <span id="residentName">Resident</span>!</h1>
            <p class="tagline" style="margin-top:14px;">You’re verified. Press START to activate the station.</p>

            <div class="stats" aria-label="Resident stats">
              <div class="stat">
                <p class="k">Current Point Balance</p>
                <p class="v"><span id="pointBalance">0</span> <small>pts</small></p>
              </div>
              <div class="stat">
                <p class="k">Weekly Points Remaining</p>
                <p class="v"><span id="weeklyRemaining">250</span> <small>pts</small></p>
              </div>
              <div class="stat">
                <p class="k">Daily Sessions Remaining</p>
                <p class="v"><span id="dailyRemaining">3</span> <small>sessions</small></p>
              </div>
            </div>

            <div class="start-wrap">
              <button class="btn btn-start" id="startBtn" type="button">START</button>
            </div>
          </div>

          <div class="footer-actions">
            <button class="btn btn-ghost" id="backToIdle1" type="button">Back to Home</button>
          </div>
        </div>
      </section>

      <section class="screen" id="screen-active" aria-label="Active session screen">
        <div class="fit-root">
          <div class="card active-card">
            <div class="active-header">
              <div class="active-header-left">
                <h1 class="title">Active Session</h1>
                <p class="tagline">Deposit recyclables. The station will detect, weigh, and calculate points.</p>
              </div>
              <div class="active-badges" aria-label="Session status">
                <div class="badge" aria-label="Live status"><span class="dot" aria-hidden="true"></span>LIVE</div>
                <div class="badge" aria-label="Point rate">Rate: <strong id="rateText">55</strong> pts/kg</div>
                <div class="badge badge-time" aria-label="Session timeout">Timeout: <strong><span id="timeout">60</span>s</strong></div>
              </div>
            </div>

            <div class="session-grid" aria-label="Live session values">
              <div class="stat stat-material">
                <div class="stat-top">
                  <div class="stat-ic" aria-hidden="true">♻</div>
                  <p class="k">Material Detected</p>
                </div>
                <p class="v"><span id="materialDetected">Plastic</span></p>
              </div>
              <div class="stat">
                <div class="stat-top">
                  <div class="stat-ic" aria-hidden="true">⚖</div>
                  <p class="k">Real-time Weight</p>
                </div>
                <p class="v"><span id="weightKg">0.00</span> <small>kg</small></p>
              </div>
              <div class="stat">
                <div class="stat-top">
                  <div class="stat-ic" aria-hidden="true">🎁</div>
                  <p class="k">Points (Live)</p>
                </div>
                <p class="v"><span id="pointsLive">0</span> <small>pts</small></p>
              </div>
            </div>

            <div class="row-actions">
              <button class="btn btn-primary" id="doneBtn" type="button">DONE</button>
              <button class="btn btn-ghost" id="cancelBtn" type="button">Back to Home</button>
            </div>
          </div>
        </div>
      </section>

      <section class="screen" id="screen-complete" aria-label="Session complete screen">
        <div class="fit-root">
          <div class="card">
            <div class="check" aria-hidden="true">
              <svg viewBox="0 0 24 24" fill="none" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M20 6L9 17l-5-5" />
              </svg>
            </div>
            <h1 class="title">Session Complete</h1>
            <p class="tagline" style="margin-top:12px;">Thank you for recycling! Points have been credited to your VictorianPass account.</p>

            <div class="summary" aria-label="Session summary">
              <div class="item">
                <p class="k">Material Deposited</p>
                <p class="v" id="sumMaterial">—</p>
              </div>
              <div class="item">
                <p class="k">Total Weight</p>
                <p class="v"><span id="sumWeight">0.00</span> kg</p>
              </div>
              <div class="item">
                <p class="k">Points Earned (This Session)</p>
                <p class="v"><span id="sumPoints">0</span> pts</p>
              </div>
              <div class="item">
                <p class="k">New Total Balance</p>
                <p class="v"><span id="sumBalance">0</span> pts</p>
              </div>
            </div>

            <p class="tagline" style="margin-top:18px;">
              Returning to Home in <strong id="returnCountdown">5</strong> seconds…
            </p>
          </div>

          <div class="footer-actions">
            <button class="btn btn-ghost" id="backToIdle2" type="button">Back to Home</button>
          </div>
        </div>
      </section>
    </main>
  </div>

  <div class="modal" id="infoModal" role="dialog" aria-modal="true" aria-label="VHEcoPoint information">
    <div class="modal-panel">
      <div class="modal-panel-inner" id="modalFit">
        <h2 class="modal-title">Learn About VHEcoPoint</h2>
        <p class="modal-sub">
          VHEcoPoint is Victorian Heights Subdivision's Smart Waste Segregation Station that automatically sorts your recyclables and rewards you with points redeemable for free amenity bookings.
        </p>

        <div class="info-grid" id="infoGrid">
          <div class="info-card">
            <h3>Accepted Materials</h3>
            <ul class="list">
              <li>
                <div class="icon">♻️</div>
                <div class="text">
                  <strong>Plastic</strong>
                  <span>PET Bottles ≤1000ml (must be capped) — 55 pts/kg</span>
                </div>
              </li>
              <li>
                <div class="icon">🥫</div>
                <div class="text">
                  <strong>Aluminum Cans</strong>
                  <span>Small to medium canned goods — 140 pts/kg</span>
                </div>
              </li>
              <li>
                <div class="icon">📄</div>
                <div class="text">
                  <strong>Paper &amp; Cardboard</strong>
                  <span>Old documents, newspapers, small to medium boxes — 30 pts/kg</span>
                </div>
              </li>
            </ul>
          </div>

          <div class="info-card">
            <h3>How It Works</h3>
            <ul class="list">
              <li>
                <div class="icon">🔍</div>
                <div class="text">
                  <strong>Scan your VictorianPass QR code</strong>
                  <span>Your account is verified instantly.</span>
                </div>
              </li>
              <li>
                <div class="icon">♻️</div>
                <div class="text">
                  <strong>Deposit your recyclables into the station</strong>
                  <span>Follow on-screen guidance while sorting.</span>
                </div>
              </li>
              <li>
                <div class="icon">⚖️</div>
                <div class="text">
                  <strong>The station weighs your materials automatically</strong>
                  <span>Weight is measured in kilograms (kg).</span>
                </div>
              </li>
              <li>
                <div class="icon">🎁</div>
                <div class="text">
                  <strong>Points are credited instantly</strong>
                  <span>Points go directly to your VictorianPass account.</span>
                </div>
              </li>
            </ul>
          </div>

          <div class="info-card">
            <h3>Point Redemption</h3>
            <ul class="list">
              <li>
                <div class="icon">🏀</div>
                <div class="text">
                  <strong>300 points = 1 free hour</strong>
                  <span>Basketball Court or Tennis Court</span>
                </div>
              </li>
              <li>
                <div class="icon">🏛️</div>
                <div class="text">
                  <strong>600 points = 1 free hour</strong>
                  <span>Clubhouse</span>
                </div>
              </li>
              <li>
                <div class="icon">🏢</div>
                <div class="text">
                  <strong>750 points = 1 free hour</strong>
                  <span>Multi-Purpose Building</span>
                </div>
              </li>
              <li>
                <div class="icon">₱</div>
                <div class="text">
                  <strong>1 point = ₱0.30</strong>
                  <span>in amenity value</span>
                </div>
              </li>
            </ul>
          </div>

          <div class="info-card">
            <h3>Daily &amp; Weekly Limits</h3>
            <ul class="list">
              <li>
                <div class="icon">📆</div>
                <div class="text">
                  <strong>Maximum 3 deposit sessions per day</strong>
                  <span>Helps maintain fair access for all residents.</span>
                </div>
              </li>
              <li>
                <div class="icon">🧾</div>
                <div class="text">
                  <strong>Maximum 250 points per week</strong>
                  <span>Resets every Monday 12:00 AM</span>
                </div>
              </li>
              <li>
                <div class="icon">💰</div>
                <div class="text">
                  <strong>Maximum balance: 3,000 points</strong>
                  <span>Keep redeeming to make the most of VHEcoPoint.</span>
                </div>
              </li>
            </ul>
          </div>
        </div>

        <div class="modal-nav" id="modalNav" aria-label="VHEcoPoint info pages">
          <button class="nav-btn" id="modalPrev" type="button">Previous</button>
          <div class="modal-dots" id="modalDots" aria-label="Pages"></div>
          <button class="nav-btn" id="modalNext" type="button">Next</button>
        </div>

        <div class="modal-actions">
          <button class="btn btn-primary" id="closeInfoBtn" type="button">CLOSE</button>
        </div>
      </div>
    </div>
  </div>

  <script>
    (function(){
      var root = document.documentElement;
      var STORAGE_KEY = 'ecopointStationTheme';
      var modeToggle = document.getElementById('modeToggle');
      var modeIcon = document.getElementById('modeIcon');
      var modeLabel = document.getElementById('modeLabel');

      function normalizeTheme(t){
        return (t === 'light' || t === 'dark') ? t : '';
      }

      function applyTheme(theme, persist){
        var t = normalizeTheme(theme) || 'dark';
        root.setAttribute('data-theme', t);
        root.style.colorScheme = t;
        if (persist) {
          try { localStorage.setItem(STORAGE_KEY, t); } catch (e) {}
        }
        var nextLabel = (t === 'dark') ? 'Light Mode' : 'Dark Mode';
        var nextIcon = (t === 'dark') ? '☀' : '🌙';
        if (modeLabel) modeLabel.textContent = nextLabel;
        if (modeIcon) modeIcon.textContent = nextIcon;
      }

      function getInitialTheme(){
        var params = new URLSearchParams(window.location.search);
        var fromUrl = normalizeTheme(params.get('theme') || '');
        if (fromUrl) return { theme: fromUrl, persist: true };
        var fromStorage = '';
        try { fromStorage = normalizeTheme(localStorage.getItem(STORAGE_KEY) || ''); } catch (e) {}
        if (fromStorage) return { theme: fromStorage, persist: false };
        var prefersLight = false;
        try { prefersLight = window.matchMedia && window.matchMedia('(prefers-color-scheme: light)').matches; } catch (e) {}
        return { theme: prefersLight ? 'light' : 'dark', persist: false };
      }

      var initial = getInitialTheme();
      applyTheme(initial.theme, initial.persist);

      if (modeToggle) {
        modeToggle.addEventListener('click', function(){
          var current = normalizeTheme(root.getAttribute('data-theme') || '') || 'dark';
          applyTheme(current === 'dark' ? 'light' : 'dark', true);
        });
      }

      var screens = {
        idle: document.getElementById('screen-idle'),
        verified: document.getElementById('screen-verified'),
        active: document.getElementById('screen-active'),
        complete: document.getElementById('screen-complete')
      };

      function raf(fn){
        if (typeof requestAnimationFrame === 'function') return requestAnimationFrame(fn);
        return setTimeout(fn, 0);
      }

      function fitToBox(fitEl, boxEl){
        if(!fitEl || !boxEl) return;
        fitEl.style.setProperty('--fit-scale', '1');
        var box = boxEl.getBoundingClientRect();
        var r = fitEl.getBoundingClientRect();
        var boxW = Math.max(1, box.width - 4);
        var boxH = Math.max(1, box.height - 4);
        var elW = Math.max(1, r.width);
        var elH = Math.max(1, r.height);
        var scale = Math.min(1, boxW / elW, boxH / elH);
        if(!Number.isFinite(scale) || scale <= 0) scale = 1;
        fitEl.style.setProperty('--fit-scale', String(scale));
      }

      function fitActive(){
        var active = document.querySelector('.screen.is-active');
        if(active){
          var fitRoot = active.querySelector('.fit-root');
          if(fitRoot){
            raf(function(){ fitToBox(fitRoot, active); });
          }
        }

        if(infoModal && infoModal.classList.contains('is-open')){
          var modalPanel = infoModal.querySelector('.modal-panel');
          if(modalPanel){
            raf(function(){ fitToBox(modalPanel, infoModal); });
          }
        }
      }

      function showScreen(key){
        Object.keys(screens).forEach(function(k){
          if(!screens[k]) return;
          screens[k].classList.toggle('is-active', k === key);
        });
        fitActive();
      }

      var infoModal = document.getElementById('infoModal');
      var learnBtn = document.getElementById('learnBtn');
      var closeInfoBtn = document.getElementById('closeInfoBtn');
      var lastFocus = null;

      var infoGrid = document.getElementById('infoGrid');
      var modalNav = document.getElementById('modalNav');
      var modalPrev = document.getElementById('modalPrev');
      var modalNext = document.getElementById('modalNext');
      var modalDots = document.getElementById('modalDots');

      var infoCards = [];
      if(infoGrid) infoCards = Array.prototype.slice.call(infoGrid.querySelectorAll('.info-card'));
      var modalPage = 0;
      var modalDotsBtns = [];

      function isModalPaged(){
        try {
          return window.matchMedia && window.matchMedia('(max-width: 640px), (max-height: 720px)').matches;
        } catch (e) {
          return false;
        }
      }

      function buildModalDots(){
        if(!modalDots || !infoCards.length) return;
        if(modalDotsBtns.length) return;
        for(var i = 0; i < infoCards.length; i++){
          var b = document.createElement('button');
          b.type = 'button';
          b.className = 'modal-dot';
          b.setAttribute('aria-label', 'Page ' + (i + 1));
          (function(idx){
            b.addEventListener('click', function(){
              modalPage = idx;
              renderModalPaging();
            });
          })(i);
          modalDots.appendChild(b);
          modalDotsBtns.push(b);
        }
      }

      function clamp(n, min, max){
        return Math.max(min, Math.min(max, n));
      }

      function renderModalPaging(){
        if(!infoGrid || !infoCards.length) return;
        var paged = isModalPaged();
        if(paged){
          buildModalDots();
          infoGrid.classList.add('paged');
          modalPage = clamp(modalPage, 0, infoCards.length - 1);
          for(var i = 0; i < infoCards.length; i++){
            infoCards[i].classList.toggle('is-active', i === modalPage);
          }
          for(var j = 0; j < modalDotsBtns.length; j++){
            modalDotsBtns[j].classList.toggle('is-active', j === modalPage);
          }
          if(modalPrev) modalPrev.disabled = (modalPage === 0);
          if(modalNext) modalNext.disabled = (modalPage === infoCards.length - 1);
        } else {
          infoGrid.classList.remove('paged');
          for(var k = 0; k < infoCards.length; k++){
            infoCards[k].classList.remove('is-active');
          }
        }
        fitActive();
      }

      if(modalPrev){
        modalPrev.addEventListener('click', function(){
          modalPage = clamp(modalPage - 1, 0, Math.max(0, infoCards.length - 1));
          renderModalPaging();
        });
      }
      if(modalNext){
        modalNext.addEventListener('click', function(){
          modalPage = clamp(modalPage + 1, 0, Math.max(0, infoCards.length - 1));
          renderModalPaging();
        });
      }

      function openInfo(){
        if(!infoModal) return;
        lastFocus = document.activeElement;
        infoModal.classList.add('is-open');
        renderModalPaging();
        var first = closeInfoBtn || infoModal;
        if(first && typeof first.focus === 'function') setTimeout(function(){ first.focus(); }, 0);
      }
      function closeInfo(){
        if(!infoModal) return;
        infoModal.classList.remove('is-open');
        if(lastFocus && typeof lastFocus.focus === 'function') lastFocus.focus();
        fitActive();
      }

      if(learnBtn) learnBtn.addEventListener('click', openInfo);
      if(closeInfoBtn) closeInfoBtn.addEventListener('click', closeInfo);
      if(infoModal) infoModal.addEventListener('click', function(e){
        if(e.target === infoModal) closeInfo();
      });
      document.addEventListener('keydown', function(e){
        if(!infoModal) return;
        if(infoModal.classList.contains('is-open') && e.key === 'Escape') closeInfo();
      });

      window.addEventListener('resize', function(){
        renderModalPaging();
        fitActive();
      });

      window.addEventListener('orientationchange', function(){
        setTimeout(function(){
          renderModalPaging();
          fitActive();
        }, 200);
      });

      var resident = {
        id: null,
        name: 'Resident',
        balance: 0,
        weeklyRemaining: 250,
        dailyRemaining: 3
      };

      var residentNameEl = document.getElementById('residentName');
      var pointBalanceEl = document.getElementById('pointBalance');
      var weeklyRemainingEl = document.getElementById('weeklyRemaining');
      var dailyRemainingEl = document.getElementById('dailyRemaining');

      function renderResident(){
        if(residentNameEl) residentNameEl.textContent = resident.name || 'Resident';
        if(pointBalanceEl) pointBalanceEl.textContent = String(resident.balance ?? 0);
        if(weeklyRemainingEl) weeklyRemainingEl.textContent = String(resident.weeklyRemaining ?? 0);
        if(dailyRemainingEl) dailyRemainingEl.textContent = String(resident.dailyRemaining ?? 0);
      }

      function clampInt(n, min, max){
        var v = parseInt(String(n), 10);
        if(Number.isNaN(v)) v = min;
        return Math.max(min, Math.min(max, v));
      }

      function parseParams(){
        var p = new URLSearchParams(window.location.search);
        var name = p.get('name');
        if(name) resident.name = name;
        if(p.has('balance')) resident.balance = clampInt(p.get('balance'), 0, 3000);
        if(p.has('weekly')) resident.weeklyRemaining = clampInt(p.get('weekly'), 0, 250);
        if(p.has('daily')) resident.dailyRemaining = clampInt(p.get('daily'), 0, 3);
        renderResident();
      }
      parseParams();

      var startBtn = document.getElementById('startBtn');
      var backToIdle1 = document.getElementById('backToIdle1');
      var backToIdle2 = document.getElementById('backToIdle2');
      var cancelBtn = document.getElementById('cancelBtn');
      var doneBtn = document.getElementById('doneBtn');

      var scanIcon = document.getElementById('scanIcon');

      var MATERIALS = [
        { key:'Plastic', rate:55, label:'Plastic' },
        { key:'Aluminum', rate:140, label:'Aluminum' },
        { key:'Paper & Cardboard', rate:30, label:'Paper & Cardboard' }
      ];

      var materialDetectedEl = document.getElementById('materialDetected');
      var weightKgEl = document.getElementById('weightKg');
      var pointsLiveEl = document.getElementById('pointsLive');
      var timeoutEl = document.getElementById('timeout');
      var rateTextEl = document.getElementById('rateText');

      var sumMaterialEl = document.getElementById('sumMaterial');
      var sumWeightEl = document.getElementById('sumWeight');
      var sumPointsEl = document.getElementById('sumPoints');
      var sumBalanceEl = document.getElementById('sumBalance');
      var returnCountdownEl = document.getElementById('returnCountdown');

      var session = {
        active:false,
        material:MATERIALS[0],
        weightKg:0,
        points:0,
        secondsLeft:60,
        countdownTimer:null,
        returnTimer:null
      };

      function fmtKg(v){
        return (Math.max(0, v)).toFixed(2);
      }

      function pickMaterial(){
        var idx = Math.floor(Math.random() * MATERIALS.length);
        session.material = MATERIALS[idx];
      }

      function renderSession(){
        if(materialDetectedEl) materialDetectedEl.textContent = session.material.label;
        if(rateTextEl) rateTextEl.textContent = String(session.material.rate);
        if(weightKgEl) weightKgEl.textContent = fmtKg(session.weightKg);
        session.points = Math.max(0, Math.round(session.weightKg * session.material.rate));
        if(pointsLiveEl) pointsLiveEl.textContent = String(session.points);
        if(timeoutEl) timeoutEl.textContent = String(Math.max(0, session.secondsLeft));
      }

      function stopSessionTimers(){
        if(session.countdownTimer) { clearInterval(session.countdownTimer); session.countdownTimer = null; }
      }

      function stopReturnTimer(){
        if(session.returnTimer) { clearInterval(session.returnTimer); session.returnTimer = null; }
      }

      function resetToIdle(){
        stopSessionTimers();
        stopReturnTimer();
        session.active = false;
        session.weightKg = 0;
        session.points = 0;
        session.secondsLeft = 60;
        resident.id = null;
        pickMaterial();
        renderSession();
        showScreen('idle');

        // Notify bridge session ended
        fetch('http://localhost:8080/session/stop', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({})
        }).catch(function(){});
      }

      async function beginSession(){
        stopSessionTimers();
        stopReturnTimer();
        session.active = true;
        session.weightKg = 0;
        session.points = 0;
        session.secondsLeft = 60;
        pickMaterial();
        renderSession();
        showScreen('active');

        // Notify bridge session started
        fetch('http://localhost:8080/session/start', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ resident: resident })
        }).catch(function(){});

        session.countdownTimer = setInterval(function(){
          if(!session.active) return;
          session.secondsLeft -= 1;
          renderSession();
          if(session.secondsLeft <= 0){
            completeSession();
          }
        }, 1000);
      }

      async function completeSession(){
        if(!session.active && screens.complete && screens.complete.classList.contains('is-active')) return;
        session.active = false;
        stopSessionTimers();

        var earned = Math.max(0, session.points);

        // Save session to database
        var newBalance = resident.balance;
        if (resident.id && earned > 0) {
          try {
            var res = await fetch('api/complete_session.php', {
              method: 'POST',
              headers: { 'Content-Type': 'application/json' },
              body: JSON.stringify({
                resident_id: resident.id,
                material: session.material.label,
                weight_kg: session.weightKg,
                points_earned: earned
              })
            });
            var data = await res.json();
            if (data.success) {
              newBalance = data.data.new_balance;
            }
          } catch (e) {
            console.error('Error saving session', e);
            newBalance = Math.min(3000, resident.balance + earned);
          }
        } else {
          newBalance = Math.min(3000, resident.balance + earned);
        }

        resident.balance = newBalance;
        renderResident();

        if(sumMaterialEl) sumMaterialEl.textContent = session.material.label;
        if(sumWeightEl) sumWeightEl.textContent = fmtKg(session.weightKg);
        if(sumPointsEl) sumPointsEl.textContent = String(earned);
        if(sumBalanceEl) sumBalanceEl.textContent = String(newBalance);

        showScreen('complete');

        // Notify bridge session ended
        fetch('http://localhost:8080/session/stop', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({})
        }).catch(function(){});

        var remaining = 5;
        if(returnCountdownEl) returnCountdownEl.textContent = String(remaining);
        stopReturnTimer();
        session.returnTimer = setInterval(function(){
          remaining -= 1;
          if(returnCountdownEl) returnCountdownEl.textContent = String(Math.max(0, remaining));
          if(remaining <= 0){
            resetToIdle();
          }
        }, 1000);
      }

      function setResident(data){
        resident.id = data && data.resident_id ? data.resident_id : null;
        resident.name = String(data && data.name ? data.name : resident.name);
        resident.balance = clampInt(data && data.balance != null ? data.balance : resident.balance, 0, 3000);
        resident.weeklyRemaining = clampInt(data && data.weeklyRemaining != null ? data.weeklyRemaining : resident.weeklyRemaining, 0, 250);
        resident.dailyRemaining = clampInt(data && data.dailyRemaining != null ? data.dailyRemaining : resident.dailyRemaining, 0, 3);
        renderResident();
      }

      async function onScan(data){
        stopReturnTimer();
        stopSessionTimers();

        var residentData = null;
        if (data.qr_code) {
          // Verify resident with backend API
          try {
            var res = await fetch('api/verify_resident.php', {
              method: 'POST',
              headers: { 'Content-Type': 'application/json' },
              body: JSON.stringify({ qr_code: data.qr_code })
            });
            var apiData = await res.json();
            if (apiData.success) {
              residentData = apiData.data;
            } else {
              alert(apiData.message || 'Resident not found');
              return;
            }
          } catch (e) {
            console.error('Error verifying resident', e);
            alert('Error connecting to server. Please try again.');
            return;
          }
        } else {
          residentData = data;
        }

        setResident(residentData || {});
        showScreen('verified');
      }

      function mockScan(){
        onScan({
          name: 'Juan Dela Cruz',
          balance: 420,
          weeklyRemaining: 180,
          dailyRemaining: 2
        });
      }

      function clickScan(){
        if(infoModal && infoModal.classList.contains('is-open')) return;
        mockScan();
      }

      if(scanIcon){
        scanIcon.addEventListener('click', clickScan);
        scanIcon.addEventListener('keydown', function(e){
          if(e.key === 'Enter' || e.key === ' ') {
            e.preventDefault();
            clickScan();
          }
        });
      }

      if(startBtn) startBtn.addEventListener('click', beginSession);
      if(doneBtn) doneBtn.addEventListener('click', completeSession);
      if(cancelBtn) cancelBtn.addEventListener('click', resetToIdle);
      if(backToIdle1) backToIdle1.addEventListener('click', resetToIdle);
      if(backToIdle2) backToIdle2.addEventListener('click', resetToIdle);

      window.EcoPointStation = {
        showIdle: resetToIdle,
        onScan: onScan,
        setResident: setResident,
        setTheme: function(theme){ applyTheme(theme, true); },
        start: beginSession,
        done: completeSession
      };

      window.addEventListener('ecopoint:qr', function(ev){
        if(!ev || !ev.detail) return;
        onScan(ev.detail);
      });

      // --- Hardware Bridge Event Listener ---
      async function pollBridgeEvents() {
        while (true) {
          try {
            var response = await fetch('http://localhost:8080/events');
            var event = await response.json();

            if (event.type === 'qr_scan') {
              onScan(event.data);
            } else if (event.type === 'weight_update' && session.active) {
              session.weightKg = event.data.weight_kg;
              renderSession();
            }
          } catch (e) {
            // Bridge not available, wait and retry
            await new Promise(function(resolve){ setTimeout(resolve, 2000); });
          }
        }
      }
      pollBridgeEvents();

      resetToIdle();
    })();
  </script>
</body>
</html>
