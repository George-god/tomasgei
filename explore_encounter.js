/**
 * Animated PvE encounter playback for world map exploration (same log format as PvEBattleService).
 */
(function (global) {
    'use strict';

    function formatNum(n) {
        return Number(n).toLocaleString();
    }

    function setBar(el, current, max) {
        if (!el) {
            return;
        }
        var pct = max > 0 ? Math.min(100, (current / max) * 100) : 0;
        el.style.width = pct + '%';
    }

    /**
     * @param {string} prefix e.g. 'explore-'
     * @param {object} data encounter payload from ExplorationService
     * @param {function(): void} [onComplete]
     */
    global.playExploreEncounterBattle = function (prefix, data, onComplete) {
        prefix = prefix || 'explore-';
        var battlePanel = document.getElementById(prefix + 'battle-panel');
        var battleLog = document.getElementById(prefix + 'battle-log');
        var battleResult = document.getElementById(prefix + 'battle-result');
        var battleChiDisplay = document.getElementById(prefix + 'battle-chi-display');
        var battleChiValue = document.getElementById(prefix + 'battle-chi-value');
        var battleNpcName = document.getElementById(prefix + 'battle-npc-name');
        var battleNpcLabel = document.getElementById(prefix + 'battle-npc-label');
        var battleUserBar = document.getElementById(prefix + 'battle-user-bar');
        var battleNpcBar = document.getElementById(prefix + 'battle-npc-bar');
        var battleUserText = document.getElementById(prefix + 'battle-user-text');
        var battleNpcText = document.getElementById(prefix + 'battle-npc-text');

        if (battlePanel) {
            battlePanel.classList.remove('hidden');
            battlePanel.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        }
        if (battleNpcName) {
            battleNpcName.textContent = data.npc_name || 'Enemy';
        }
        if (battleNpcLabel) {
            battleNpcLabel.textContent = data.npc_name || 'Enemy';
        }
        if (battleLog) {
            battleLog.innerHTML = '';
        }
        if (battleResult) {
            battleResult.classList.add('hidden');
            battleResult.textContent = '';
        }
        if (battleChiDisplay) {
            battleChiDisplay.classList.add('hidden');
        }

        var log = data.battle_log || [];
        var userMax = data.user_max_chi || 1;
        var npcMax = data.npc_hp_max || 1;
        var delay = 400;
        var idx = 0;

        function finishBattle() {
            var winner = data.winner;
            var msg = winner === 'user' ? 'Victory! +' + formatNum(data.chi_reward || 0) + ' Chi.' : 'Defeat.';
            if (winner === 'user' && (data.gold_gained || 0) > 0) {
                msg += ' Gold +' + formatNum(data.gold_gained);
            }
            if (winner === 'user' && (data.spirit_stone_gained || 0) > 0) {
                msg += ' Spirit stones +' + formatNum(data.spirit_stone_gained);
            }
            if (winner === 'user' && data.dropped_item) {
                var it = data.dropped_item;
                msg += ' Dropped: ' + (it.name || 'Item') + ' (+' + (it.attack_bonus || 0) + ' ATK, +' + (it.defense_bonus || 0) + ' DEF, +' + (it.hp_bonus || 0) + ' HP).';
            }
            if (winner === 'user' && data.herb_dropped) {
                msg += ' Herb: ' + (data.herb_dropped.name || 'Herb') + '.';
            }
            if (winner === 'user' && data.material_dropped) {
                msg += ' Material: ' + (data.material_dropped.name || 'Material') + '.';
            }
            if (winner === 'user' && data.rune_fragment_dropped) {
                msg += ' Rune Fragment.';
            }
            if (battleResult) {
                battleResult.classList.remove('hidden');
                battleResult.textContent = msg;
                battleResult.className = 'text-lg font-semibold ' + (winner === 'user' ? 'text-green-400' : 'text-red-400');
            }
            if (battleChiDisplay && battleChiValue) {
                battleChiDisplay.classList.remove('hidden');
                battleChiValue.textContent = formatNum(data.user_chi_after || 0);
            }
            if (typeof onComplete === 'function') {
                onComplete();
            }
        }

        function next() {
            if (idx >= log.length) {
                finishBattle();
                return;
            }
            var entry = log[idx];
            var userChi = entry.user_chi;
            var npcHp = entry.npc_hp;
            setBar(battleUserBar, userChi, userMax);
            setBar(battleNpcBar, npcHp, npcMax);
            if (battleUserText) {
                battleUserText.textContent = formatNum(userChi) + ' / ' + formatNum(userMax);
            }
            if (battleNpcText) {
                battleNpcText.textContent = formatNum(npcHp) + ' / ' + formatNum(npcMax);
            }

            var line = document.createElement('div');
            line.className = 'text-gray-300';
            if (entry.attacker === 'user') {
                if (entry.technique_name) {
                    line.innerHTML = 'Turn ' + entry.turn + ': You use <span class="text-cyan-300">' + entry.technique_name + '</span> for <span class="text-red-400">' + formatNum(entry.damage) + '</span> damage.';
                } else {
                    line.innerHTML = 'Turn ' + entry.turn + ': You hit for <span class="text-red-400">' + formatNum(entry.damage) + '</span> damage.';
                }
            } else if (entry.action_type === 'dodge') {
                line.innerHTML = 'Turn ' + entry.turn + ': You evade ' + (data.npc_name || 'Enemy') + '\'s attack.';
            } else {
                line.innerHTML = 'Turn ' + entry.turn + ': ' + (data.npc_name || 'Enemy') + ' hits you for <span class="text-red-400">' + formatNum(entry.damage) + '</span> damage.';
            }
            if (battleLog) {
                battleLog.appendChild(line);
                battleLog.scrollTop = battleLog.scrollHeight;
            }
            idx += 1;
            setTimeout(next, delay);
        }

        if (log.length === 0) {
            finishBattle();
        } else {
            next();
        }
    };
})(window);
