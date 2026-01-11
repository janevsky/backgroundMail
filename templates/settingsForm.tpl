{** Minimal settings form for BackgroundMail plugin **}

<div class="pkp_form">
    <h2>Background Mail — Announcement Emails</h2>
    <p>Queue announcement emails for background delivery using scheduled tasks. Use the button below to apply the small
        core compatibility patch required by this plugin.</p>

    {if $patchResult}
        <div class="pkp_form_success">
            <strong>{translate key="plugins.generic.backgroundMail.patchPKPAnnouncementHandler"}</strong><br>
            <pre>{$patchResult|escape}</pre>
        </div>
    {/if}

    <div>
        <button id="bgm-patch-btn" class="pkp_button">Apply core compatibility patch</button>

        <div id="bgm-patch-output" style="margin-top:10px;"></div>
    </div>

    {literal}
        <script>
            (function() {
                const btn = document.getElementById('bgm-patch-btn');
                const out = document.getElementById('bgm-patch-output');
                btn.addEventListener('click', function() {
                    btn.disabled = true;
                    out.textContent = 'Running patch...';
                    const token = '{/literal}{$patchToken}{literal}';
                    const url = '{/literal}{$patchScriptUrl}{literal}' + '?token=' + encodeURIComponent(token);
                    fetch(url, {
                        method: 'GET',
                        credentials: 'same-origin',
                        headers: {
                            'X-Requested-With': 'XMLHttpRequest'
                        }
                    }).then(function(resp) {
                        return resp.text();
                    }).then(function(text) {
                        // Try to parse JSON; if the response is HTML (404 page), show the raw text
                        try {
                            const data = JSON.parse(text);
                            out.textContent = data.message || JSON.stringify(data);
                            if (data && data.status === 'success') {
                                btn.disabled = true;
                                btn.textContent = 'Patched';
                            } else {
                                btn.disabled = false;
                            }
                        } catch (e) {
                            out.textContent = text;
                            btn.disabled = false;
                        }
                    }).catch(function(err) {
                        out.textContent = 'Error: ' + err;
                        btn.disabled = false;
                    });
                });
            })();
        </script>
    {/literal}
</div>