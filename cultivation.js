(function () {
    'use strict';

    var btn = document.getElementById('cultivate-btn');
    var statusEl = document.getElementById('cultivate-status');
    var chiValueEl = document.getElementById('chi-value');
    var chiBarFill = document.getElementById('chi-bar-fill');
    var chiPercentEl = document.getElementById('chi-percent');
    var statAttackEl = document.getElementById('stat-attack');
    var statDefenseEl = document.getElementById('stat-defense');
    var statLevelEl = document.getElementById('stat-level');

    if (!btn) return;

    var initialCooldown = parseInt(btn.getAttribute('data-cooldown-remaining'), 10) || 0;
    if (initialCooldown > 0 && statusEl) {
        statusEl.style.display = 'block';
        statusEl.className = 'mt-3 text-sm text-red-400';
    }

    function setButtonDisabled(disabled, text) {
        btn.disabled = disabled;
        btn.textContent = text || (disabled ? '⏳ Cultivating…' : '⚡ Cultivate Now');
    }

    function showStatus(msg, isError) {
        if (!statusEl) return;
        statusEl.textContent = msg;
        statusEl.className = isError ? 'mt-3 text-sm text-red-400' : 'mt-3 text-sm text-gray-400 text-center';
        statusEl.style.display = msg ? 'block' : 'none';
    }

    function updateChiBar(chi, maxChi) {
        var pct = maxChi > 0 ? Math.min(100, (chi / maxChi) * 100) : 0;
        if (chiValueEl) chiValueEl.textContent = formatNum(chi) + ' / ' + formatNum(maxChi);
        if (chiBarFill) chiBarFill.style.width = pct + '%';
        if (chiPercentEl) chiPercentEl.textContent = pct.toFixed(1) + '%';
    }

    function formatNum(n) {
        return Number(n).toLocaleString();
    }

    function updateStats(data) {
        if (data.attack != null && statAttackEl) statAttackEl.textContent = formatNum(data.attack);
        if (data.defense != null && statDefenseEl) statDefenseEl.textContent = formatNum(data.defense);
        if (data.level != null && statLevelEl) statLevelEl.textContent = data.level;
    }

    function startCooldown(seconds) {
        var remaining = seconds;
        setButtonDisabled(true, '⏳ Cooldown: ' + remaining + 's');
        showStatus('Wait ' + remaining + 's to cultivate again.', true);

        var tick = setInterval(function () {
            remaining--;
            if (remaining <= 0) {
                clearInterval(tick);
                setButtonDisabled(false);
                showStatus('');
                return;
            }
            btn.textContent = '⏳ Cooldown: ' + remaining + 's';
            statusEl.textContent = 'Wait ' + remaining + 's to cultivate again.';
        }, 1000);
    }

    btn.addEventListener('click', function () {
        if (btn.disabled) return;

        setButtonDisabled(true, '⏳ Cultivating…');
        showStatus('');

        fetch('../controllers/cultivate_action.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest' },
            body: 'action=cultivate'
        })
            .then(function (res) {
                return res.text().then(function (text) {
                    try {
                        return { data: JSON.parse(text), ok: res.ok, status: res.status };
                    } catch (e) {
                        var snippet = text ? text.substring(0, 300).replace(/\s+/g, ' ') : '(empty)';
                        throw new Error('Server returned invalid JSON. Check PHP error log. Response: ' + snippet);
                    }
                });
            })
            .then(function (result) {
                var data = result.data;
                if (data.success && data.data) {
                    var d = data.data;
                    updateChiBar(d.chi, d.max_chi);
                    updateStats({ attack: d.attack, defense: d.defense, level: d.level });
                    showStatus(d.level_up ? 'Level up! Level ' + d.new_level + '.' : 'Gained ' + formatNum(d.chi_gained) + ' chi.', false);
                    setButtonDisabled(false);
                    startCooldown(10);
                } else {
                    var cooldown = (data.data && data.data.cooldown_remaining) || 0;
                    if (cooldown > 0) {
                        startCooldown(cooldown);
                    } else {
                        showStatus(data.message || 'Cultivation failed.', true);
                        setButtonDisabled(false);
                    }
                }
            })
            .catch(function (err) {
                showStatus(err && err.message ? err.message : 'Request failed. Try again.', true);
                setButtonDisabled(false);
            });
    });

    if (initialCooldown > 0) {
        startCooldown(initialCooldown);
    }
})();

