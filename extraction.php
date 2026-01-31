<?php
/**
 * PORSCHE OPTIONS MANAGER - Page d'extraction
 */
require_once 'config.php';

$db = getDB();

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
            // Mode SYNCHRONE pour voir le résultat directement
            $tooltipFlag = $fetchTooltips ? ' --fetch-tooltips' : '';
            $cmd = sprintf('cd %s && %s porsche_options_extractor.js --model %s%s 2>&1',
                escapeshellarg($extractorDir), $nodePath, escapeshellarg($model), $tooltipFlag);
            
            file_put_contents($logFile, "🚀 Lancement: $cmd\n\n");
            
            // Exécuter et capturer la sortie
            $output = shell_exec($cmd);
            file_put_contents($logFile, $output, FILE_APPEND);
            
            $message = "Extraction terminée pour $model";
            $messageType = "success";
            
        } elseif ($action === 'extract_model_async' && !empty($_POST['model'])) {
            $model = $_POST['model'];
            $fetchTooltips = isset($_POST['fetch_tooltips']) && $_POST['fetch_tooltips'] === '1';
            // Mode ASYNCHRONE (arrière-plan)
            $tooltipFlag = $fetchTooltips ? ' --fetch-tooltips' : '';
            $cmd = sprintf('cd %s && %s porsche_options_extractor.js --model %s%s > %s 2>&1 & echo $!',
                escapeshellarg($extractorDir), $nodePath, escapeshellarg($model), $tooltipFlag, escapeshellarg($logFile));
            $pid = trim(shell_exec($cmd));
            if ($pid && is_numeric($pid)) {
                file_put_contents($lockFile, $pid);
                $isRunning = true;
                $message = "Extraction lancée en arrière-plan (PID: $pid)";
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
                file_put_contents($logFile, "🗑️ Toutes les données ont été supprimées.\n");
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
<html lang="fr">
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
                <a href="index.php" class="text-gray-600 hover:text-black transition">Dashboard</a>
                <a href="models.php" class="text-gray-600 hover:text-black transition">Modèles</a>
                <a href="options.php" class="text-gray-600 hover:text-black transition">Options</a>
                <a href="extraction.php" class="text-black font-medium">Extraction</a>
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
        <div id="statusBar" class="mb-6 p-4 rounded-lg border border-porsche-border bg-porsche-gray flex items-center justify-between">
            <div class="flex items-center gap-4">
                <div id="statusIndicator" class="w-3 h-3 rounded-full <?= $isRunning ? 'bg-yellow-500 animate-pulse' : 'bg-gray-400' ?>"></div>
                <span id="statusText"><?= $isRunning ? 'Extraction en cours...' : 'En attente' ?></span>
            </div>
            <div id="statsDisplay" class="text-sm text-gray-500">
                Chargement...
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
                        <p>node porsche_options_extractor.js --model 982850</p>
                        <p class="text-gray-500"># Avec infobulles:</p>
                        <p>node porsche_options_extractor.js --model 982850 --fetch-tooltips</p>
                        <p class="text-gray-500"># Avec debug images:</p>
                        <p>node porsche_options_extractor.js --model 982850 --debug-img</p>
                        <p>node porsche_options_extractor.js --list</p>
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
        Porsche Options Manager v6.0 - Complete Overhaul
    </footer>

    <script>
        let isRunning = <?= $isRunning ? 'true' : 'false' ?>;
        let lastLogLength = 0;
        
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
                    }
                    
                    // Mettre à jour le statut
                    const indicator = document.getElementById('statusIndicator');
                    const text = document.getElementById('statusText');
                    
                    if (data.running) {
                        indicator.className = 'w-3 h-3 rounded-full bg-yellow-400 animate-pulse';
                        text.textContent = '⏳ Extraction en cours...';
                        isRunning = true;
                    } else if (data.logs && (data.logs.includes('options extraites') || data.logs.includes('Extraction terminée') || data.logs.includes('descriptions extraites') || data.logs.includes('mises à jour'))) {
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
        
        // Polling
        setInterval(() => {
            fetchLogs();
            fetchStats();
        }, 2000);
        
        // Initial fetch
        fetchLogs();
        fetchStats();
    </script>
</body>
</html>