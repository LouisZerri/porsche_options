<?php
/**
 * PORSCHE OPTIONS MANAGER - Détail d'un modèle
 */
require_once 'config.php';

$db = getDB();

$code = $_GET['code'] ?? '';
if (!$code) {
    header('Location: models.php');
    exit;
}

$model = null;
$options = [];

try {
    // Récupérer le modèle
    $stmt = $db->prepare("
        SELECT m.*, f.name as family_name
        FROM p_models m
        LEFT JOIN p_families f ON m.family_id = f.id
        WHERE m.code = ?
    ");
    $stmt->execute([$code]);
    $model = $stmt->fetch();
} catch (PDOException $e) {
    // Table n'existe pas
}

if (!$model) {
    header('Location: models.php');
    exit;
}

try {
    // Récupérer les options groupées par catégorie
    $stmt = $db->prepare("
        SELECT o.*, c.name as category_name
        FROM p_options o
        LEFT JOIN p_categories c ON o.category_id = c.id
        WHERE o.model_id = ?
        ORDER BY c.name, o.price DESC
    ");
    $stmt->execute([$model['id']]);
    $options = $stmt->fetchAll();
} catch (PDOException $e) {
    $options = [];
}

// Grouper par catégorie
$byCategory = [];
foreach ($options as $opt) {
    $cat = $opt['category_name'] ?: 'Autre';
    if (!isset($byCategory[$cat])) {
        $byCategory[$cat] = [];
    }
    $byCategory[$cat][] = $opt;
}
ksort($byCategory);

// Stats
$totalValue = array_sum(array_column(array_filter($options, fn($o) => !$o['is_standard']), 'price'));
$standardCount = count(array_filter($options, fn($o) => $o['is_standard']));
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($model['name']) ?> - Porsche Options Manager</title>
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
                <a href="options.php" class="text-gray-400 hover:text-white transition">Options</a>
                <a href="extraction.php" class="bg-porsche-red hover:bg-red-700 px-4 py-2 rounded-lg transition">
                    🚀 Extraction
                </a>
            </nav>
        </div>
    </header>

    <main class="max-w-7xl mx-auto px-4 py-8">
        <!-- Breadcrumb -->
        <div class="flex items-center gap-2 text-sm text-gray-400 mb-6">
            <a href="models.php" class="hover:text-white">Modèles</a>
            <span>/</span>
            <a href="models.php?family=<?= $model['family_id'] ?>" class="hover:text-white">
                <?= htmlspecialchars($model['family_name'] ?? '') ?>
            </a>
            <span>/</span>
            <span class="text-white"><?= htmlspecialchars($model['name']) ?></span>
        </div>

        <!-- Model Header -->
        <div class="bg-gray-800 rounded-xl border border-gray-700 p-6 mb-6">
            <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4">
                <div>
                    <span class="text-xs text-gray-500 font-mono"><?= htmlspecialchars($model['code']) ?></span>
                    <h1 class="text-3xl font-bold"><?= htmlspecialchars($model['name']) ?></h1>
                    <p class="text-gray-400"><?= htmlspecialchars($model['family_name'] ?? '') ?></p>
                </div>
                <div class="text-right">
                    <p class="text-gray-400 text-sm">Prix de base</p>
                    <p class="text-3xl font-bold text-porsche-red"><?= formatPrice($model['base_price']) ?></p>
                </div>
            </div>
        </div>

        <!-- Stats -->
        <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
            <div class="bg-gray-800 rounded-xl p-4 border border-gray-700">
                <p class="text-gray-400 text-sm">Options totales</p>
                <p class="text-2xl font-bold"><?= count($options) ?></p>
            </div>
            <div class="bg-gray-800 rounded-xl p-4 border border-gray-700">
                <p class="text-gray-400 text-sm">De série</p>
                <p class="text-2xl font-bold text-green-400"><?= $standardCount ?></p>
            </div>
            <div class="bg-gray-800 rounded-xl p-4 border border-gray-700">
                <p class="text-gray-400 text-sm">Catégories</p>
                <p class="text-2xl font-bold text-blue-400"><?= count($byCategory) ?></p>
            </div>
            <div class="bg-gray-800 rounded-xl p-4 border border-gray-700">
                <p class="text-gray-400 text-sm">Valeur totale options</p>
                <p class="text-2xl font-bold text-purple-400"><?= formatPrice($totalValue) ?></p>
            </div>
        </div>

        <!-- Search -->
        <div class="mb-6">
            <input type="text" id="searchOptions" placeholder="Rechercher une option..." 
                   class="w-full bg-gray-800 border border-gray-700 rounded-lg px-4 py-3 focus:outline-none focus:border-porsche-red">
        </div>

        <!-- Options par catégorie -->
        <?php foreach ($byCategory as $category => $categoryOptions): ?>
        <div class="bg-gray-800 rounded-xl border border-gray-700 mb-4 option-category" data-category="<?= htmlspecialchars(strtolower($category)) ?>">
            <div class="p-4 border-b border-gray-700 flex items-center justify-between cursor-pointer" 
                 onclick="this.parentElement.classList.toggle('collapsed')">
                <h3 class="font-semibold"><?= htmlspecialchars($category) ?></h3>
                <span class="text-gray-400"><?= count($categoryOptions) ?> options</span>
            </div>
            <div class="category-content">
                <table class="w-full">
                    <thead class="text-left text-xs text-gray-500 uppercase">
                        <tr>
                            <th class="px-4 py-2 w-24">Code</th>
                            <th class="px-4 py-2">Nom</th>
                            <th class="px-4 py-2 w-32 text-right">Prix</th>
                            <th class="px-4 py-2 w-24 text-center">Série</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($categoryOptions as $opt): ?>
                        <tr class="border-t border-gray-700/50 hover:bg-gray-700/30 option-row"
                            data-search="<?= htmlspecialchars(strtolower($opt['code'] . ' ' . $opt['name'])) ?>">
                            <td class="px-4 py-3">
                                <span class="font-mono text-sm bg-gray-700 px-2 py-1 rounded"><?= htmlspecialchars($opt['code']) ?></span>
                            </td>
                            <td class="px-4 py-3"><?= htmlspecialchars($opt['name']) ?></td>
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
        </div>
        <?php endforeach; ?>

        <?php if (empty($options)): ?>
        <div class="bg-gray-800 rounded-xl border border-gray-700 p-12 text-center">
            <p class="text-gray-500 text-lg">Aucune option pour ce modèle</p>
            <a href="extraction.php?model=<?= urlencode($model['code']) ?>" class="inline-block mt-4 bg-porsche-red hover:bg-red-700 px-6 py-3 rounded-lg transition">
                Extraire ce modèle
            </a>
        </div>
        <?php endif; ?>
    </main>

    <script>
        // Recherche dans les options
        document.getElementById('searchOptions').addEventListener('input', function(e) {
            const search = e.target.value.toLowerCase();
            
            document.querySelectorAll('.option-row').forEach(row => {
                const text = row.dataset.search;
                row.style.display = text.includes(search) ? '' : 'none';
            });
            
            // Cacher les catégories vides
            document.querySelectorAll('.option-category').forEach(cat => {
                const visibleRows = cat.querySelectorAll('.option-row[style=""], .option-row:not([style])');
                cat.style.display = visibleRows.length > 0 ? '' : 'none';
            });
        });
    </script>

    <style>
        .collapsed .category-content { display: none; }
    </style>
</body>
</html>