# p2pwiki on-wiki JavaScript / CSS

Source of truth for the code that runs in readers' browsers on
wiki.p2pfoundation.net. The wiki does **not** have the Gadgets extension
installed (`load.php?modules=ext.gadget.translate` answers `missing`), so
`MediaWiki:Common.js` / `MediaWiki:Common.css` are the real delivery path.
`MediaWiki:Gadget-translate.js|css` are kept in sync as inert copies for the
day the extension is enabled.

Deploy (Netcup, where the wiki is authoritative):

    scp translate.js translate.css netcup-full:/tmp/
    ssh netcup-full 'docker cp /tmp/translate.js p2pwiki:/tmp/'
    # wrap in the BEGIN/END markers, then:
    ssh netcup-full 'docker exec p2pwiki sh -c "cd /var/www/html && \
      php maintenance/edit.php --user=JeffEmmett --summary=\"...\" \
      \"MediaWiki:Common.js\" < /tmp/common.new.js"'

Verify with `curl -s '.../load.php?modules=site&only=scripts'` — RL caches the
page for a minute or so, it does not need a purge.
