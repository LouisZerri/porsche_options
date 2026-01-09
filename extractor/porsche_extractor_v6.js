/**
 * PORSCHE OPTIONS EXTRACTOR v6.2 - CLIENT FEEDBACK FIX
 * 
 * Corrections v6.2:
 * 1. ✅ Prix véhicule: extraction précise du "Prix de base"
 * 2. ✅ Prix jantes: prix individuels par option (pas prix catégorie)
 * 3. ✅ Prix teintes INT: prix individuels par option
 * 4. ✅ Sièges: extraction des modèles de sièges + options
 * 5. ✅ Sous-catégories: H3 complets stockés pour chaque option
 * 6. ✅ Équipement de série + données techniques
 * 7. ✅ Support extraction DE pour dictionnaire
 */

const mysql = require('mysql2/promise');
const path = require('path');

process.env.PLAYWRIGHT_BROWSERS_PATH = path.join(__dirname, 'browsers');

const { chromium } = require('playwright');

const DB_CONFIG = {
    host: process.env.DB_HOST || 'localhost',
    port: process.env.DB_PORT || 3306,
    user: process.env.DB_USER || 'root',
    password: process.env.DB_PASSWORD || 'root',
    database: process.env.DB_NAME || 'porsche_options',
    charset: 'utf8mb4',
};

const CONFIG = {
    baseUrl: 'https://configurator.porsche.com',
    locale: 'fr-FR',
    timeout: 60000,
};

// ═══════════════════════════════════════════════════════════════════════════════
// BASE DE DONNÉES v6.1
// ═══════════════════════════════════════════════════════════════════════════════

class PorscheDB {
    constructor() { this.pool = null; }
    
    async connect(skipDbSelect = false) {
        if (skipDbSelect) {
            this.pool = mysql.createPool({ 
                host: DB_CONFIG.host, 
                port: DB_CONFIG.port, 
                user: DB_CONFIG.user, 
                password: DB_CONFIG.password, 
                charset: DB_CONFIG.charset,
                waitForConnections: true, 
                connectionLimit: 10 
            });
        } else {
            const tempPool = mysql.createPool({ host: DB_CONFIG.host, port: DB_CONFIG.port, user: DB_CONFIG.user, password: DB_CONFIG.password, charset: DB_CONFIG.charset });
            await tempPool.query(`CREATE DATABASE IF NOT EXISTS \`${DB_CONFIG.database}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci`);
            await tempPool.end();
            this.pool = mysql.createPool({ ...DB_CONFIG, waitForConnections: true, connectionLimit: 10 });
        }
        console.log(`✅ Connecté à MySQL: ${DB_CONFIG.host}`);
    }
    
    async initSchema() {
        console.log('🗑️  Suppression de la base existante...');
        await this.pool.query(`DROP DATABASE IF EXISTS \`${DB_CONFIG.database}\``);
        
        console.log('📦 Création de la base de données...');
        await this.pool.query(`CREATE DATABASE \`${DB_CONFIG.database}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci`);
        await this.pool.query(`USE \`${DB_CONFIG.database}\``);
        
        console.log('📋 Création des tables v6.1...\n');
        
        await this.pool.query(`CREATE TABLE IF NOT EXISTS p_families (
            id INT AUTO_INCREMENT PRIMARY KEY, 
            code VARCHAR(50) UNIQUE NOT NULL, 
            name VARCHAR(100) NOT NULL, 
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4`);
        
        await this.pool.query(`CREATE TABLE IF NOT EXISTS p_models (
            id INT AUTO_INCREMENT PRIMARY KEY, 
            code VARCHAR(20) UNIQUE NOT NULL, 
            name VARCHAR(100) NOT NULL, 
            family_id INT, 
            base_price DECIMAL(10,2), 
            year INT,
            technical_data JSON,
            standard_equipment JSON,
            options_count INT DEFAULT 0, 
            colors_ext_count INT DEFAULT 0, 
            colors_int_count INT DEFAULT 0, 
            last_updated TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, 
            FOREIGN KEY (family_id) REFERENCES p_families(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4`);
        
        await this.pool.query(`CREATE TABLE IF NOT EXISTS p_categories (
            id INT AUTO_INCREMENT PRIMARY KEY, 
            name VARCHAR(150) NOT NULL, 
            parent_name VARCHAR(150),
            sub_category VARCHAR(150),
            slug VARCHAR(150),
            display_order INT DEFAULT 0,
            UNIQUE KEY unique_cat (name, parent_name, sub_category)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4`);
        
        await this.pool.query(`CREATE TABLE IF NOT EXISTS p_options (
            id INT AUTO_INCREMENT PRIMARY KEY, 
            model_id INT NOT NULL, 
            category_id INT,
            code VARCHAR(20) NOT NULL, 
            name VARCHAR(255),
            name_de VARCHAR(255),
            description TEXT,
            price DECIMAL(10,2), 
            is_standard BOOLEAN DEFAULT FALSE,
            is_exclusive_manufaktur BOOLEAN DEFAULT FALSE,
            option_type ENUM('option', 'color_ext', 'color_int', 'wheel', 'seat', 'pack', 'roof', 'hood') DEFAULT 'option',
            sub_category VARCHAR(150),
            image_url VARCHAR(500),
            display_order INT DEFAULT 0,
            UNIQUE KEY unique_model_option (model_id, code), 
            FOREIGN KEY (model_id) REFERENCES p_models(id) ON DELETE CASCADE, 
            FOREIGN KEY (category_id) REFERENCES p_categories(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4`);
        
        console.log('✅ Base de données v6.1 réinitialisée avec succès !');
    }
    
    async getOrCreateFamily(code, name) {
        const [existing] = await this.pool.query('SELECT id FROM p_families WHERE code = ?', [code]);
        if (existing.length > 0) return existing[0].id;
        const [result] = await this.pool.query('INSERT INTO p_families (code, name) VALUES (?, ?)', [code, name]);
        return result.insertId;
    }
    
    async getOrCreateCategory(name, parentName = null, subCategory = null, displayOrder = 0) {
        if (!name) return null;
        const slug = name.toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '').replace(/[^a-z0-9]+/g, '_');
        
        const [existing] = await this.pool.query(
            `SELECT id FROM p_categories WHERE name = ? AND (parent_name = ? OR (parent_name IS NULL AND ? IS NULL)) AND (sub_category = ? OR (sub_category IS NULL AND ? IS NULL))`, 
            [name, parentName, parentName, subCategory, subCategory]
        );
        if (existing.length > 0) return existing[0].id;
        
        try {
            const [result] = await this.pool.query(
                'INSERT INTO p_categories (name, parent_name, sub_category, slug, display_order) VALUES (?, ?, ?, ?, ?)', 
                [name, parentName, subCategory, slug, displayOrder]
            );
            return result.insertId;
        } catch (e) { return null; }
    }
    
    async upsertModel(code, name, family, basePrice, technicalData = null, standardEquipment = null) {
        const familyId = await this.getOrCreateFamily(family, family);
        await this.pool.query(
            `INSERT INTO p_models (code, name, family_id, base_price, year, technical_data, standard_equipment) 
             VALUES (?, ?, ?, ?, 2025, ?, ?) 
             ON DUPLICATE KEY UPDATE name = VALUES(name), family_id = VALUES(family_id), base_price = VALUES(base_price), 
             technical_data = VALUES(technical_data), standard_equipment = VALUES(standard_equipment)`, 
            [code, name, familyId, basePrice, JSON.stringify(technicalData), JSON.stringify(standardEquipment)]
        );
        const [result] = await this.pool.query('SELECT id FROM p_models WHERE code = ?', [code]);
        return result[0].id;
    }
    
    async upsertOption(modelId, option) {
        const categoryId = await this.getOrCreateCategory(
            option.category, 
            option.parentCategory, 
            option.subCategory || null, 
            option.displayOrder || 0
        );
        
        await this.pool.query(
            `INSERT INTO p_options (model_id, category_id, code, name, name_de, description, price, is_standard, is_exclusive_manufaktur, option_type, sub_category, image_url, display_order) 
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?) 
             ON DUPLICATE KEY UPDATE 
                name = VALUES(name), 
                name_de = COALESCE(VALUES(name_de), name_de),
                description = VALUES(description),
                price = VALUES(price), 
                is_standard = VALUES(is_standard), 
                is_exclusive_manufaktur = VALUES(is_exclusive_manufaktur),
                option_type = VALUES(option_type), 
                category_id = VALUES(category_id), 
                sub_category = VALUES(sub_category),
                image_url = VALUES(image_url),
                display_order = VALUES(display_order)`,
            [
                modelId, 
                categoryId, 
                option.code, 
                option.name, 
                option.nameDe || null,
                option.description || null,
                option.price, 
                option.isStandard, 
                option.isExclusiveManufaktur || false,
                option.type, 
                option.subCategory || null,
                option.imageUrl, 
                option.displayOrder || 0
            ]
        );
    }
    
    async updateModelStats(modelId) {
        await this.pool.query(`
            UPDATE p_models m SET 
                options_count = (SELECT COUNT(*) FROM p_options WHERE model_id = m.id AND option_type = 'option'),
                colors_ext_count = (SELECT COUNT(*) FROM p_options WHERE model_id = m.id AND option_type IN ('color_ext', 'hood')),
                colors_int_count = (SELECT COUNT(*) FROM p_options WHERE model_id = m.id AND option_type = 'color_int')
            WHERE id = ?
        `, [modelId]);
    }
    
    async close() {
        if (this.pool) await this.pool.end();
    }
}

// ═══════════════════════════════════════════════════════════════════════════════
// EXTRACTEUR v6.1
// ═══════════════════════════════════════════════════════════════════════════════

class PorscheExtractor {
    constructor(db) {
        this.db = db;
        this.browser = null;
        this.context = null;
    }
    
    async init(headless = true) {
        this.browser = await chromium.launch({ 
            headless, 
            args: ['--no-sandbox', '--disable-setuid-sandbox', '--disable-dev-shm-usage'] 
        });
        this.context = await this.browser.newContext({ 
            locale: 'fr-FR', 
            viewport: { width: 1920, height: 1080 },
            userAgent: 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36'
        });
        console.log(`🔧 Mode: ${headless ? 'Invisible' : 'Visible'}`);
    }
    
    async close() {
        if (this.browser) await this.browser.close();
    }
    
    detectFamily(modelName) {
        const name = modelName.toLowerCase();
        if (name.includes('taycan')) return 'Taycan';
        if (name.includes('panamera')) return 'Panamera';
        if (name.includes('cayenne')) return 'Cayenne';
        if (name.includes('macan')) return 'Macan';
        if (name.includes('911')) return '911';
        if (name.includes('718') || name.includes('boxster') || name.includes('cayman') || name.includes('spyder')) return '718';
        return 'Autre';
    }
    
    async extractModel(modelCode, debugMode = false) {
        console.log(`\n${'═'.repeat(70)}`);
        console.log(`📦 EXTRACTION v6.1: ${modelCode}${debugMode ? ' (DEBUG)' : ''}`);
        console.log(`${'═'.repeat(70)}\n`);
        
        const page = await this.context.newPage();
        
        // Capture console logs from page
        page.on('console', msg => {
            const text = msg.text();
            if (text.includes('[DEBUG')) {
                console.log(`   ${text}`);
            }
        });
        
        try {
            console.log('⏳ Chargement...');
            
            // Essayer différentes années
            const years = [2025, 2024, 2026];
            let loaded = false;
            let url;
            
            for (const year of years) {
                url = `${CONFIG.baseUrl}/${CONFIG.locale}/mode/model/${year}/${modelCode}`;
                try {
                    const response = await page.goto(url, { waitUntil: 'networkidle', timeout: CONFIG.timeout });
                    if (response && response.status() === 200) {
                        const pageUrl = page.url();
                        if (!pageUrl.includes('/error') && !pageUrl.includes('/404')) {
                            console.log(`   ✓ Trouvé avec année ${year}`);
                            loaded = true;
                            break;
                        }
                    }
                } catch (e) {
                    continue;
                }
            }
            
            if (!loaded) {
                // Essayer sans année
                url = `${CONFIG.baseUrl}/${CONFIG.locale}/mode/model/${modelCode}`;
                try {
                    await page.goto(url, { waitUntil: 'networkidle', timeout: CONFIG.timeout });
                    loaded = true;
                    console.log(`   ✓ Trouvé sans année`);
                } catch (e) {
                    console.log('   ❌ Modèle non trouvé');
                    return 0;
                }
            }
            
            // Accepter les cookies
            try {
                await page.getByRole('button', { name: /Tout accepter/i }).click({ timeout: 5000 });
                await page.waitForTimeout(1000);
            } catch (e) {}
            
            // Extraire nom et prix de base - CORRIGÉ v6.2
            const modelName = await page.locator('h1').first().textContent() || modelCode;
            console.log(`📋 ${modelName.trim()}`);
            
            // POINT 1 FIX: Chercher spécifiquement le prix de base, pas le premier prix trouvé
            const basePrice = await page.evaluate(() => {
                // Méthode 1: Chercher dans la section "Prix" ou "Prix de base"
                const priceLabels = document.querySelectorAll('*');
                for (const el of priceLabels) {
                    const text = el.textContent?.trim()?.toLowerCase() || '';
                    // Chercher "Prix" suivi d'un montant, en évitant "Prix Total" et "Prix des options"
                    if ((text === 'prix' || text.includes('prix de base') || text.startsWith('prix\n')) && 
                        !text.includes('total') && !text.includes('options')) {
                        // Chercher le prix dans le parent ou les siblings
                        const parent = el.parentElement;
                        if (parent) {
                            const parentText = parent.innerText || '';
                            const match = parentText.match(/(\d{2,3}(?:[\s\u00a0]\d{3})+[,.]\d{2})\s*€/);
                            if (match) {
                                const price = parseFloat(match[1].replace(/[\s\u00a0]/g, '').replace(',', '.'));
                                if (price > 30000 && price < 1000000) {
                                    return price;
                                }
                            }
                        }
                    }
                }
                
                // Méthode 2: Chercher le premier prix > 50000€ qui n'est pas dans "Total"
                const allText = document.body.innerText;
                const lines = allText.split('\n');
                for (const line of lines) {
                    if (line.toLowerCase().includes('total')) continue;
                    if (line.toLowerCase().includes('options')) continue;
                    
                    const match = line.match(/(\d{2,3}(?:[\s\u00a0]\d{3})+[,.]\d{2})\s*€/);
                    if (match) {
                        const price = parseFloat(match[1].replace(/[\s\u00a0]/g, '').replace(',', '.'));
                        if (price > 50000 && price < 1000000) {
                            return price;
                        }
                    }
                }
                
                // Méthode 3: Fallback - premier grand prix
                const priceRegex = /(\d{1,3}(?:[\s\u00a0]\d{3})*[,.]\d{2})\s*€/g;
                let match;
                while ((match = priceRegex.exec(allText)) !== null) {
                    const price = parseFloat(match[1].replace(/[\s\u00a0]/g, '').replace(',', '.'));
                    if (price > 50000 && price < 1000000) {
                        return price;
                    }
                }
                return null;
            });
            console.log(`💰 ${basePrice?.toLocaleString('fr-FR')} €`);
            
            // ═══════════════════════════════════════════════════════════════
            // POINT 6: Extraire données techniques et équipement de série
            // ═══════════════════════════════════════════════════════════════
            console.log('\n📊 Extraction des données techniques et équipements de série...');
            
            let technicalData = {};
            let standardEquipment = [];
            
            try {
                // ÉTAPE 1: Naviguer vers l'onglet DONNÉES TECHNIQUES
                const techUrl = `https://configurator.porsche.com/fr-FR/mode/model/${modelCode}/specifications?tab=technical-data`;
                console.log(`   📍 Navigation vers: ${techUrl}`);
                await page.goto(techUrl, { waitUntil: 'networkidle', timeout: 30000 });
                await page.waitForTimeout(3000);
                
                // Extraire les données techniques depuis les <dl>
                technicalData = await page.evaluate(() => {
                    const data = {};
                    document.querySelectorAll('dl').forEach(dl => {
                        const dts = dl.querySelectorAll('dt');
                        const dds = dl.querySelectorAll('dd');
                        dts.forEach((dt, i) => {
                            const key = dt.textContent?.trim();
                            const value = dds[i]?.textContent?.trim();
                            if (key && value && key.length < 100 && value.length < 200) {
                                data[key] = value;
                            }
                        });
                    });
                    return data;
                });
                
                console.log(`   ✓ ${Object.keys(technicalData).length} données techniques`);
                Object.entries(technicalData).slice(0, 5).forEach(([k, v]) => console.log(`      • ${k}: ${v}`));
                
                // ÉTAPE 2: Naviguer vers l'onglet ÉQUIPEMENTS DE SÉRIE
                const equipUrl = `https://configurator.porsche.com/fr-FR/mode/model/${modelCode}/specifications?tab=standard-equipment`;
                console.log(`   📍 Navigation vers: ${equipUrl}`);
                await page.goto(equipUrl, { waitUntil: 'networkidle', timeout: 30000 });
                await page.waitForTimeout(3000);
                
                // Extraire les équipements de série
                // La structure de la page utilise des sections avec h3 (catégories) et h4 (équipements)
                standardEquipment = await page.evaluate(() => {
                    const items = [];
                    const seen = new Set();
                    
                    // Mots à exclure (navigation, marketing)
                    const excludeWords = [
                        'télécharger', 'pdf', 'tva', 'cookie', 'politique',
                        'données techniques', 'équipement de série', 'configuration',
                        'accepter', 'refuser', 'paramètre', 'en savoir plus',
                        'votre rêve', 'rêve devient', 'devient réalité',
                        'prix des options', 'configurer', 'configurez',
                        'découvrir', 'découvrez', 'newsletter', 'contact',
                        'personnalisez', 'créez votre', 'changer de modèle',
                        'sauvegarder', 'code porsche', 'aperçu', 'dismiss',
                        'prev', 'next', 'changer'
                    ];
                    
                    const shouldExclude = (text) => {
                        const lower = text.toLowerCase();
                        if (excludeWords.some(w => lower.includes(w))) return true;
                        if (text.length < 10 || text.length > 150) return true;
                        if (text.includes('€')) return true;
                        // Exclure les dimensions de jantes/pneus
                        if (/^\d+[,.]?\d*\s*x\s*\d+/.test(text)) return true;
                        if (/^\d+\/\d+\s*(zr|r)\s*\d+/i.test(text)) return true;
                        return false;
                    };
                    
                    // Méthode 1: Chercher les h4 qui sont les titres des équipements
                    document.querySelectorAll('h4').forEach(h4 => {
                        const text = h4.textContent?.trim();
                        if (text && !shouldExclude(text) && !seen.has(text)) {
                            seen.add(text);
                            items.push(text);
                        }
                    });
                    
                    // Méthode 2: Chercher dans le flyout/panel des équipements de série
                    // Les éléments sont souvent dans des divs avec des classes spécifiques
                    document.querySelectorAll('[class*="equipment"] h4, [class*="feature"] h4, [class*="standard"] h4').forEach(el => {
                        const text = el.textContent?.trim();
                        if (text && !shouldExclude(text) && !seen.has(text)) {
                            seen.add(text);
                            items.push(text);
                        }
                    });
                    
                    // Méthode 3: Chercher les éléments avec "Équipement de série" comme badge
                    document.querySelectorAll('*').forEach(el => {
                        if (el.textContent?.includes('Équipement de série')) {
                            // Remonter pour trouver le titre associé
                            const parent = el.closest('div, article, section, li');
                            if (parent) {
                                const title = parent.querySelector('h4, h5, [class*="title"], [class*="name"]');
                                if (title) {
                                    const text = title.textContent?.trim();
                                    if (text && !shouldExclude(text) && !seen.has(text)) {
                                        seen.add(text);
                                        items.push(text);
                                    }
                                }
                            }
                        }
                    });
                    
                    return items;
                });
                
                console.log(`   ✓ ${standardEquipment.length} équipements de série`);
                standardEquipment.slice(0, 8).forEach(e => console.log(`      • ${e}`));
                
                // Retourner à la page du configurateur
                await page.goto(`https://configurator.porsche.com/fr-FR/mode/model/${modelCode}`, { waitUntil: 'networkidle', timeout: 30000 });
                await page.waitForTimeout(2000);
                
            } catch (e) {
                console.log(`   ⚠️ Données techniques: ${e.message}`);
            }
            
            const family = this.detectFamily(modelName);
            const modelId = await this.db.upsertModel(modelCode, modelName.trim(), family, basePrice, technicalData, standardEquipment);
            
            // Scroll et déploiement des sections
            console.log('\n📜 Déploiement des sections...');
            
            // ÉTAPE 1: Scroll complet pour charger le contenu lazy-loaded
            await page.evaluate(async () => {
                for (let i = 0; i < document.body.scrollHeight; i += 500) {
                    window.scrollTo(0, i);
                    await new Promise(r => setTimeout(r, 100));
                }
                await new Promise(r => setTimeout(r, 500));
                for (let i = 0; i < document.body.scrollHeight; i += 500) {
                    window.scrollTo(0, i);
                    await new Promise(r => setTimeout(r, 50));
                }
                window.scrollTo(0, 0);
            });
            
            // DEBUG: Afficher la structure de la page après scroll
            const pageStructure = await page.evaluate(() => {
                const structure = {
                    h2Count: document.querySelectorAll('h2').length,
                    h3Count: document.querySelectorAll('h3').length,
                    inputsCount: document.querySelectorAll('input[name="options"]').length,
                    linksCount: document.querySelectorAll('a[href*="options="]').length,
                    h2Texts: [],
                    pricesFound: []
                };
                
                document.querySelectorAll('h2').forEach(h2 => {
                    const text = h2.textContent?.trim();
                    if (text && text.length < 100) structure.h2Texts.push(text);
                });
                
                // Chercher les prix sur la page
                const priceRegex = /(\d{1,3}(?:[\s\u00a0]\d{3})*[,.]\d{2})\s*€/g;
                const bodyText = document.body.innerText;
                let match;
                while ((match = priceRegex.exec(bodyText)) !== null) {
                    const price = parseFloat(match[1].replace(/[\s\u00a0]/g, '').replace(',', '.'));
                    if (price > 100 && price < 500000 && !structure.pricesFound.includes(price)) {
                        structure.pricesFound.push(price);
                    }
                }
                structure.pricesFound = structure.pricesFound.slice(0, 20);
                
                return structure;
            });
            
            console.log('\n   [DEBUG] Structure de la page après scroll:');
            console.log(`      H2: ${pageStructure.h2Count}, H3: ${pageStructure.h3Count}`);
            console.log(`      Inputs options: ${pageStructure.inputsCount}, Links options: ${pageStructure.linksCount}`);
            console.log(`      H2 trouvés: ${pageStructure.h2Texts.join(' | ')}`);
            console.log(`      Premiers prix trouvés: ${pageStructure.pricesFound.slice(0, 10).map(p => p.toLocaleString('fr-FR') + '€').join(', ')}`);
            
            // ÉTAPE 2: Ouvrir les sections FERMÉES (Accessoires pour véhicules, Livraison spéciale) EN PREMIER
            console.log('   🔓 Ouverture des sections fermées (Accessoires, Livraison)...');
            
            const closedSectionsOpened = await page.evaluate(async () => {
                const delay = ms => new Promise(r => setTimeout(r, ms));
                const opened = [];
                
                // Chercher les boutons fermés (aria-expanded="false")
                // UNIQUEMENT les sections principales (pas les sous-sections comme "Accessoires de roue")
                const buttons = document.querySelectorAll('button[aria-expanded="false"]');
                for (const btn of buttons) {
                    const text = btn.textContent?.trim() || '';
                    const textLower = text.toLowerCase();
                    
                    // Seulement "Accessoires pour véhicules" et "Livraison spéciale"
                    // Utiliser startsWith pour éviter "Accessoires de roue", "Accessoires intérieurs", etc.
                    const isMainAccessoires = textLower.startsWith('accessoires pour véhicules');
                    const isMainLivraison = textLower.startsWith('livraison spéciale');
                    
                    if (isMainAccessoires || isMainLivraison) {
                        try {
                            btn.scrollIntoView({ behavior: 'instant', block: 'center' });
                            await delay(300);
                            btn.click();
                            await delay(1000); // Attendre plus longtemps pour le chargement du contenu
                            opened.push(text.substring(0, 30));
                        } catch (e) {}
                    }
                }
                
                return opened;
            });
            
            if (closedSectionsOpened.length > 0) {
                console.log(`      ✓ Ouvert: ${closedSectionsOpened.join(', ')}`);
            }
            
            await page.waitForTimeout(1000);
            
            // ÉTAPE 3: Cliquer sur tous les H3 pour ouvrir les sous-sections
            // MAIS ne PAS re-cliquer sur les boutons de sections principales
            console.log('   📂 Déploiement de toutes les sous-sections...');
            
            await page.evaluate(async () => {
                const delay = ms => new Promise(r => setTimeout(r, ms));
                
                // Cliquer sur les H2 (sections principales) SAUF Accessoires et Livraison
                for (const h2 of document.querySelectorAll('h2')) {
                    const text = h2.textContent?.toLowerCase() || '';
                    // Ne pas re-cliquer sur Accessoires/Livraison (déjà ouverts)
                    if (text.includes('accessoires') || text.includes('livraison')) continue;
                    try { h2.click(); await delay(250); } catch (e) {}
                }
                
                // Cliquer sur les H3 (sous-sections) - maintenant elles sont toutes visibles
                for (const h3 of document.querySelectorAll('h3')) {
                    try { h3.click(); await delay(200); } catch (e) {}
                }
                
                // Cliquer UNIQUEMENT sur les boutons encore FERMÉS (pas les sections principales)
                for (const btn of document.querySelectorAll('button[aria-expanded="false"]')) {
                    const text = btn.textContent?.toLowerCase() || '';
                    // Ne pas toucher aux sections principales Accessoires/Livraison
                    if (text.includes('accessoires pour véhicules') || text.includes('livraison spéciale')) continue;
                    try { btn.click(); await delay(300); } catch (e) {}
                }
            });
            
            await page.waitForTimeout(2000);
            
            // ÉTAPE 4: Vérifier l'état final (sans cliquer)
            console.log('   🔄 Vérification de l\'état des sections...');
            
            const sectionStates = await page.evaluate(() => {
                const states = [];
                const buttons = document.querySelectorAll('button[aria-expanded]');
                for (const btn of buttons) {
                    const text = btn.textContent?.trim()?.toLowerCase() || '';
                    if (text.includes('accessoires') || text.includes('livraison') || text.includes('transport')) {
                        states.push({
                            text: btn.textContent?.trim()?.substring(0, 35),
                            isOpen: btn.getAttribute('aria-expanded') === 'true'
                        });
                    }
                }
                return states;
            });
            
            sectionStates.forEach(s => {
                console.log(`      ${s.isOpen ? '✅' : '❌'} "${s.text}" → ${s.isOpen ? 'OUVERT' : 'FERMÉ'}`);
            });
            
            await page.waitForTimeout(500);
            
            // ═══════════════════════════════════════════════════════════════
            // SCAN DES IMAGES
            // ═══════════════════════════════════════════════════════════════
            console.log('\n🔍 Scan des images...');
            
            const imageMap = await page.evaluate(() => {
                const map = {};
                
                // Scanner toutes les balises <img>
                document.querySelectorAll('img').forEach(img => {
                    let src = img.src || img.getAttribute('data-src') || img.getAttribute('data-lazy-src') || '';
                    if (!src || src.includes('data:') || src.includes('.svg') || src.includes('porsche-design-system')) return;
                    
                    // Patterns pour extraire le code depuis l'URL
                    const patterns = [
                        /studio_([A-Z0-9]+)\./i,          // studio_0Q.jpg
                        /detail_M?([A-Z0-9]+)_/i,         // detail_M04I_m_0.jpg
                        /\/([A-Z0-9]{2,5})\.jpg/i,        // /0Q.jpg
                        /exteriors\/studio_([A-Z0-9]+)/i, // exteriors/studio_0Q
                        /seats\/[^\/]+\/studio_([A-Z0-9]+)/i, // seats/982/studio_P11
                        /interiors?\/([A-Z0-9]+)/i,       // interior/41
                        /softtop[_-]?([A-Z0-9]{1,3})/i,   // softtop_1V ou softtop-V9
                        /hood[_-]?([A-Z0-9]{1,3})/i,      // hood_1V
                        /roof[_-]?([A-Z0-9]{1,3})/i,      // roof_V8
                        /capote[_-]?([A-Z0-9]{1,3})/i,    // capote_1V
                    ];
                    
                    for (const pattern of patterns) {
                        const match = src.match(pattern);
                        if (match && match[1]) {
                            const code = match[1].toUpperCase();
                            if (!map[code]) map[code] = src;
                            break;
                        }
                    }
                    
                    // Aussi chercher les codes dans le alt text
                    const alt = img.alt || '';
                    const altMatch = alt.match(/\b([A-Z0-9]{2,5})\b/);
                    if (altMatch && !map[altMatch[1]]) {
                        map[altMatch[1]] = src;
                    }
                });
                
                // Scanner les background-images
                document.querySelectorAll('[style*="background"]').forEach(el => {
                    const style = window.getComputedStyle(el);
                    const bgImage = style.backgroundImage;
                    if (bgImage && bgImage !== 'none' && bgImage.includes('url(')) {
                        const urlMatch = bgImage.match(/url\(["']?([^"')]+)["']?\)/);
                        if (urlMatch && urlMatch[1] && !urlMatch[1].includes('data:') && !urlMatch[1].includes('.svg')) {
                            const src = urlMatch[1];
                            const codeMatch = src.match(/studio_([A-Z0-9]+)\./i) || src.match(/detail_M?([A-Z0-9]+)_/i);
                            if (codeMatch && codeMatch[1]) {
                                const code = codeMatch[1].toUpperCase();
                                if (!map[code]) map[code] = src;
                            }
                        }
                    }
                });
                
                return map;
            });
            
            console.log(`📋 ${Object.keys(imageMap).length} codes mappés`);
            
            // ═══════════════════════════════════════════════════════════════
            // EXTRACTION DES OPTIONS
            // ═══════════════════════════════════════════════════════════════
            console.log('\n📊 Extraction des options...');
            
            const extractionResult = await page.evaluate(({ imageMap, standardEquipment: stdEquipmentList }) => {
                const results = [];
                const seen = new Set();
                let globalDisplayOrder = 0;
                
                // Normaliser les équipements de série pour comparaison
                const normalizedStdEquipment = (stdEquipmentList || []).map(e => 
                    e.toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '')
                );
                
                // Fonction pour vérifier si un nom correspond à un équipement de série
                const isInStandardEquipment = (name) => {
                    if (!name || !normalizedStdEquipment.length) return false;
                    const normalized = name.toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '');
                    return normalizedStdEquipment.some(stdName => 
                        normalized.includes(stdName) || stdName.includes(normalized)
                    );
                };
                
                // DEBUG info to return
                const debugInfo = {
                    point1_intSubCategories: {},
                    point2_hoods: [],
                    point4_exclusive: [],
                    h3sFound: [],
                    exclusiveElements: [],
                    intColorH3s: [],
                    stdEquipmentCount: normalizedStdEquipment.length,
                    stdEquipmentSample: normalizedStdEquipment.slice(0, 5)
                };
                
                // Mapping des couleurs pour intérieurs
                const colorNameToHex = {
                    'noir': '#1a1a1a', 'black': '#1a1a1a',
                    'gris arctique': '#8C9DA8', 'arctic grey': '#8C9DA8',
                    'bleu abysse': '#1E3A5F', 'abyss blue': '#1E3A5F',
                    'bordeaux': '#722F37', 'rouge': '#8B0000',
                    'beige': '#C8B896', 'craie': '#E8E4D9',
                    'havane': '#8B4513', 'espresso': '#3C2415',
                    'cognac': '#9A463D', 'graphite': '#4A4A4A',
                    'gris': '#808080', 'bleu': '#2B4B6F', 'vert': '#2D5A3D',
                };
                
                // DEBUG: Lister tous les H3 de la page avec leur contenu complet
                document.querySelectorAll('h3').forEach(h3 => {
                    const text = h3.textContent?.trim();
                    if (text && text.length > 2 && text.length < 200) {
                        debugInfo.h3sFound.push(text);
                    }
                });
                
                // DEBUG: Chercher "Exclusive Manufaktur" partout dans la page
                const exclusiveElements = [];
                document.querySelectorAll('*').forEach(el => {
                    const text = el.textContent?.trim()?.toLowerCase() || '';
                    if (text === 'exclusive manufaktur' || text === 'porsche exclusive manufaktur') {
                        exclusiveElements.push({
                            tag: el.tagName,
                            text: el.textContent?.trim(),
                            parent: el.parentElement?.tagName,
                            nextSibling: el.nextElementSibling?.textContent?.trim()?.substring(0, 50),
                            classes: el.className
                        });
                    }
                });
                debugInfo.exclusiveElements = exclusiveElements.slice(0, 10);
                
                // DEBUG: Chercher les H3 avec prix dans "Couleurs Intérieures"
                const intColorH3s = [];
                document.querySelectorAll('h2').forEach(h2 => {
                    if (h2.textContent?.toLowerCase().includes('couleurs intérieure')) {
                        let section = h2.parentElement;
                        for (let i = 0; i < 5 && section; i++) {
                            section = section.parentElement;
                        }
                        if (section) {
                            section.querySelectorAll('h3').forEach(h3 => {
                                // Chercher le prix dans le conteneur du H3
                                const container = h3.parentElement;
                                let priceNear = null;
                                if (container) {
                                    const containerText = container.innerText || '';
                                    const priceMatch = containerText.match(/(\d[\d\s\u00a0.,]*)\s*€/);
                                    if (priceMatch) {
                                        priceNear = priceMatch[0];
                                    }
                                }
                                
                                intColorH3s.push({
                                    text: h3.textContent?.trim(),
                                    priceInContainer: priceNear
                                });
                            });
                        }
                    }
                });
                debugInfo.intColorH3s = intColorH3s;
                
                // Fonction pour trouver l'image
                function findImageUrl(element, type, code, optionName = '') {
                    // Pour les couleurs intérieures: générer bandes de couleurs
                    if (type === 'color_int') {
                        const nameLower = (optionName || '').toLowerCase();
                        const colors = [];
                        
                        Object.keys(colorNameToHex).sort((a, b) => b.length - a.length).forEach(colorName => {
                            if (nameLower.includes(colorName) && !colors.includes(colorNameToHex[colorName])) {
                                colors.push(colorNameToHex[colorName]);
                            }
                        });
                        
                        if (colors.length >= 2) return `colors:${colors.join(',')}`;
                        if (colors.length === 1) return `colors:#1a1a1a,${colors[0]}`;
                    }
                    
                    // POUR LES CAPOTES: Toujours générer des bandes de couleurs
                    // Car Porsche utilise souvent la même image pour toutes les capotes
                    if (type === 'hood') {
                        const nameLower = (optionName || '').toLowerCase();
                        
                        // Capote Noir + Gris Arctique
                        if (nameLower.includes('gris arctique') || nameLower.includes('arctic grey')) {
                            return 'colors:#1a1a1a,#8C9DA8';
                        }
                        // Capote Noir + Rouge Carmin
                        if (nameLower.includes('rouge carmin') || nameLower.includes('carmine red') || nameLower.includes('rouge')) {
                            return 'colors:#1a1a1a,#8B0000';
                        }
                        // Capote Noir + Bleu
                        if (nameLower.includes('bleu') || nameLower.includes('blue')) {
                            return 'colors:#1a1a1a,#2B4B6F';
                        }
                        // Capote Noir simple
                        if (nameLower.includes('noir') || nameLower.includes('black')) {
                            return 'colors:#1a1a1a,#2d2d2d';
                        }
                        // Capote Beige/Craie
                        if (nameLower.includes('beige') || nameLower.includes('craie') || nameLower.includes('chalk')) {
                            return 'colors:#C8B896,#E8E4D9';
                        }
                        // Capote Bordeaux
                        if (nameLower.includes('bordeaux') || nameLower.includes('burgundy')) {
                            return 'colors:#722F37,#4A1C23';
                        }
                        // Fallback générique
                        return 'colors:#333333,#555555';
                    }
                    
                    // STRATÉGIE 0: Utiliser le mapping pré-calculé avec variantes de préfixes
                    if (imageMap[code]) return imageMap[code];
                    
                    // Essayer d'AJOUTER des préfixes au code
                    const prefixes = ['P', 'C', 'M', 'Q', 'X', 'PP', 'CC', 'MM'];
                    for (const prefix of prefixes) {
                        const testCode = prefix + code;
                        if (imageMap[testCode]) return imageMap[testCode];
                    }
                    
                    // Essayer de RETIRER des préfixes du code
                    const prefixesToStrip = ['P', 'C', 'M', 'Q', 'X'];
                    for (const prefix of prefixesToStrip) {
                        if (code.startsWith(prefix) && code.length > 2) {
                            const strippedCode = code.substring(1);
                            if (imageMap[strippedCode]) return imageMap[strippedCode];
                        }
                    }
                    
                    // Chercher les codes qui CONTIENNENT notre code
                    for (const [mapCode, mapUrl] of Object.entries(imageMap)) {
                        if (mapCode.endsWith(code) && mapCode.length <= code.length + 2) {
                            return mapUrl;
                        }
                        if (code.endsWith(mapCode) && code.length <= mapCode.length + 2) {
                            return mapUrl;
                        }
                    }
                    
                    // STRATÉGIE 1: Chercher dans les parents proches
                    let el = element;
                    for (let i = 0; i < 10 && el; i++) {
                        // Chercher toutes les images dans ce conteneur
                        const allImgs = el.querySelectorAll('img');
                        for (const img of allImgs) {
                            let src = img.src || '';
                            if (!src || src.includes('data:')) {
                                src = img.getAttribute('data-src') || img.getAttribute('data-lazy-src') || '';
                            }
                            
                            if (src && !src.includes('data:') && !src.includes('icon') && !src.includes('.svg') && 
                                !src.includes('porsche-design-system')) {
                                // Priorité aux images qui contiennent le code
                                if (src.includes(code) || src.includes(`studio_${code}`) || src.includes(`_${code}`)) {
                                    return src;
                                }
                                // Pour couleurs/capotes: prendre la première image valide
                                if ((type === 'color_ext' || type === 'hood' || type === 'seat' || type === 'wheel' || type === 'pack') &&
                                    (src.includes('/assets/') || src.includes('/model/') || src.includes('pictures.porsche.com'))) {
                                    return src;
                                }
                            }
                        }
                        
                        // Chercher les picture/source srcset
                        const sources = el.querySelectorAll('picture source, source');
                        for (const source of sources) {
                            const srcset = source.getAttribute('srcset') || '';
                            if (srcset) {
                                const firstSrc = srcset.split(',')[0]?.trim()?.split(' ')[0];
                                if (firstSrc && !firstSrc.includes('data:') && 
                                    (firstSrc.includes('/assets/') || firstSrc.includes('/model/'))) {
                                    return firstSrc;
                                }
                            }
                        }
                        
                        // Chercher background-image
                        const allElements = el.querySelectorAll('*');
                        for (const child of allElements) {
                            const style = window.getComputedStyle(child);
                            const bgImage = style.backgroundImage;
                            if (bgImage && bgImage !== 'none' && bgImage.includes('url(')) {
                                const urlMatch = bgImage.match(/url\(["']?([^"')]+)["']?\)/);
                                if (urlMatch && urlMatch[1] && !urlMatch[1].includes('data:') && !urlMatch[1].includes('icon')) {
                                    if (urlMatch[1].includes('/assets/') || urlMatch[1].includes('/model/')) {
                                        return urlMatch[1];
                                    }
                                }
                            }
                        }
                        
                        el = el.parentElement;
                    }
                    
                    // STRATÉGIE 2: Chercher dans les siblings
                    const parent = element.parentElement;
                    if (parent) {
                        const siblings = parent.querySelectorAll('img');
                        for (const img of siblings) {
                            const src = img.src || img.getAttribute('data-src') || '';
                            if (src && !src.includes('data:') && !src.includes('icon') && !src.includes('.svg')) {
                                if (src.includes('/assets/') || src.includes('/model/')) {
                                    return src;
                                }
                            }
                        }
                    }
                    
                    // STRATÉGIE 3: Chercher le label associé
                    const inputId = element.getAttribute('id');
                    if (inputId) {
                        const label = document.querySelector(`label[for="${inputId}"]`);
                        if (label) {
                            const labelImg = label.querySelector('img');
                            if (labelImg) {
                                const src = labelImg.src || labelImg.getAttribute('data-src') || '';
                                if (src && !src.includes('data:')) {
                                    return src;
                                }
                            }
                        }
                    }
                    
                    // STRATÉGIE 4: Chercher dans TOUTE la page une image qui contient le code
                    const allPageImgs = document.querySelectorAll('img');
                    for (const img of allPageImgs) {
                        const src = img.src || img.getAttribute('data-src') || '';
                        if (src && (src.includes(`_${code}`) || src.includes(`/${code}.`) || src.includes(`studio_${code}`))) {
                            return src;
                        }
                    }
                    
                    return null;
                }
                
                // POINT 4: Approche TOP-DOWN pour Exclusive Manufaktur
                // On trouve d'abord TOUS les labels "Exclusive Manufaktur" sur la page
                // Puis on trouve l'input/link le plus proche pour chacun
                const exclusiveOptionCodes = new Set();
                const exclusiveOptionNames = new Map();
                
                // Étape 1: Trouver tous les labels "Exclusive Manufaktur"
                document.querySelectorAll('p, span, small, div').forEach(el => {
                    // Ne considérer que les éléments FEUILLES (pas de sous-éléments ou 1 seul)
                    if (el.children.length > 1) return;
                    
                    const text = el.textContent?.trim()?.toLowerCase() || '';
                    if (text !== 'exclusive manufaktur' && text !== 'porsche exclusive manufaktur') return;
                    
                    // Trouver l'input le plus proche en remontant
                    let container = el;
                    for (let i = 0; i < 5 && container; i++) {
                        container = container.parentElement;
                        if (!container) break;
                        
                        // Chercher un input dans ce conteneur spécifique
                        const input = container.querySelector(':scope > input[name="options"], :scope input[name="options"]');
                        if (input) {
                            const code = input.getAttribute('value');
                            if (code && !exclusiveOptionCodes.has(code)) {
                                exclusiveOptionCodes.add(code);
                                const name = input.getAttribute('aria-label');
                                if (name) exclusiveOptionNames.set(code, name);
                            }
                            break; // Trouvé, on arrête
                        }
                        
                        // Chercher un lien
                        const link = container.querySelector('a[href*="options="]');
                        if (link) {
                            const href = link.getAttribute('href') || '';
                            const match = href.match(/options=([A-Z0-9]+)/i);
                            if (match && !exclusiveOptionCodes.has(match[1])) {
                                exclusiveOptionCodes.add(match[1]);
                            }
                            break;
                        }
                    }
                });
                
                // Fonction simplifiée - juste vérifier si le code est dans le Set
                function findExclusiveManufaktur(element, code) {
                    if (exclusiveOptionCodes.has(code)) {
                        return { 
                            isExclusive: true, 
                            realName: exclusiveOptionNames.get(code) || null 
                        };
                    }
                    return { isExclusive: false, realName: null };
                }
                
                // Parcourir les sections H2
                const allH2 = document.querySelectorAll('h2');
                
                // Log tous les H2 pour debug
                const allH2Texts = [];
                allH2.forEach(h2 => {
                    const text = h2.textContent?.trim() || '';
                    if (text && text.length < 100) allH2Texts.push(text);
                });
                debugInfo.allH2Sections = allH2Texts;
                
                allH2.forEach((h2, h2Index) => {
                    const h2Text = h2.textContent?.trim() || '';
                    const h2Lower = h2Text.toLowerCase();
                    
                    // Ignorer les sections de résumé
                    if (h2Lower.includes('et jantes') || h2Lower.includes('et sièges') || 
                        h2Lower.includes('résumé') || h2Lower.includes('prix total')) {
                        return;
                    }
                    
                    // Trouver la section
                    let section = h2.parentElement;
                    for (let i = 0; i < 5 && section; i++) {
                        if (section.querySelectorAll('input[name="options"], a[href*="options="]').length > 0) break;
                        section = section.parentElement;
                    }
                    if (!section) return;
                    
                    // Type de base
                    let baseType = 'option';
                    if (h2Lower.includes('couleurs extérieure')) baseType = 'color_ext';
                    else if (h2Lower.includes('couleurs intérieure')) baseType = 'color_int';
                    else if (h2Lower.includes('jante')) baseType = 'wheel';
                    else if (h2Lower.includes('siège')) baseType = 'seat';
                    else if (h2Lower.includes('pack')) baseType = 'pack';
                    
                    // DEBUG: Log section type
                    if (baseType !== 'option') {
                        debugInfo.sectionTypes = debugInfo.sectionTypes || [];
                        debugInfo.sectionTypes.push({ h2: h2Text, type: baseType });
                    }
                    
                    // DEBUG POINT 4: Si c'est la section Sièges, loguer tout ce qu'on trouve
                    if (baseType === 'seat') {
                        debugInfo.seatSection = debugInfo.seatSection || { inputs: [], links: [], optionLinks: [], h3s: [] };
                        section.querySelectorAll('input[name="options"]').forEach(input => {
                            debugInfo.seatSection.inputs.push({
                                code: input.getAttribute('value'),
                                name: input.getAttribute('aria-label')?.substring(0, 50)
                            });
                        });
                        // Pattern 1: options= query param
                        section.querySelectorAll('a[href*="options="]').forEach(link => {
                            const href = link.getAttribute('href') || '';
                            const match = href.match(/options=([A-Z0-9]+)/i);
                            if (match) {
                                debugInfo.seatSection.links.push({
                                    code: match[1],
                                    text: link.textContent?.trim()?.substring(0, 50)
                                });
                            }
                        });
                        // Pattern 2: /option/XXX path
                        section.querySelectorAll('a[href*="/option/"]').forEach(link => {
                            const href = link.getAttribute('href') || '';
                            const match = href.match(/\/option\/([A-Z0-9]+)/i);
                            if (match) {
                                debugInfo.seatSection.optionLinks.push({
                                    code: match[1],
                                    text: link.textContent?.trim()?.substring(0, 50),
                                    href: href.substring(0, 80)
                                });
                            }
                        });
                        section.querySelectorAll('h3').forEach(h3 => {
                            debugInfo.seatSection.h3s.push(h3.textContent?.trim());
                        });
                    }
                    
                    // Extraire les inputs (couleurs, jantes, sièges)
                    section.querySelectorAll('input[name="options"]').forEach(input => {
                        const code = input.getAttribute('value');
                        const name = input.getAttribute('aria-label');
                        
                        if (!code || !name || seen.has(code)) return;
                        seen.add(code);
                        
                        // POINT 5: Trouver H3 pour sous-catégorie
                        let parentH3 = null;
                        let h3Price = null;
                        
                        let searchEl = input;
                        for (let i = 0; i < 12 && searchEl; i++) {
                            searchEl = searchEl.parentElement;
                            if (!searchEl) break;
                            
                            let prev = searchEl.previousElementSibling;
                            while (prev) {
                                if (prev.tagName === 'H3') {
                                    parentH3 = prev.textContent?.trim() || '';
                                    
                                    // Le prix n'est PAS dans le H3, chercher dans le conteneur parent du H3
                                    const h3Container = prev.parentElement;
                                    if (h3Container) {
                                        const containerText = h3Container.innerText || '';
                                        // Chercher un prix dans le conteneur
                                        const priceMatch = containerText.match(/(\d[\d\s\u00a0.,]*\d)\s*€/);
                                        if (priceMatch) {
                                            let priceStr = priceMatch[1].replace(/[\s\u00a0]/g, '').replace(/\./g, '').replace(',', '.');
                                            const parsedPrice = parseFloat(priceStr);
                                            if (parsedPrice > 0 && parsedPrice < 50000) {
                                                h3Price = parsedPrice;
                                            }
                                        }
                                    }
                                    break;
                                }
                                const h3 = prev.querySelector('h3');
                                if (h3) {
                                    parentH3 = h3.textContent?.trim() || '';
                                    
                                    // Chercher le prix dans le conteneur
                                    const h3Container = h3.parentElement;
                                    if (h3Container) {
                                        const containerText = h3Container.innerText || '';
                                        const priceMatch = containerText.match(/(\d[\d\s\u00a0.,]*\d)\s*€/);
                                        if (priceMatch) {
                                            let priceStr = priceMatch[1].replace(/[\s\u00a0]/g, '').replace(/\./g, '').replace(',', '.');
                                            const parsedPrice = parseFloat(priceStr);
                                            if (parsedPrice > 0 && parsedPrice < 50000) {
                                                h3Price = parsedPrice;
                                            }
                                        }
                                    }
                                    break;
                                }
                                prev = prev.previousElementSibling;
                            }
                            if (parentH3) break;
                        }
                        
                        // DEBUG POINT 1: Track interior subcategories
                        if (baseType === 'color_int' && parentH3) {
                            if (!debugInfo.point1_intSubCategories[parentH3]) {
                                debugInfo.point1_intSubCategories[parentH3] = { count: 0, price: h3Price };
                            }
                            debugInfo.point1_intSubCategories[parentH3].count++;
                        }
                        
                        // DEBUG POINT 3: Capture HTML structure for color_int
                        if (baseType === 'color_int') {
                            debugInfo.colorIntDebug = debugInfo.colorIntDebug || [];
                            let htmlDebug = { code, name: name.substring(0, 30), levels: [], inputAttrs: {} };
                            
                            // Capture all input attributes
                            const attrs = input.attributes;
                            for (let a = 0; a < attrs.length; a++) {
                                htmlDebug.inputAttrs[attrs[a].name] = attrs[a].value?.substring(0, 50);
                            }
                            htmlDebug.inputAttrs['checked'] = input.checked;
                            htmlDebug.inputAttrs['disabled'] = input.disabled;
                            
                            let debugEl = input;
                            for (let lvl = 0; lvl < 6 && debugEl; lvl++) {
                                debugEl = debugEl.parentElement;
                                if (!debugEl) break;
                                const text = debugEl.innerText?.substring(0, 200) || '';
                                const hasPrice = text.match(/\d+[,.]\d{2}\s*€/);
                                const hasSerie = text.toLowerCase().includes('série');
                                htmlDebug.levels.push({
                                    lvl,
                                    tag: debugEl.tagName,
                                    childInputs: debugEl.querySelectorAll('input[name="options"]').length,
                                    hasPrice: !!hasPrice,
                                    hasSerie,
                                    priceFound: hasPrice ? hasPrice[0] : null,
                                    textPreview: text.replace(/\s+/g, ' ').substring(0, 80)
                                });
                            }
                            debugInfo.colorIntDebug.push(htmlDebug);
                        }
                        
                        // POINT 4: Détecter Exclusive Manufaktur sur TOUS les types d'options (y compris inputs)
                        const exclusiveInfo = findExclusiveManufaktur(input, code);
                        
                        if (exclusiveInfo.isExclusive) {
                            debugInfo.point4_exclusive.push({ code, realName: exclusiveInfo.realName, source: 'input' });
                        }
                        
                        // POINT 2: Détecter les capotes
                        let type = baseType;
                        const h3Lower = (parentH3 || '').toLowerCase();
                        if (baseType === 'color_ext' && (h3Lower.includes('capote') || h3Lower.includes('toit') || h3Lower.includes('soft top'))) {
                            type = 'hood';
                            debugInfo.point2_hoods.push({ code, name, h3: parentH3 });
                        }
                        
                        // Prix - CORRIGÉ v6.2: chercher le prix INDIVIDUEL de l'option
                        let price = null;
                        let isStandard = false;
                        
                        // v6.2 FIX: Chercher le prix proche de l'input
                        // IMPORTANT: Chercher d'abord un prix explicite, puis seulement "de série"
                        let el = input;
                        let priceFound = false;
                        let foundSerieText = false;
                        let serieLevel = -1;
                        
                        // Étape 1: Chercher un prix OU "de série" dans les parents proches
                        for (let i = 0; i < 6 && el && !priceFound; i++) {
                            el = el.parentElement;
                            if (!el) break;
                            
                            // Ne pas remonter trop haut (éviter les grands conteneurs)
                            const childCount = el.querySelectorAll('input[name="options"]').length;
                            if (childCount > 1) break; // Ce conteneur contient plusieurs options, stop!
                            
                            const elText = el.innerText || '';
                            
                            // Marquer si on trouve "de série" mais continuer à chercher un prix
                            if (!foundSerieText && i <= 3) {
                                const textLower = elText.toLowerCase();
                                if (textLower.includes('équipement de série') || 
                                    textLower.includes('standard equipment')) {
                                    foundSerieText = true;
                                    serieLevel = i;
                                }
                            }
                            
                            // Chercher un prix en € dans les éléments feuilles
                            const priceElements = el.querySelectorAll('*');
                            for (const priceEl of priceElements) {
                                if (priceEl.children.length > 2) continue;
                                
                                const priceText = priceEl.textContent?.trim() || '';
                                if (priceText.length > 100) continue;
                                
                                // Chercher un prix > 0
                                const priceMatch = priceText.match(/(\d{1,3}(?:[\s\u00a0.]\d{3})*[,.]\d{2})\s*€/);
                                if (priceMatch) {
                                    let priceStr = priceMatch[1]
                                        .replace(/[\s\u00a0]/g, '')
                                        .replace(/\.(?=\d{3})/g, '')
                                        .replace(',', '.');
                                    const val = parseFloat(priceStr);
                                    if (val > 0 && val < 100000) {
                                        // Prix trouvé > 0, c'est le bon!
                                        price = val;
                                        isStandard = false;
                                        priceFound = true;
                                        break;
                                    } else if (val === 0) {
                                        // Prix = 0, c'est de série
                                        price = 0;
                                        isStandard = true;
                                        priceFound = true;
                                        break;
                                    }
                                }
                            }
                        }
                        
                        // Étape 2: Si pas de prix trouvé mais "de série" trouvé proche (niveau <= 3)
                        if (!priceFound && foundSerieText && serieLevel <= 3) {
                            price = 0;
                            isStandard = true;
                            priceFound = true;
                        }
                        
                        // Étape 3: Si pas de prix trouvé pour color_int, utiliser le prix du H3 si disponible
                        if (!priceFound && type === 'color_int' && h3Price !== null) {
                            price = h3Price;
                            isStandard = (h3Price === 0);
                            priceFound = true;
                        }
                        
                        // Étape 4: Pour color_int sans prix, chercher le prix dans le groupe H3
                        if (!priceFound && type === 'color_int' && parentH3) {
                            // Trouver la section H3 et chercher un prix global
                            let h3El = null;
                            let searchH3 = input;
                            for (let i = 0; i < 12 && searchH3; i++) {
                                searchH3 = searchH3.parentElement;
                                if (!searchH3) break;
                                
                                let prev = searchH3.previousElementSibling;
                                while (prev) {
                                    if (prev.tagName === 'H3' && prev.textContent?.trim() === parentH3) {
                                        h3El = prev;
                                        break;
                                    }
                                    prev = prev.previousElementSibling;
                                }
                                if (h3El) break;
                            }
                            
                            if (h3El) {
                                // Chercher le prix dans le conteneur du H3 (pas dans les options individuelles)
                                const h3Container = h3El.parentElement;
                                if (h3Container) {
                                    // Chercher un prix directement après le H3
                                    let nextEl = h3El.nextElementSibling;
                                    for (let j = 0; j < 3 && nextEl; j++) {
                                        const nextText = nextEl.textContent?.trim() || '';
                                        if (nextText.length < 50) {
                                            const priceMatch = nextText.match(/(\d{1,3}(?:[\s\u00a0.]\d{3})*[,.]\d{2})\s*€/);
                                            if (priceMatch) {
                                                let priceStr = priceMatch[1].replace(/[\s\u00a0]/g, '').replace(/\.(?=\d{3})/g, '').replace(',', '.');
                                                const val = parseFloat(priceStr);
                                                if (val >= 0 && val < 100000) {
                                                    price = val;
                                                    isStandard = (val === 0);
                                                    priceFound = true;
                                                    break;
                                                }
                                            }
                                        }
                                        nextEl = nextEl.nextElementSibling;
                                    }
                                }
                            }
                        }
                        
                        // Étape 5: Pour color_int, si checked c'est de série, sinon option payante
                        if (!priceFound && type === 'color_int') {
                            if (input.checked) {
                                price = 0;
                                isStandard = true;
                            } else if (isInStandardEquipment(name)) {
                                // Option trouvée dans la liste des équipements de série
                                price = 0;
                                isStandard = true;
                            }
                            // Note: si pas checked et pas de prix, on laisse price=null (option payante sans prix connu)
                        }
                        
                        // Étape 6: Utiliser la liste des équipements de série pour tous les types
                        if (!priceFound && !isStandard && isInStandardEquipment(name)) {
                            price = 0;
                            isStandard = true;
                        }
                        
                        // DEBUG v6.2: Log pour les jantes et couleurs int
                        if (baseType === 'wheel' || baseType === 'color_int') {
                            debugInfo[`price_debug_${baseType}`] = debugInfo[`price_debug_${baseType}`] || [];
                            debugInfo[`price_debug_${baseType}`].push({
                                code,
                                name: name.substring(0, 40),
                                price,
                                isStandard,
                                h3: parentH3,
                                h3Price
                            });
                        }
                        
                        if (price === null && (input.checked || input.disabled)) {
                            isStandard = true;
                            price = 0;
                        }
                        
                        globalDisplayOrder++;
                        
                        // Utiliser le nom Exclusive Manufaktur si disponible
                        const finalName = exclusiveInfo.realName || name;
                        
                        results.push({
                            code,
                            name: finalName,
                            price,
                            isStandard,
                            type,
                            category: h2Text,
                            parentCategory: h2Text,
                            subCategory: parentH3,
                            isExclusiveManufaktur: exclusiveInfo.isExclusive,
                            imageUrl: findImageUrl(input, type, code, finalName),
                            displayOrder: globalDisplayOrder
                        });
                    });
                    
                    // Extraire les liens (options) - Pattern 1: ?options=XXX
                    section.querySelectorAll('a[href*="options="]').forEach(link => {
                        const href = link.getAttribute('href') || '';
                        const match = href.match(/options=([A-Z0-9]+)/i);
                        if (!match) return;
                        
                        const code = match[1];
                        if (seen.has(code)) return;
                        seen.add(code);
                        
                        // POINT 4: Détecter Exclusive Manufaktur
                        const exclusiveInfo = findExclusiveManufaktur(link, code);
                        
                        if (exclusiveInfo.isExclusive) {
                            debugInfo.point4_exclusive.push({ code, realName: exclusiveInfo.realName, source: 'link' });
                        }
                        
                        // Extraire le nom
                        let name = '';
                        if (exclusiveInfo.realName) {
                            name = exclusiveInfo.realName;
                        } else {
                            const linkText = link.textContent?.trim();
                            if (linkText && linkText.length > 2 && linkText.length < 200 && !linkText.includes('€')) {
                                name = linkText;
                            }
                        }
                        
                        // Nettoyer le nom
                        if (name.toLowerCase().includes('exclusive manufaktur')) {
                            name = name.replace(/exclusive manufaktur/gi, '').trim();
                        }
                        if (!name || name.length < 3) name = code;
                        
                        // Prix
                        let price = null;
                        let isStandard = false;
                        
                        let container = link.parentElement;
                        for (let i = 0; i < 5 && container; i++) {
                            const text = container.innerText || '';
                            const priceMatches = text.match(/(\d{1,3}(?:[\s\u00a0]\d{3})*[,.]\d{2})\s*€/g);
                            if (priceMatches) {
                                for (const pm of priceMatches) {
                                    const val = parseFloat(pm.replace(/[\s\u00a0€]/g, '').replace(',', '.'));
                                    if (val >= 0 && val < 100000) {
                                        price = val;
                                        isStandard = (val === 0);
                                        break;
                                    }
                                }
                                if (price !== null) break;
                            }
                            container = container.parentElement;
                        }
                        
                        // Sous-catégorie
                        let parentH3 = null;
                        let searchEl = link;
                        for (let i = 0; i < 8 && searchEl; i++) {
                            searchEl = searchEl.parentElement;
                            if (!searchEl) break;
                            
                            let prev = searchEl.previousElementSibling;
                            while (prev) {
                                if (prev.tagName === 'H3') {
                                    parentH3 = prev.textContent?.trim();
                                    break;
                                }
                                prev = prev.previousElementSibling;
                            }
                            if (parentH3) break;
                        }
                        
                        globalDisplayOrder++;
                        
                        results.push({
                            code,
                            name: name.substring(0, 250),
                            price,
                            isStandard,
                            type: 'option',
                            category: h2Text,
                            parentCategory: h2Text,
                            subCategory: parentH3,
                            isExclusiveManufaktur: exclusiveInfo.isExclusive,
                            imageUrl: findImageUrl(link, 'option', code, name),
                            displayOrder: globalDisplayOrder
                        });
                    });
                    
                    // Pattern 2: Extraire les liens avec /option/XXX (notamment pour les sièges)
                    section.querySelectorAll('a[href*="/option/"]').forEach(link => {
                        const href = link.getAttribute('href') || '';
                        const match = href.match(/\/option\/([A-Z0-9]+)/i);
                        if (!match) return;
                        
                        const code = match[1];
                        if (seen.has(code)) return;
                        seen.add(code);
                        
                        // Fonction pour nettoyer les noms de sièges
                        const cleanSeatName = (text) => {
                            if (!text) return '';
                            return text
                                .replace(/^Afficher plus d'informations sur\s*/i, '')
                                .replace(/^Voir\s+/i, '')
                                .replace(/^Détails\s+/i, '')
                                .trim();
                        };
                        
                        // Extraire le nom depuis le contexte du lien
                        let name = '';
                        
                        // Méthode 1: aria-label sur le lien lui-même
                        const ariaLabel = link.getAttribute('aria-label');
                        if (ariaLabel && ariaLabel.length > 3 && !ariaLabel.includes('€')) {
                            name = cleanSeatName(ariaLabel);
                        }
                        
                        // Méthode 2: aria-label sur une image à l'intérieur du lien
                        if (!name || name.length < 3) {
                            const img = link.querySelector('img');
                            if (img) {
                                const imgAria = img.getAttribute('aria-label') || img.getAttribute('alt');
                                if (imgAria && imgAria.length > 3 && !imgAria.includes('€')) {
                                    name = cleanSeatName(imgAria);
                                }
                            }
                        }
                        
                        // Méthode 3: Chercher dans le conteneur parent (carte)
                        if (!name) {
                            let container = link;
                            for (let i = 0; i < 6 && container; i++) {
                                container = container.parentElement;
                                if (!container) break;
                                
                                // aria-label sur le conteneur
                                const containerAria = container.getAttribute('aria-label');
                                if (containerAria && containerAria.length > 3 && !containerAria.includes('€')) {
                                    name = containerAria;
                                    break;
                                }
                                
                                // Chercher un titre h4, h5, strong, span avec classe title
                                const titleEl = container.querySelector('h4, h5, strong, [class*="title"], [class*="name"], [class*="label"]');
                                if (titleEl) {
                                    const titleText = titleEl.textContent?.trim();
                                    if (titleText && titleText.length > 3 && titleText.length < 150 && !titleText.includes('€')) {
                                        name = titleText;
                                        break;
                                    }
                                }
                                
                                // Chercher un paragraphe ou span avec du texte significatif
                                const textEls = container.querySelectorAll('p, span');
                                for (const el of textEls) {
                                    // Ne pas prendre les éléments avec des enfants complexes
                                    if (el.children.length > 1) continue;
                                    const text = el.textContent?.trim();
                                    // Texte significatif: > 10 chars, pas de prix, pas trop long
                                    if (text && text.length > 10 && text.length < 150 && 
                                        !text.includes('€') && !text.match(/^\d+[,.]\d{2}$/) &&
                                        !text.toLowerCase().includes('série') &&
                                        !text.toLowerCase().includes('sélectionner')) {
                                        name = text;
                                        break;
                                    }
                                }
                                if (name) break;
                            }
                        }
                        
                        // Méthode 4: Fallback - utiliser le H3 parent comme indication
                        if (!name || name.length < 3) {
                            // Le H3 donne le type de siège
                            let searchEl = link;
                            for (let i = 0; i < 8 && searchEl; i++) {
                                searchEl = searchEl.parentElement;
                                if (!searchEl) break;
                                
                                let prev = searchEl.previousElementSibling;
                                while (prev) {
                                    if (prev.tagName === 'H3') {
                                        const h3Text = prev.textContent?.trim();
                                        if (h3Text && h3Text.length > 3) {
                                            name = h3Text;
                                            break;
                                        }
                                    }
                                    prev = prev.previousElementSibling;
                                }
                                if (name && name.length > 3) break;
                            }
                        }
                        
                        // Nettoyer le nom final
                        name = cleanSeatName(name);
                        if (!name || name.length < 3) name = code;
                        
                        // Prix et statut standard
                        let price = null;
                        let isStandard = false;
                        let foundSerieText = false;
                        let serieLevel = -1;
                        
                        let priceContainer = link;
                        for (let i = 0; i < 5 && priceContainer; i++) {
                            priceContainer = priceContainer.parentElement;
                            if (!priceContainer) break;
                            
                            // Stop si le conteneur contient plusieurs options
                            const optionLinks = priceContainer.querySelectorAll('a[href*="/option/"]').length;
                            if (optionLinks > 2) break;
                            
                            const text = priceContainer.innerText || '';
                            
                            // Marquer "de série" mais continuer à chercher un prix
                            if (!foundSerieText && i <= 2) {
                                const textLower = text.toLowerCase();
                                if (textLower.includes('équipement de série') || 
                                    textLower.includes('standard equipment')) {
                                    foundSerieText = true;
                                    serieLevel = i;
                                }
                            }
                            
                            // Chercher un prix > 0 d'abord
                            const priceMatch = text.match(/(\d{1,3}(?:[\s\u00a0.]\d{3})*[,.]\d{2})\s*€/);
                            if (priceMatch) {
                                let priceStr = priceMatch[1]
                                    .replace(/[\s\u00a0]/g, '')
                                    .replace(/\.(?=\d{3})/g, '')
                                    .replace(',', '.');
                                const val = parseFloat(priceStr);
                                if (val > 0 && val < 100000) {
                                    price = val;
                                    isStandard = false;
                                    break;
                                } else if (val === 0) {
                                    price = 0;
                                    isStandard = true;
                                    break;
                                }
                            }
                        }
                        
                        // Si pas de prix mais "de série" trouvé proche
                        if (price === null && foundSerieText && serieLevel <= 2) {
                            price = 0;
                            isStandard = true;
                        }
                        
                        // Vérifier si l'option est dans la liste des équipements de série
                        if (price === null && !isStandard && isInStandardEquipment(name)) {
                            price = 0;
                            isStandard = true;
                        }
                        
                        // Sous-catégorie H3
                        let parentH3 = null;
                        let searchEl = link;
                        for (let i = 0; i < 8 && searchEl; i++) {
                            searchEl = searchEl.parentElement;
                            if (!searchEl) break;
                            
                            let prev = searchEl.previousElementSibling;
                            while (prev) {
                                if (prev.tagName === 'H3') {
                                    parentH3 = prev.textContent?.trim();
                                    break;
                                }
                                prev = prev.previousElementSibling;
                            }
                            if (parentH3) break;
                        }
                        
                        globalDisplayOrder++;
                        
                        // DEBUG: Log pour les sièges extraits via /option/
                        if (baseType === 'seat') {
                            debugInfo.seatExtracted = debugInfo.seatExtracted || [];
                            debugInfo.seatExtracted.push({ code, name: name.substring(0, 40), price, isStandard, h3: parentH3 });
                        }
                        
                        results.push({
                            code,
                            name: name.substring(0, 250),
                            price,
                            isStandard,
                            type: baseType,  // Utiliser le type de la section (seat, color_int, etc.)
                            category: h2Text,
                            parentCategory: h2Text,
                            subCategory: parentH3,
                            isExclusiveManufaktur: false,
                            imageUrl: findImageUrl(link, baseType, code, name),
                            displayOrder: globalDisplayOrder
                        });
                    });
                });
                
                // ═══════════════════════════════════════════════════════════════
                // EXTRACTION SPÉCIALE: Accessoires et Livraison spéciale
                // Ces sections utilisent des BOUTONS (pas des H2) comme titres
                // ═══════════════════════════════════════════════════════════════
                
                // Regex pour valider un code Porsche (1-4 caractères alphanumériques)
                const isPorscheCode = (code) => /^[A-Z0-9]{1,4}$/i.test(code);
                
                // Debug: compter les inputs restants
                let remainingInputs = [];
                
                // Chercher les BOUTONS avec "accessoires" ou "livraison"
                const sectionButtons = document.querySelectorAll('button[aria-expanded], button[aria-controls]');
                
                sectionButtons.forEach(btn => {
                    // Utiliser aria-controls pour identifier les sections (plus fiable que le texte)
                    const sectionContainerId = btn.getAttribute('aria-controls') || '';
                    const btnText = btn.textContent?.trim() || '';
                    const btnLower = btnText.toLowerCase();
                    
                    // Identifier les sections par aria-controls ou par le texte
                    const isMainAccessoires = sectionContainerId.includes('vehicle-accessories') || 
                                              (btnLower.includes('accessoires pour v') && 
                                               !btnLower.includes('accessoires de') && 
                                               !btnLower.includes('accessoires int') && 
                                               !btnLower.includes('accessoires audio'));
                    const isMainLivraison = sectionContainerId.includes('special-delivery') || 
                                            btnLower.startsWith('livraison spéciale');
                    
                    if (!isMainAccessoires && !isMainLivraison) {
                        return;
                    }
                    
                    // Catégorie PROPRE (hardcodée)
                    let category = isMainAccessoires ? 'Accessoires pour véhicules' : 'Livraison spéciale';
                    
                    // Trouver le conteneur associé via aria-controls
                    let section = sectionContainerId ? document.getElementById(sectionContainerId) : null;
                    
                    // Si pas de conteneur via ID, chercher dans le parent
                    if (!section) {
                        section = btn.parentElement;
                        for (let i = 0; i < 10 && section; i++) {
                            const inputs = section.querySelectorAll('input[name="options"]');
                            if (inputs.length > 0 && inputs.length < 50) break;
                            section = section.parentElement;
                        }
                    }
                    
                    if (!section) return;
                    
                    // Extraire les inputs de cette section
                    const inputs = section.querySelectorAll('input[name="options"]');
                    
                    inputs.forEach(input => {
                        const code = input.getAttribute('value');
                        if (!code || seen.has(code) || !isPorscheCode(code)) return;
                        
                        // Trouver le nom
                        let name = input.getAttribute('aria-label');
                        
                        if (!name) {
                            // Chercher dans le conteneur parent
                            let container = input.parentElement;
                            for (let j = 0; j < 10 && container && !name; j++) {
                                const candidates = container.querySelectorAll('p, span, div');
                                for (const el of candidates) {
                                    if (el.querySelector('input, button, img')) continue;
                                    
                                    const text = el.textContent?.trim();
                                    if (text && 
                                        text.length > 5 && 
                                        text.length < 200 && 
                                        !text.includes('€') && 
                                        !text.match(/^\d+[,.\s]/) &&
                                        !text.toLowerCase().includes('ajouter') &&
                                        !text.toLowerCase().includes('certains accessoires') &&
                                        !text.toLowerCase().includes('sélectionner')) {
                                        name = text;
                                        break;
                                    }
                                }
                                container = container.parentElement;
                            }
                        }
                        
                        if (!name || name.length < 3) {
                            remainingInputs.push({ code, reason: 'no_name', category });
                            return;
                        }
                        
                        seen.add(code);
                        
                        // Trouver le prix
                        let price = null;
                        let priceContainer = input.parentElement;
                        for (let j = 0; j < 8 && priceContainer; j++) {
                            const text = priceContainer.innerText || '';
                            const priceMatch = text.match(/(\d[\d\s\u00a0.,]*)\s*€/);
                            if (priceMatch) {
                                let priceStr = priceMatch[1].replace(/[\s\u00a0]/g, '').replace(',', '.');
                                const parsed = parseFloat(priceStr);
                                if (parsed > 0 && parsed < 50000) {
                                    price = parsed;
                                    break;
                                }
                            }
                            priceContainer = priceContainer.parentElement;
                        }
                        
                        // Trouver la sous-catégorie H3
                        let subCategory = null;
                        let searchEl = input;
                        for (let j = 0; j < 10 && searchEl; j++) {
                            searchEl = searchEl.parentElement;
                            if (!searchEl) break;
                            
                            let prev = searchEl.previousElementSibling;
                            while (prev) {
                                if (prev.tagName === 'H3') {
                                    subCategory = prev.textContent?.trim();
                                    break;
                                }
                                const h3 = prev.querySelector('h3');
                                if (h3) {
                                    subCategory = h3.textContent?.trim();
                                    break;
                                }
                                prev = prev.previousElementSibling;
                            }
                            if (subCategory) break;
                        }
                        
                        globalDisplayOrder++;
                        results.push({
                            code,
                            name: name.substring(0, 250),
                            price,
                            isStandard: false,
                            type: 'option',
                            category: category,
                            parentCategory: category,
                            subCategory: subCategory,
                            isExclusiveManufaktur: false,
                            imageUrl: findImageUrl(input, 'option', code, name),
                            displayOrder: globalDisplayOrder
                        });
                    });
                });
                
                debugInfo.remainingInputs = remainingInputs;
                
                return { results, debugInfo };
            }, { imageMap, standardEquipment });
            
            const allOptions = extractionResult.results;
            const debugInfo = extractionResult.debugInfo;
            
            // Nettoyage des catégories polluées (texte d'avertissement inclus par erreur)
            allOptions.forEach(opt => {
                if (opt.category && opt.category.toLowerCase().startsWith('accessoires pour véhicules')) {
                    opt.category = 'Accessoires pour véhicules';
                    opt.parentCategory = 'Accessoires pour véhicules';
                }
                if (opt.category && opt.category.toLowerCase().startsWith('livraison spéciale')) {
                    opt.category = 'Livraison spéciale';
                    opt.parentCategory = 'Livraison spéciale';
                }
            });
            
            // ═══════════════════════════════════════════════════════════════
            // EXTRACTION PRIX PAR CLIC - Pour options sans prix dans le HTML
            // ═══════════════════════════════════════════════════════════════
            const optionsWithoutPrice = allOptions.filter(o => 
                (o.type === 'color_int' || o.type === 'seat' || o.type === 'wheel') && 
                o.price === null && 
                !o.isStandard
            );
            
            if (optionsWithoutPrice.length > 0) {
                console.log(`\n🔄 Récupération des prix par clic (${optionsWithoutPrice.length} options)...`);
                
                // D'abord, trouver le sélecteur du prix total
                const priceSelector = await page.evaluate(() => {
                    // Chercher différents formats de prix total
                    const selectors = [
                        '[data-testid="total-price"]',
                        '[data-testid*="price"]',
                        '[class*="totalPrice"]',
                        '[class*="total-price"]',
                        '[class*="TotalPrice"]',
                        '.price-display',
                        '[class*="ConfiguratorPrice"]',
                        '[class*="summary"] [class*="price"]',
                        'header [class*="price"]'
                    ];
                    
                    for (const sel of selectors) {
                        const el = document.querySelector(sel);
                        if (el) {
                            const text = el.textContent || '';
                            if (text.match(/\d{3}.*€/)) {
                                return { selector: sel, sample: text.substring(0, 50) };
                            }
                        }
                    }
                    
                    // Fallback: chercher n'importe quel élément avec le prix du véhicule
                    const allElements = document.querySelectorAll('*');
                    for (const el of allElements) {
                        if (el.children.length > 3) continue; // Pas un conteneur
                        const text = el.textContent?.trim() || '';
                        if (text.match(/^[\d\s.]+,\d{2}\s*€$/) && text.includes('162')) {
                            return { selector: 'fallback', element: el.tagName + '.' + el.className?.substring(0, 30), sample: text };
                        }
                    }
                    
                    return null;
                });
                
                console.log(`   [DEBUG] Sélecteur prix trouvé: ${JSON.stringify(priceSelector)}`);
                
                for (const opt of optionsWithoutPrice) {
                    try {
                        // Scroller vers l'élément d'abord
                        await page.evaluate((code) => {
                            const input = document.querySelector(`input[name="options"][value="${code}"]`);
                            if (input) {
                                input.scrollIntoView({ behavior: 'instant', block: 'center' });
                            }
                        }, opt.code);
                        
                        await new Promise(r => setTimeout(r, 300));
                        
                        // Trouver et cliquer sur l'option
                        const priceResult = await page.evaluate(async (code) => {
                            // Chercher l'input avec ce code
                            const input = document.querySelector(`input[name="options"][value="${code}"]`);
                            if (!input) return { found: false, error: 'input not found' };
                            
                            // Récupérer le prix actuel du configurateur - chercher dans TOUT le document
                            const getPriceFromPage = () => {
                                // Méthode 1: Chercher dans le header/summary
                                const priceRegex = /(\d{1,3}(?:[\s\u00a0.]\d{3})*)[,.](\d{2})\s*€/g;
                                
                                // Chercher dans des zones spécifiques
                                const zones = [
                                    document.querySelector('header'),
                                    document.querySelector('[class*="summary"]'),
                                    document.querySelector('[class*="total"]'),
                                    document.querySelector('[class*="price"]'),
                                    document.querySelector('[class*="Price"]')
                                ];
                                
                                for (const zone of zones) {
                                    if (!zone) continue;
                                    const text = zone.textContent || '';
                                    const matches = [...text.matchAll(priceRegex)];
                                    for (const match of matches) {
                                        const price = parseFloat(match[1].replace(/[\s\u00a0.]/g, '') + '.' + match[2]);
                                        if (price > 100000 && price < 500000) return price;
                                    }
                                }
                                
                                // Méthode 2: Chercher dans tout le body le prix > 100k
                                const bodyText = document.body.innerText;
                                const allMatches = [...bodyText.matchAll(priceRegex)];
                                for (const match of allMatches) {
                                    const price = parseFloat(match[1].replace(/[\s\u00a0.]/g, '') + '.' + match[2]);
                                    if (price > 100000 && price < 500000) return price;
                                }
                                
                                return null;
                            };
                            
                            const priceBefore = getPriceFromPage();
                            
                            // Cliquer sur le label parent pour activer l'option
                            const label = input.closest('label') || input.parentElement;
                            if (!label) return { found: true, error: 'no label', priceBefore };
                            
                            label.click();
                            
                            // Attendre que le prix se mette à jour
                            await new Promise(r => setTimeout(r, 1000));
                            
                            const priceAfter = getPriceFromPage();
                            
                            // Revenir à l'état initial - resélectionner l'option originale
                            await new Promise(r => setTimeout(r, 200));
                            
                            // Chercher l'option avec checked=true dans la même section
                            let parent = input.parentElement;
                            for (let i = 0; i < 10 && parent; i++) {
                                const checkedInput = parent.querySelector('input[name="options"][checked]');
                                const checkedByAttr = parent.querySelector('input[name="options"]:checked');
                                if (checkedByAttr && checkedByAttr !== input) {
                                    const origLabel = checkedByAttr.closest('label') || checkedByAttr.parentElement;
                                    if (origLabel) {
                                        origLabel.click();
                                        break;
                                    }
                                }
                                parent = parent.parentElement;
                            }
                            
                            if (priceBefore !== null && priceAfter !== null) {
                                const delta = Math.round((priceAfter - priceBefore) * 100) / 100;
                                return { found: true, delta, priceBefore, priceAfter };
                            }
                            
                            return { found: true, delta: null, priceBefore, priceAfter, error: 'price not found' };
                        }, opt.code);
                        
                        if (priceResult.found && priceResult.delta !== null && priceResult.delta >= 0) {
                            opt.price = priceResult.delta;
                            opt.isStandard = (priceResult.delta === 0);
                            console.log(`   ✓ ${opt.code}: ${opt.name?.substring(0, 30)} => ${priceResult.delta}€`);
                        } else {
                            console.log(`   ⚠️ ${opt.code}: ${priceResult.error || 'no delta'} (before=${priceResult.priceBefore}, after=${priceResult.priceAfter})`);
                        }
                        
                        // Petit délai entre chaque clic
                        await new Promise(r => setTimeout(r, 300));
                        
                    } catch (e) {
                        // Ignorer les erreurs de clic
                    }
                }
            }
            
            // ═══════════════════════════════════════════════════════════════
            // DEBUG OUTPUT v6.2 - Vérification des 7 points client
            // ═══════════════════════════════════════════════════════════════
            console.log('\n' + '═'.repeat(70));
            console.log('🔍 DEBUG v6.2 - Vérification des 7 points client:');
            console.log('═'.repeat(70));
            
            // POINT 1: Prix véhicule
            console.log('\n📍 POINT 1 - Prix du véhicule:');
            console.log(`   💰 Prix extrait: ${basePrice?.toLocaleString('fr-FR')} €`);
            console.log(`   ⚠️  Vérifier sur le configurateur si ce prix est correct!`);
            
            // POINT 2: Prix des jantes
            console.log('\n📍 POINT 2 - Prix des jantes (individuels):');
            const wheels = allOptions.filter(o => o.type === 'wheel');
            if (wheels.length > 0) {
                wheels.forEach(w => {
                    console.log(`   🛞 ${w.code}: ${w.name?.substring(0, 50)} => ${w.isStandard ? 'SÉRIE' : (w.price ? w.price + '€' : '??? €')}`);
                });
            } else {
                console.log('   ⚠️ Aucune jante extraite');
            }
            if (debugInfo.price_debug_wheel) {
                console.log('   [DEBUG price_debug_wheel]:', JSON.stringify(debugInfo.price_debug_wheel.slice(0, 5), null, 2));
            }
            
            // POINT 3: Prix couleurs intérieures
            console.log('\n📍 POINT 3 - Prix couleurs intérieures (individuels):');
            const intColors = allOptions.filter(o => o.type === 'color_int');
            if (intColors.length > 0) {
                intColors.forEach(c => {
                    console.log(`   🛋️ ${c.code}: ${c.name?.substring(0, 50)} => ${c.isStandard ? 'SÉRIE' : (c.price ? c.price + '€' : '??? €')} [H3: ${c.subCategory || 'aucun'}]`);
                });
            } else {
                console.log('   ⚠️ Aucune couleur intérieure extraite');
            }
            if (debugInfo.price_debug_color_int) {
                console.log('   [DEBUG price_debug_color_int]:', JSON.stringify(debugInfo.price_debug_color_int.slice(0, 5), null, 2));
            }
            
            // DEBUG: H3 trouvés dans la section Couleurs Intérieures
            if (debugInfo.intColorH3s && debugInfo.intColorH3s.length > 0) {
                console.log('\n   [DEBUG] H3 dans section Couleurs Intérieures:');
                debugInfo.intColorH3s.forEach(h3 => {
                    console.log(`      H3: "${h3.text}" - Prix trouvé: ${h3.priceInContainer || 'aucun'}`);
                });
            }
            
            // DEBUG: Structure HTML des couleurs intérieures
            if (debugInfo.colorIntDebug && debugInfo.colorIntDebug.length > 0) {
                console.log('\n   [DEBUG] Structure HTML couleurs intérieures:');
                debugInfo.colorIntDebug.forEach(c => {
                    console.log(`      ${c.code} (${c.name}):`);
                    console.log(`         Input attrs: checked=${c.inputAttrs?.checked}, disabled=${c.inputAttrs?.disabled}`);
                    c.levels.forEach(l => {
                        console.log(`         Lvl${l.lvl} <${l.tag}> inputs=${l.childInputs} price=${l.hasPrice} serie=${l.hasSerie} => "${l.priceFound || ''}" | ${l.textPreview}`);
                    });
                });
            }
            
            // POINT 4: Sièges (modèles + options)
            console.log('\n📍 POINT 4 - Sièges (modèles et options):');
            const seats = allOptions.filter(o => o.type === 'seat');
            if (seats.length > 0) {
                seats.forEach(s => {
                    console.log(`   💺 ${s.code}: ${s.name?.substring(0, 60)} => ${s.isStandard ? 'SÉRIE' : (s.price ? s.price + '€' : '??? €')}`);
                });
            } else {
                console.log('   ⚠️ Aucun siège de type "seat" extrait');
            }
            
            // DEBUG: Afficher ce qui a été trouvé dans la section Sièges
            if (debugInfo.seatSection) {
                console.log('\n   [DEBUG] Section Sièges - Éléments trouvés:');
                console.log(`      Inputs: ${debugInfo.seatSection.inputs?.length || 0}`);
                if (debugInfo.seatSection.inputs?.length > 0) {
                    debugInfo.seatSection.inputs.forEach(i => {
                        console.log(`         INPUT: ${i.code} - ${i.name}`);
                    });
                }
                console.log(`      Links (options=): ${debugInfo.seatSection.links?.length || 0}`);
                if (debugInfo.seatSection.links?.length > 0) {
                    debugInfo.seatSection.links.forEach(l => {
                        console.log(`         LINK: ${l.code} - ${l.text}`);
                    });
                }
                console.log(`      Links (/option/): ${debugInfo.seatSection.optionLinks?.length || 0}`);
                if (debugInfo.seatSection.optionLinks?.length > 0) {
                    debugInfo.seatSection.optionLinks.forEach(l => {
                        console.log(`         /option/: ${l.code} - ${l.text}`);
                        console.log(`            href: ${l.href}`);
                    });
                }
                console.log(`      H3s: ${debugInfo.seatSection.h3s?.join(' | ') || 'aucun'}`);
            } else {
                console.log('   [DEBUG] Aucune section Sièges (H2) trouvée');
            }
            
            // DEBUG: Sièges extraits via /option/
            if (debugInfo.seatExtracted && debugInfo.seatExtracted.length > 0) {
                console.log('\n   [DEBUG] Sièges extraits via /option/:');
                debugInfo.seatExtracted.forEach(s => {
                    console.log(`      💺 ${s.code}: ${s.name} => ${s.isStandard ? 'SÉRIE' : (s.price ? s.price + '€' : '???€')} [H3: ${s.h3 || 'aucun'}]`);
                });
            }
            
            // Chercher aussi les options qui contiennent "siège" dans le nom
            const seatOptions = allOptions.filter(o => o.type === 'option' && o.name?.toLowerCase().includes('siège'));
            if (seatOptions.length > 0) {
                console.log('   Options liées aux sièges (type=option):');
                seatOptions.forEach(s => {
                    console.log(`      ⚙️ ${s.code}: ${s.name?.substring(0, 50)}`);
                });
            }
            
            // POINT 5: Sous-catégories
            console.log('\n📍 POINT 5 - Sous-catégories (H3):');
            const subCategories = [...new Set(allOptions.map(o => o.subCategory).filter(Boolean))];
            if (subCategories.length > 0) {
                console.log(`   ✓ ${subCategories.length} sous-catégories trouvées:`);
                subCategories.slice(0, 15).forEach(sc => {
                    const count = allOptions.filter(o => o.subCategory === sc).length;
                    console.log(`      • "${sc}" (${count} options)`);
                });
                if (subCategories.length > 15) console.log(`      ... et ${subCategories.length - 15} autres`);
            } else {
                console.log('   ⚠️ Aucune sous-catégorie trouvée');
            }
            
            // DEBUG: Tous les H3 trouvés sur la page
            console.log('\n   [DEBUG] Tous les H3 trouvés sur la page:');
            if (debugInfo.h3sFound && debugInfo.h3sFound.length > 0) {
                debugInfo.h3sFound.slice(0, 20).forEach(h3 => {
                    console.log(`      H3: "${h3}"`);
                });
                if (debugInfo.h3sFound.length > 20) {
                    console.log(`      ... et ${debugInfo.h3sFound.length - 20} autres H3`);
                }
            }
            
            // DEBUG: Section types detected
            if (debugInfo.sectionTypes && debugInfo.sectionTypes.length > 0) {
                console.log('\n   [DEBUG] Types de sections détectés:');
                debugInfo.sectionTypes.forEach(s => {
                    console.log(`      ${s.type}: "${s.h2}"`);
                });
            }
            
            // POINT 6: Stats pour Dashboard (à implémenter côté PHP)
            console.log('\n📍 POINT 6 - Stats pour Dashboard:');
            const exclusiveCount = allOptions.filter(o => o.isExclusiveManufaktur).length;
            const standardCount = allOptions.filter(o => o.isStandard).length;
            const uniqueCodes = [...new Set(allOptions.map(o => o.code))];
            console.log(`   📊 Total options: ${allOptions.length}`);
            console.log(`   📊 Codes uniques: ${uniqueCodes.length}`);
            console.log(`   📊 Exclusive Manufaktur: ${exclusiveCount}`);
            console.log(`   📊 De série: ${standardCount}`);
            console.log(`   📊 Catégories: ${[...new Set(allOptions.map(o => o.category))].length}`);
            
            // POINT 7: Préparation dictionnaire DE
            console.log('\n📍 POINT 7 - Dictionnaire DE:');
            console.log('   ℹ️ Colonne name_de ajoutée à la table p_options');
            console.log('   ℹ️ Utiliser --locale de-DE pour extraire les noms allemands');
            
            // Résumé par type
            console.log('\n📊 RÉSUMÉ PAR TYPE:');
            const byType = {};
            allOptions.forEach(o => {
                if (!byType[o.type]) byType[o.type] = [];
                byType[o.type].push(o);
            });
            Object.entries(byType).forEach(([type, opts]) => {
                const withPrice = opts.filter(o => o.price !== null && o.price > 0).length;
                const standard = opts.filter(o => o.isStandard).length;
                console.log(`   ${type}: ${opts.length} (${withPrice} avec prix, ${standard} de série)`);
            });
            
            // H2 sections trouvées
            console.log('\n📋 H2 SECTIONS TROUVÉES:');
            if (debugInfo.allH2Sections) {
                debugInfo.allH2Sections.forEach(h2 => console.log(`   • ${h2}`));
            }
            
            console.log('\n' + '═'.repeat(70));
            
            // Afficher tous les H2 de la page
            if (debugInfo.allH2Sections && debugInfo.allH2Sections.length > 0) {
                console.log('\n   📍 TOUTES LES SECTIONS H2 DE LA PAGE:');
                debugInfo.allH2Sections.forEach((h2, idx) => {
                    console.log(`      ${idx + 1}. ${h2}`);
                });
            }
            
            // POINT 6 déjà affiché plus haut (données techniques)
            
            // DEBUG supplémentaire
            console.log('\n   📍 DEBUG - Structure HTML:');
            if (debugInfo.exclusiveElements && debugInfo.exclusiveElements.length > 0) {
                console.log('      Éléments "Exclusive Manufaktur" trouvés:');
                debugInfo.exclusiveElements.forEach(e => {
                    console.log(`         <${e.tag} class="${e.classes}"> parent:<${e.parent}>`);
                    console.log(`            next: "${e.nextSibling}"`);
                });
            } else {
                console.log('      ⚠️ Aucun élément "Exclusive Manufaktur" trouvé sur la page');
            }
            
            if (debugInfo.intColorH3s && debugInfo.intColorH3s.length > 0) {
                console.log('      H3s dans Couleurs Intérieures:');
                debugInfo.intColorH3s.forEach(h => {
                    const priceInfo = h.priceInContainer ? ` [prix: ${h.priceInContainer}]` : '';
                    console.log(`         "${h.text}"${priceInfo}`);
                });
            }
            
            // Debug: inputs non extraits
            if (debugInfo.remainingInputs && debugInfo.remainingInputs.length > 0) {
                console.log(`\n   📍 INPUTS NON EXTRAITS (${debugInfo.remainingInputs.length}):`);
                debugInfo.remainingInputs.slice(0, 10).forEach(r => {
                    console.log(`      • ${r.code}: ${r.reason} ${r.name ? `(${r.name.substring(0, 30)})` : ''} ${r.category ? `[${r.category}]` : ''}`);
                });
            } else {
                console.log('\n   ✅ Tous les inputs name="options" ont été extraits');
            }
            
            // Stats
            const stats = {
                colorExt: allOptions.filter(o => o.type === 'color_ext').length,
                colorInt: allOptions.filter(o => o.type === 'color_int').length,
                hood: allOptions.filter(o => o.type === 'hood').length,
                wheel: allOptions.filter(o => o.type === 'wheel').length,
                seat: allOptions.filter(o => o.type === 'seat').length,
                pack: allOptions.filter(o => o.type === 'pack').length,
                option: allOptions.filter(o => o.type === 'option').length,
                exclusive: allOptions.filter(o => o.isExclusiveManufaktur).length,
                withImages: allOptions.filter(o => o.imageUrl).length,
            };
            
            console.log(`\n📊 ${allOptions.length} éléments extraits`);
            console.log(`   🎨 Couleurs ext: ${stats.colorExt}`);
            console.log(`   🛋️ Couleurs int: ${stats.colorInt}`);
            console.log(`   🏠 Capotes: ${stats.hood}`);
            console.log(`   🛞 Jantes: ${stats.wheel}`);
            console.log(`   💺 Sièges: ${stats.seat}`);
            console.log(`   📦 Packs: ${stats.pack}`);
            console.log(`   ⚙️ Options: ${stats.option}`);
            console.log(`   🏷️ Exclusive Manufaktur: ${stats.exclusive}`);
            console.log(`   🖼️ Images: ${stats.withImages}`);
            
            // Sauvegarder
            console.log('\n💾 Sauvegarde...');
            for (const opt of allOptions) {
                await this.db.upsertOption(modelId, opt);
            }
            await this.db.updateModelStats(modelId);
            
            console.log(`\n${'═'.repeat(70)}`);
            console.log(`✅ TERMINÉ v6.2: ${allOptions.length} éléments`);
            console.log(`${'═'.repeat(70)}`);
            
            return allOptions.length;
            
        } catch (error) {
            console.error('❌ Erreur:', error.message);
            return 0;
        } finally {
            await page.close();
        }
    }
    
    /**
     * POINT 7: Extraire les noms allemands depuis le configurateur DE
     */
    async fetchGermanNames(modelCode) {
        console.log(`\n🇩🇪 Extraction des noms allemands pour ${modelCode}...`);
        
        const page = await this.context.newPage();
        
        try {
            // Charger le configurateur allemand
            const url = `https://configurator.porsche.com/de-DE/mode/model/${modelCode}`;
            await page.goto(url, { waitUntil: 'networkidle', timeout: 30000 });
            
            // Accepter cookies
            try {
                await page.getByRole('button', { name: /Alle akzeptieren|accept/i }).click({ timeout: 5000 });
                await page.waitForTimeout(1000);
            } catch (e) {}
            
            // Scroll pour charger tout le contenu
            await page.evaluate(async () => {
                for (let i = 0; i < document.body.scrollHeight; i += 500) {
                    window.scrollTo(0, i);
                    await new Promise(r => setTimeout(r, 100));
                }
                window.scrollTo(0, 0);
            });
            
            // Déployer toutes les sections
            await page.evaluate(async () => {
                const delay = ms => new Promise(r => setTimeout(r, ms));
                const buttons = document.querySelectorAll('button[aria-expanded="false"]');
                for (const btn of buttons) {
                    try {
                        btn.click();
                        await delay(300);
                    } catch (e) {}
                }
            });
            await page.waitForTimeout(2000);
            
            // Extraire les codes et noms allemands
            const germanNames = await page.evaluate(() => {
                const names = {};
                
                // Depuis les inputs
                document.querySelectorAll('input[name="options"]').forEach(input => {
                    const code = input.getAttribute('value');
                    const name = input.getAttribute('aria-label');
                    if (code && name) {
                        names[code] = name;
                    }
                });
                
                // Depuis les liens
                document.querySelectorAll('a[href*="options="]').forEach(link => {
                    const href = link.getAttribute('href') || '';
                    const match = href.match(/options=([A-Z0-9]+)/i);
                    if (match) {
                        const code = match[1];
                        // Chercher le nom dans le contexte
                        let container = link;
                        for (let i = 0; i < 5 && container; i++) {
                            container = container.parentElement;
                            if (!container) break;
                            
                            const h4 = container.querySelector('h4');
                            if (h4) {
                                names[code] = h4.textContent?.trim();
                                break;
                            }
                            
                            const strong = container.querySelector('strong, b');
                            if (strong) {
                                names[code] = strong.textContent?.trim();
                                break;
                            }
                        }
                    }
                });
                
                return names;
            });
            
            console.log(`   ✓ ${Object.keys(germanNames).length} noms allemands extraits`);
            
            // Quelques exemples
            const examples = Object.entries(germanNames).slice(0, 5);
            examples.forEach(([code, name]) => {
                console.log(`      ${code}: ${name?.substring(0, 50)}`);
            });
            
            // Mettre à jour la BDD
            console.log('   💾 Mise à jour des noms allemands en BDD...');
            let updated = 0;
            for (const [code, nameDe] of Object.entries(germanNames)) {
                if (nameDe) {
                    const result = await this.db.pool.query(
                        `UPDATE p_options SET name_de = ? WHERE code = ? AND name_de IS NULL`,
                        [nameDe, code]
                    );
                    if (result[0].affectedRows > 0) updated++;
                }
            }
            console.log(`   ✓ ${updated} options mises à jour avec noms DE`);
            
        } catch (error) {
            console.error('   ❌ Erreur extraction DE:', error.message);
        } finally {
            await page.close();
        }
    }
}

// ═══════════════════════════════════════════════════════════════════════════════
// MAIN
// ═══════════════════════════════════════════════════════════════════════════════

async function main() {
    const args = process.argv.slice(2);
    
    if (args.length === 0) {
        console.log(`
╔══════════════════════════════════════════════════════════════════════════╗
║       PORSCHE OPTIONS EXTRACTOR v6.2 - CLIENT FEEDBACK FIX               ║
╚══════════════════════════════════════════════════════════════════════════╝

Usage:
  node porsche_extractor_v6.2.js --init              Initialiser la BDD
  node porsche_extractor_v6.2.js --model <code>      Extraire un modèle
  node porsche_extractor_v6.2.js --model <code> --visible  Mode visible
  node porsche_extractor_v6.2.js --model <code> --debug    Mode debug
  node porsche_extractor_v6.2.js --model <code> --fetch-de Extraire aussi noms DE

Corrections v6.2 (retour client):
  1. ✅ Prix véhicule: extraction précise du prix de base
  2. ✅ Prix jantes: prix individuels par option
  3. ✅ Prix teintes INT: prix individuels par option  
  4. ✅ Sièges: modèles de sièges + options
  5. ✅ Sous-catégories: H3 complets
  6. ✅ Stats Dashboard: comparaisons, doublons, Exclusive
  7. ✅ Dictionnaire FR/DE: colonne name_de
`);
        return;
    }
    
    const db = new PorscheDB();
    
    if (args.includes('--init')) {
        await db.connect(true);
        await db.initSchema();
        await db.close();
        return;
    }
    
    const modelIndex = args.indexOf('--model');
    if (modelIndex === -1 || !args[modelIndex + 1]) {
        console.log('❌ Spécifiez un code modèle avec --model');
        return;
    }
    
    const modelCodes = args[modelIndex + 1].split(',');
    const visible = args.includes('--visible');
    const debug = args.includes('--debug');
    const fetchDe = args.includes('--fetch-de');
    
    await db.connect();
    
    const extractor = new PorscheExtractor(db);
    await extractor.init(!visible);
    
    console.log(`🚗 Modèle(s): ${modelCodes.join(', ')}`);
    if (fetchDe) console.log('🇩🇪 Extraction noms allemands activée');
    
    for (const code of modelCodes) {
        await extractor.extractModel(code.trim(), debug);
        
        // Extraire les noms allemands si demandé
        if (fetchDe) {
            await extractor.fetchGermanNames(code.trim());
        }
    }
    
    await extractor.close();
    await db.close();
}

main().catch(console.error);