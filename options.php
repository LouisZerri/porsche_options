<?php
/**
 * PORSCHE OPTIONS MANAGER - Recherche d'options
 */
require_once 'config.php';

$db = getDB();

$search = $_GET['q'] ?? '';
$options = [];
$popularOptions = [];

try {
    if ($search && strlen($search) >= 2) {
        $stmt = $db->prepare("
            SELECT o.*, m.name as model_name, m.code as model_code, f.name as family_name, c.name as category_name
            FROM p_options o
            JOIN p_models m ON o.model_id = m.id
            LEFT JOIN p_families f ON m.family_id = f.id
            LEFT JOIN p_categories c ON o.category_id = c.id
            WHERE o.code LIKE ? OR o.name LIKE ?
            ORDER BY o.code, m.name
            LIMIT 500
        ");
        $stmt->execute(["%$search%", "%$search%"]);
        $options = $stmt->fetchAll();
    }

    // Options populaires (par nombre de modèles)
    $stmt = $db->query("
        SELECT code, name, COUNT(DISTINCT model_id) as model_count, 
               MIN(price) as min_price, MAX(price) as max_price
        FROM p_options
        WHERE price > 0
        GROUP BY code
        HAVING COUNT(DISTINCT model_id) > 3
        ORDER BY model_count DESC
        LIMIT 20
    ");
    $popularOptions = $stmt ? $stmt->fetchAll() : [];
} catch (PDOException $e) {
    // Table n'existe pas encore ou erreur - on continue avec des tableaux vides
    $options = [];
    $popularOptions = [];
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Recherche d'options - Porsche Options Manager</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: { extend: { colors: { 'porsche-red': '#d5001c' } } }
        }
    </script>
</head>
<body class="bg-gray-900 text-white min-h-screen">
    <!-- Header -->
    <header class="bg-black border-b border-gray-800 sticky top-0 z-50">
        <div class="max-w-7xl mx-auto px-4 py-4 flex items-center justify-between">
            <div class="flex items-center gap-4">
                <div class="w-10 h-10 bg-porsche-red rounded-full flex items-center justify-center font-bold text-xl">P</div>
                <div>
                    <h1 class="text-xl font-bold">Porsche Options Manager</h1>
                    <p class="text-gray-400 text-sm">Gestion des options du configurateur</p>
                </div>
            </div>
            <nav class="flex items-center gap-4">
                <a href="index.php" class="text-gray-400 hover:text-white transition">Dashboard</a>
                <a href="models.php" class="text-gray-400 hover:text-white transition">Modèles</a>
                <a href="options.php" class="text-white hover:text-porsche-red transition">Options</a>
                <a href="extraction.php" class="bg-porsche-red hover:bg-red-700 px-4 py-2 rounded-lg transition">
                    🚀 Extraction
                </a>
            </nav>
        </div>
    </header>

    <main class="max-w-7xl mx-auto px-4 py-8">
        <h2 class="text-2xl font-bold mb-6">Rechercher une option</h2>

        <!-- Search Form -->
        <form method="GET" class="mb-8">
            <div class="flex gap-4">
                <input type="text" name="q" value="<?= htmlspecialchars($search) ?>" 
                       placeholder="Code ou nom d'option (ex: PSM, Bose, LED...)"
                       autofocus
                       class="flex-1 bg-gray-800 border border-gray-700 rounded-lg px-4 py-3 text-lg focus:outline-none focus:border-porsche-red">
                <button type="submit" class="bg-porsche-red hover:bg-red-700 px-8 py-3 rounded-lg transition font-medium">
                    🔍 Rechercher
                </button>
            </div>
        </form>

        <?php if ($search): ?>
            <!-- Results -->
            <div class="mb-4 text-gray-400">
                <?= count($options) ?> résultat(s) pour "<?= htmlspecialchars($search) ?>"
            </div>

            <?php if (empty($options)): ?>
                <div class="bg-gray-800 rounded-xl border border-gray-700 p-12 text-center">
                    <p class="text-gray-500 text-lg">Aucune option trouvée</p>
                </div>
            <?php else: ?>
                <div class="bg-gray-800 rounded-xl border border-gray-700 overflow-hidden">
                    <table class="w-full">
                        <thead class="text-left text-xs text-gray-500 uppercase bg-gray-900">
                            <tr>
                                <th class="px-4 py-3">Code</th>
                                <th class="px-4 py-3">Option</th>
                                <th class="px-4 py-3">Modèle</th>
                                <th class="px-4 py-3 text-right">Prix</th>
                                <th class="px-4 py-3 text-center">Série</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($options as $opt): ?>
                            <tr class="border-t border-gray-700/50 hover:bg-gray-700/30">
                                <td class="px-4 py-3">
                                    <span class="font-mono text-sm bg-gray-700 px-2 py-1 rounded">
                                        <?= htmlspecialchars($opt['code']) ?>
                                    </span>
                                </td>
                                <td class="px-4 py-3">
                                    <div><?= htmlspecialchars($opt['name']) ?></div>
                                    <?php if ($opt['category_name']): ?>
                                    <div class="text-xs text-gray-500"><?= htmlspecialchars($opt['category_name']) ?></div>
                                    <?php endif; ?>
                                </td>
                                <td class="px-4 py-3">
                                    <a href="model-detail.php?code=<?= urlencode($opt['model_code']) ?>" 
                                       class="text-blue-400 hover:underline">
                                        <?= htmlspecialchars($opt['model_name']) ?>
                                    </a>
                                    <div class="text-xs text-gray-500"><?= htmlspecialchars($opt['family_name'] ?? '') ?></div>
                                </td>
                                <td class="px-4 py-3 text-right font-medium">
                                    <?= $opt['price'] ? formatPrice($opt['price']) : '-' ?>
                                </td>
                                <td class="px-4 py-3 text-center">
                                    <?php if ($opt['is_standard']): ?>
                                    <span class="text-green-400">✓</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>

        <?php else: ?>
            <!-- Popular Options -->
            <div class="bg-gray-800 rounded-xl border border-gray-700">
                <div class="p-4 border-b border-gray-700">
                    <h3 class="font-semibold">Options populaires (présentes sur 3+ modèles)</h3>
                </div>
                <?php if (empty($popularOptions)): ?>
                    <div class="p-8 text-center text-gray-500">
                        Aucune donnée. Lancez une extraction.
                    </div>
                <?php else: ?>
                    <div class="divide-y divide-gray-700/50">
                        <?php foreach ($popularOptions as $opt): ?>
                        <a href="?q=<?= urlencode($opt['code']) ?>" class="flex items-center justify-between p-4 hover:bg-gray-700/30 transition">
                            <div class="flex items-center gap-4">
                                <span class="font-mono bg-gray-700 px-2 py-1 rounded text-sm"><?= htmlspecialchars($opt['code']) ?></span>
                                <span><?= htmlspecialchars($opt['name']) ?></span>
                            </div>
                            <div class="flex items-center gap-6 text-sm">
                                <span class="text-gray-400"><?= $opt['model_count'] ?> modèles</span>
                                <span class="text-green-400">
                                    <?= formatPrice($opt['min_price']) ?>
                                    <?php if ($opt['min_price'] != $opt['max_price']): ?>
                                        - <?= formatPrice($opt['max_price']) ?>
                                    <?php endif; ?>
                                </span>
                            </div>
                        </a>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </main>
</body>
</html>