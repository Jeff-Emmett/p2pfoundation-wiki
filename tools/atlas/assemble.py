import os
SP = os.path.dirname(os.path.abspath(__file__))
head = open(f"{SP}/atlas.head.html", encoding="utf-8").read()
body = open(f"{SP}/atlas.body.html", encoding="utf-8").read()
script = open(f"{SP}/atlas.script.js", encoding="utf-8").read()
payload = open(f"{SP}/payload.json", encoding="utf-8").read()
out = (head + "\n" + body
       + '\n<script id="atlas-data" type="application/json">'
       + payload.replace("</", "<\\/") + "</script>\n"
       + "<script>\nwindow.__ATLAS__ = JSON.parse(document.getElementById('atlas-data').textContent);\n</script>\n"
       + "<script>\n" + script + "\n</script>\n")
p = f"{SP}/p2p-wiki-atlas.html"
open(p, "w", encoding="utf-8").write(out)
print("MB", round(os.path.getsize(p) / 1048576, 2))
