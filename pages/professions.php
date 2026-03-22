<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/core/bootstrap.php';
require_once dirname(__DIR__) . '/core/SessionHelper.php';
require_once dirname(__DIR__) . '/services/ProfessionService.php';

use Game\Helper\SessionHelper;
use Game\Service\ProfessionService;

session_start();
$userId = SessionHelper::requireLoggedIn();

$professionService = new ProfessionService();
$professions = $professionService->getProfessions();
$mainProfession = $professionService->getMainProfession($userId);
$secondaryProfession = $professionService->getSecondaryProfession($userId);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Professions - Cultivation Journey</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gradient-to-br from-gray-900 via-slate-900 to-gray-900 min-h-screen">
    <div class="container mx-auto px-4 py-8 max-w-2xl">
        <div class="flex justify-between items-center mb-8 flex-wrap gap-4">
            <div class="flex items-center gap-4 flex-wrap">
                <?php $site_brand_compact = true; require_once dirname(__DIR__) . '/includes/site_brand.php'; ?>
                <h1 class="text-4xl font-bold bg-gradient-to-r from-amber-400 to-yellow-500 bg-clip-text text-transparent">Professions</h1>
            </div>
            <a href="game.php" class="px-4 py-2 bg-gray-800 hover:bg-gray-700 rounded-lg border border-cyan-500/30 text-cyan-300 transition-all">← Dashboard</a>
        </div>
        <p class="text-gray-400 mb-4">Main = 100% effect. Secondary = 50% effect. One of each.</p>
        <?php if (isset($_GET['msg'])): ?>
            <div class="mb-4 p-3 bg-green-900/30 border border-green-500/50 rounded-lg text-green-300"><?php echo htmlspecialchars($_GET['msg'], ENT_QUOTES, 'UTF-8'); ?></div>
        <?php endif; ?>
        <?php if (isset($_GET['err'])): ?>
            <div class="mb-4 p-3 bg-red-900/30 border border-red-500/50 rounded-lg text-red-300"><?php echo htmlspecialchars($_GET['err'], ENT_QUOTES, 'UTF-8'); ?></div>
        <?php endif; ?>
        <form method="POST" action="../controllers/update_professions.php" class="bg-gray-800/90 backdrop-blur border border-amber-500/30 rounded-xl p-6 space-y-4">
            <div>
                <label class="block text-sm text-gray-400 mb-1">Main profession</label>
                <select name="main_profession_id" required class="w-full bg-gray-900 border border-gray-600 rounded-lg px-3 py-2 text-white">
                    <?php foreach ($professions as $p): ?>
                        <option value="<?php echo (int)$p['id']; ?>" <?php echo ($mainProfession && (int)$mainProfession['profession_id'] === (int)$p['id']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($p['name'] ?? '', ENT_QUOTES, 'UTF-8'); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="block text-sm text-gray-400 mb-1">Secondary profession (optional)</label>
                <select name="secondary_profession_id" class="w-full bg-gray-900 border border-gray-600 rounded-lg px-3 py-2 text-white">
                    <option value="">None</option>
                    <?php $mainId = $mainProfession ? (int)$mainProfession['profession_id'] : 0; foreach ($professions as $p): if ((int)$p['id'] === $mainId) continue; ?>
                        <option value="<?php echo (int)$p['id']; ?>" <?php echo ($secondaryProfession && (int)$secondaryProfession['profession_id'] === (int)$p['id']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($p['name'] ?? '', ENT_QUOTES, 'UTF-8'); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button type="submit" class="px-4 py-2 bg-amber-600 hover:bg-amber-500 text-white font-semibold rounded-lg">Save</button>
        </form>
    </div>
</body>
</html>




