<?php if ( !function_exists( 'er_h' ) ) { exit; } ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>P2P Foundation Wiki — Editor access</title>
<style>
	:root { --ink:#1d2530; --muted:#6b7785; --line:#e2e6ea; --brand:#0a6c74; --brandink:#075058; --bg:#f6f8f9; }
	* { box-sizing:border-box; }
	body { margin:0; background:var(--bg); color:var(--ink);
		font:16px/1.55 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif; }
	.wrap { max-width:560px; margin:7vh auto; padding:0 20px; }
	.card { background:#fff; border:1px solid var(--line); border-radius:14px;
		padding:32px 30px; box-shadow:0 1px 2px rgba(16,24,40,.04),0 8px 28px rgba(16,24,40,.06); }
	h1 { font-size:24px; margin:0 0 14px; letter-spacing:-.01em; }
	p { margin:0 0 14px; }
	label { display:block; font-weight:600; font-size:14px; margin:16px 0 0; }
	input[type=text],input[type=email] { display:block; width:100%; margin-top:6px;
		padding:11px 12px; font-size:16px; border:1px solid #cfd6dd; border-radius:9px;
		background:#fff; color:var(--ink); }
	input:focus { outline:none; border-color:var(--brand); box-shadow:0 0 0 3px rgba(10,108,116,.15); }
	button { margin-top:22px; width:100%; padding:12px 16px; font-size:16px; font-weight:600;
		color:#fff; background:var(--brand); border:0; border-radius:9px; cursor:pointer; }
	button:hover { background:var(--brandink); }
	button.danger { background:#b42318; } button.danger:hover { background:#912018; }
	button.ghost { background:#fff; color:var(--ink); border:1px solid #cfd6dd; }
	button.ghost:hover { background:#f2f4f6; }
	.row { display:flex; gap:12px; }
	.row form { flex:1; margin:0; }
	.muted { color:var(--muted); font-size:13px; }
	.ok { background:#eafaf1; border:1px solid #b7eccd; color:#10623b; padding:14px 16px; border-radius:10px; }
	.err { background:#fdecea; border:1px solid #f5c2bd; color:#8a1c12; padding:10px 14px; border-radius:9px; margin:10px 0; }
	.info { background:#eef4fb; border:1px solid #c7dcf2; color:#1d4e89; padding:14px 16px; border-radius:10px; }
	.kv { width:100%; border-collapse:collapse; margin:8px 0 20px; font-size:14px; }
	.kv th { text-align:left; color:var(--muted); font-weight:600; padding:6px 12px 6px 0; vertical-align:top; white-space:nowrap; }
	.kv td { padding:6px 0; }
	.hp { position:absolute; left:-9999px; height:0; overflow:hidden; }
	.brandbar { font-size:13px; font-weight:700; letter-spacing:.04em; text-transform:uppercase;
		color:var(--brand); margin-bottom:18px; }
	footer { text-align:center; color:var(--muted); font-size:12px; margin-top:20px; }
	a { color:var(--brand); }
</style>
</head>
<body>
<div class="wrap">
	<div class="card">
		<div class="brandbar">P2P Foundation Wiki</div>
