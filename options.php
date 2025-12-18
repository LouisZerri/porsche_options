<?php
/**
 * PORSCHE OPTIONS MANAGER v5.7 - Recherche d'options
 * Avec filtres par type et catégorie
 */
require_once 'config.php';

$db = getDB();

$search = $_GET['q'] ?? '';
$typeFilter = $_GET['type'] ?? '';
$categoryFilter = $_GET['cat'] ?? '';

$options = [];
$categories = [];
$stats = [];

try {
    // Récupérer les catégories disponibles
    $categories = $db->query("
        SELECT DISTINCT c.parent_name, c.name, COUNT(*) as count
        FROM p_categories c
        JOIN p_options o ON o.category_id = c.id
        GROUP BY c.id
        ORDER BY c.parent_name, c.name
    ")->fetchAll();
    
    // Stats par type
    $stats = $db->query("
        SELECT option_type, COUNT(*) as count
        FROM p_options
        GROUP BY option_type
    ")->fetchAll();
    
    // Recherche
    if ($search && strlen($search) >= 2) {
        $sql = "
            SELECT o.*, m.name as model_name, m.code as model_code, f.name as family_name, 
                   c.name as category_name, c.parent_name as parent_category
            FROM p_options o
            JOIN p_models m ON o.model_id = m.id
            LEFT JOIN p_families f ON m.family_id = f.id
            LEFT JOIN p_categories c ON o.category_id = c.id
            WHERE (o.code LIKE ? OR o.name LIKE ?)
        ";
        $params = ["%$search%", "%$search%"];
        
        if ($typeFilter) {
            $sql .= " AND o.option_type = ?";
            $params[] = $typeFilter;
        }
        
        $sql .= " ORDER BY o.option_type, c.parent_name, c.name, o.code LIMIT 500";
        
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $options = $stmt->fetchAll();
    } elseif ($typeFilter || $categoryFilter) {
        // Filtrer par type ou catégorie
        $sql = "
            SELECT o.*, m.name as model_name, m.code as model_code, f.name as family_name, 
                   c.name as category_name, c.parent_name as parent_category
            FROM p_options o
            JOIN p_models m ON o.model_id = m.id
            LEFT JOIN p_families f ON m.family_id = f.id
            LEFT JOIN p_categories c ON o.category_id = c.id
            WHERE 1=1
        ";
        $params = [];
        
        if ($typeFilter) {
            $sql .= " AND o.option_type = ?";
            $params[] = $typeFilter;
        }
        if ($categoryFilter) {
            $sql .= " AND c.name = ?";
            $params[] = $categoryFilter;
        }
        
        $sql .= " ORDER BY c.parent_name, c.name, o.code LIMIT 500";
        
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $options = $stmt->fetchAll();
    }
} catch (PDOException $e) {
    $options = [];
    $categories = [];
    $stats = [];
}

// Grouper les options par catégorie pour l'affichage
$optionsByCategory = [];
foreach ($options as $opt) {
    $cat = $opt['parent_category'] ? $opt['parent_category'] . ' > ' . $opt['category_name'] : ($opt['category_name'] ?: 'Autre');
    if (!isset($optionsByCategory[$cat])) {
        $optionsByCategory[$cat] = [];
    }
    $optionsByCategory[$cat][] = $opt;
}
ksort($optionsByCategory);

// Labels types
$typeLabels = [
    'color_ext' => '🎨 Couleurs Ext.',
    'color_int' => '🛋️ Couleurs Int.',
    'wheel' => '🛞 Jantes',
    'seat' => '💺 Sièges',
    'pack' => '📦 Packs',
    'option' => '⚙️ Options'
];
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Options - Porsche Options Manager</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: { extend: { colors: { 'porsche-red': '#d5001c' } } }
        }
    </script>
</head>
<body class="bg-gray-900 text-white min-h-screen">
    <header class="bg-black border-b border-gray-800 sticky top-0 z-50">
        <div class="max-w-7xl mx-auto px-4 py-4 flex items-center justify-between">
            <div class="flex items-center gap-4">
                <div class="w-10 h-10 bg-porsche-red rounded-full flex items-center justify-center font-bold text-xl">P</div>
                <div>
                    <h1 class="text-xl font-bold">Porsche Options Manager</h1>
                    <p class="text-gray-400 text-sm">v5.7 - Couleurs & Catégories</p>
                </div>
            </div>
            <nav class="flex items-center gap-4">
                <a href="index.php" class="text-gray-400 hover:text-white transition">Dashboard</a>
                <a href="models.php" class="text-gray-400 hover:text-white transition">Modèles</a>
                <a href="options.php" class="text-white font-bold">Options</a>
                <a href="extraction.php" class="bg-porsche-red hover:bg-red-700 px-4 py-2 rounded-lg transition">🚀 Extraction</a>
            </nav>
        </div>
    </header>

    <main class="max-w-7xl mx-auto px-4 py-8">
        <h2 class="text-2xl font-bold mb-6">Options & Couleurs</h2>

        <!-- Filtres par type -->
        <?php if (!empty($stats)): ?>
        <div class="flex flex-wrap gap-2 mb-6">
            <a href="options.php" class="px-4 py-2 rounded-lg <?= !$typeFilter ? 'bg-porsche-red' : 'bg-gray-700 hover:bg-gray-600' ?> transition">
                Tous
            </a>
            <?php foreach ($stats as $s): ?>
            <a href="?type=<?= urlencode($s['option_type']) ?>" 
               class="px-4 py-2 rounded-lg <?= $typeFilter === $s['option_type'] ? 'bg-porsche-red' : 'bg-gray-700 hover:bg-gray-600' ?> transition">
                <?= $typeLabels[$s['option_type']] ?? $s['option_type'] ?> 
                <span class="text-gray-400">(<?= $s['count'] ?>)</span>
            </a>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <!-- Recherche -->
        <form method="GET" class="mb-6">
            <div class="flex gap-4">
                <?php if ($typeFilter): ?>
                <input type="hidden" name="type" value="<?= htmlspecialchars($typeFilter) ?>">
                <?php endif; ?>
                <input type="text" name="q" value="<?= htmlspecialchars($search) ?>" 
                       placeholder="Code ou nom (ex: PSM, Bose, Blanc...)"
                       class="flex-1 bg-gray-800 border border-gray-700 rounded-lg px-4 py-3 focus:outline-none focus:border-porsche-red">
                <button type="submit" class="bg-porsche-red hover:bg-red-700 px-6 py-3 rounded-lg transition">
                    🔍 Rechercher
                </button>
            </div>
        </form>

        <?php if (!empty($options)): ?>
            <p class="text-gray-400 mb-4"><?= count($options) ?> résultat(s)</p>
            
            <!-- Résultats groupés par catégorie -->
            <?php foreach ($optionsByCategory as $category => $categoryOptions): ?>
            <div class="bg-gray-800 rounded-xl border border-gray-700 mb-4">
                <div class="p-4 border-b border-gray-700 bg-gray-900/50">
                    <h3 class="font-semibold"><?= htmlspecialchars($category) ?></h3>
                    <span class="text-gray-400 text-sm"><?= count($categoryOptions) ?> éléments</span>
                </div>
                <table class="w-full">
                    <thead class="text-left text-xs text-gray-500 uppercase">
                        <tr>
                            <th class="px-4 py-2 w-20">Type</th>
                            <th class="px-4 py-2 w-24">Code</th>
                            <th class="px-4 py-2">Nom</th>
                            <th class="px-4 py-2">Modèle</th>
                            <th class="px-4 py-2 w-28 text-right">Prix</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($categoryOptions as $opt): ?>
                        <tr class="border-t border-gray-700/50 hover:bg-gray-700/30">
                            <td class="px-4 py-2">
                                <?php
                                $icons = ['color_ext' => '🎨', 'color_int' => '🛋️', 'wheel' => '🛞', 'seat' => '💺', 'pack' => '📦', 'option' => '⚙️'];
                                echo $icons[$opt['option_type']] ?? '⚙️';
                                ?>
                            </td>
                            <td class="px-4 py-2">
                                <span class="font-mono text-sm bg-gray-700 px-2 py-1 rounded"><?= htmlspecialchars($opt['code']) ?></span>
                            </td>
                            <td class="px-4 py-2"><?= htmlspecialchars($opt['name']) ?></td>
                            <td class="px-4 py-2">
                                <a href="model-detail.php?code=<?= urlencode($opt['model_code']) ?>" class="text-blue-400 hover:underline">
                                    <?= htmlspecialchars($opt['model_name']) ?>
                                </a>
                            </td>
                            <td class="px-4 py-2 text-right">
                                <?php if ($opt['is_standard']): ?>
                                <span class="text-green-400">✓ Série</span>
                                <?php else: ?>
                                <?= $opt['price'] ? formatPrice($opt['price']) : '-' ?>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endforeach; ?>

        <?php elseif ($search || $typeFilter): ?>
            <div class="bg-gray-800 rounded-xl border border-gray-700 p-12 text-center">
                <p class="text-gray-500 text-lg">Aucun résultat</p>
            </div>

        <?php else: ?>
            <!-- Catégories disponibles -->
            <div class="bg-gray-800 rounded-xl border border-gray-700">
                <div class="p-4 border-b border-gray-700">
                    <h3 class="font-semibold">Catégories disponibles</h3>
                </div>
                <?php if (empty($categories)): ?>
                <div class="p-8 text-center text-gray-500">
                    Aucune donnée. Lancez une extraction.
                </div>
                <?php else: ?>
                <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-2 p-4">
                    <?php 
                    $currentParent = null;
                    foreach ($categories as $cat): 
                        if ($cat['parent_name'] !== $currentParent):
                            $currentParent = $cat['parent_name'];
                    ?>
                    <div class="col-span-full text-gray-500 text-sm mt-4 first:mt-0 border-b border-gray-700 pb-2">
                        <?= htmlspecialchars($currentParent ?: 'Autre') ?>
                    </div>
                    <?php endif; ?>
                    <a href="?cat=<?= urlencode($cat['name']) ?>" 
                       class="bg-gray-700/50 hover:bg-gray-600 rounded-lg p-3 transition">
                        <div class="font-medium"><?= htmlspecialchars($cat['name']) ?></div>
                        <div class="text-gray-400 text-sm"><?= $cat['count'] ?> options</div>
                    </a>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </main>
</body>
</html>