<?php
declare(strict_types=1);

// NPC combat is only available through World Map exploration.
header('Location: world_map.php', true, 302);
exit;
