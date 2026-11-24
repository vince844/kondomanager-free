{{--
SPDX-License-Identifier: AGPL-3.0-or-later
Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
--}}
{{--
  Lo stile delle pagine d'errore, dentro la pagina (bozza approvata docs/design/beta14_pagine_errore_grafica_bozza.html): le pagine reggono senza Vite, senza
  la rete e con il database fermo. I colori sono quelli di resources/css/app.css (--muted, --card, --foreground, --muted-foreground, --border, --primary,
  chiari e .dark), la scheda è quella delle pagine d'accesso (AuthCardLayout). Il tema: quello scelto nel programma (`appearance`, letto da `_script`),
  altrimenti quello del sistema. Il carattere Instrument Sans viene dai file compilati, se ci sono. Niente testo sotto i 12 px.
--}}
@php($pesiDelCarattere = \App\Support\Errori\PaginaDiErrore::carattere())
<style>
@foreach ($pesiDelCarattere as $peso => $indirizzo)
@font-face{font-family:"Instrument Sans";font-style:normal;font-weight:{{ $peso }};font-display:swap;src:url("{{ $indirizzo }}") format("woff2")}
@endforeach
:root{color-scheme:light;--e-bg:#f5f5f5;--e-card:#fff;--e-fg:#0a0a0a;--e-mut:#737373;--e-b:#e8e8e8;--e-soft:#fafafa;--e-pri:#171717;--e-onpri:#fafafa;--e-in:#e5e5e5;
--ro-bg:#fff1f2;--ro-b:#fecdd3;--ro-t:#be123c;--am-bg:#fffbeb;--am-b:#fde68a;--am-t:#92400e;--sk-bg:#f0f9ff;--sk-b:#bae6fd;--sk-t:#075985;
--em-bg:#ecfdf5;--em-b:#a7f3d0;--em-t:#065f46}
@media (prefers-color-scheme:dark){html:not(.chiaro){color-scheme:dark;--e-bg:#292929;--e-card:#0a0a0a;--e-fg:#fafafa;--e-mut:#a3a3a3;--e-b:#262626;--e-soft:#141414;--e-pri:#fafafa;--e-onpri:#171717;--e-in:#262626;
--ro-bg:#4c051933;--ro-b:#881337;--ro-t:#fda4af;--am-bg:#451a0333;--am-b:#78350f;--am-t:#fcd34d;--sk-bg:#082f4955;--sk-b:#075985;--sk-t:#7dd3fc;
--em-bg:#022c2233;--em-b:#065f46;--em-t:#6ee7b7}}
html.scuro{color-scheme:dark;--e-bg:#292929;--e-card:#0a0a0a;--e-fg:#fafafa;--e-mut:#a3a3a3;--e-b:#262626;--e-soft:#141414;--e-pri:#fafafa;--e-onpri:#171717;--e-in:#262626;
--ro-bg:#4c051933;--ro-b:#881337;--ro-t:#fda4af;--am-bg:#451a0333;--am-b:#78350f;--am-t:#fcd34d;--sk-bg:#082f4955;--sk-b:#075985;--sk-t:#7dd3fc;
--em-bg:#022c2233;--em-b:#065f46;--em-t:#6ee7b7}
*{box-sizing:border-box}
html,body{margin:0;padding:0}
body{background:var(--e-bg);color:var(--e-fg);font:14px/1.5 "Instrument Sans",ui-sans-serif,system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;-webkit-font-smoothing:antialiased}
svg.i{width:16px;height:16px;fill:none;stroke:currentColor;stroke-width:2;stroke-linecap:round;stroke-linejoin:round;flex-shrink:0}
svg.i.s{width:14px;height:14px}svg.i.l{width:22px;height:22px}
.ep{min-height:100vh;display:flex;flex-direction:column;align-items:center;justify-content:center;padding:48px 24px}
.ep-logo{width:32px;height:32px;border-radius:6px;background:var(--e-pri);color:var(--e-onpri);display:flex;align-items:center;justify-content:center;font:700 14px/1 Arial,Helvetica,sans-serif;margin:0 0 20px;flex-shrink:0;text-decoration:none}
.ep-card{width:100%;max-width:448px;background:var(--e-card);border:1px solid var(--e-b);border-radius:12px;box-shadow:0 1px 3px rgba(0,0,0,.05),0 12px 32px -20px rgba(0,0,0,.25);padding:32px 32px 28px}
.ep-card.ep-larga{max-width:560px}
.ep-top{display:flex;align-items:center;justify-content:space-between;gap:12px;margin:0 0 18px}
.ep-ico{width:44px;height:44px;border-radius:12px;display:flex;align-items:center;justify-content:center;border:1px solid}
.ep-ico.ro{background:var(--ro-bg);border-color:var(--ro-b);color:var(--ro-t)}.ep-ico.am{background:var(--am-bg);border-color:var(--am-b);color:var(--am-t)}
.ep-ico.sk{background:var(--sk-bg);border-color:var(--sk-b);color:var(--sk-t)}.ep-ico.sl{background:var(--e-soft);border-color:var(--e-b);color:var(--e-mut)}
.ep-num{display:inline-flex;align-items:center;height:22px;padding:0 8px;border-radius:6px;border:1px solid var(--e-b);background:var(--e-soft);color:var(--e-mut);font-size:12px;font-weight:500;font-variant-numeric:tabular-nums}
.ep h1{font-size:20px;line-height:1.25;font-weight:600;letter-spacing:-.01em;margin:0 0 6px;color:var(--e-fg)}
.ep .lead{font-size:14px;line-height:1.55;color:var(--e-mut);margin:0}
.ep .lead b{color:var(--e-fg);font-weight:500}
.ep-codice{margin-top:20px;border:1px solid var(--e-b);border-radius:10px;padding:12px 14px;background:var(--e-soft)}
.ep-codice .lab{font-size:13px;color:var(--e-mut);margin:0}
.ep-codice .riga{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-top:4px}
.ep-codice .cod{font-size:26px;line-height:1.2;font-weight:700;letter-spacing:.14em;font-variant-numeric:tabular-nums;color:var(--e-fg)}
.ep-codice .quando{font-size:12px;color:var(--e-mut);margin:2px 0 0}
.eb{display:inline-flex;align-items:center;justify-content:center;gap:8px;height:36px;padding:0 14px;border-radius:6px;border:1px solid var(--e-b);background:var(--e-card);color:var(--e-fg);font:inherit;font-size:14px;font-weight:500;white-space:nowrap;cursor:pointer;text-decoration:none;box-shadow:0 1px 2px rgba(0,0,0,.04)}
.eb:hover{background:var(--e-soft)}
.eb.pri{background:var(--e-pri);color:var(--e-onpri);border-color:var(--e-pri)}
.eb.pri:hover{opacity:.9}
.eb.sm{height:30px;font-size:13px;padding:0 10px}
.eb.full{width:100%}
.eb[disabled]{opacity:.55;cursor:default}
.eb.fatto{color:var(--em-t);border-color:var(--em-b);background:var(--em-bg)}
.eb:focus-visible{outline:2px solid var(--e-fg);outline-offset:2px}
.ep-azioni{display:flex;gap:8px;margin-top:20px}
.ep-azioni .eb{flex:1}
.ep-sep{height:1px;background:var(--e-b);margin:20px 0 0}
.ep-aiuto{font-size:13px;color:var(--e-mut);margin:12px 0 0}
.ep-avviso{margin:16px 0 0}
.ep-avviso .tt{display:flex;align-items:center;gap:8px;font-size:14px;font-weight:600;margin:0}
.ep-avviso .tt svg{color:var(--e-mut)}
.ep-avviso .sot{font-size:13px;color:var(--e-mut);margin:2px 0 12px}
.ep-campo label{display:block;font-size:13px;font-weight:500;margin:0 0 6px}
.ep-campo label span{font-weight:400;color:var(--e-mut)}
.ep-ta{width:100%;min-height:64px;border:1px solid var(--e-in);border-radius:6px;padding:8px 10px;font:inherit;font-size:14px;color:var(--e-fg);background:transparent;line-height:1.45;resize:vertical}
.ep-ta::placeholder{color:var(--e-mut)}
.ep-ta:focus{outline:2px solid var(--e-fg);outline-offset:0}
.ep-campo .sotto{display:flex;justify-content:space-between;gap:8px;font-size:12px;color:var(--e-mut);margin:4px 0 12px}
.ep-esito{display:flex;gap:10px;align-items:flex-start;margin-top:16px;border:1px solid var(--em-b);background:var(--em-bg);color:var(--em-t);border-radius:10px;padding:12px 14px;font-size:13px}
.ep-esito b{display:block;font-size:14px;font-weight:600}
.ep-esito.am{border-color:var(--am-b);background:var(--am-bg);color:var(--am-t)}
.ep-nota{display:flex;gap:10px;align-items:flex-start;margin-top:16px;border:1px solid var(--e-b);background:var(--e-soft);border-radius:10px;padding:12px 14px;font-size:13px;color:var(--e-mut)}
.ep-nota b{color:var(--e-fg);font-weight:600}
.ep-nota.sk{border-color:var(--sk-b);background:var(--sk-bg);color:var(--sk-t)}.ep-nota.sk b{color:inherit}
.ep-nota svg{margin-top:1px}
.ep-tec{margin-top:20px;border:1px solid var(--am-b);background:var(--am-bg);border-radius:10px;padding:12px 14px}
.ep-tec .hd{display:flex;align-items:center;gap:8px;font-size:13px;font-weight:600;color:var(--am-t);margin:0 0 8px}
.ep-tec .msg{font-size:13px;line-height:1.5;color:var(--e-fg);word-break:break-word;margin:0;background:var(--e-card);border:1px solid var(--e-b);border-radius:8px;padding:8px 10px}
.ep-tec dl{display:grid;grid-template-columns:auto minmax(0,1fr);gap:4px 12px;margin:10px 0 0;font-size:12px}
.ep-tec dt{color:var(--e-mut)}.ep-tec dd{margin:0;color:var(--e-fg);overflow-wrap:anywhere}
.ep-tec .btns{display:flex;gap:8px;flex-wrap:wrap;margin-top:12px}
.ep-tec details{margin-top:10px}
.ep-tec summary{cursor:pointer;font-size:13px;color:var(--am-t);font-weight:500}
.ep-tec .traccia{margin:8px 0 0;font-size:12px;line-height:1.6;color:var(--e-mut);white-space:pre-wrap;overflow-wrap:anywhere}
.spin{width:16px;height:16px;border-radius:50%;border:2px solid var(--e-b);border-top-color:var(--e-mut);display:inline-block;flex-shrink:0;animation:gira 1s linear infinite}
@keyframes gira{to{transform:rotate(360deg)}}
[hidden]{display:none!important}
@media (max-width:520px){
.ep{justify-content:flex-start;padding:24px 16px 32px}
.ep-card,.ep-card.ep-larga{padding:24px 20px 22px}
.ep-azioni{flex-direction:column-reverse}
.ep-azioni .eb{flex:none;width:100%}
.ep-tec .btns{flex-direction:column}
.ep-tec .btns .eb{width:100%}
}
</style>
