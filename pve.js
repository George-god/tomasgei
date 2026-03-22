(function () {
    'use strict';

    var battlePanel = document.getElementById('battle-panel');
    var battleLog = document.getElementById('battle-log');
    var battleResult = document.getElementById('battle-result');
    var battleChiDisplay = document.getElementById('battle-chi-display');
    var battleChiValue = document.getElementById('battle-chi-value');
    var battleNpcName = document.getElementById('battle-npc-name');
    var battleNpcLabel = document.getElementById('battle-npc-label');
    var battleUserBar = document.getElementById('battle-user-bar');
    var battleNpcBar = document.getElementById('battle-npc-bar');
    var battleUserText = document.getElementById('battle-user-text');
    var battleNpcText = document.getElementById('battle-npc-text');

    function formatNum(n) {
        return Number(n).toLocaleString();
    }

    function setBar(el, current, max) {
        if (!el) return;
        var pct = max > 0 ? Math.min(100, (current / max) * 100) : 0;
        el.style.width = pct + '%';
    }

    function showBattlePanel(npcName) {
        if (battlePanel) battlePanel.classList.remove('hidden');
        if (battleNpcName) battleNpcName.textContent = npcName;
        if (battleNpcLabel) battleNpcLabel.textContent = npcName;
        if (battleLog) battleLog.innerHTML = '';
        if (battleResult) {
            battleResult.classList.add('hidden');
            battleResult.textContent = '';
        }
        if (battleChiDisplay) battleChiDisplay.classList.add('hidden');
    }

    function animateBattle(data) {
        var log = data.battle_log || [];
        var userMax = data.user_max_chi || 1;
        var npcMax = data.npc_hp_max || 1;
        var delay = 400;
        var idx = 0;

        function next() {
            if (idx >= log.length) {
                finishBattle(data);
                return;
            }
            var entry = log[idx];
            var userChi = entry.user_chi;
            var npcHp = entry.npc_hp;
            setBar(battleUserBar, userChi, userMax);
            setBar(battleNpcBar, npcHp, npcMax);
            if (battleUserText) battleUserText.textContent = formatNum(userChi) + ' / ' + formatNum(userMax);
            if (battleNpcText) battleNpcText.textContent = formatNum(npcHp) + ' / ' + formatNum(npcMax);

            var line = document.createElement('div');
            line.className = 'text-gray-300';
            if (entry.attacker === 'user') {
                if (entry.technique_name) {
                    line.innerHTML = 'Turn ' + entry.turn + ': You use <span class="text-cyan-300">' + entry.technique_name + '</span> for <span class="text-red-400">' + formatNum(entry.damage) + '</span> damage.';
                } else {
                    line.innerHTML = 'Turn ' + entry.turn + ': You hit for <span class="text-red-400">' + formatNum(entry.damage) + '</span> damage.';
                }
            } else {
                if (entry.action_type === 'dodge') {
                    line.innerHTML = 'Turn ' + entry.turn + ': You evade ' + (data.npc_name || 'Enemy') + '\'s attack.';
                } else {
                    line.innerHTML = 'Turn ' + entry.turn + ': ' + (data.npc_name || 'Enemy') + ' hits you for <span class="text-red-400">' + formatNum(entry.damage) + '</span> damage.';
                }
            }
            if (battleLog) battleLog.appendChild(line);
            battleLog.scrollTop = battleLog.scrollHeight;
            idx++;
            setTimeout(next, delay);
        }
        next();
    }

    function finishBattle(data) {
        var winner = data.winner;
        var msg = winner === 'user' ? 'Victory! +' + formatNum(data.chi_reward || 0) + ' Chi.' : 'Defeat.';
        if (winner === 'user' && data.dropped_item) {
            var it = data.dropped_item;
            msg += ' Dropped: ' + (it.name || 'Item') + ' (+' + (it.attack_bonus || 0) + ' ATK, +' + (it.defense_bonus || 0) + ' DEF, +' + (it.hp_bonus || 0) + ' HP).';
        }
        if (winner === 'user' && data.herb_dropped) msg += ' Herb: ' + (data.herb_dropped.name || 'Herb') + '.';
        if (winner === 'user' && data.material_dropped) msg += ' Material: ' + (data.material_dropped.name || 'Material') + '.';
        if (winner === 'user' && data.rune_fragment_dropped) msg += ' Rune Fragment.';
        if (battleResult) {
            battleResult.classList.remove('hidden');
            battleResult.textContent = msg;
            battleResult.className = 'text-lg font-semibold ' + (winner === 'user' ? 'text-green-400' : 'text-red-400');
        }
        if (battleChiDisplay && battleChiValue) {
            battleChiDisplay.classList.remove('hidden');
            battleChiValue.textContent = formatNum(data.user_chi_after || 0);
        }
        enableFightButtons();
    }

    function disableFightButtons() {
        var btns = document.querySelectorAll('.pve-fight-btn');
        for (var i = 0; i < btns.length; i++) btns[i].disabled = true;
    }

    function enableFightButtons() {
        var btns = document.querySelectorAll('.pve-fight-btn');
        for (var i = 0; i < btns.length; i++) btns[i].disabled = false;
    }

    var npcList = document.getElementById('npc-list');
    if (npcList) {
        npcList.addEventListener('click', function (e) {
            var btn = e.target.closest('.pve-fight-btn');
            if (!btn || btn.disabled) return;
            var npcId = btn.getAttribute('data-npc-id');
            var npcName = btn.getAttribute('data-npc-name') || 'Enemy';
            var card = btn.parentElement;
            var techniqueToggle = card ? card.querySelector('.pve-technique-toggle') : null;
            if (!npcId) return;

            showBattlePanel(npcName);
            disableFightButtons();
            setBar(battleUserBar, 100, 100);
            setBar(battleNpcBar, 100, 100);
            if (battleUserText) battleUserText.textContent = '— / —';
            if (battleNpcText) battleNpcText.textContent = '— / —';

            var form = new FormData();
            form.append('npc_id', npcId);
            form.append('use_dao_techniques', techniqueToggle && techniqueToggle.checked ? '1' : '0');

            fetch('../controllers/pve_attack.php', {
                method: 'POST',
                body: form
            })
                .then(function (res) { return res.json(); })
                .then(function (data) {
                    if (data.success && data.data) {
                        animateBattle(data.data);
                    } else {
                        if (battleLog) battleLog.innerHTML = '<div class="text-red-400">' + (data.message || 'Battle failed.') + '</div>';
                        enableFightButtons();
                    }
                })
                .catch(function () {
                    if (battleLog) battleLog.innerHTML = '<div class="text-red-400">Request failed.</div>';
                    enableFightButtons();
                });
        });
    }
})();

