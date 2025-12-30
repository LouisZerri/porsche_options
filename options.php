<?php
/**
 * PORSCHE OPTIONS MANAGER v5.9 - Recherche d'options
 * Avec filtres par type et catégorie + affichage images
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
            theme: { 
                extend: { 
                    colors: { 
                        'porsche-red': '#d5001c',
                        'porsche-gray': '#f2f2f2',
                        'porsche-border': '#e0e0e0'
                    } 
                } 
            }
        }
    </script>
    <style>
        body { font-family: 'PorscheNext', 'Segoe UI', Arial, sans-serif; }
    </style>
</head>
<body class="bg-white text-black min-h-screen">
    <header class="bg-white border-b border-porsche-border sticky top-0 z-50">
        <div class="max-w-7xl mx-auto px-6 py-4 flex items-center justify-between">
            <div class="flex items-center gap-4">
                <svg class="w-10 h-10" viewBox="0 0 100 100">
                    <circle cx="50" cy="50" r="48" fill="#d5001c"/>
                    <text x="50" y="62" text-anchor="middle" fill="white" font-size="32" font-weight="bold">P</text>
                </svg>
                <div>
                    <h1 class="text-xl font-bold text-black">Porsche Options Manager</h1>
                    <p class="text-gray-500 text-sm">v5.9</p>
                </div>
            </div>
            <nav class="flex items-center gap-6 text-sm">
                <a href="index.php" class="text-gray-600 hover:text-black transition">Dashboard</a>
                <a href="models.php" class="text-gray-600 hover:text-black transition">Modèles</a>
                <a href="options.php" class="text-black font-medium">Options</a>
                <a href="extraction.php" class="bg-porsche-red hover:bg-red-700 text-white px-4 py-2 rounded transition">Extraction</a>
            </nav>
        </div>
    </header>

    <main class="max-w-7xl mx-auto px-6 py-8">
        <h2 class="text-2xl font-bold mb-6">Options & Couleurs</h2>

        <!-- Filtres par type -->
        <?php if (!empty($stats)): ?>
        <div class="flex flex-wrap gap-2 mb-6">
            <a href="options.php" class="px-4 py-2 rounded <?= !$typeFilter ? 'bg-black text-white' : 'border border-porsche-border hover:bg-gray-50' ?> transition">
                Tous
            </a>
            <?php foreach ($stats as $s): ?>
            <a href="?type=<?= urlencode($s['option_type']) ?>" 
               class="px-4 py-2 rounded <?= $typeFilter === $s['option_type'] ? 'bg-black text-white' : 'border border-porsche-border hover:bg-gray-50' ?> transition">
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
                       class="flex-1 border border-porsche-border rounded px-4 py-3 focus:outline-none focus:border-black focus:ring-1 focus:ring-black">
                <button type="submit" class="bg-black hover:bg-gray-800 text-white px-6 py-3 rounded transition">
                    Rechercher
                </button>
            </div>
        </form>

        <?php if (!empty($options)): ?>
            <p class="text-gray-500 mb-4"><?= count($options) ?> résultat(s)</p>
            
            <!-- Résultats groupés par catégorie -->
            <?php foreach ($optionsByCategory as $category => $categoryOptions): ?>
            <div class="border border-porsche-border rounded-lg mb-4">
                <div class="p-4 border-b border-porsche-border bg-porsche-gray">
                    <h3 class="font-bold"><?= htmlspecialchars($category) ?></h3>
                    <span class="text-gray-500 text-sm"><?= count($categoryOptions) ?> éléments</span>
                </div>
                <div>
                    <?php $i = 0; foreach ($categoryOptions as $opt): ?>
                    <div class="px-4 py-3 flex items-center hover:bg-gray-100 transition <?= $i % 2 ? 'bg-porsche-gray' : 'bg-white' ?>">
                        <!-- Image thumbnail -->
                        <div class="w-16 h-12 mr-3 flex-shrink-0 rounded overflow-hidden bg-gray-100">
                            <?php if (!empty($opt['image_url']) && str_starts_with($opt['image_url'], 'gradient:')): ?>
                            <?php $gradient = substr($opt['image_url'], 9); ?>
                            <div class="w-full h-full" style="background-image: <?= htmlspecialchars($gradient) ?>"></div>
                            <?php elseif (!empty($opt['image_url'])): ?>
                            <img src="<?= htmlspecialchars($opt['image_url']) ?>" 
                                 alt="<?= htmlspecialchars($opt['name']) ?>"
                                 class="w-full h-full object-cover"
                                 loading="lazy"
                                 onerror="this.parentElement.innerHTML='<div class=\'w-full h-full flex items-center justify-center text-gray-400 text-xs\'>—</div>'">
                            <?php else: ?>
                            <div class="w-full h-full flex items-center justify-center text-gray-400 text-xs">—</div>
                            <?php endif; ?>
                        </div>
                        <span class="w-8 text-center flex-shrink-0">
                            <?php
                            $icons = ['color_ext' => '🎨', 'color_int' => '🛋️', 'wheel' => '🛞', 'seat' => '💺', 'pack' => '📦', 'option' => '⚙️'];
                            echo $icons[$opt['option_type']] ?? '⚙️';
                            ?>
                        </span>
                        <span class="font-mono text-xs font-bold bg-black text-white px-2 py-1 rounded flex-shrink-0"><?= htmlspecialchars($opt['code']) ?></span>
                        <span class="flex-1 ml-3 truncate"><?= htmlspecialchars($opt['name']) ?></span>
                        <a href="model-detail.php?code=<?= urlencode($opt['model_code']) ?>" class="text-gray-500 hover:text-black hover:underline text-sm mr-6 flex-shrink-0">
                            <?= htmlspecialchars($opt['model_name']) ?>
                        </a>
                        <span class="w-24 text-right flex-shrink-0 <?= $opt['is_standard'] ? 'text-green-600' : 'font-medium' ?>">
                            <?php if ($opt['is_standard']): ?>
                            Série
                            <?php else: ?>
                            <?= $opt['price'] ? formatPrice($opt['price']) : '-' ?>
                            <?php endif; ?>
                        </span>
                    </div>
                    <?php $i++; endforeach; ?>
                </div>
            </div>
            <?php endforeach; ?>

        <?php elseif ($search || $typeFilter): ?>
            <div class="border border-porsche-border rounded-lg p-12 text-center">
                <p class="text-gray-500 text-lg">Aucun résultat</p>
            </div>

        <?php else: ?>
            <!-- Catégories disponibles -->
            <div class="border border-porsche-border rounded-lg">
                <div class="p-4 border-b border-porsche-border">
                    <h3 class="font-bold">Catégories disponibles</h3>
                </div>
                <?php if (empty($categories)): ?>
                <div class="p-8 text-center text-gray-500">
                    Aucune donnée. Lancez une extraction.
                </div>
                <?php else: ?>
                <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-3 p-4">
                    <?php 
                    $currentParent = null;
                    foreach ($categories as $cat): 
                        if ($cat['parent_name'] !== $currentParent):
                            $currentParent = $cat['parent_name'];
                    ?>
                    <div class="col-span-full text-gray-500 text-sm mt-4 first:mt-0 border-b border-porsche-border pb-2 font-medium">
                        <?= htmlspecialchars($currentParent ?: 'Autre') ?>
                    </div>
                    <?php endif; ?>
                    <a href="?cat=<?= urlencode($cat['name']) ?>" 
                       class="border border-porsche-border hover:shadow-md hover:border-gray-400 rounded-lg p-3 transition">
                        <div class="font-medium"><?= htmlspecialchars($cat['name']) ?></div>
                        <div class="text-gray-400 text-sm"><?= $cat['count'] ?> options</div>
                    </a>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </main>

    <footer class="border-t border-porsche-border mt-12 py-6 text-center text-gray-400 text-sm">
        Porsche Options Manager v5.9
    </footer>
</body>
</html>