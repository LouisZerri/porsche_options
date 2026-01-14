<?php
/**
 * PORSCHE OPTIONS MANAGER v6.1 - Détail d'un modèle
 * Affiche couleurs extérieures, capotes, intérieures et options par catégorie
 */
require_once 'config.php';

$db = getDB();

// Gestion de la suppression d'option
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_option_id'])) {
    $optionId = (int)$_POST['delete_option_id'];
    $modelCode = $_POST['model_code'] ?? '';
    
    try {
        $stmt = $db->prepare("DELETE FROM p_options WHERE id = ?");
        $stmt->execute([$optionId]);
        
        // Mettre à jour les compteurs du modèle
        $stmt = $db->prepare("
            UPDATE p_models m SET 
                options_count = (SELECT COUNT(*) FROM p_options WHERE model_id = m.id AND option_type NOT IN ('color_ext', 'color_int', 'hood')),
                colors_ext_count = (SELECT COUNT(*) FROM p_options WHERE model_id = m.id AND option_type IN ('color_ext', 'hood')),
                colors_int_count = (SELECT COUNT(*) FROM p_options WHERE model_id = m.id AND option_type = 'color_int')
            WHERE code = ?
        ");
        $stmt->execute([$modelCode]);
        
        header("Location: model-detail.php?code=" . urlencode($modelCode) . "&deleted=1");
        exit;
    } catch (Exception $e) {
        $deleteError = $e->getMessage();
    }
}

$code = $_GET['code'] ?? '';
if (!$code) {
    header('Location: models.php');
    exit;
}

$model = null;
$extColors = [];
$hoods = [];
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
        ORDER BY o.display_order ASC, o.price ASC
    ");
    $stmt->execute([$model['id']]);
    $extColors = $stmt->fetchAll();
    
    // Capotes / Toits
    $stmt = $db->prepare("
        SELECT o.*, c.name as category_name, c.parent_name
        FROM p_options o
        LEFT JOIN p_categories c ON o.category_id = c.id
        WHERE o.model_id = ? AND o.option_type = 'hood'
        ORDER BY o.display_order ASC, o.price ASC
    ");
    $stmt->execute([$model['id']]);
    $hoods = $stmt->fetchAll();
    
    // Jantes
    $stmt = $db->prepare("
        SELECT o.*, c.name as category_name, c.parent_name
        FROM p_options o
        LEFT JOIN p_categories c ON o.category_id = c.id
        WHERE o.model_id = ? AND o.option_type = 'wheel'
        ORDER BY o.display_order ASC, o.is_standard DESC, o.price ASC
    ");
    $stmt->execute([$model['id']]);
    $wheels = $stmt->fetchAll();
    
    // Couleurs intérieures
    $stmt = $db->prepare("
        SELECT o.*, c.name as category_name, c.parent_name
        FROM p_options o
        LEFT JOIN p_categories c ON o.category_id = c.id
        WHERE o.model_id = ? AND o.option_type = 'color_int'
        ORDER BY o.display_order ASC, o.price ASC
    ");
    $stmt->execute([$model['id']]);
    $intColors = $stmt->fetchAll();
    
    // Sièges (modèles et options)
    $stmt = $db->prepare("
        SELECT o.*, c.name as category_name, c.parent_name
        FROM p_options o
        LEFT JOIN p_categories c ON o.category_id = c.id
        WHERE o.model_id = ? AND o.option_type = 'seat'
        ORDER BY o.display_order ASC, o.is_standard DESC, o.price ASC
    ");
    $stmt->execute([$model['id']]);
    $seats = $stmt->fetchAll();
    
    // Autres options (exclure couleurs, jantes, sièges)
    $stmt = $db->prepare("
        SELECT o.*, c.name as category_name, c.parent_name
        FROM p_options o
        LEFT JOIN p_categories c ON o.category_id = c.id
        WHERE o.model_id = ? AND o.option_type NOT IN ('color_ext', 'color_int', 'hood', 'wheel', 'seat')
        ORDER BY o.display_order ASC, c.parent_name, c.name, o.price DESC
    ");
    $stmt->execute([$model['id']]);
    $options = $stmt->fetchAll();
} catch (PDOException $e) {
    // Tables n'existent pas encore
    $wheels = [];
    $seats = [];
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

// Trier les catégories selon l'ordre du configurateur
uksort($byParent, function($a, $b) use ($categoryOrder) {
    $orderA = $categoryOrder[$a] ?? 50;
    $orderB = $categoryOrder[$b] ?? 50;
    return $orderA - $orderB;
});

// Grouper les jantes par sous-catégorie
$wheelsBySubCat = [];
foreach ($wheels as $w) {
    $subCat = $w['sub_category'] ?: 'Jantes';
    if (!isset($wheelsBySubCat[$subCat])) $wheelsBySubCat[$subCat] = [];
    $wheelsBySubCat[$subCat][] = $w;
}

// Grouper les sièges par sous-catégorie
$seatsBySubCat = [];
foreach ($seats as $s) {
    $subCat = $s['sub_category'] ?: 'Sièges';
    if (!isset($seatsBySubCat[$subCat])) $seatsBySubCat[$subCat] = [];
    $seatsBySubCat[$subCat][] = $s;
}

// Grouper les couleurs intérieures par sous-catégorie
$intColorsBySubCat = [];
foreach ($intColors as $c) {
    $subCat = $c['sub_category'] ?: 'Intérieur';
    if (!isset($intColorsBySubCat[$subCat])) $intColorsBySubCat[$subCat] = [];
    $intColorsBySubCat[$subCat][] = $c;
}

// Stats
$totalOptions = count($extColors) + count($hoods) + count($wheels) + count($intColors) + count($seats) + count($options);
$allItems = array_merge($extColors, $hoods, $wheels, $intColors, $seats, $options);
$totalValue = 0;
foreach ($allItems as $o) {
    if (!$o['is_standard'] && $o['price']) $totalValue += $o['price'];
}
$standardCount = count(array_filter($allItems, fn($o) => $o['is_standard']));
$exclusiveCount = count(array_filter($allItems, fn($o) => $o['is_exclusive_manufaktur'] ?? false));
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
        .collapsed .section-content { display: none; }
        .section-header:hover { background-color: #f7f7f7; }
        .exclusive-badge { background: linear-gradient(135deg, #d4af37, #f4e4bc); }
    </style>
</head>
<body class="bg-white text-black min-h-screen">
    <!-- Header Porsche Style -->
    <header class="bg-white border-b border-porsche-border sticky top-0 z-50">
        <div class="max-w-7xl mx-auto px-6 py-4 flex items-center justify-between">
            <div class="flex items-center gap-4">
                <svg class="w-10 h-10" viewBox="0 0 100 100">
                    <circle cx="50" cy="50" r="48" fill="#d5001c"/>
                    <text x="50" y="62" text-anchor="middle" fill="white" font-size="32" font-weight="bold">P</text>
                </svg>
                <div>
                    <h1 class="text-xl font-bold text-black">Porsche Options Manager</h1>
                    <p class="text-gray-500 text-sm">Détails des options du modèle</p>
                </div>
            </div>
            <nav class="flex items-center gap-6 text-sm">
                <a href="index.php" class="text-gray-600 hover:text-black transition">Dashboard</a>
                <a href="models.php" class="text-gray-600 hover:text-black transition">Modèles</a>
                <a href="options.php" class="text-gray-600 hover:text-black transition">Options</a>
                <a href="option-edit.php" class="text-gray-600 hover:text-black transition">+ Option</a>
                <a href="stats.php" class="text-gray-600 hover:text-black transition">Stats</a>
                <a href="extraction.php" class="bg-porsche-red hover:bg-red-700 text-white px-4 py-2 rounded transition">
                    Extraction
                </a>
            </nav>
        </div>
    </header>

    <main class="max-w-7xl mx-auto px-6 py-8">
        <!-- Breadcrumb -->
        <div class="flex items-center gap-2 text-sm text-gray-500 mb-6">
            <a href="models.php" class="hover:text-black">Modèles</a>
            <span>›</span>
            <span class="text-black font-medium"><?= htmlspecialchars($model['name']) ?></span>
        </div>

        <!-- Model Header -->
        <div class="border-b border-porsche-border pb-6 mb-8">
            <div class="flex flex-col md:flex-row md:items-end md:justify-between gap-4">
                <div>
                    <p class="text-xs text-gray-400 font-mono mb-1"><?= htmlspecialchars($model['code']) ?></p>
                    <h1 class="text-3xl font-bold text-black"><?= htmlspecialchars($model['name']) ?></h1>
                    <p class="text-gray-500 mt-1"><?= htmlspecialchars($model['family_name'] ?? '') ?></p>
                </div>
                <div class="text-right">
                    <p class="text-xs text-gray-400 uppercase tracking-wide">Prix de base</p>
                    <p class="text-2xl font-bold text-black"><?= formatPrice($model['base_price']) ?></p>
                </div>
            </div>
        </div>

        <!-- Stats Cards -->
        <div class="grid grid-cols-2 md:grid-cols-6 gap-4 mb-8">
            <div class="bg-porsche-gray rounded-lg p-4">
                <p class="text-xs text-gray-500 uppercase tracking-wide">Total</p>
                <p class="text-2xl font-bold"><?= $totalOptions ?></p>
            </div>
            <div class="bg-porsche-gray rounded-lg p-4">
                <p class="text-xs text-gray-500 uppercase tracking-wide">Couleurs ext.</p>
                <p class="text-2xl font-bold"><?= count($extColors) ?></p>
            </div>
            <div class="bg-porsche-gray rounded-lg p-4">
                <p class="text-xs text-gray-500 uppercase tracking-wide">Capotes</p>
                <p class="text-2xl font-bold"><?= count($hoods) ?></p>
            </div>
            <div class="bg-porsche-gray rounded-lg p-4">
                <p class="text-xs text-gray-500 uppercase tracking-wide">Couleurs int.</p>
                <p class="text-2xl font-bold"><?= count($intColors) ?></p>
            </div>
            <div class="bg-porsche-gray rounded-lg p-4">
                <p class="text-xs text-gray-500 uppercase tracking-wide">De série</p>
                <p class="text-2xl font-bold text-green-600"><?= $standardCount ?></p>
            </div>
            <?php if ($exclusiveCount > 0): ?>
            <div class="bg-amber-50 border border-amber-200 rounded-lg p-4">
                <p class="text-xs text-amber-700 uppercase tracking-wide">Exclusive</p>
                <p class="text-2xl font-bold text-amber-700"><?= $exclusiveCount ?></p>
            </div>
            <?php else: ?>
            <div class="bg-porsche-gray rounded-lg p-4">
                <p class="text-xs text-gray-500 uppercase tracking-wide">Valeur options</p>
                <p class="text-2xl font-bold"><?= formatPrice($totalValue) ?></p>
            </div>
            <?php endif; ?>
        </div>

        <?php
        // Décoder les données techniques et équipements de série
        $technicalData = !empty($model['technical_data']) ? json_decode($model['technical_data'], true) : [];
        $standardEquipment = !empty($model['standard_equipment']) ? json_decode($model['standard_equipment'], true) : [];
        ?>

        <!-- DONNÉES TECHNIQUES -->
        <?php if (!empty($technicalData)): ?>
        <div class="border border-porsche-border rounded-lg mb-4 option-section">
            <div class="section-header p-4 flex items-center justify-between cursor-pointer border-b border-porsche-border"
                 onclick="this.parentElement.classList.toggle('collapsed')">
                <h3 class="font-bold text-lg">📊 Données techniques</h3>
                <div class="flex items-center gap-3">
                    <span class="text-gray-500 text-sm"><?= count($technicalData) ?> spécifications</span>
                    <svg class="w-5 h-5 text-gray-400 transition-transform" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                    </svg>
                </div>
            </div>
            <div class="section-content">
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-px bg-porsche-border">
                    <?php foreach ($technicalData as $label => $value): ?>
                    <div class="bg-white p-3 flex justify-between items-center">
                        <span class="text-gray-600 text-sm"><?= htmlspecialchars($label) ?></span>
                        <span class="font-medium text-sm"><?= htmlspecialchars($value) ?></span>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- ÉQUIPEMENTS DE SÉRIE -->
        <?php if (!empty($standardEquipment)): ?>
        <div class="border border-porsche-border rounded-lg mb-4 option-section collapsed">
            <div class="section-header p-4 flex items-center justify-between cursor-pointer border-b border-porsche-border"
                 onclick="this.parentElement.classList.toggle('collapsed')">
                <h3 class="font-bold text-lg">✅ Équipements de série</h3>
                <div class="flex items-center gap-3">
                    <span class="text-gray-500 text-sm"><?= count($standardEquipment) ?> équipements</span>
                    <svg class="w-5 h-5 text-gray-400 transition-transform" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                    </svg>
                </div>
            </div>
            <div class="section-content p-4">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-2">
                    <?php foreach ($standardEquipment as $equipment): ?>
                    <div class="flex items-start gap-2 p-2 rounded hover:bg-gray-50">
                        <span class="text-green-500 mt-0.5">✓</span>
                        <span class="text-sm"><?= htmlspecialchars($equipment) ?></span>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Search -->
        <div class="mb-8">
            <input type="text" id="searchOptions" placeholder="Rechercher une option, couleur, code..." 
                   class="w-full border border-porsche-border rounded-lg px-4 py-3 focus:outline-none focus:border-black focus:ring-1 focus:ring-black">
        </div>

        <!-- COULEURS EXTÉRIEURES (incluant Capotes/Toits) -->
        <?php if (!empty($extColors) || !empty($hoods)): ?>
        <div class="border border-porsche-border rounded-lg mb-4 option-section">
            <div class="section-header p-4 flex items-center justify-between cursor-pointer border-b border-porsche-border"
                 onclick="this.parentElement.classList.toggle('collapsed')">
                <h3 class="font-bold text-lg">🎨 Couleurs Extérieures</h3>
                <div class="flex items-center gap-3">
                    <span class="text-gray-500 text-sm"><?= count($extColors) + count($hoods) ?> options</span>
                    <svg class="w-5 h-5 text-gray-400 transition-transform" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                    </svg>
                </div>
            </div>
            <div class="section-content p-4">
                <?php if (!empty($extColors)): ?>
                <div class="grid grid-cols-2 md:grid-cols-4 lg:grid-cols-5 gap-4">
                    <?php $i = 0; foreach ($extColors as $color): ?>
                    <div class="border border-porsche-border rounded-lg p-4 hover:shadow-md transition option-row <?= $i % 2 ? 'bg-porsche-gray' : 'bg-white' ?> relative group" 
                         data-search="<?= htmlspecialchars(strtolower($color['code'] . ' ' . $color['name'])) ?>">
                        <button onclick="deleteOption(<?= $color['id'] ?>, '<?= htmlspecialchars($color['code']) ?>', '<?= htmlspecialchars(addslashes($color['name'])) ?>')" 
                                class="absolute top-2 right-2 text-gray-300 hover:text-red-500 transition p-1 opacity-0 group-hover:opacity-100" title="Supprimer">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                            </svg>
                        </button>
                        <?php if (!empty($color['image_url'])): ?>
                        <img src="<?= htmlspecialchars($color['image_url']) ?>" alt="<?= htmlspecialchars($color['name']) ?>" 
                             class="w-full h-20 object-cover rounded-md mb-3 border border-gray-200 cursor-pointer hover:opacity-80 transition"
                             onclick="openLightbox('<?= htmlspecialchars($color['image_url']) ?>', '<?= htmlspecialchars(addslashes($color['name'])) ?>')">
                        <?php else: ?>
                        <div class="w-full h-20 rounded-md bg-gradient-to-br from-gray-200 to-gray-400 mb-3 border border-gray-200 flex items-center justify-center">
                            <span class="text-2xl">🎨</span>
                        </div>
                        <?php endif; ?>
                        <p class="font-mono text-xs font-bold bg-black text-white px-2 py-1 rounded inline-block mb-1"><?= htmlspecialchars($color['code']) ?></p>
                        <p class="font-medium text-sm mb-2 leading-tight"><?= htmlspecialchars($color['name']) ?></p>
                        <p class="text-sm <?= $color['is_standard'] ? 'text-green-600' : 'text-black font-medium' ?>">
                            <?= $color['is_standard'] ? 'Série' : ($color['price'] ? formatPrice($color['price']) : '-') ?>
                        </p>
                    </div>
                    <?php $i++; endforeach; ?>
                </div>
                <?php endif; ?>
                
                <?php if (!empty($hoods)): ?>
                <!-- Sous-section Capotes -->
                <div class="mt-4 pt-4 border-t border-porsche-border">
                    <h4 class="font-medium text-sm text-gray-600 mb-3">Capotes / Toits</h4>
                    <div class="grid grid-cols-2 md:grid-cols-4 lg:grid-cols-5 gap-4">
                        <?php $i = 0; foreach ($hoods as $hood): ?>
                        <div class="border border-porsche-border rounded-lg p-4 hover:shadow-md transition option-row <?= $i % 2 ? 'bg-porsche-gray' : 'bg-white' ?> relative group" 
                             data-search="<?= htmlspecialchars(strtolower($hood['code'] . ' ' . $hood['name'])) ?>">
                            <button onclick="deleteOption(<?= $hood['id'] ?>, '<?= htmlspecialchars($hood['code']) ?>', '<?= htmlspecialchars(addslashes($hood['name'])) ?>')" 
                                    class="absolute top-2 right-2 text-gray-300 hover:text-red-500 transition p-1 opacity-0 group-hover:opacity-100" title="Supprimer">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                                </svg>
                            </button>
                            <?php if (!empty($hood['image_url']) && str_starts_with($hood['image_url'], 'colors:')): ?>
                            <?php 
                                $colorsStr = substr($hood['image_url'], 7);
                                $colors = explode(',', $colorsStr);
                            ?>
                            <div class="w-full h-20 rounded-md mb-3 border border-gray-200 overflow-hidden flex cursor-pointer hover:opacity-80 transition"
                                 onclick="openLightbox(null, '<?= htmlspecialchars(addslashes($hood['name'])) ?>', '<?= htmlspecialchars($colorsStr) ?>')">
                                <?php foreach ($colors as $c): ?>
                                <div class="flex-1 h-full" style="background-color: <?= htmlspecialchars(trim($c)) ?>"></div>
                                <?php endforeach; ?>
                            </div>
                            <?php elseif (!empty($hood['image_url'])): ?>
                            <img src="<?= htmlspecialchars($hood['image_url']) ?>" alt="<?= htmlspecialchars($hood['name']) ?>" 
                                 class="w-full h-20 object-cover rounded-md mb-3 border border-gray-200 cursor-pointer hover:opacity-80 transition"
                                 onclick="openLightbox('<?= htmlspecialchars($hood['image_url']) ?>', '<?= htmlspecialchars(addslashes($hood['name'])) ?>')">
                            <?php else: ?>
                            <div class="w-full h-20 rounded-md bg-gradient-to-br from-gray-700 to-gray-900 mb-3 border border-gray-200 flex items-center justify-center">
                                <span class="text-2xl">🏠</span>
                            </div>
                            <?php endif; ?>
                            <p class="font-mono text-xs font-bold bg-black text-white px-2 py-1 rounded inline-block mb-1"><?= htmlspecialchars($hood['code']) ?></p>
                            <p class="font-medium text-sm mb-2 leading-tight"><?= htmlspecialchars($hood['name']) ?></p>
                            <p class="text-sm <?= $hood['is_standard'] ? 'text-green-600' : 'text-black font-medium' ?>">
                                <?= $hood['is_standard'] ? 'Série' : ($hood['price'] ? formatPrice($hood['price']) : '-') ?>
                            </p>
                        </div>
                        <?php $i++; endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- JANTES -->
        <?php if (!empty($wheels)): ?>
        <div class="border border-porsche-border rounded-lg mb-4 option-section">
            <div class="section-header p-4 flex items-center justify-between cursor-pointer border-b border-porsche-border"
                 onclick="this.parentElement.classList.toggle('collapsed')">
                <h3 class="font-bold text-lg">🛞 Jantes</h3>
                <div class="flex items-center gap-3">
                    <span class="text-gray-500 text-sm"><?= count($wheels) ?> options</span>
                    <svg class="w-5 h-5 text-gray-400 transition-transform" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                    </svg>
                </div>
            </div>
            <div class="section-content p-4">
                <?php foreach ($wheelsBySubCat as $subCat => $subItems): ?>
                <?php if (count($wheelsBySubCat) > 1): ?>
                <h4 class="text-sm font-semibold text-gray-600 mb-3 mt-2 border-b border-gray-200 pb-1"><?= htmlspecialchars($subCat) ?></h4>
                <?php endif; ?>
                <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-4 mb-4">
                    <?php $i = 0; foreach ($subItems as $jante): ?>
                    <div class="border border-porsche-border rounded-lg p-4 hover:shadow-md transition option-row <?= $i % 2 ? 'bg-porsche-gray' : 'bg-white' ?> <?= ($jante['is_exclusive_manufaktur'] ?? false) ? 'bg-amber-50' : '' ?> relative group" 
                         data-search="<?= htmlspecialchars(strtolower($jante['code'] . ' ' . $jante['name'])) ?>">
                        <button onclick="deleteOption(<?= $jante['id'] ?>, '<?= htmlspecialchars($jante['code']) ?>', '<?= htmlspecialchars(addslashes($jante['name'])) ?>')" 
                                class="absolute top-2 right-2 text-gray-300 hover:text-red-500 transition p-1 opacity-0 group-hover:opacity-100" title="Supprimer">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                            </svg>
                        </button>
                        <?php if (!empty($jante['image_url'])): ?>
                        <img src="<?= htmlspecialchars($jante['image_url']) ?>" alt="<?= htmlspecialchars($jante['name']) ?>" 
                             class="w-full h-20 object-contain rounded-md mb-3 border border-gray-200 cursor-pointer hover:opacity-80 transition bg-white"
                             onclick="openLightbox('<?= htmlspecialchars($jante['image_url']) ?>', '<?= htmlspecialchars(addslashes($jante['name'])) ?>')"
                             onerror="this.style.display='none'">
                        <?php else: ?>
                        <div class="w-full h-20 rounded-md bg-gradient-to-br from-gray-200 to-gray-400 mb-3 border border-gray-200 flex items-center justify-center">
                            <span class="text-2xl">🛞</span>
                        </div>
                        <?php endif; ?>
                        <?php if ($jante['is_exclusive_manufaktur'] ?? false): ?>
                        <span class="text-xs exclusive-badge text-amber-800 px-2 py-0.5 rounded-full mb-1 inline-block">Exclusive Manufaktur</span>
                        <?php endif; ?>
                        <p class="font-mono text-xs font-bold bg-black text-white px-2 py-1 rounded inline-block mb-1"><?= htmlspecialchars($jante['code']) ?></p>
                        <p class="font-medium text-sm mb-2 leading-tight"><?= htmlspecialchars($jante['name']) ?></p>
                        <?php if (!empty($jante['sub_category'])): ?>
                        <p class="text-xs text-gray-400 mb-1"><?= htmlspecialchars($jante['sub_category']) ?></p>
                        <?php endif; ?>
                        <p class="text-sm <?= $jante['is_standard'] ? 'text-green-600' : 'text-black font-medium' ?>">
                            <?= $jante['is_standard'] ? 'Série' : ($jante['price'] ? formatPrice($jante['price']) : '-') ?>
                        </p>
                    </div>
                    <?php $i++; endforeach; ?>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- COULEURS INTÉRIEURES -->
        <?php if (!empty($intColors)): ?>
        <div class="border border-porsche-border rounded-lg mb-4 option-section">
            <div class="section-header p-4 flex items-center justify-between cursor-pointer border-b border-porsche-border"
                 onclick="this.parentElement.classList.toggle('collapsed')">
                <h3 class="font-bold text-lg">🛋️ Couleurs Intérieures</h3>
                <div class="flex items-center gap-3">
                    <span class="text-gray-500 text-sm"><?= count($intColors) ?> options</span>
                    <svg class="w-5 h-5 text-gray-400 transition-transform" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                    </svg>
                </div>
            </div>
            <div class="section-content p-4">
                <?php foreach ($intColorsBySubCat as $subCat => $subItems): ?>
                <?php if (count($intColorsBySubCat) > 1): ?>
                <h4 class="text-sm font-semibold text-gray-600 mb-3 mt-2 border-b border-gray-200 pb-1"><?= htmlspecialchars($subCat) ?></h4>
                <?php endif; ?>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-3 mb-4">
                    <?php $i = 0; foreach ($subItems as $color): ?>
                    <div class="border border-porsche-border rounded-lg p-4 flex items-center justify-between hover:shadow-md transition option-row <?= $i % 2 ? 'bg-porsche-gray' : 'bg-white' ?> group"
                         data-search="<?= htmlspecialchars(strtolower($color['code'] . ' ' . $color['name'])) ?>">
                        <div class="flex items-center gap-4">
                            <?php if (!empty($color['image_url']) && str_starts_with($color['image_url'], 'colors:')): ?>
                            <?php 
                                $colorsStr = substr($color['image_url'], 7); // Remove "colors:" prefix
                                $colors = explode(',', $colorsStr);
                            ?>
                            <div class="w-16 h-16 rounded-md border border-gray-300 overflow-hidden flex flex-col cursor-pointer hover:opacity-80 transition"
                                 onclick="openLightbox(null, '<?= htmlspecialchars(addslashes($color['name'])) ?>', '<?= htmlspecialchars($colorsStr) ?>')">
                                <?php foreach ($colors as $c): ?>
                                <div class="flex-1" style="background-color: <?= htmlspecialchars(trim($c)) ?>"></div>
                                <?php endforeach; ?>
                            </div>
                            <?php elseif (!empty($color['image_url'])): ?>
                            <img src="<?= htmlspecialchars($color['image_url']) ?>" alt="<?= htmlspecialchars($color['name']) ?>" 
                                 class="w-16 h-16 object-cover rounded-md border border-gray-300 cursor-pointer hover:opacity-80 transition"
                                 onclick="openLightbox('<?= htmlspecialchars($color['image_url']) ?>', '<?= htmlspecialchars(addslashes($color['name'])) ?>')">
                            <?php else: ?>
                            <div class="w-16 h-16 rounded-md bg-gradient-to-br from-gray-200 to-gray-400 border border-gray-300 flex items-center justify-center">
                                <span class="text-2xl">🛋️</span>
                            </div>
                            <?php endif; ?>
                            <div>
                                <p class="font-mono text-xs font-bold bg-black text-white px-2 py-1 rounded inline-block mb-1"><?= htmlspecialchars($color['code']) ?></p>
                                <p class="font-medium"><?= htmlspecialchars($color['name']) ?></p>
                            </div>
                        </div>
                        <div class="flex items-center gap-3">
                            <span class="<?= $color['is_standard'] ? 'text-green-600' : 'font-medium' ?>">
                                <?= $color['is_standard'] ? 'Série' : ($color['price'] ? formatPrice($color['price']) : '-') ?>
                            </span>
                            <button onclick="deleteOption(<?= $color['id'] ?>, '<?= htmlspecialchars($color['code']) ?>', '<?= htmlspecialchars(addslashes($color['name'])) ?>')" 
                                    class="text-gray-300 hover:text-red-500 transition p-1 opacity-0 group-hover:opacity-100" title="Supprimer">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                                </svg>
                            </button>
                        </div>
                    </div>
                    <?php $i++; endforeach; ?>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- SIÈGES -->
        <?php if (!empty($seats)): ?>
        <div class="border border-porsche-border rounded-lg mb-4 option-section">
            <div class="section-header p-4 flex items-center justify-between cursor-pointer border-b border-porsche-border"
                 onclick="this.parentElement.classList.toggle('collapsed')">
                <h3 class="font-bold text-lg">💺 Sièges</h3>
                <div class="flex items-center gap-3">
                    <span class="text-gray-500 text-sm"><?= count($seats) ?> options</span>
                    <svg class="w-5 h-5 text-gray-400 transition-transform" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                    </svg>
                </div>
            </div>
            <div class="section-content p-4">
                <?php foreach ($seatsBySubCat as $subCat => $subItems): ?>
                <?php if (count($seatsBySubCat) > 1): ?>
                <h4 class="text-sm font-semibold text-gray-600 mb-3 mt-2 border-b border-gray-200 pb-1"><?= htmlspecialchars($subCat) ?></h4>
                <?php endif; ?>
                <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-4 mb-4">
                    <?php $i = 0; foreach ($subItems as $seat): ?>
                    <div class="border border-porsche-border rounded-lg p-4 hover:shadow-md transition option-row <?= $i % 2 ? 'bg-porsche-gray' : 'bg-white' ?> <?= ($seat['is_exclusive_manufaktur'] ?? false) ? 'bg-amber-50' : '' ?> relative group" 
                         data-search="<?= htmlspecialchars(strtolower($seat['code'] . ' ' . $seat['name'])) ?>">
                        <button onclick="deleteOption(<?= $seat['id'] ?>, '<?= htmlspecialchars($seat['code']) ?>', '<?= htmlspecialchars(addslashes($seat['name'])) ?>')" 
                                class="absolute top-2 right-2 text-gray-300 hover:text-red-500 transition p-1 opacity-0 group-hover:opacity-100" title="Supprimer">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                            </svg>
                        </button>
                        <?php if (!empty($seat['image_url'])): ?>
                        <img src="<?= htmlspecialchars($seat['image_url']) ?>" alt="<?= htmlspecialchars($seat['name']) ?>" 
                             class="w-full h-24 object-contain rounded-md mb-3 border border-gray-200 cursor-pointer hover:opacity-80 transition bg-white"
                             onclick="openLightbox('<?= htmlspecialchars($seat['image_url']) ?>', '<?= htmlspecialchars(addslashes($seat['name'])) ?>')"
                             onerror="this.style.display='none'">
                        <?php else: ?>
                        <div class="w-full h-24 rounded-md bg-gradient-to-br from-gray-200 to-gray-400 mb-3 border border-gray-200 flex items-center justify-center">
                            <span class="text-3xl">💺</span>
                        </div>
                        <?php endif; ?>
                        <?php if ($seat['is_exclusive_manufaktur'] ?? false): ?>
                        <span class="text-xs exclusive-badge text-amber-800 px-2 py-0.5 rounded-full mb-1 inline-block">Exclusive Manufaktur</span>
                        <?php endif; ?>
                        <p class="font-mono text-xs font-bold bg-black text-white px-2 py-1 rounded inline-block mb-1"><?= htmlspecialchars($seat['code']) ?></p>
                        <p class="font-medium text-sm mb-2 leading-tight"><?= htmlspecialchars($seat['name']) ?></p>
                        <?php if (!empty($seat['sub_category'])): ?>
                        <p class="text-xs text-gray-400 mb-1"><?= htmlspecialchars($seat['sub_category']) ?></p>
                        <?php endif; ?>
                        <p class="text-sm <?= $seat['is_standard'] ? 'text-green-600' : 'text-black font-medium' ?>">
                            <?= $seat['is_standard'] ? 'Série' : ($seat['price'] ? formatPrice($seat['price']) : '-') ?>
                        </p>
                    </div>
                    <?php $i++; endforeach; ?>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- OPTIONS PAR CATÉGORIE -->
        <?php foreach ($byParent as $parentName => $subCategories): ?>
        <div class="border border-porsche-border rounded-lg mb-4 option-section" data-category="<?= htmlspecialchars(strtolower($parentName)) ?>">
            <div class="section-header p-4 flex items-center justify-between cursor-pointer border-b border-porsche-border" 
                 onclick="this.parentElement.classList.toggle('collapsed')">
                <h3 class="font-bold text-lg"><?= htmlspecialchars($parentName) ?></h3>
                <div class="flex items-center gap-3">
                    <span class="text-gray-500 text-sm"><?= array_sum(array_map('count', $subCategories)) ?> options</span>
                    <svg class="w-5 h-5 text-gray-400 transition-transform" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                    </svg>
                </div>
            </div>
            <div class="section-content">
                <?php foreach ($subCategories as $subCatName => $categoryOptions): ?>
                <?php if ($subCatName !== $parentName && count($subCategories) > 1): ?>
                <div class="px-4 py-3 bg-porsche-gray border-b border-porsche-border">
                    <span class="font-medium text-sm"><?= htmlspecialchars($subCatName) ?></span>
                    <span class="text-gray-400 text-sm ml-2">(<?= count($categoryOptions) ?>)</span>
                </div>
                <?php endif; ?>
                <div>
                    <?php $i = 0; foreach ($categoryOptions as $opt): ?>
                    <div class="option-row <?= $i % 2 ? 'bg-porsche-gray' : 'bg-white' ?> <?= ($opt['is_exclusive_manufaktur'] ?? false) ? 'bg-amber-50' : '' ?>"
                         data-search="<?= htmlspecialchars(strtolower($opt['code'] . ' ' . $opt['name'])) ?>">
                        <div class="px-4 py-3 flex items-center justify-between hover:bg-gray-100 transition">
                            <div class="flex items-center gap-4">
                                <!-- Image thumbnail -->
                                <?php if (!empty($opt['image_url']) && str_starts_with($opt['image_url'], 'colors:')): ?>
                                <?php $colors = explode(',', substr($opt['image_url'], 7)); ?>
                                <div class="w-16 h-12 rounded border border-gray-200 flex-shrink-0 overflow-hidden flex flex-col cursor-pointer hover:opacity-80"
                                     onclick="openLightbox(null, '<?= htmlspecialchars(addslashes($opt['name'])) ?>', '<?= htmlspecialchars(substr($opt['image_url'], 7)) ?>')">
                                    <?php foreach ($colors as $c): ?>
                                    <div class="flex-1" style="background-color: <?= htmlspecialchars(trim($c)) ?>"></div>
                                    <?php endforeach; ?>
                                </div>
                                <?php elseif (!empty($opt['image_url'])): ?>
                                <img src="<?= htmlspecialchars($opt['image_url']) ?>" alt="<?= htmlspecialchars($opt['name']) ?>" 
                                     class="w-16 h-12 object-cover rounded border border-gray-200 flex-shrink-0 cursor-pointer hover:opacity-80"
                                     loading="lazy"
                                     onclick="openLightbox('<?= htmlspecialchars($opt['image_url']) ?>', '<?= htmlspecialchars(addslashes($opt['name'])) ?>')"
                                     onerror="this.style.display='none'">
                                <?php else: ?>
                                <div class="w-16 h-12 rounded border border-gray-200 bg-gray-100 flex items-center justify-center flex-shrink-0 text-gray-400 text-xs">—</div>
                                <?php endif; ?>
                                <div>
                                    <?php if ($opt['is_exclusive_manufaktur'] ?? false): ?>
                                    <span class="text-xs exclusive-badge text-amber-800 px-2 py-0.5 rounded-full mr-2">Exclusive Manufaktur</span>
                                    <?php endif; ?>
                                    <span class="font-mono text-xs font-bold bg-black text-white px-2 py-1 rounded"><?= htmlspecialchars($opt['code']) ?></span>
                                    <span class="text-sm ml-2"><?= htmlspecialchars($opt['name']) ?></span>
                                </div>
                            </div>
                            <div class="flex items-center gap-4">
                                <span class="text-sm <?= $opt['is_standard'] ? 'text-green-600' : 'font-medium' ?>">
                                    <?php if ($opt['is_standard']): ?>
                                        Série
                                    <?php elseif ($opt['price']): ?>
                                        <?= formatPrice($opt['price']) ?>
                                    <?php else: ?>
                                        <span class="text-gray-400">-</span>
                                    <?php endif; ?>
                                </span>
                                <button onclick="deleteOption(<?= $opt['id'] ?>, '<?= htmlspecialchars($opt['code']) ?>', '<?= htmlspecialchars(addslashes($opt['name'])) ?>')" 
                                        class="text-gray-300 hover:text-red-500 transition p-1" title="Supprimer cette option">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                                    </svg>
                                </button>
                            </div>
                        </div>
                    </div>
                    <?php $i++; endforeach; ?>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endforeach; ?>

        <?php if ($totalOptions === 0): ?>
        <div class="border border-porsche-border rounded-lg p-12 text-center">
            <p class="text-gray-500 text-lg mb-4">Aucune option pour ce modèle</p>
            <a href="extraction.php" class="inline-block bg-porsche-red hover:bg-red-700 text-white px-6 py-3 rounded transition">
                Extraire ce modèle
            </a>
        </div>
        <?php endif; ?>
    </main>

    <!-- Hidden form for deletion -->
    <form id="deleteForm" method="POST" style="display: none;">
        <input type="hidden" name="delete_option_id" id="deleteOptionId">
        <input type="hidden" name="model_code" value="<?= htmlspecialchars($code) ?>">
    </form>

    <!-- Notification -->
    <?php if (isset($_GET['deleted'])): ?>
    <div id="notification" class="fixed bottom-4 right-4 bg-green-500 text-white px-6 py-3 rounded-lg shadow-lg flex items-center gap-2">
        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
        </svg>
        Option supprimée
    </div>
    <script>setTimeout(() => document.getElementById('notification').remove(), 3000);</script>
    <?php endif; ?>

    <!-- Lightbox Modal -->
    <div id="lightbox" class="fixed inset-0 bg-black bg-opacity-90 z-50 hidden items-center justify-center p-4" onclick="closeLightbox(event)">
        <button onclick="closeLightbox()" class="absolute top-4 right-4 text-white hover:text-gray-300 text-4xl">&times;</button>
        <div class="max-w-4xl max-h-full flex flex-col items-center">
            <img id="lightbox-img" src="" alt="" class="max-w-full max-h-[85vh] object-contain rounded-lg shadow-2xl">
            <div id="lightbox-colors" class="hidden w-64 h-64 rounded-lg shadow-2xl border-4 border-white overflow-hidden flex flex-col"></div>
            <p id="lightbox-caption" class="text-white text-center mt-4 text-lg"></p>
        </div>
    </div>

    <script>
        // Lightbox functions
        function openLightbox(src, caption, colorsOrGradient = false) {
            const lightbox = document.getElementById('lightbox');
            const img = document.getElementById('lightbox-img');
            const colorsDiv = document.getElementById('lightbox-colors');
            const captionEl = document.getElementById('lightbox-caption');
            
            // Reset
            img.classList.add('hidden');
            colorsDiv.classList.add('hidden');
            colorsDiv.innerHTML = '';
            
            if (colorsOrGradient && typeof colorsOrGradient === 'string') {
                // Colors format: "#000000,#333333,#666666"
                const colors = colorsOrGradient.split(',');
                colors.forEach(color => {
                    const band = document.createElement('div');
                    band.className = 'flex-1';
                    band.style.backgroundColor = color.trim();
                    colorsDiv.appendChild(band);
                });
                colorsDiv.classList.remove('hidden');
            } else if (src) {
                // Regular image
                img.src = src;
                img.alt = caption;
                img.classList.remove('hidden');
            }
            
            captionEl.textContent = caption;
            lightbox.classList.remove('hidden');
            lightbox.classList.add('flex');
            document.body.style.overflow = 'hidden';
        }
        
        function closeLightbox(event) {
            if (event && event.target !== event.currentTarget && !event.target.closest('button')) return;
            const lightbox = document.getElementById('lightbox');
            lightbox.classList.add('hidden');
            lightbox.classList.remove('flex');
            document.body.style.overflow = '';
        }
        
        // Close on Escape key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') closeLightbox();
        });

        // Suppression d'option
        function deleteOption(id, code, name) {
            if (confirm(`Supprimer l'option [${code}] ${name} ?`)) {
                document.getElementById('deleteOptionId').value = id;
                document.getElementById('deleteForm').submit();
            }
        }
        
        // Recherche
        document.getElementById('searchOptions').addEventListener('input', function(e) {
            const search = e.target.value.toLowerCase();
            
            document.querySelectorAll('.option-row').forEach(row => {
                const text = row.dataset.search || '';
                row.style.display = text.includes(search) ? '' : 'none';
            });
            
            document.querySelectorAll('.option-section').forEach(section => {
                const visibleRows = section.querySelectorAll('.option-row:not([style*="display: none"])');
                section.style.display = visibleRows.length > 0 ? '' : 'none';
            });
        });
    </script>
</body>
</html>