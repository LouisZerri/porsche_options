<?php
/**
 * PORSCHE OPTIONS MANAGER v5.7 - Détail d'un modèle
 * Affiche couleurs extérieures, intérieures et options par catégorie
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
                options_count = (SELECT COUNT(*) FROM p_options WHERE model_id = m.id AND option_type NOT IN ('color_ext', 'color_int')),
                colors_ext_count = (SELECT COUNT(*) FROM p_options WHERE model_id = m.id AND option_type = 'color_ext'),
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
                    <p class="text-gray-500 text-sm">v5.8</p>
                </div>
            </div>
            <nav class="flex items-center gap-6 text-sm">
                <a href="index.php" class="text-gray-600 hover:text-black transition">Dashboard</a>
                <a href="models.php" class="text-gray-600 hover:text-black transition">Modèles</a>
                <a href="options.php" class="text-gray-600 hover:text-black transition">Options</a>
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
        <div class="grid grid-cols-2 md:grid-cols-5 gap-4 mb-8">
            <div class="bg-porsche-gray rounded-lg p-4">
                <p class="text-xs text-gray-500 uppercase tracking-wide">Total</p>
                <p class="text-2xl font-bold"><?= $totalOptions ?></p>
            </div>
            <div class="bg-porsche-gray rounded-lg p-4">
                <p class="text-xs text-gray-500 uppercase tracking-wide">Couleurs ext.</p>
                <p class="text-2xl font-bold"><?= count($extColors) ?></p>
            </div>
            <div class="bg-porsche-gray rounded-lg p-4">
                <p class="text-xs text-gray-500 uppercase tracking-wide">Couleurs int.</p>
                <p class="text-2xl font-bold"><?= count($intColors) ?></p>
            </div>
            <div class="bg-porsche-gray rounded-lg p-4">
                <p class="text-xs text-gray-500 uppercase tracking-wide">De série</p>
                <p class="text-2xl font-bold text-green-600"><?= $standardCount ?></p>
            </div>
            <div class="bg-porsche-gray rounded-lg p-4">
                <p class="text-xs text-gray-500 uppercase tracking-wide">Valeur options</p>
                <p class="text-2xl font-bold"><?= formatPrice($totalValue) ?></p>
            </div>
        </div>

        <!-- Search -->
        <div class="mb-8">
            <input type="text" id="searchOptions" placeholder="Rechercher une option, couleur, code..." 
                   class="w-full border border-porsche-border rounded-lg px-4 py-3 focus:outline-none focus:border-black focus:ring-1 focus:ring-black">
        </div>

        <!-- COULEURS EXTÉRIEURES -->
        <?php if (!empty($extColors)): ?>
        <div class="border border-porsche-border rounded-lg mb-4 option-section">
            <div class="section-header p-4 flex items-center justify-between cursor-pointer border-b border-porsche-border"
                 onclick="this.parentElement.classList.toggle('collapsed')">
                <h3 class="font-bold text-lg">Couleurs Extérieures</h3>
                <div class="flex items-center gap-3">
                    <span class="text-gray-500 text-sm"><?= count($extColors) ?> couleurs</span>
                    <svg class="w-5 h-5 text-gray-400 transition-transform" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                    </svg>
                </div>
            </div>
            <div class="section-content p-4">
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
                             class="w-full h-20 object-cover rounded-md mb-3 border border-gray-200">
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
            </div>
        </div>
        <?php endif; ?>

        <!-- COULEURS INTÉRIEURES -->
        <?php if (!empty($intColors)): ?>
        <div class="border border-porsche-border rounded-lg mb-4 option-section">
            <div class="section-header p-4 flex items-center justify-between cursor-pointer border-b border-porsche-border"
                 onclick="this.parentElement.classList.toggle('collapsed')">
                <h3 class="font-bold text-lg">Couleurs Intérieures</h3>
                <div class="flex items-center gap-3">
                    <span class="text-gray-500 text-sm"><?= count($intColors) ?> options</span>
                    <svg class="w-5 h-5 text-gray-400 transition-transform" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                    </svg>
                </div>
            </div>
            <div class="section-content p-4">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                    <?php $i = 0; foreach ($intColors as $color): ?>
                    <div class="border border-porsche-border rounded-lg p-4 flex items-center justify-between hover:shadow-md transition option-row <?= $i % 2 ? 'bg-porsche-gray' : 'bg-white' ?> group"
                         data-search="<?= htmlspecialchars(strtolower($color['code'] . ' ' . $color['name'])) ?>">
                        <div class="flex items-center gap-4">
                            <?php if (!empty($color['image_url'])): ?>
                            <img src="<?= htmlspecialchars($color['image_url']) ?>" alt="<?= htmlspecialchars($color['name']) ?>" 
                                 class="w-16 h-16 object-cover rounded-md border border-gray-300">
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
                    <div class="px-4 py-3 flex items-center justify-between hover:bg-gray-100 transition option-row <?= $i % 2 ? 'bg-porsche-gray' : 'bg-white' ?>"
                         data-search="<?= htmlspecialchars(strtolower($opt['code'] . ' ' . $opt['name'])) ?>">
                        <div class="flex items-center gap-4">
                            <?php if ($opt['option_type'] === 'seat' && !empty($opt['image_url'])): ?>
                            <img src="<?= htmlspecialchars($opt['image_url']) ?>" alt="<?= htmlspecialchars($opt['name']) ?>" 
                                 class="w-16 h-12 object-cover rounded border border-gray-300">
                            <?php endif; ?>
                            <span class="font-mono text-xs font-bold bg-black text-white px-2 py-1 rounded w-16 text-center"><?= htmlspecialchars($opt['code']) ?></span>
                            <span class="text-sm"><?= htmlspecialchars($opt['name']) ?></span>
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

    <script>
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