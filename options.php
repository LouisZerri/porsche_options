<?php
/**
 * PORSCHE OPTIONS MANAGER v6.2 - Recherche d'options
 * Avec filtres par type et catégorie + affichage images
 */
require_once 'config.php';

$db = getDB();

$search = $_GET['q'] ?? '';
$categoryFilter = $_GET['cat'] ?? '';
$typeFilter = $_GET['type'] ?? '';

$options = [];
$categories = [];
$typesCounts = [];

try {
    // Récupérer les catégories disponibles (basées sur les parent_name uniques)
    $categories = $db->query("
        SELECT c.name, COUNT(DISTINCT o.id) as count
        FROM p_categories c
        JOIN p_options o ON o.category_id = c.id
        GROUP BY c.name
        ORDER BY c.name
    ")->fetchAll();
    
    // Récupérer les types d'options avec comptage
    $typesCounts = $db->query("
        SELECT option_type, COUNT(*) as count
        FROM p_options
        GROUP BY option_type
        ORDER BY option_type
    ")->fetchAll(PDO::FETCH_KEY_PAIR);
    
    // Construire la requête de recherche
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
    
    if ($search && strlen($search) >= 2) {
        $sql .= " AND (o.code LIKE ? OR o.name LIKE ?)";
        $params[] = "%$search%";
        $params[] = "%$search%";
    }
    
    if ($categoryFilter) {
        $sql .= " AND c.name = ?";
        $params[] = $categoryFilter;
    }
    
    if ($typeFilter) {
        $sql .= " AND o.option_type = ?";
        $params[] = $typeFilter;
    }
    
    $sql .= " ORDER BY c.name, o.code LIMIT 500";
    
    // Exécuter seulement si recherche ou filtre
    if ($search || $categoryFilter || $typeFilter) {
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $options = $stmt->fetchAll();
    }
    
} catch (PDOException $e) {
    $options = [];
    $categories = [];
    $typesCounts = [];
}

// Ordre des catégories comme sur le configurateur Porsche
$categoryOrder = [
    'Couleurs Extérieures' => 1,
    'Jantes' => 2,
    'Couleurs Intérieures' => 3,
    'Sièges' => 4,
    'Packs' => 5,
    'Extérieur' => 6,
    'Intérieur' => 7,
    'Technologies' => 8,
    'Accessoires pour véhicules' => 9,
    'Livraison spéciale' => 10,
    'Autre' => 99
];

// Trier les catégories disponibles selon l'ordre du configurateur
usort($categories, function($a, $b) use ($categoryOrder) {
    $orderA = $categoryOrder[$a['name']] ?? 50;
    $orderB = $categoryOrder[$b['name']] ?? 50;
    return $orderA - $orderB;
});

// Grouper les options par catégorie pour l'affichage
// Les capotes (hood) sont regroupées avec Couleurs Extérieures
$optionsByCategory = [];
foreach ($options as $opt) {
    // Fusionner les capotes avec Couleurs Extérieures
    if ($opt['option_type'] === 'hood') {
        $cat = 'Couleurs Extérieures > Capotes';
    } else {
        $cat = $opt['parent_category'] ? $opt['parent_category'] . ' > ' . $opt['category_name'] : ($opt['category_name'] ?: 'Autre');
    }
    if (!isset($optionsByCategory[$cat])) {
        $optionsByCategory[$cat] = [];
    }
    $optionsByCategory[$cat][] = $opt;
}

// Trier les catégories selon l'ordre du configurateur
uksort($optionsByCategory, function($a, $b) use ($categoryOrder) {
    // Extraire la catégorie parente (avant le ">")
    $parentA = explode(' > ', $a)[0];
    $parentB = explode(' > ', $b)[0];
    
    $orderA = $categoryOrder[$parentA] ?? 50;
    $orderB = $categoryOrder[$parentB] ?? 50;
    
    // Si même catégorie parente, trier les sous-catégories alphabétiquement
    if ($orderA === $orderB) {
        return strcmp($a, $b);
    }
    return $orderA - $orderB;
});
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
        .info-icon { cursor: pointer; transition: all 0.2s; }
        .info-icon:hover { transform: scale(1.1); }
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
                    <p class="text-gray-500 text-sm">Toutes les options disponibles</p>
                </div>
            </div>
            <nav class="flex items-center gap-6 text-sm">
                <a href="index.php" class="text-gray-600 hover:text-black transition">Dashboard</a>
                <a href="models.php" class="text-gray-600 hover:text-black transition">Modèles</a>
                <a href="options.php" class="text-black font-medium">Options</a>
                <a href="option-edit.php" class="text-gray-600 hover:text-black transition">+ Option</a>
                <a href="stats.php" class="text-gray-600 hover:text-black transition">Stats</a>
                <a href="extraction.php" class="bg-porsche-red hover:bg-red-700 text-white px-4 py-2 rounded transition">Extraction</a>
            </nav>
        </div>
    </header>

    <main class="max-w-7xl mx-auto px-6 py-8">
        <h2 class="text-2xl font-bold mb-6">Options & Couleurs</h2>

        <!-- Filtres par TYPE d'option -->
        <?php if (!empty($typesCounts)): ?>
        <div class="mb-4">
            <p class="text-sm text-gray-500 mb-2">Filtrer par type :</p>
            <div class="flex flex-wrap gap-2">
                <?php
                $typeLabels = [
                    'color_ext' => ['🎨', 'Couleurs Ext.'],
                    'color_int' => ['🛋️', 'Couleurs Int.'],
                    'hood' => ['🏠', 'Capotes'],
                    'wheel' => ['🛞', 'Jantes'],
                    'seat' => ['💺', 'Sièges'],
                    'pack' => ['📦', 'Packs'],
                    'option' => ['⚙️', 'Options']
                ];
                $typeOrder = ['color_ext', 'hood', 'wheel', 'color_int', 'seat', 'pack', 'option'];
                foreach ($typeOrder as $type):
                    if (!isset($typesCounts[$type])) continue;
                    $count = $typesCounts[$type];
                    $icon = $typeLabels[$type][0] ?? '⚙️';
                    $label = $typeLabels[$type][1] ?? $type;
                ?>
                <a href="?type=<?= urlencode($type) ?>" 
                   class="px-3 py-1.5 rounded text-sm <?= $typeFilter === $type ? 'bg-porsche-red text-white' : 'border border-porsche-border hover:bg-gray-50' ?> transition">
                    <?= $icon ?> <?= htmlspecialchars($label) ?> 
                    <span class="<?= $typeFilter === $type ? 'text-red-200' : 'text-gray-400' ?>">(<?= $count ?>)</span>
                </a>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Filtres par catégorie -->
        <?php if (!empty($categories)): ?>
        <div class="mb-6">
            <p class="text-sm text-gray-500 mb-2">Filtrer par catégorie :</p>
            <div class="flex flex-wrap gap-2">
                <a href="options.php" class="px-4 py-2 rounded <?= !$categoryFilter && !$typeFilter ? 'bg-black text-white' : 'border border-porsche-border hover:bg-gray-50' ?> transition">
                    Tous
                </a>
                <?php foreach ($categories as $cat): ?>
                <a href="?cat=<?= urlencode($cat['name']) ?>" 
                   class="px-4 py-2 rounded <?= $categoryFilter === $cat['name'] ? 'bg-black text-white' : 'border border-porsche-border hover:bg-gray-50' ?> transition">
                    <?= htmlspecialchars($cat['name']) ?> 
                    <span class="text-gray-400">(<?= $cat['count'] ?>)</span>
                </a>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Recherche -->
        <form method="GET" class="mb-6">
            <div class="flex gap-4">
                <?php if ($categoryFilter): ?>
                <input type="hidden" name="cat" value="<?= htmlspecialchars($categoryFilter) ?>">
                <?php endif; ?>
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
                            $icons = ['color_ext' => '🎨', 'hood' => '🏠', 'color_int' => '🛋️', 'wheel' => '🛞', 'seat' => '💺', 'pack' => '📦', 'option' => '⚙️'];
                            echo $icons[$opt['option_type']] ?? '⚙️';
                            ?>
                        </span>
                        <span class="font-mono text-xs font-bold bg-black text-white px-2 py-1 rounded flex-shrink-0"><?= htmlspecialchars($opt['code']) ?></span>
                        <?php if (!empty($opt['description'])): ?>
                        <button type="button" onclick="showDescription('<?= htmlspecialchars($opt['code']) ?>', '<?= htmlspecialchars(addslashes($opt['name'])) ?>', <?= htmlspecialchars(json_encode($opt['description']), ENT_QUOTES) ?>)"
                                class="info-icon w-5 h-5 rounded-full bg-blue-500 text-white text-xs font-bold flex items-center justify-center hover:bg-blue-600 ml-2 flex-shrink-0" title="Voir la description">i</button>
                        <?php endif; ?>
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

        <?php elseif ($search || $categoryFilter || $typeFilter): ?>
            <div class="border border-porsche-border rounded-lg p-12 text-center">
                <p class="text-gray-500 text-lg">Aucun résultat</p>
            </div>

        <?php else: ?>
            <!-- Grille des catégories et types -->
            <div class="border border-porsche-border rounded-lg p-6">
                <h3 class="font-bold mb-4">Types d'options</h3>
                <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-8">
                    <?php
                    $typeLabels = [
                        'color_ext' => ['🎨', 'Couleurs Extérieures'],
                        'hood' => ['🏠', 'Capotes'],
                        'wheel' => ['🛞', 'Jantes'],
                        'color_int' => ['🛋️', 'Couleurs Intérieures'],
                        'seat' => ['💺', 'Sièges'],
                        'pack' => ['📦', 'Packs'],
                        'option' => ['⚙️', 'Options générales']
                    ];
                    foreach ($typeLabels as $type => $info):
                        $count = $typesCounts[$type] ?? 0;
                        if ($count === 0) continue;
                    ?>
                    <a href="?type=<?= urlencode($type) ?>" 
                       class="p-4 border border-porsche-border rounded-lg hover:bg-porsche-gray transition">
                        <div class="text-2xl mb-1"><?= $info[0] ?></div>
                        <div class="font-medium"><?= $info[1] ?></div>
                        <div class="text-gray-500 text-sm"><?= $count ?> options</div>
                    </a>
                    <?php endforeach; ?>
                </div>
                
                <h3 class="font-bold mb-4">Catégories disponibles</h3>
                <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                    <?php foreach ($categories as $cat): ?>
                    <a href="?cat=<?= urlencode($cat['name']) ?>" 
                       class="p-4 border border-porsche-border rounded-lg hover:bg-porsche-gray transition">
                        <div class="font-medium"><?= htmlspecialchars($cat['name']) ?></div>
                        <div class="text-gray-500 text-sm"><?= $cat['count'] ?> options</div>
                    </a>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>
    </main>

    <!-- Modal Description -->
    <div id="descriptionModal" class="fixed inset-0 bg-black bg-opacity-50 z-50 hidden items-center justify-center p-4" onclick="closeDescriptionModal(event)">
        <div class="bg-white rounded-lg shadow-2xl max-w-lg w-full max-h-[80vh] overflow-hidden" onclick="event.stopPropagation()">
            <div class="p-4 border-b border-porsche-border flex items-center justify-between bg-porsche-gray">
                <div>
                    <span id="descModal-code" class="font-mono text-xs font-bold bg-black text-white px-2 py-1 rounded"></span>
                    <span id="descModal-name" class="text-sm font-medium ml-2"></span>
                </div>
                <button onclick="closeDescriptionModal()" class="text-gray-500 hover:text-black text-2xl leading-none">&times;</button>
            </div>
            <div class="p-6 overflow-y-auto max-h-[60vh]">
                <p id="descModal-text" class="text-gray-700 leading-relaxed whitespace-pre-line"></p>
            </div>
        </div>
    </div>

    <script>
        function showDescription(code, name, description) {
            document.getElementById('descModal-code').textContent = code;
            document.getElementById('descModal-name').textContent = name;
            document.getElementById('descModal-text').textContent = description;
            const modal = document.getElementById('descriptionModal');
            modal.classList.remove('hidden');
            modal.classList.add('flex');
            document.body.style.overflow = 'hidden';
        }

        function closeDescriptionModal(event) {
            if (event && event.target !== event.currentTarget) return;
            const modal = document.getElementById('descriptionModal');
            modal.classList.add('hidden');
            modal.classList.remove('flex');
            document.body.style.overflow = '';
        }

        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') closeDescriptionModal();
        });
    </script>
</body>
</html>