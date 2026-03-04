(function () {
    'use strict';

    function updateStats(stats) {
        if (!stats) return;
        var attackEl = document.getElementById('stat-attack');
        var defenseEl = document.getElementById('stat-defense');
        var maxChiEl = document.getElementById('stat-max-chi');
        if (attackEl) attackEl.textContent = stats.attack;
        if (defenseEl) defenseEl.textContent = stats.defense;
        if (maxChiEl) maxChiEl.textContent = stats.max_chi;
    }

    function onEquipClick(e) {
        var btn = e.target.closest('.equip-btn');
        if (!btn || btn.disabled) return;
        var inventoryId = btn.getAttribute('data-inventory-id');
        if (!inventoryId) return;

        btn.disabled = true;
        var formData = new FormData();
        formData.append('inventory_id', inventoryId);

        fetch('equip_item.php', {
            method: 'POST',
            body: formData,
            credentials: 'same-origin'
        })
            .then(function (res) { return res.json(); })
            .then(function (data) {
                if (data.success && data.data && data.data.stats) {
                    updateStats(data.data.stats);
                    window.location.reload();
                } else {
                    if (data.message) alert(data.message);
                    btn.disabled = false;
                }
            })
            .catch(function () {
                alert('Request failed.');
                btn.disabled = false;
            });
    }

    document.addEventListener('DOMContentLoaded', function () {
        document.body.addEventListener('click', function (e) {
            if (e.target.closest('.equip-btn')) onEquipClick(e);
        });
    });
})();
