<?php
/**
 * PORSCHE OPTIONS MANAGER v6.2 - Statistiques & Comparaisons
 * Point 6 client: Filtres comparatifs, doublons, Exclusive Manufaktur
 */
require_once 'config.php';

$db = getDB();

// Récupérer les modèles pour le filtre
$models = [];
$stats = [];
$duplicates = [];
$exclusiveOptions = [];
$priceComparisons = [];

try {
    $models = $db->query("
        SELECT m.*, f.name as family_name 
        FROM p_models m 
        LEFT JOIN p_families f ON m.family_id = f.id 
        ORDER BY f.name, m.name
    ")->fetchAll();
    
    // Statistiques globales
    $stats = [
        'totalModels' => count($models),
        'totalOptions' => $db->query("SELECT COUNT(*) FROM p_options")->fetchColumn(),
        'totalExclusive' => $db->query("SELECT COUNT(*) FROM p_options WHERE is_exclusive_manufaktur = 1")->fetchColumn(),
        'totalStandard' => $db->query("SELECT COUNT(*) FROM p_options WHERE is_standard = 1")->fetchColumn(),
        'uniqueCodes' => $db->query("SELECT COUNT(DISTINCT code) FROM p_options")->fetchColumn(),
    ];
    
    // Doublons: codes présents dans plusieurs modèles avec prix différents
    $duplicates = $db->query("
        SELECT 
            o.code,
            COUNT(DISTINCT o.model_id) as model_count,
            COUNT(DISTINCT o.price) as price_variations,
            MIN(o.price) as min_price,
            MAX(o.price) as max_price,
            GROUP_CONCAT(DISTINCT m.name SEPARATOR ', ') as models,
            ANY_VALUE(o.name) as option_name
        FROM p_options o
        JOIN p_models m ON o.model_id = m.id
        WHERE o.price IS NOT NULL AND o.price > 0
        GROUP BY o.code
        HAVING model_count > 1 AND price_variations > 1
        ORDER BY (MAX(o.price) - MIN(o.price)) DESC
        LIMIT 50
    ")->fetchAll();
    
    // Options Exclusive Manufaktur
    $exclusiveOptions = $db->query("
        SELECT 
            o.code,
            o.name,
            o.price,
            o.option_type,
            m.name as model_name,
            m.code as model_code
        FROM p_options o
        JOIN p_models m ON o.model_id = m.id
        WHERE o.is_exclusive_manufaktur = 1
        ORDER BY m.name, o.name
    ")->fetchAll();
    
    // Comparaison des prix de base des véhicules
    $priceComparisons = $db->query("
        SELECT 
            m.code,
            m.name,
            m.base_price,
            f.name as family_name,
            (SELECT COUNT(*) FROM p_options WHERE model_id = m.id) as options_count
        FROM p_models m
        LEFT JOIN p_families f ON m.family_id = f.id
        WHERE m.base_price IS NOT NULL
        ORDER BY m.base_price DESC
    ")->fetchAll();
    
} catch (PDOException $e) {
    // Tables may not exist
}

// Recherche de comparaison de code spécifique
$compareCode = $_GET['compare'] ?? '';
$codeComparison = [];
if ($compareCode) {
    try {
        $stmt = $db->prepare("
            SELECT 
                o.code,
                o.name,
                o.name_de,
                o.price,
                o.is_standard,
                o.is_exclusive_manufaktur,
                o.option_type,
                o.sub_category,
                m.name as model_name,
                m.code as model_code,
                m.base_price as model_price
            FROM p_options o
            JOIN p_models m ON o.model_id = m.id
            WHERE o.code = ?
            ORDER BY o.price DESC
        ");
        $stmt->execute([$compareCode]);
        $codeComparison = $stmt->fetchAll();
    } catch (PDOException $e) {}
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Stats & Comparaisons - Porsche Options Manager</title>
    <script src="https://cdn.tailwindcss.com"></script>
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
<body class="bg-white text-gray-900">
    <header class="bg-white border-b border-porsche-border sticky top-0 z-50">
        <div class="max-w-7xl mx-auto px-6 py-4 flex items-center justify-between">
            <div class="flex items-center gap-4">
                <svg class="w-10 h-10" viewBox="0 0 100 100">
                    <circle cx="50" cy="50" r="48" fill="#d5001c"/>
                    <text x="50" y="62" text-anchor="middle" fill="white" font-size="32" font-weight="bold">P</text>
                </svg>
                <div>
                    <h1 class="text-xl font-bold text-black">Porsche Options Manager</h1>
                    <p class="text-gray-500 text-sm">v6.1</p>
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
        <h2 class="text-2xl font-bold mb-6">📊 Statistiques & Comparaisons</h2>

        <!-- Stats globales -->
        <div class="grid grid-cols-2 md:grid-cols-5 gap-4 mb-8">
            <div class="bg-porsche-gray rounded-lg p-4 text-center">
                <p class="text-3xl font-bold"><?= $stats['totalModels'] ?? 0 ?></p>
                <p class="text-gray-500 text-sm">Modèles</p>
            </div>
            <div class="bg-porsche-gray rounded-lg p-4 text-center">
                <p class="text-3xl font-bold"><?= number_format($stats['totalOptions'] ?? 0) ?></p>
                <p class="text-gray-500 text-sm">Options totales</p>
            </div>
            <div class="bg-porsche-gray rounded-lg p-4 text-center">
                <p class="text-3xl font-bold"><?= number_format($stats['uniqueCodes'] ?? 0) ?></p>
                <p class="text-gray-500 text-sm">Codes uniques</p>
            </div>
            <div class="bg-amber-50 border border-amber-200 rounded-lg p-4 text-center">
                <p class="text-3xl font-bold text-amber-700"><?= $stats['totalExclusive'] ?? 0 ?></p>
                <p class="text-amber-600 text-sm">Exclusive Manufaktur</p>
            </div>
            <div class="bg-green-50 border border-green-200 rounded-lg p-4 text-center">
                <p class="text-3xl font-bold text-green-700"><?= number_format($stats['totalStandard'] ?? 0) ?></p>
                <p class="text-green-600 text-sm">De série</p>
            </div>
        </div>

        <!-- Recherche de code pour comparaison -->
        <div class="border border-porsche-border rounded-lg p-6 mb-8">
            <h3 class="font-bold text-lg mb-4">🔍 Comparer un code option</h3>
            <form method="GET" class="flex gap-4">
                <input type="text" name="compare" value="<?= htmlspecialchars($compareCode) ?>" 
                       placeholder="Code option (ex: PSM, 9JB, XSC...)"
                       class="flex-1 border border-porsche-border rounded px-4 py-2 focus:outline-none focus:border-black">
                <button type="submit" class="bg-black text-white px-6 py-2 rounded hover:bg-gray-800 transition">
                    Comparer
                </button>
            </form>
            
            <?php if (!empty($codeComparison)): ?>
            <div class="mt-6">
                <h4 class="font-medium mb-3">Résultats pour "<?= htmlspecialchars($compareCode) ?>" (<?= count($codeComparison) ?> modèles)</h4>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="bg-porsche-gray">
                            <tr>
                                <th class="px-4 py-2 text-left">Modèle</th>
                                <th class="px-4 py-2 text-left">Nom FR</th>
                                <th class="px-4 py-2 text-left">Nom DE</th>
                                <th class="px-4 py-2 text-right">Prix option</th>
                                <th class="px-4 py-2 text-right">Prix véhicule</th>
                                <th class="px-4 py-2 text-center">Série</th>
                                <th class="px-4 py-2 text-center">Exclusive</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($codeComparison as $item): ?>
                            <tr class="border-b border-porsche-border hover:bg-gray-50">
                                <td class="px-4 py-2">
                                    <a href="model-detail.php?code=<?= urlencode($item['model_code']) ?>" class="text-blue-600 hover:underline">
                                        <?= htmlspecialchars($item['model_name']) ?>
                                    </a>
                                </td>
                                <td class="px-4 py-2"><?= htmlspecialchars($item['name'] ?? '-') ?></td>
                                <td class="px-4 py-2 text-gray-500"><?= htmlspecialchars($item['name_de'] ?? '-') ?></td>
                                <td class="px-4 py-2 text-right font-medium">
                                    <?= $item['is_standard'] ? '<span class="text-green-600">Série</span>' : ($item['price'] ? number_format($item['price'], 0, ',', ' ') . ' €' : '-') ?>
                                </td>
                                <td class="px-4 py-2 text-right text-gray-500">
                                    <?= $item['model_price'] ? number_format($item['model_price'], 0, ',', ' ') . ' €' : '-' ?>
                                </td>
                                <td class="px-4 py-2 text-center"><?= $item['is_standard'] ? '✓' : '' ?></td>
                                <td class="px-4 py-2 text-center"><?= $item['is_exclusive_manufaktur'] ? '🏷️' : '' ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php elseif ($compareCode): ?>
            <p class="mt-4 text-gray-500">Aucun résultat pour "<?= htmlspecialchars($compareCode) ?>"</p>
            <?php endif; ?>
        </div>

        <!-- Prix des véhicules -->
        <div class="border border-porsche-border rounded-lg mb-8">
            <div class="p-4 border-b border-porsche-border bg-porsche-gray">
                <h3 class="font-bold">💰 Comparaison des prix véhicules</h3>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-4 py-2 text-left">Modèle</th>
                            <th class="px-4 py-2 text-left">Famille</th>
                            <th class="px-4 py-2 text-right">Prix de base</th>
                            <th class="px-4 py-2 text-right">Nb options</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($priceComparisons as $model): ?>
                        <tr class="border-b border-porsche-border hover:bg-gray-50">
                            <td class="px-4 py-2">
                                <a href="model-detail.php?code=<?= urlencode($model['code']) ?>" class="text-blue-600 hover:underline">
                                    <?= htmlspecialchars($model['name']) ?>
                                </a>
                                <span class="text-gray-400 text-xs ml-2"><?= htmlspecialchars($model['code']) ?></span>
                            </td>
                            <td class="px-4 py-2 text-gray-500"><?= htmlspecialchars($model['family_name'] ?? '-') ?></td>
                            <td class="px-4 py-2 text-right font-bold"><?= number_format($model['base_price'], 0, ',', ' ') ?> €</td>
                            <td class="px-4 py-2 text-right"><?= $model['options_count'] ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Doublons avec variations de prix -->
        <?php if (!empty($duplicates)): ?>
        <div class="border border-porsche-border rounded-lg mb-8">
            <div class="p-4 border-b border-porsche-border bg-porsche-gray">
                <h3 class="font-bold">⚠️ Variations de prix entre modèles (<?= count($duplicates) ?> codes)</h3>
                <p class="text-gray-500 text-sm">Options présentes dans plusieurs modèles avec des prix différents</p>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-4 py-2 text-left">Code</th>
                            <th class="px-4 py-2 text-left">Option</th>
                            <th class="px-4 py-2 text-center">Modèles</th>
                            <th class="px-4 py-2 text-right">Prix min</th>
                            <th class="px-4 py-2 text-right">Prix max</th>
                            <th class="px-4 py-2 text-right">Écart</th>
                            <th class="px-4 py-2 text-center">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($duplicates as $dup): ?>
                        <tr class="border-b border-porsche-border hover:bg-gray-50">
                            <td class="px-4 py-2 font-mono font-bold"><?= htmlspecialchars($dup['code']) ?></td>
                            <td class="px-4 py-2"><?= htmlspecialchars($dup['option_name'] ?? '-') ?></td>
                            <td class="px-4 py-2 text-center"><?= $dup['model_count'] ?></td>
                            <td class="px-4 py-2 text-right"><?= number_format($dup['min_price'], 0, ',', ' ') ?> €</td>
                            <td class="px-4 py-2 text-right"><?= number_format($dup['max_price'], 0, ',', ' ') ?> €</td>
                            <td class="px-4 py-2 text-right font-bold text-red-600">
                                +<?= number_format($dup['max_price'] - $dup['min_price'], 0, ',', ' ') ?> €
                            </td>
                            <td class="px-4 py-2 text-center">
                                <a href="?compare=<?= urlencode($dup['code']) ?>" class="text-blue-600 hover:underline">Détails</a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>

        <!-- Options Exclusive Manufaktur -->
        <?php if (!empty($exclusiveOptions)): ?>
        <div class="border border-porsche-border rounded-lg">
            <div class="p-4 border-b border-porsche-border bg-amber-50">
                <h3 class="font-bold text-amber-800">🏷️ Options Exclusive Manufaktur (<?= count($exclusiveOptions) ?>)</h3>
            </div>
            <div class="overflow-x-auto max-h-96 overflow-y-auto">
                <table class="w-full text-sm">
                    <thead class="bg-gray-50 sticky top-0">
                        <tr>
                            <th class="px-4 py-2 text-left">Code</th>
                            <th class="px-4 py-2 text-left">Option</th>
                            <th class="px-4 py-2 text-left">Modèle</th>
                            <th class="px-4 py-2 text-right">Prix</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($exclusiveOptions as $opt): ?>
                        <tr class="border-b border-porsche-border hover:bg-amber-50">
                            <td class="px-4 py-2 font-mono"><?= htmlspecialchars($opt['code']) ?></td>
                            <td class="px-4 py-2"><?= htmlspecialchars($opt['name'] ?? '-') ?></td>
                            <td class="px-4 py-2">
                                <a href="model-detail.php?code=<?= urlencode($opt['model_code']) ?>" class="text-blue-600 hover:underline">
                                    <?= htmlspecialchars($opt['model_name']) ?>
                                </a>
                            </td>
                            <td class="px-4 py-2 text-right"><?= $opt['price'] ? number_format($opt['price'], 0, ',', ' ') . ' €' : '-' ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>
    </main>

    <footer class="border-t border-porsche-border mt-12 py-6 text-center text-gray-400 text-sm">
        Porsche Options Manager v6.2
    </footer>
</body>
</html>