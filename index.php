<?php
/**
 * PORSCHE OPTIONS MANAGER - Interface Web
 */
require_once 'config.php';

$db = getDB();
$locale = getCurrentLocale();
$lang = getLocaleCode();

// Statistiques
$stats = ['families' => 0, 'models' => 0, 'options' => 0, 'categories' => 0];
$families = [];
$recentModels = [];

try {
    $stats = [
        'families' => $db->query("SELECT COUNT(*) FROM p_families")->fetchColumn() ?: 0,
        'models' => $db->query("SELECT COUNT(*) FROM p_models")->fetchColumn() ?: 0,
        'options' => $db->query("SELECT COUNT(*) FROM p_options")->fetchColumn() ?: 0,
        'categories' => $db->query("SELECT COUNT(*) FROM p_categories")->fetchColumn() ?: 0,
    ];

    // Modèles par famille (filtré par locale)
    $stmt = $db->prepare("
        SELECT f.id, f.code, f.name, COUNT(m.id) as model_count,
               SUM(m.options_count + m.colors_ext_count + m.colors_int_count) as total_options
        FROM p_families f
        LEFT JOIN p_models m ON m.family_id = f.id AND m.locale = ?
        GROUP BY f.id
        ORDER BY f.name
    ");
    $stmt->execute([$locale]);
    $families = $stmt->fetchAll();

    // Derniers modèles extraits (filtré par locale)
    $stmt = $db->prepare("
        SELECT m.*, f.name as family_name,
               (m.options_count + m.colors_ext_count + m.colors_int_count) as total_count
        FROM p_models m
        LEFT JOIN p_families f ON m.family_id = f.id
        WHERE m.locale = ?
        ORDER BY m.last_updated DESC
        LIMIT 10
    ");
    $stmt->execute([$locale]);
    $recentModels = $stmt->fetchAll();
} catch (PDOException $e) {
    // Tables n'existent pas encore
}

$isRunning = isExtractionRunning();
?>
<!DOCTYPE html>
<html lang="<?= $lang ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Porsche Options Manager</title>
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
    <!-- Header -->
    <header class="bg-white border-b border-porsche-border sticky top-0 z-50">
        <div class="max-w-7xl mx-auto px-6 py-4 flex items-center justify-between">
            <div class="flex items-center gap-4">
                <svg class="w-10 h-10" viewBox="0 0 100 100">
                    <circle cx="50" cy="50" r="48" fill="#d5001c"/>
                    <text x="50" y="62" text-anchor="middle" fill="white" font-size="32" font-weight="bold">P</text>
                </svg>
                <div>
                    <h1 class="text-xl font-bold text-black">Porsche Options Manager</h1>
                    <p class="text-gray-500 text-sm">Gestion des options du configurateur</p>
                </div>
            </div>
             <nav class="flex items-center gap-6 text-sm">
                <a href="index.php<?= langParam() ?>" class="text-gray-600 hover:text-black transition">Dashboard</a>
                <a href="models.php<?= langParam() ?>" class="text-gray-600 hover:text-black transition">Modèles</a>
                <a href="options.php<?= langParam() ?>" class="text-gray-600 hover:text-black transition">Options</a>
                <a href="option-edit.php<?= langParam() ?>" class="text-gray-600 hover:text-black transition">+ Option</a>
                <a href="stats.php<?= langParam() ?>" class="text-gray-600 hover:text-black transition">Stats</a>
                <a href="extraction.php<?= langParam() ?>" class="bg-porsche-red hover:bg-red-700 text-white px-4 py-2 rounded transition">
                    Extraction
                </a>
                <!-- Sélecteur de langue -->
                <div class="flex items-center gap-1 ml-4 border-l border-gray-300 pl-4">
                    <a href="?lang=fr" class="px-2 py-1 rounded text-xs font-bold <?= $lang === 'fr' ? 'bg-black text-white' : 'text-gray-500 hover:text-black' ?>">FR</a>
                    <a href="?lang=de" class="px-2 py-1 rounded text-xs font-bold <?= $lang === 'de' ? 'bg-black text-white' : 'text-gray-500 hover:text-black' ?>">DE</a>
                </div>
            </nav>
        </div>
    </header>

    <main class="max-w-7xl mx-auto px-6 py-8">
        <!-- Status -->
        <?php if ($isRunning): ?>
        <div class="bg-yellow-50 border border-yellow-200 text-yellow-800 px-4 py-3 rounded-lg mb-6 flex items-center gap-3">
            <div class="w-3 h-3 bg-yellow-500 rounded-full animate-pulse"></div>
            <span>Extraction en cours...</span>
            <a href="extraction.php" class="ml-auto text-yellow-700 underline">Voir le statut</a>
        </div>
        <?php endif; ?>

        <!-- Stats Cards -->
        <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-8">
            <div class="bg-porsche-gray rounded-lg p-6">
                <p class="text-xs text-gray-500 uppercase tracking-wide">Familles</p>
                <p class="text-3xl font-bold"><?= $stats['families'] ?></p>
            </div>
            <div class="bg-porsche-gray rounded-lg p-6">
                <p class="text-xs text-gray-500 uppercase tracking-wide">Modèles</p>
                <p class="text-3xl font-bold"><?= $stats['models'] ?></p>
            </div>
            <div class="bg-porsche-gray rounded-lg p-6">
                <p class="text-xs text-gray-500 uppercase tracking-wide">Options</p>
                <p class="text-3xl font-bold"><?= number_format($stats['options'], 0, ',', ' ') ?></p>
            </div>
            <div class="bg-porsche-gray rounded-lg p-6">
                <p class="text-xs text-gray-500 uppercase tracking-wide">Catégories</p>
                <p class="text-3xl font-bold"><?= $stats['categories'] ?></p>
            </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">
            <!-- Familles -->
            <div class="border border-porsche-border rounded-lg">
                <div class="p-4 border-b border-porsche-border">
                    <h2 class="text-lg font-bold">Familles de modèles</h2>
                </div>
                <div class="p-4">
                    <?php if (empty($families)): ?>
                        <p class="text-gray-500 text-center py-8">Aucune donnée. Lancez une extraction.</p>
                    <?php else: ?>
                        <div>
                            <?php $i = 0; foreach ($families as $family): ?>
                            <a href="models.php?family=<?= $family['id'] ?><?= langParamAmp() ?>" 
                               class="flex items-center justify-between py-3 hover:bg-gray-100 transition px-3 rounded <?= $i % 2 ? 'bg-porsche-gray' : 'bg-white' ?>">
                                <div>
                                    <span class="font-medium"><?= htmlspecialchars($family['name']) ?></span>
                                    <span class="text-gray-400 text-sm ml-2"><?= $family['model_count'] ?> modèles</span>
                                </div>
                                <span class="text-gray-600 font-medium"><?= number_format($family['total_options'] ?? 0, 0, ',', ' ') ?> options</span>
                            </a>
                            <?php $i++; endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Derniers modèles -->
            <div class="border border-porsche-border rounded-lg">
                <div class="p-4 border-b border-porsche-border">
                    <h2 class="text-lg font-bold">Dernières extractions</h2>
                </div>
                <div class="p-4">
                    <?php if (empty($recentModels)): ?>
                        <p class="text-gray-500 text-center py-8">Aucune extraction effectuée.</p>
                    <?php else: ?>
                        <div>
                            <?php $i = 0; foreach ($recentModels as $model): ?>
                            <a href="model-detail.php?code=<?= $model['code'] ?><?= langParamAmp() ?>" 
                               class="flex items-center justify-between py-3 hover:bg-gray-100 transition px-3 rounded <?= $i % 2 ? 'bg-porsche-gray' : 'bg-white' ?>">
                                <div>
                                    <span class="font-medium"><?= htmlspecialchars($model['name']) ?></span>
                                    <span class="text-gray-400 text-sm block"><?= htmlspecialchars($model['family_name'] ?? '') ?></span>
                                </div>
                                <div class="text-right">
                                    <span class="font-medium"><?= $model['total_count'] ?> options</span>
                                    <span class="text-gray-400 text-xs block">
                                        <?= $model['last_updated'] ? date('d/m/Y H:i', strtotime($model['last_updated'])) : '' ?>
                                    </span>
                                </div>
                            </a>
                            <?php $i++; endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Quick Actions -->
        <div class="mt-8 border border-porsche-border rounded-lg p-6">
            <h2 class="text-lg font-bold mb-4">Actions rapides</h2>
            <div class="flex flex-wrap gap-4">
                <a href="extraction.php" class="bg-porsche-red hover:bg-red-700 text-white px-6 py-3 rounded transition">
                    Lancer une extraction
                </a>
                <a href="options.php" class="border border-porsche-border hover:bg-gray-50 px-6 py-3 rounded transition">
                    Rechercher une option
                </a>
            </div>
        </div>
    </main>

</body>
</html>