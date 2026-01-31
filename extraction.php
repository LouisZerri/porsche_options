<?php
/**
 * PORSCHE OPTIONS MANAGER - Page d'extraction
 */
require_once 'config.php';

$db = getDB();
$locale = getCurrentLocale();
$lang = getLocaleCode();

// Chemins
$extractorDir = __DIR__ . '/extractor';
$logFile = $extractorDir . '/extraction.log';
$lockFile = $extractorDir . '/extraction.lock';

// Vérifier si une extraction est en cours
function checkIsRunning($lockFile) {
    if (!file_exists($lockFile)) return false;
    $pid = trim(file_get_contents($lockFile));
    if (empty($pid) || !is_numeric($pid)) return false;
    
    // Linux: vérifier si le processus existe
    if (file_exists("/proc/$pid")) {
        return true;
    }
    @unlink($lockFile);
    return false;
}

// API endpoint pour les logs (AJAX)
if (isset($_GET['api'])) {
    header('Content-Type: application/json');

    if ($_GET['api'] === 'logs') {
        $logs = file_exists($logFile) ? file_get_contents($logFile) : '';
        $running = checkIsRunning($lockFile);

        // Si les logs indiquent que c'est terminé, nettoyer le lock
        // Mais attention: si les infobulles sont en cours, ne pas arrêter
        $hasTermine = strpos($logs, 'TERMINÉ') !== false;
        $hasTooltipStart = strpos($logs, 'Extraction des descriptions') !== false;
        $hasTooltipEnd = strpos($logs, 'descriptions extraites') !== false && strpos($logs, 'options mises à jour') !== false;

        // C'est vraiment fini si: TERMINÉ sans infobulles OU infobulles terminées
        $reallyFinished = $hasTermine && (!$hasTooltipStart || $hasTooltipEnd);

        if ($running && $logs && $reallyFinished) {
            @unlink($lockFile);
            $running = false;
        }

        echo json_encode([
            'running' => $running,
            'logs' => $logs,
            'timestamp' => time()
        ]);
        exit;
    }
    
    if ($_GET['api'] === 'stats') {
        try {
            $models = $db->query("SELECT COUNT(*) FROM p_models")->fetchColumn();
            $options = $db->query("SELECT COUNT(*) FROM p_options")->fetchColumn();
            echo json_encode(['models' => $models, 'options' => $options]);
        } catch (Exception $e) {
            echo json_encode(['models' => 0, 'options' => 0]);
        }
        exit;
    }
    exit;
}

$isRunning = checkIsRunning($lockFile);
$message = null;
$messageType = null;

// Actions POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    // Actions toujours autorisées (même si extraction en cours)
    if ($action === 'clear') {
        file_put_contents($logFile, '');
        $message = "Console vidée";
        $messageType = "success";
    } elseif ($action === 'stop') {
        if (file_exists($lockFile)) {
            $pid = trim(file_get_contents($lockFile));
            if ($pid) shell_exec("kill $pid 2>/dev/null");
            @unlink($lockFile);
        }
        file_put_contents($logFile, "⏹️ Extraction arrêtée.\n", FILE_APPEND);
        $message = "Extraction arrêtée";
        $messageType = "warning";
        $isRunning = false;
    } elseif (!$isRunning) {
        // Actions seulement si pas d'extraction en cours
        
        // Vider le log au début des nouvelles actions
        @file_put_contents($logFile, '');
        
        // Vérifier que node existe
        $nodePath = trim(shell_exec('which node 2>/dev/null') ?: '');
        if (empty($nodePath)) {
            $nodePath = '/usr/bin/node';
        }
        
        if ($action === 'init_db') {
            $cmd = sprintf('cd %s && %s porsche_options_extractor.js --init 2>&1', 
                escapeshellarg($extractorDir), $nodePath);
            $output = shell_exec($cmd);
            file_put_contents($logFile, "Commande: $cmd\n\n" . $output);
            $message = "Initialisation terminée";
            $messageType = "success";
            
        } elseif ($action === 'extract_model' && !empty($_POST['model'])) {
            $model = $_POST['model'];
            $fetchTooltips = isset($_POST['fetch_tooltips']) && $_POST['fetch_tooltips'] === '1';
            $extractLocale = $_POST['extract_locale'] ?? 'fr-FR';
            // Mode ASYNCHRONE avec logs en temps réel pour la progress bar
            $tooltipFlag = $fetchTooltips ? ' --fetch-tooltips' : '';
            $localeFlag = " --locale " . escapeshellarg($extractLocale);

            // Vider le log
            file_put_contents($logFile, "");

            // Vérifier si stdbuf est disponible pour les logs temps réel
            $stdbuf = trim(shell_exec('which stdbuf 2>/dev/null') ?: '');
            $stdbufPrefix = $stdbuf ? 'stdbuf -oL ' : '';

            // Lancer en arrière-plan
            $cmd = sprintf('cd %s && %s%s porsche_options_extractor.js --model %s%s%s >> %s 2>&1 & echo $!',
                escapeshellarg($extractorDir), $stdbufPrefix, $nodePath, escapeshellarg($model), $localeFlag, $tooltipFlag, escapeshellarg($logFile));
            $pid = trim(shell_exec($cmd));

            if ($pid && is_numeric($pid)) {
                file_put_contents($lockFile, $pid);
                $isRunning = true;
                $message = "Extraction lancée pour $model ($extractLocale)";
                $messageType = "success";
            } else {
                file_put_contents($logFile, "Erreur lancement commande:\n$cmd\n\nPID retourné: $pid");
                $message = "Erreur lors du lancement";
                $messageType = "error";
            }
            
        } elseif ($action === 'purge') {
            try {
                $db->exec("SET FOREIGN_KEY_CHECKS = 0");
                $db->exec("TRUNCATE TABLE p_options");
                $db->exec("TRUNCATE TABLE p_models");
                $db->exec("TRUNCATE TABLE p_categories");
                $db->exec("TRUNCATE TABLE p_families");
                $db->exec("SET FOREIGN_KEY_CHECKS = 1");
                // Vider aussi les logs et le lock
                file_put_contents($logFile, "🗑️ Toutes les données ont été supprimées.\n");
                @unlink($lockFile);
                $message = "Données supprimées";
                $messageType = "success";
            } catch (Exception $e) {
                $message = "Erreur: " . $e->getMessage();
                $messageType = "error";
            }
            
        } elseif ($action === 'test') {
            // Test de diagnostic
            $output = "=== DIAGNOSTIC ===\n\n";
            $output .= "📁 Dossier extracteur: $extractorDir\n";
            $output .= "📄 Script existe: " . (file_exists($extractorDir . '/porsche_options_extractor.js') ? '✅ Oui' : '❌ Non') . "\n";
            $output .= "📦 node_modules existe: " . (is_dir($extractorDir . '/node_modules') ? '✅ Oui' : '❌ Non (faire npm install)') . "\n";
            $output .= "📦 browsers existe: " . (is_dir($extractorDir . '/browsers') ? '✅ Oui' : '❌ Non (faire npm run setup)') . "\n";
            $output .= "🔧 Node.js path: $nodePath\n";
            $output .= "🔧 Node.js version: " . trim(shell_exec("$nodePath --version 2>&1") ?: 'Non trouvé') . "\n";
            $output .= "👤 Utilisateur PHP: " . trim(shell_exec('whoami') ?: 'inconnu') . "\n";
            $output .= "\n=== TEST NODE ===\n\n";
            $output .= shell_exec("cd $extractorDir && $nodePath -e \"console.log('Node.js fonctionne!')\" 2>&1") ?: "Erreur Node.js";
            file_put_contents($logFile, $output);
            $message = "Diagnostic effectué";
            $messageType = "success";
        }
    }
}

// Liste des modèles - récupérée depuis la base de données
$modelsInDb = [];
try {
    $stmt = $db->query("
        SELECT m.code, m.name, f.name as family, 
               (m.options_count + m.colors_ext_count + m.colors_int_count) as total_count 
        FROM p_models m 
        LEFT JOIN p_families f ON m.family_id = f.id 
        ORDER BY f.name, m.name
    ");
    $modelsInDb = $stmt->fetchAll();
} catch (Exception $e) {
    // Table n'existe pas encore
}
?>
<!DOCTYPE html>
<html lang="<?= $lang ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Extraction - Porsche Options Manager</title>
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
                    <p class="text-gray-500 text-sm">Extraction des données</p>
                </div>
            </div>
            <nav class="flex items-center gap-6 text-sm">
                <a href="index.php<?= langParam() ?>" class="text-gray-600 hover:text-black transition">Dashboard</a>
                <a href="models.php<?= langParam() ?>" class="text-gray-600 hover:text-black transition">Modèles</a>
                <a href="options.php<?= langParam() ?>" class="text-gray-600 hover:text-black transition">Options</a>
                <a href="extraction.php<?= langParam() ?>" class="text-black font-medium">Extraction</a>
                <!-- Sélecteur de langue -->
                <div class="flex items-center gap-1 ml-4 border-l border-gray-300 pl-4">
                    <a href="?lang=fr" class="px-2 py-1 rounded text-xs font-bold <?= $lang === 'fr' ? 'bg-black text-white' : 'text-gray-500 hover:text-black' ?>">FR</a>
                    <a href="?lang=de" class="px-2 py-1 rounded text-xs font-bold <?= $lang === 'de' ? 'bg-black text-white' : 'text-gray-500 hover:text-black' ?>">DE</a>
                </div>
            </nav>
        </div>
    </header>

    <main class="max-w-7xl mx-auto px-6 py-8">
        
        <?php if ($message): ?>
        <div class="mb-6 p-4 rounded-lg <?= $messageType === 'success' ? 'bg-green-50 border border-green-200 text-green-800' : ($messageType === 'error' ? 'bg-red-50 border border-red-200 text-red-800' : 'bg-yellow-50 border border-yellow-200 text-yellow-800') ?>">
            <?= htmlspecialchars($message) ?>
        </div>
        <?php endif; ?>

        <!-- Status Bar -->
        <div id="statusBar" class="mb-6 p-4 rounded-lg border border-porsche-border bg-porsche-gray">
            <div class="flex items-center justify-between mb-3">
                <div class="flex items-center gap-4">
                    <div id="statusIndicator" class="w-3 h-3 rounded-full <?= $isRunning ? 'bg-yellow-500 animate-pulse' : 'bg-gray-400' ?>"></div>
                    <span id="statusText"><?= $isRunning ? 'Extraction en cours...' : 'En attente' ?></span>
                </div>
                <div id="statsDisplay" class="text-sm text-gray-500">
                    Chargement...
                </div>
            </div>
            <!-- Progress Bar -->
            <div id="progressContainer" class="hidden">
                <div class="flex items-center justify-between text-xs text-gray-500 mb-1">
                    <span id="progressStep">Initialisation...</span>
                    <span id="progressPercent">0%</span>
                </div>
                <div class="w-full bg-gray-300 rounded-full h-3 overflow-hidden">
                    <div id="progressBar" class="bg-porsche-red h-3 rounded-full transition-all duration-500 ease-out" style="width: 0%"></div>
                </div>
                <div class="flex justify-between text-xs text-gray-400 mt-1">
                    <span>Début</span>
                    <span id="progressDetails" class="text-gray-500"></span>
                    <span>Fin</span>
                </div>
            </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-6">
            <!-- Actions -->
            <div class="lg:col-span-1 space-y-4">
                <!-- Init DB -->
                <div class="border border-porsche-border rounded-lg p-4">
                    <h3 class="font-bold mb-3">Base de données</h3>
                    <form method="POST" class="space-y-2">
                        <input type="hidden" name="action" value="init_db">
                        <button type="submit" class="w-full bg-black hover:bg-gray-800 text-white py-2 rounded transition text-sm" <?= $isRunning ? 'disabled' : '' ?>>
                            Initialiser les tables
                        </button>
                    </form>
                    <form method="POST" class="mt-2">
                        <input type="hidden" name="action" value="test">
                        <button type="submit" class="w-full border border-porsche-border hover:bg-gray-50 py-2 rounded transition text-sm">
                            Diagnostic
                        </button>
                    </form>
                </div>

                <!-- Extraction un modèle -->
                <div class="border border-porsche-border rounded-lg p-4">
                    <h3 class="font-bold mb-3">Extraire un modèle</h3>
                    <form method="POST">
                        <input type="hidden" name="action" value="extract_model">
                        <input type="text" name="model" required
                               placeholder="Code modèle (ex: 982850)"
                               pattern="[A-Za-z0-9]+"
                               class="w-full border border-porsche-border rounded px-3 py-2 mb-2 text-sm font-mono uppercase focus:outline-none focus:border-black"
                               <?= $isRunning ? 'disabled' : '' ?>>
                        <p class="text-xs text-gray-500 mb-3">
                            Trouvez le code dans l'URL du configurateur Porsche<br>
                            Ex: configurator.porsche.com/.../model/<strong>982850</strong>
                        </p>
                        <!-- Sélecteur de langue d'extraction -->
                        <div class="mb-3">
                            <label class="block text-xs text-gray-500 uppercase tracking-wide mb-1">Langue d'extraction</label>
                            <div class="flex gap-2">
                                <label class="flex-1 cursor-pointer">
                                    <input type="radio" name="extract_locale" value="fr-FR" checked class="sr-only peer" <?= $isRunning ? 'disabled' : '' ?>>
                                    <div class="text-center py-2 px-3 border border-porsche-border rounded peer-checked:bg-black peer-checked:text-white peer-checked:border-black transition text-sm">
                                        🇫🇷 Français
                                    </div>
                                </label>
                                <label class="flex-1 cursor-pointer">
                                    <input type="radio" name="extract_locale" value="de-DE" class="sr-only peer" <?= $isRunning ? 'disabled' : '' ?>>
                                    <div class="text-center py-2 px-3 border border-porsche-border rounded peer-checked:bg-black peer-checked:text-white peer-checked:border-black transition text-sm">
                                        🇩🇪 Allemand
                                    </div>
                                </label>
                            </div>
                        </div>
                        <label class="flex items-center gap-2 mb-3 cursor-pointer">
                            <input type="checkbox" name="fetch_tooltips" value="1"
                                   class="w-4 h-4 accent-porsche-red rounded border-porsche-border"
                                   <?= $isRunning ? 'disabled' : '' ?>>
                            <span class="text-sm text-gray-700">Extraire les infobulles (descriptions)</span>
                        </label>
                        <button type="submit" class="w-full bg-porsche-red hover:bg-red-700 text-white py-2 rounded transition text-sm" <?= $isRunning ? 'disabled' : '' ?>>
                            Extraire
                        </button>
                    </form>
                </div>

                <!-- Extraction plusieurs modèles -->
                <div class="border border-porsche-border rounded-lg p-4">
                    <h3 class="font-bold mb-3">Extraire plusieurs modèles</h3>
                    <form method="POST">
                        <input type="hidden" name="action" value="extract_model">
                        <input type="text" name="model" required
                               placeholder="CODE1,CODE2,CODE3"
                               class="w-full border border-porsche-border rounded px-3 py-2 mb-2 text-sm font-mono uppercase focus:outline-none focus:border-black"
                               <?= $isRunning ? 'disabled' : '' ?>>
                        <p class="text-xs text-gray-500 mb-3">
                            Séparez les codes par des virgules
                        </p>
                        <!-- Sélecteur de langue d'extraction -->
                        <div class="mb-3">
                            <div class="flex gap-2">
                                <label class="flex-1 cursor-pointer">
                                    <input type="radio" name="extract_locale" value="fr-FR" checked class="sr-only peer" <?= $isRunning ? 'disabled' : '' ?>>
                                    <div class="text-center py-2 px-3 border border-porsche-border rounded peer-checked:bg-black peer-checked:text-white peer-checked:border-black transition text-sm">
                                        🇫🇷 FR
                                    </div>
                                </label>
                                <label class="flex-1 cursor-pointer">
                                    <input type="radio" name="extract_locale" value="de-DE" class="sr-only peer" <?= $isRunning ? 'disabled' : '' ?>>
                                    <div class="text-center py-2 px-3 border border-porsche-border rounded peer-checked:bg-black peer-checked:text-white peer-checked:border-black transition text-sm">
                                        🇩🇪 DE
                                    </div>
                                </label>
                            </div>
                        </div>
                        <button type="submit" class="w-full bg-black hover:bg-gray-800 text-white py-2 rounded transition text-sm" <?= $isRunning ? 'disabled' : '' ?>>
                            Extraire tous
                        </button>
                    </form>
                </div>

                <!-- Arrêter / Purger -->
                <div class="border border-red-200 rounded-lg p-4 bg-red-50">
                    <h3 class="font-bold mb-3 text-red-700">Actions dangereuses</h3>
                    <div class="space-y-2">
                        <?php if ($isRunning): ?>
                        <form method="POST">
                            <input type="hidden" name="action" value="stop">
                            <button type="submit" class="w-full bg-yellow-500 hover:bg-yellow-600 text-white py-2 rounded transition text-sm">
                                Arrêter l'extraction
                            </button>
                        </form>
                        <?php endif; ?>
                        <form method="POST" onsubmit="return confirm('Supprimer TOUTES les données ?');">
                            <input type="hidden" name="action" value="purge">
                            <button type="submit" class="w-full bg-red-600 hover:bg-red-700 text-white py-2 rounded transition text-sm" <?= $isRunning ? 'disabled' : '' ?>>
                                Purger les données
                            </button>
                        </form>
                    </div>
                </div>

                <!-- Commandes Terminal -->
                <div class="border border-porsche-border rounded-lg p-4">
                    <h3 class="font-bold mb-3">Terminal SSH</h3>
                    <p class="text-gray-500 text-xs mb-2">Commandes à exécuter :</p>
                    <div class="bg-gray-900 rounded p-3 font-mono text-xs text-green-400 space-y-1">
                        <p>cd extractor</p>
                        <p>node porsche_options_extractor.js --init</p>
                        <p class="text-gray-500"># Extraction FR (défaut):</p>
                        <p>node porsche_options_extractor.js --model 982850</p>
                        <p class="text-gray-500"># Extraction DE:</p>
                        <p>node porsche_options_extractor.js --model 982850 --locale de-DE</p>
                        <p class="text-gray-500"># Avec infobulles:</p>
                        <p>node porsche_options_extractor.js --model 982850 --fetch-tooltips</p>
                    </div>
                </div>

                <!-- Modèles en base -->
                <?php if (!empty($modelsInDb)): ?>
                <div class="border border-porsche-border rounded-lg p-4">
                    <h3 class="font-bold mb-3">Modèles en base (<?= count($modelsInDb) ?>)</h3>
                    <div class="max-h-48 overflow-y-auto text-xs">
                        <?php $i = 0; foreach ($modelsInDb as $m): ?>
                        <div class="flex justify-between py-2 px-2 rounded <?= $i % 2 ? 'bg-porsche-gray' : 'bg-white' ?>">
                            <span class="font-mono text-gray-500"><?= htmlspecialchars($m['code']) ?></span>
                            <span class="truncate mx-2"><?= htmlspecialchars($m['name']) ?></span>
                            <span class="text-green-600 font-medium"><?= $m['total_count'] ?></span>
                        </div>
                        <?php $i++; endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>

            <!-- Console -->
            <div class="lg:col-span-2">
                <div class="border border-porsche-border rounded-lg h-full flex flex-col">
                    <div class="p-4 border-b border-porsche-border flex items-center justify-between bg-porsche-gray">
                        <h3 class="font-bold">Console</h3>
                        <form method="POST" class="m-0">
                            <input type="hidden" name="action" value="clear">
                            <button type="submit" class="text-xs text-gray-500 hover:text-black border border-porsche-border hover:bg-white px-3 py-1 rounded transition">
                                Vider
                            </button>
                        </form>
                    </div>
                    <div id="console" class="flex-1 bg-gray-900 p-4 font-mono text-sm overflow-y-auto rounded-b-lg" style="min-height: 500px; max-height: 70vh;">
                        <pre id="logContent" class="text-green-400 whitespace-pre-wrap"><?= htmlspecialchars(file_exists($logFile) ? file_get_contents($logFile) : 'En attente de logs...') ?></pre>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <footer class="border-t border-porsche-border mt-12 py-6 text-center text-gray-400 text-sm">
        Porsche Options Manager v6.4 - Progress Bar
    </footer>

    <script>
        let isRunning = <?= $isRunning ? 'true' : 'false' ?>;
        let lastLogLength = 0;

        // Étapes de progression avec leurs mots-clés et pourcentages
        const progressSteps = [
            { keyword: 'Lancement:', percent: 5, step: 'Lancement de l\'extraction...' },
            { keyword: 'Chargement...', percent: 8, step: 'Chargement du configurateur...' },
            { keyword: 'Trouvé avec année', percent: 12, step: 'Modèle trouvé' },
            { keyword: 'données techniques', percent: 20, step: 'Extraction des données techniques...' },
            { keyword: 'équipements de série', percent: 28, step: 'Extraction des équipements de série...' },
            { keyword: 'Déploiement des sections', percent: 35, step: 'Déploiement des sections...' },
            { keyword: 'Scan des images', percent: 42, step: 'Scan des images...' },
            { keyword: 'Extraction des options', percent: 50, step: 'Extraction des options...' },
            { keyword: 'Récupération des prix par clic', percent: 55, step: 'Récupération des prix...' },
            { keyword: 'DEBUG - Vérification', percent: 58, step: 'Vérification de l\'extraction...' },
            { keyword: 'RÉSUMÉ PAR TYPE', percent: 62, step: 'Génération du résumé...' },
            { keyword: 'Sauvegarde...', percent: 65, step: 'Sauvegarde en base de données...' },
            { keyword: 'TERMINÉ:', percent: 68, step: 'Options extraites...' },
            // Étapes infobulles (si activées)
            { keyword: 'Extraction des descriptions', percent: 70, step: 'Extraction des infobulles...' },
            { keyword: 'descriptions extraites', percent: 95, step: 'Infobulles extraites...' },
            { keyword: 'options mises à jour', percent: 100, step: 'Extraction terminée !' }
        ];

        // Analyser les logs pour déterminer la progression
        function parseProgress(logs) {
            if (!logs || logs.length === 0) {
                return { percent: 0, step: 'En attente...', details: '' };
            }

            let currentPercent = 0;
            let currentStep = 'Initialisation...';
            let details = '';

            // Vérifier si vraiment terminé (prioritaire)
            const hasTermine = logs.includes('TERMINÉ');
            const hasTooltipStart = logs.includes('Extraction des descriptions');
            const hasTooltipEnd = logs.includes('options mises à jour');

            if (hasTermine && (!hasTooltipStart || hasTooltipEnd)) {
                return { percent: 100, step: 'Extraction terminée !', details: '' };
            }

            // Trouver l'étape la plus avancée
            for (const step of progressSteps) {
                if (logs.includes(step.keyword)) {
                    currentPercent = step.percent;
                    currentStep = step.step;
                }
            }

            // Extraire des détails supplémentaires
            const modelMatch = logs.match(/📋 ([^\n]+)/);
            if (modelMatch) {
                details = modelMatch[1];
            }

            // Compter les options extraites
            const optionsMatch = logs.match(/📊 (\d+) éléments extraits/);
            if (optionsMatch) {
                details = `${optionsMatch[1]} éléments extraits`;
            }

            // Extraction en cours - compter les lignes de progression
            if (currentPercent >= 50 && currentPercent < 68) {
                const inputsMatch = logs.match(/Inputs options: (\d+)/);
                if (inputsMatch) {
                    details = `${inputsMatch[1]} options détectées`;
                }
            }

            // Progression des infobulles (⏳ 50/105 (10 descriptions))
            const tooltipMatch = logs.match(/⏳ (\d+)\/(\d+) \((\d+) descriptions?\)/g);
            if (tooltipMatch) {
                const lastMatch = tooltipMatch[tooltipMatch.length - 1];
                const nums = lastMatch.match(/(\d+)\/(\d+) \((\d+)/);
                if (nums) {
                    const current = parseInt(nums[1]);
                    const total = parseInt(nums[2]);
                    const found = parseInt(nums[3]);
                    details = `${current}/${total} options (${found} descriptions)`;
                    // Calculer le pourcentage entre 70% et 95%
                    if (current > 0 && total > 0) {
                        const tooltipProgress = (current / total) * 25; // 25% de plage (70-95)
                        currentPercent = Math.round(70 + tooltipProgress); // Arrondi
                        currentStep = `Extraction des infobulles... (${Math.round(current/total*100)}%)`;
                    }
                }
            }

            // Arrondir le pourcentage final
            currentPercent = Math.round(currentPercent);

            return { percent: currentPercent, step: currentStep, details: details };
        }

        // Mettre à jour l'affichage de la progression
        function updateProgressBar(percent, step, details) {
            const container = document.getElementById('progressContainer');
            const bar = document.getElementById('progressBar');
            const stepEl = document.getElementById('progressStep');
            const percentEl = document.getElementById('progressPercent');
            const detailsEl = document.getElementById('progressDetails');

            if (percent > 0) {
                container.classList.remove('hidden');
                bar.style.width = percent + '%';
                stepEl.textContent = step;
                percentEl.textContent = percent + '%';
                detailsEl.textContent = details;

                // Couleur selon l'état
                if (percent === 100) {
                    bar.classList.remove('bg-porsche-red');
                    bar.classList.add('bg-green-500');
                } else {
                    bar.classList.remove('bg-green-500');
                    bar.classList.add('bg-porsche-red');
                }
            } else {
                container.classList.add('hidden');
            }
        }

        // Polling des logs
        function fetchLogs() {
            fetch('?api=logs')
                .then(r => r.json())
                .then(data => {
                    // Mettre à jour les logs
                    const logEl = document.getElementById('logContent');
                    if (data.logs && data.logs.length > 0) {
                        logEl.textContent = data.logs;
                        // Auto-scroll si nouveaux logs
                        if (data.logs.length > lastLogLength) {
                            const console = document.getElementById('console');
                            console.scrollTop = console.scrollHeight;
                            lastLogLength = data.logs.length;
                        }

                        // Mettre à jour la barre de progression
                        const progress = parseProgress(data.logs);
                        updateProgressBar(progress.percent, progress.step, progress.details);
                    }

                    // Mettre à jour le statut
                    const indicator = document.getElementById('statusIndicator');
                    const text = document.getElementById('statusText');

                    // Détecter si vraiment terminé (TERMINÉ sans infobulles OU infobulles terminées)
                    const hasTermine = data.logs && data.logs.includes('TERMINÉ');
                    const hasTooltipStart = data.logs && data.logs.includes('Extraction des descriptions');
                    const hasTooltipEnd = data.logs && data.logs.includes('options mises à jour');
                    const reallyFinished = hasTermine && (!hasTooltipStart || hasTooltipEnd);

                    if (data.running) {
                        indicator.className = 'w-3 h-3 rounded-full bg-yellow-400 animate-pulse';
                        text.textContent = '⏳ Extraction en cours...';
                        isRunning = true;
                    } else if (reallyFinished) {
                        indicator.className = 'w-3 h-3 rounded-full bg-green-500';
                        text.textContent = '✅ Terminé';
                        isRunning = false;
                    } else {
                        indicator.className = 'w-3 h-3 rounded-full bg-gray-400';
                        text.textContent = 'En attente';
                        isRunning = false;
                    }
                })
                .catch(err => console.error('Erreur logs:', err));
        }
        
        // Stats
        function fetchStats() {
            fetch('?api=stats')
                .then(r => r.json())
                .then(data => {
                    document.getElementById('statsDisplay').textContent = 
                        `${data.models} modèles | ${data.options} options`;
                })
                .catch(err => {});
        }
        
        // Polling - plus rapide pour une meilleure réactivité
        setInterval(() => {
            fetchLogs();
            fetchStats();
        }, 1000);

        // Initial fetch + affichage progression si logs existants
        fetchLogs();
        fetchStats();

        // Afficher la progression initiale si des logs existent
        const initialLogs = document.getElementById('logContent').textContent;
        if (initialLogs && initialLogs.length > 50) {
            const progress = parseProgress(initialLogs);
            updateProgressBar(progress.percent, progress.step, progress.details);
        }
    </script>
</body>
</html>