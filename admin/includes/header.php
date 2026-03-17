<?php
/**
 * Heavenly Dao Administration Panel - Shared header with navigation.
 * Include after SessionHelper::requireAdmin() has been called.
 */
$currentPage = basename($_SERVER['PHP_SELF'], '.php');
$navItems = [
    'heavenly_observatory' => ['label' => 'Heavenly Observatory', 'icon' => '🏛️'],
    'users' => ['label' => 'Cultivator Registry', 'icon' => '👥'],
    'dao_commands' => ['label' => 'Dao Commands', 'icon' => '⚡'],
    'bug_reports' => ['label' => 'Anomaly Reports', 'icon' => '🔭'],
    'dao_petitions' => ['label' => 'Dao Petitions', 'icon' => '📜'],
    'dao_records' => ['label' => 'Dao Records', 'icon' => '📘'],
    'world_monitor' => ['label' => 'World Monitor', 'icon' => '🌐'],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($pageTitle ?? 'Heavenly Dao Administration', ENT_QUOTES, 'UTF-8'); ?> - Cultivation Journey</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gradient-to-br from-gray-950 via-slate-950 to-indigo-950 min-h-screen">
    <div class="container mx-auto px-4 py-8 max-w-7xl">
        <div class="flex flex-wrap justify-between items-center gap-4 mb-8">
            <div>
                <h1 class="text-4xl font-bold bg-gradient-to-r from-cyan-400 via-violet-400 to-fuchsia-400 bg-clip-text text-transparent">
                    <?php echo htmlspecialchars($pageTitle ?? 'Heavenly Dao Administration', ENT_QUOTES, 'UTF-8'); ?>
                </h1>
                <p class="text-gray-400 text-sm mt-1">The celestial bureaucracy oversees the realm of cultivation.</p>
            </div>
            <div class="flex gap-2">
                <a href="heavenly_observatory.php" class="px-4 py-2 bg-gray-800 hover:bg-gray-700 rounded-lg border border-cyan-500/30 text-cyan-300 transition-all">← Observatory</a>
                <a href="../pages/game.php" class="px-4 py-2 bg-gray-800 hover:bg-gray-700 rounded-lg border border-gray-600 text-gray-300 transition-all">Return to Realm</a>
            </div>
        </div>

        <nav class="flex flex-wrap gap-2 mb-6 p-4 bg-gray-800/50 border border-cyan-500/20 rounded-xl">
            <?php foreach ($navItems as $key => $item): ?>
                <a href="<?php echo htmlspecialchars($key . '.php', ENT_QUOTES, 'UTF-8'); ?>"
                   class="px-4 py-2 rounded-lg border transition-all <?php echo $currentPage === $key ? 'border-cyan-500/50 text-cyan-300 bg-cyan-500/10' : 'border-gray-700 text-gray-300 hover:border-cyan-500/30'; ?>">
                    <span class="mr-1"><?php echo $item['icon']; ?></span>
                    <?php echo htmlspecialchars($item['label'], ENT_QUOTES, 'UTF-8'); ?>
                </a>
            <?php endforeach; ?>
        </nav>

        <?php if ($msg ?? null): ?>
            <div class="mb-4 p-4 bg-green-900/20 border border-green-500/40 rounded-xl text-green-200"><?php echo htmlspecialchars($msg, ENT_QUOTES, 'UTF-8'); ?></div>
        <?php endif; ?>
        <?php if ($err ?? null): ?>
            <div class="mb-4 p-4 bg-red-900/20 border border-red-500/40 rounded-xl text-red-200"><?php echo htmlspecialchars($err, ENT_QUOTES, 'UTF-8'); ?></div>
        <?php endif; ?>
