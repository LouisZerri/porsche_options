<?php
/**
 * PORSCHE OPTIONS MANAGER - Interface Web
 */
require_once 'config.php';

$db = getDB();

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

    // Modèles par famille
    $families = $db->query("
        SELECT f.id, f.code, f.name, COUNT(m.id) as model_count, SUM(m.options_count) as options_count
        FROM p_families f
        LEFT JOIN p_models m ON m.family_id = f.id
        GROUP BY f.id
        ORDER BY f.name
    ")->fetchAll();

    // Derniers modèles extraits
    $recentModels = $db->query("
        SELECT m.*, f.name as family_name
        FROM p_models m
        LEFT JOIN p_families f ON m.family_id = f.id
        ORDER BY m.last_updated DESC
        LIMIT 10
    ")->fetchAll();
} catch (PDOException $e) {
    // Tables n'existent pas encore
}

$isRunning = isExtractionRunning();
?>
<!DOCTYPE html>
<html lang="fr">
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
                    }
                }
            }
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
                <a href="index.php" class="text-white hover:text-porsche-red transition">Dashboard</a>
                <a href="models.php" class="text-gray-400 hover:text-white transition">Modèles</a>
                <a href="options.php" class="text-gray-400 hover:text-white transition">Options</a>
                <a href="extraction.php" class="bg-porsche-red hover:bg-red-700 px-4 py-2 rounded-lg transition">
                    🚀 Extraction
                </a>
            </nav>
        </div>
    </header>

    <main class="max-w-7xl mx-auto px-4 py-8">
        <!-- Status -->
        <?php if ($isRunning): ?>
        <div class="bg-yellow-500/20 border border-yellow-500/50 text-yellow-400 px-4 py-3 rounded-lg mb-6 flex items-center gap-3">
            <div class="w-3 h-3 bg-yellow-400 rounded-full animate-pulse"></div>
            <span>Extraction en cours...</span>
            <a href="extraction.php" class="ml-auto underline">Voir le statut</a>
        </div>
        <?php endif; ?>

        <!-- Stats Cards -->
        <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-8">
            <div class="bg-gray-800 rounded-xl p-6 border border-gray-700">
                <p class="text-gray-400 text-sm">Familles</p>
                <p class="text-3xl font-bold text-blue-400"><?= $stats['families'] ?></p>
            </div>
            <div class="bg-gray-800 rounded-xl p-6 border border-gray-700">
                <p class="text-gray-400 text-sm">Modèles</p>
                <p class="text-3xl font-bold text-green-400"><?= $stats['models'] ?></p>
            </div>
            <div class="bg-gray-800 rounded-xl p-6 border border-gray-700">
                <p class="text-gray-400 text-sm">Options</p>
                <p class="text-3xl font-bold text-purple-400"><?= number_format($stats['options'], 0, ',', ' ') ?></p>
            </div>
            <div class="bg-gray-800 rounded-xl p-6 border border-gray-700">
                <p class="text-gray-400 text-sm">Catégories</p>
                <p class="text-3xl font-bold text-orange-400"><?= $stats['categories'] ?></p>
            </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">
            <!-- Familles -->
            <div class="bg-gray-800 rounded-xl border border-gray-700">
                <div class="p-4 border-b border-gray-700">
                    <h2 class="text-lg font-semibold">Familles de modèles</h2>
                </div>
                <div class="p-4">
                    <?php if (empty($families)): ?>
                        <p class="text-gray-500 text-center py-8">Aucune donnée. Lancez une extraction.</p>
                    <?php else: ?>
                        <div class="space-y-3">
                            <?php foreach ($families as $family): ?>
                            <a href="models.php?family=<?= $family['id'] ?>" 
                               class="flex items-center justify-between p-3 rounded-lg hover:bg-gray-700 transition">
                                <div>
                                    <span class="font-medium"><?= htmlspecialchars($family['name']) ?></span>
                                    <span class="text-gray-500 text-sm ml-2"><?= $family['model_count'] ?> modèles</span>
                                </div>
                                <span class="text-blue-400"><?= number_format($family['options_count'] ?? 0, 0, ',', ' ') ?> options</span>
                            </a>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Derniers modèles -->
            <div class="bg-gray-800 rounded-xl border border-gray-700">
                <div class="p-4 border-b border-gray-700">
                    <h2 class="text-lg font-semibold">Dernières extractions</h2>
                </div>
                <div class="p-4">
                    <?php if (empty($recentModels)): ?>
                        <p class="text-gray-500 text-center py-8">Aucune extraction effectuée.</p>
                    <?php else: ?>
                        <div class="space-y-2">
                            <?php foreach ($recentModels as $model): ?>
                            <a href="model-detail.php?code=<?= $model['code'] ?>" 
                               class="flex items-center justify-between p-3 rounded-lg hover:bg-gray-700 transition">
                                <div>
                                    <span class="font-medium"><?= htmlspecialchars($model['name']) ?></span>
                                    <span class="text-gray-500 text-sm block"><?= htmlspecialchars($model['family_name'] ?? '') ?></span>
                                </div>
                                <div class="text-right">
                                    <span class="text-green-400"><?= $model['options_count'] ?> options</span>
                                    <span class="text-gray-500 text-xs block">
                                        <?= $model['last_updated'] ? date('d/m/Y H:i', strtotime($model['last_updated'])) : '' ?>
                                    </span>
                                </div>
                            </a>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Quick Actions -->
        <div class="mt-8 bg-gray-800 rounded-xl border border-gray-700 p-6">
            <h2 class="text-lg font-semibold mb-4">Actions rapides</h2>
            <div class="flex flex-wrap gap-4">
                <a href="extraction.php" class="bg-porsche-red hover:bg-red-700 px-6 py-3 rounded-lg transition flex items-center gap-2">
                    🚀 Lancer une extraction
                </a>
                <a href="options.php" class="bg-gray-700 hover:bg-gray-600 px-6 py-3 rounded-lg transition flex items-center gap-2">
                    🔍 Rechercher une option
                </a>
            </div>
        </div>
    </main>

    <footer class="border-t border-gray-800 mt-12 py-6 text-center text-gray-500 text-sm">
        Porsche Options Manager &copy; <?= date('Y') ?>
    </footer>
</body>
</html>