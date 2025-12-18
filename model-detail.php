<?php
/**
 * PORSCHE OPTIONS MANAGER v5.7 - Détail d'un modèle
 * Affiche couleurs extérieures, intérieures et options par catégorie
 */
require_once 'config.php';

$db = getDB();

$code = $_GET['code'] ?? '';
if (!$code) {
    header('Location: models.php');
    exit;
}

$model = null;
$extColors = [];
$intColors = [];
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
    // Couleurs extérieures
    $stmt = $db->prepare("
        SELECT o.*, c.name as category_name, c.parent_name
        FROM p_options o
        LEFT JOIN p_categories c ON o.category_id = c.id
        WHERE o.model_id = ? AND o.option_type = 'color_ext'
        ORDER BY o.price ASC
    ");
    $stmt->execute([$model['id']]);
    $extColors = $stmt->fetchAll();
    
    // Couleurs intérieures
    $stmt = $db->prepare("
        SELECT o.*, c.name as category_name, c.parent_name
        FROM p_options o
        LEFT JOIN p_categories c ON o.category_id = c.id
        WHERE o.model_id = ? AND o.option_type = 'color_int'
        ORDER BY o.price ASC
    ");
    $stmt->execute([$model['id']]);
    $intColors = $stmt->fetchAll();
    
    // Autres options
    $stmt = $db->prepare("
        SELECT o.*, c.name as category_name, c.parent_name
        FROM p_options o
        LEFT JOIN p_categories c ON o.category_id = c.id
        WHERE o.model_id = ? AND o.option_type NOT IN ('color_ext', 'color_int')
        ORDER BY c.parent_name, c.name, o.price DESC
    ");
    $stmt->execute([$model['id']]);
    $options = $stmt->fetchAll();
} catch (PDOException $e) {
    // Tables n'existent pas encore
}

// Grouper les options par catégorie parent puis sous-catégorie
$byParent = [];
foreach ($options as $opt) {
    $parent = $opt['parent_name'] ?: 'Autre';
    $cat = $opt['category_name'] ?: 'Autre';
    
    if (!isset($byParent[$parent])) {
        $byParent[$parent] = [];
    }
    if (!isset($byParent[$parent][$cat])) {
        $byParent[$parent][$cat] = [];
    }
    $byParent[$parent][$cat][] = $opt;
}
ksort($byParent);

// Aussi garder l'ancien format pour compatibilité
$byCategory = [];
foreach ($options as $opt) {
    $parent = $opt['parent_name'] ?: 'Autre';
    $cat = $opt['category_name'] ?: 'Autre';
    $key = $parent !== $cat ? "$parent > $cat" : $cat;
    if (!isset($byCategory[$key])) {
        $byCategory[$key] = [];
    }
    $byCategory[$key][] = $opt;
}
ksort($byCategory);

// Stats
$totalOptions = count($extColors) + count($intColors) + count($options);
$totalValue = 0;
foreach (array_merge($extColors, $intColors, $options) as $o) {
    if (!$o['is_standard'] && $o['price']) $totalValue += $o['price'];
}
$standardCount = count(array_filter(array_merge($extColors, $intColors, $options), fn($o) => $o['is_standard']));
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
                    <p class="text-gray-400 text-sm">v5.7 - Couleurs & Catégories</p>
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
        <div class="grid grid-cols-2 md:grid-cols-5 gap-4 mb-6">
            <div class="bg-gray-800 rounded-xl p-4 border border-gray-700">
                <p class="text-gray-400 text-sm">Total éléments</p>
                <p class="text-2xl font-bold"><?= $totalOptions ?></p>
            </div>
            <div class="bg-gray-800 rounded-xl p-4 border border-gray-700">
                <p class="text-gray-400 text-sm">🎨 Couleurs ext.</p>
                <p class="text-2xl font-bold text-yellow-400"><?= count($extColors) ?></p>
            </div>
            <div class="bg-gray-800 rounded-xl p-4 border border-gray-700">
                <p class="text-gray-400 text-sm">🛋️ Couleurs int.</p>
                <p class="text-2xl font-bold text-orange-400"><?= count($intColors) ?></p>
            </div>
            <div class="bg-gray-800 rounded-xl p-4 border border-gray-700">
                <p class="text-gray-400 text-sm">De série</p>
                <p class="text-2xl font-bold text-green-400"><?= $standardCount ?></p>
            </div>
            <div class="bg-gray-800 rounded-xl p-4 border border-gray-700">
                <p class="text-gray-400 text-sm">Valeur options</p>
                <p class="text-2xl font-bold text-purple-400"><?= formatPrice($totalValue) ?></p>
            </div>
        </div>

        <!-- Search -->
        <div class="mb-6">
            <input type="text" id="searchOptions" placeholder="Rechercher une option, couleur..." 
                   class="w-full bg-gray-800 border border-gray-700 rounded-lg px-4 py-3 focus:outline-none focus:border-porsche-red">
        </div>

        <!-- COULEURS EXTÉRIEURES -->
        <?php if (!empty($extColors)): ?>
        <div class="bg-gray-800 rounded-xl border border-yellow-600/50 mb-6 option-section">
            <div class="p-4 border-b border-gray-700 flex items-center justify-between cursor-pointer bg-yellow-900/20"
                 onclick="this.parentElement.classList.toggle('collapsed')">
                <h3 class="font-semibold text-lg">🎨 Couleurs Extérieures</h3>
                <span class="text-gray-400"><?= count($extColors) ?> couleurs</span>
            </div>
            <div class="section-content p-4">
                <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-4">
                    <?php foreach ($extColors as $color): ?>
                    <div class="bg-gray-700/50 rounded-lg p-4 option-row" 
                         data-search="<?= htmlspecialchars(strtolower($color['code'] . ' ' . $color['name'])) ?>">
                        <div class="flex items-center gap-3 mb-2">
                            <div class="w-10 h-10 rounded-full border-2 border-gray-500 bg-gradient-to-br from-gray-400 to-gray-600"></div>
                            <span class="font-mono text-xs bg-gray-600 px-2 py-1 rounded"><?= htmlspecialchars($color['code']) ?></span>
                        </div>
                        <p class="font-medium text-sm mb-1"><?= htmlspecialchars($color['name']) ?></p>
                        <p class="text-sm <?= $color['is_standard'] ? 'text-green-400' : 'text-porsche-red' ?>">
                            <?= $color['is_standard'] ? '✓ Série' : ($color['price'] ? formatPrice($color['price']) : '? €') ?>
                        </p>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- COULEURS INTÉRIEURES -->
        <?php if (!empty($intColors)): ?>
        <div class="bg-gray-800 rounded-xl border border-orange-600/50 mb-6 option-section">
            <div class="p-4 border-b border-gray-700 flex items-center justify-between cursor-pointer bg-orange-900/20"
                 onclick="this.parentElement.classList.toggle('collapsed')">
                <h3 class="font-semibold text-lg">🛋️ Couleurs Intérieures</h3>
                <span class="text-gray-400"><?= count($intColors) ?> options</span>
            </div>
            <div class="section-content p-4">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <?php foreach ($intColors as $color): ?>
                    <div class="bg-gray-700/50 rounded-lg p-4 flex items-center justify-between option-row"
                         data-search="<?= htmlspecialchars(strtolower($color['code'] . ' ' . $color['name'])) ?>">
                        <div>
                            <span class="font-mono text-xs bg-gray-600 px-2 py-1 rounded mr-2"><?= htmlspecialchars($color['code']) ?></span>
                            <span class="font-medium"><?= htmlspecialchars($color['name']) ?></span>
                        </div>
                        <span class="<?= $color['is_standard'] ? 'text-green-400' : 'text-porsche-red' ?>">
                            <?= $color['is_standard'] ? '✓ Série' : formatPrice($color['price']) ?>
                        </span>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- OPTIONS PAR CATÉGORIE (hiérarchique) -->
        <?php foreach ($byParent as $parentName => $subCategories): ?>
        <div class="bg-gray-800 rounded-xl border border-gray-700 mb-4 option-section" data-category="<?= htmlspecialchars(strtolower($parentName)) ?>">
            <div class="p-4 border-b border-gray-700 flex items-center justify-between cursor-pointer bg-gray-700/30" 
                 onclick="this.parentElement.classList.toggle('collapsed')">
                <h3 class="font-semibold text-lg">
                    <?php
                    // Icônes par catégorie
                    $icon = '⚙️';
                    $catLower = strtolower($parentName);
                    if (str_contains($catLower, 'jante') || str_contains($catLower, 'roue')) $icon = '🛞';
                    elseif (str_contains($catLower, 'siège')) $icon = '💺';
                    elseif (str_contains($catLower, 'pack')) $icon = '📦';
                    elseif (str_contains($catLower, 'extérieur')) $icon = '🚗';
                    elseif (str_contains($catLower, 'intérieur')) $icon = '🏠';
                    elseif (str_contains($catLower, 'technolog')) $icon = '💻';
                    elseif (str_contains($catLower, 'audio')) $icon = '🔊';
                    elseif (str_contains($catLower, 'accessoire')) $icon = '🔧';
                    elseif (str_contains($catLower, 'livraison')) $icon = '🚚';
                    echo $icon . ' ' . htmlspecialchars($parentName);
                    ?>
                </h3>
                <span class="text-gray-400"><?= array_sum(array_map('count', $subCategories)) ?> options</span>
            </div>
            <div class="section-content">
                <?php foreach ($subCategories as $subCatName => $categoryOptions): ?>
                <?php if ($subCatName !== $parentName && count($subCategories) > 1): ?>
                <div class="px-4 py-2 bg-gray-900/50 border-b border-gray-700/50 text-sm text-gray-400 font-medium">
                    📂 <?= htmlspecialchars($subCatName) ?> 
                    <span class="text-gray-500">(<?= count($categoryOptions) ?>)</span>
                </div>
                <?php endif; ?>
                <table class="w-full">
                    <tbody>
                        <?php foreach ($categoryOptions as $opt): ?>
                        <tr class="border-t border-gray-700/50 hover:bg-gray-700/30 option-row"
                            data-search="<?= htmlspecialchars(strtolower($opt['code'] . ' ' . $opt['name'])) ?>">
                            <td class="px-4 py-3 w-24">
                                <span class="font-mono text-sm bg-gray-700 px-2 py-1 rounded"><?= htmlspecialchars($opt['code']) ?></span>
                            </td>
                            <td class="px-4 py-3"><?= htmlspecialchars($opt['name']) ?></td>
                            <td class="px-4 py-3 w-32 text-right font-medium">
                                <?php if ($opt['is_standard']): ?>
                                <span class="text-green-400">✓ Série</span>
                                <?php elseif ($opt['price']): ?>
                                <span class="text-porsche-red"><?= formatPrice($opt['price']) ?></span>
                                <?php else: ?>
                                <span class="text-gray-500">-</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endforeach; ?>

        <?php if ($totalOptions === 0): ?>
        <div class="bg-gray-800 rounded-xl border border-gray-700 p-12 text-center">
            <p class="text-gray-500 text-lg mb-4">Aucune option pour ce modèle</p>
            <a href="extraction.php" class="inline-block bg-porsche-red hover:bg-red-700 px-6 py-3 rounded-lg transition">
                🚀 Extraire ce modèle
            </a>
        </div>
        <?php endif; ?>
    </main>

    <script>
        // Recherche
        document.getElementById('searchOptions').addEventListener('input', function(e) {
            const search = e.target.value.toLowerCase();
            
            document.querySelectorAll('.option-row').forEach(row => {
                const text = row.dataset.search || '';
                row.style.display = text.includes(search) ? '' : 'none';
            });
            
            // Cacher les sections vides
            document.querySelectorAll('.option-section').forEach(section => {
                const visibleRows = section.querySelectorAll('.option-row:not([style*="display: none"])');
                section.style.display = visibleRows.length > 0 ? '' : 'none';
            });
        });
    </script>

    <style>
        .collapsed .section-content { display: none; }
    </style>
</body>
</html>