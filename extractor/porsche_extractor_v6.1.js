/**
 * PORSCHE OPTIONS EXTRACTOR v6.1 - COMPLETE FIX
 * 
 * Corrections v6.1:
 * 1. ✅ Teintes INT: sous-catégories (Race-Tex, etc.) + prix H3
 * 2. ✅ Teintes EXT: photos de capote distinctes
 * 3. ✅ Ordre configurateur + accordéon
 * 4. ✅ Exclusive Manufaktur: nom complet (texte en dessous)
 * 5. ✅ Catégories bien séparées
 * 6. ✅ Équipement de série + données techniques
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
            `INSERT INTO p_options (model_id, category_id, code, name, description, price, is_standard, is_exclusive_manufaktur, option_type, sub_category, image_url, display_order) 
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?) 
             ON DUPLICATE KEY UPDATE 
                name = VALUES(name), 
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
            
            // Extraire nom et prix de base
            const modelName = await page.locator('h1').first().textContent() || modelCode;
            console.log(`📋 ${modelName.trim()}`);
            
            const basePrice = await page.evaluate(() => {
                const allPrices = [];
                const priceRegex = /(\d{1,3}(?:[\s\u00a0]\d{3})*[,.]\d{2})\s*€/g;
                const text = document.body.innerText;
                let match;
                while ((match = priceRegex.exec(text)) !== null) {
                    const price = parseFloat(match[1].replace(/[\s\u00a0]/g, '').replace(',', '.'));
                    if (price > 30000 && price < 1000000) {
                        allPrices.push(price);
                    }
                }
                return allPrices.length > 0 ? allPrices[0] : null;
            });
            console.log(`💰 ${basePrice?.toLocaleString('fr-FR')} €`);
            
            // ═══════════════════════════════════════════════════════════════
            // POINT 6: Extraire données techniques et équipement de série
            // ═══════════════════════════════════════════════════════════════
            console.log('\n📊 Extraction des données techniques...');
            
            let technicalData = {};
            let standardEquipment = [];
            
            try {
                // Cliquer sur le lien des données techniques
                const techLink = page.getByRole('link', { name: /données techniques/i }).first();
                if (await techLink.count() > 0) {
                    await techLink.click();
                    await page.waitForTimeout(3000);
                    
                    console.log(`   📍 URL: ${page.url()}`);
                    
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
                    // DEBUG POINT 6: Afficher quelques specs
                    const techSamples = Object.entries(technicalData).slice(0, 3);
                    techSamples.forEach(([k, v]) => console.log(`      - ${k}: ${v}`));
                    
                    // Cliquer sur l'onglet équipement de série
                    try {
                        const equipTab = page.locator('button[role="tab"]').filter({ hasText: /équipement/i }).first();
                        if (await equipTab.count() > 0) {
                            console.log(`   🖱️ Clic sur onglet "Équipements de série"...`);
                            await equipTab.click();
                            await page.waitForTimeout(3000);
                            
                            // DEBUG: Afficher le contenu de la page après clic
                            const pageContent = await page.evaluate(() => {
                                const content = {
                                    activeTab: document.querySelector('button[role="tab"][aria-selected="true"]')?.textContent?.trim(),
                                    h3s: Array.from(document.querySelectorAll('h3')).map(h => h.textContent?.trim()).slice(0, 10),
                                    listsCount: document.querySelectorAll('ul, ol').length,
                                    liCount: document.querySelectorAll('li').length,
                                };
                                return content;
                            });
                            
                            console.log(`   📑 Onglet actif: ${pageContent.activeTab}`);
                            console.log(`   📋 H3s: ${pageContent.h3s.slice(0, 5).join(', ')}`);
                            console.log(`   📋 Listes: ${pageContent.listsCount}, Items: ${pageContent.liCount}`);
                            
                            // Extraire l'équipement depuis le panel actif
                            standardEquipment = await page.evaluate(() => {
                                const items = [];
                                const seen = new Set();
                                
                                // Mots à exclure
                                const excludeWords = [
                                    'télécharger', 'pdf', 'tva', 'cookie', 'politique', 
                                    'données techniques', 'équipement de série', 'configuration',
                                    'accepter', 'refuser', 'paramètre', 'en savoir plus',
                                    'couleur de série', 'couleur métallisée', 'couleur spéciale',
                                    'capote', 'pouces', 'jantes'
                                ];
                                
                                // Patterns à exclure (dimensions, specs techniques)
                                const excludePatterns = [
                                    /^\d+[,.]?\d*\s*x\s*\d+/i,  // 8,5x20
                                    /^\d+\/\d+\s*(zr|r)\s*\d+/i, // 245/35 ZR 20
                                    /^\d+\s*(ch|kw|nm|km|ps|cv)/i, // 500 ch, 450 Nm
                                    /^[\d\s.,]+€/, // Prix
                                    /puissance.*\d+\s*(kw|ch|ps)/i, // Puissance maximale 368 kW
                                    /couple.*\d+\s*nm/i, // Couple maximal 450 Nm
                                    /^\d+\s*haut-parleurs?$/i, // 6 haut-parleurs
                                    /^\d+\s*watts?$/i, // 110 watts
                                    /amplificateur/i // Amplificateur intégré
                                ];
                                
                                const shouldExclude = (text) => {
                                    const lower = text.toLowerCase();
                                    if (excludeWords.some(w => lower.includes(w))) return true;
                                    if (excludePatterns.some(p => p.test(text))) return true;
                                    if (text.includes('€')) return true;
                                    if (text.length < 15 || text.length > 300) return true;
                                    return false;
                                };
                                
                                // Chercher dans le panel actif ou le contenu visible
                                const activePanel = document.querySelector('[role="tabpanel"]:not([hidden])') ||
                                                   document.querySelector('[role="tabpanel"][aria-hidden="false"]') ||
                                                   document.querySelector('.tab-content:not(.hidden)');
                                
                                const searchRoot = activePanel || document.body;
                                
                                // Méthode 1: Chercher les listes
                                searchRoot.querySelectorAll('li').forEach(li => {
                                    if (li.closest('nav, header, footer, [role="menu"], [role="tablist"]')) return;
                                    
                                    const text = li.textContent?.trim();
                                    if (text && !shouldExclude(text) && !seen.has(text)) {
                                        // Vérifier que c'est un vrai équipement (contient des mots descriptifs)
                                        const hasDescriptiveWords = /système|éclairage|rétroviseur|volant|suspension|direction|climatisation|audio|bluetooth|usb|navigation|caméra|capteur|aide|assistant|frein|airbag|ceinture|siège|chauffant|cuir|aluminium|sport|confort|pack/i.test(text);
                                        if (hasDescriptiveWords || text.length > 30) {
                                            seen.add(text);
                                            items.push(text);
                                        }
                                    }
                                });
                                
                                // Méthode 2: Chercher les paragraphes structurés si pas assez de résultats
                                if (items.length < 10) {
                                    searchRoot.querySelectorAll('p, div[class*="equipment"], div[class*="feature"]').forEach(el => {
                                        if (el.closest('nav, header, footer')) return;
                                        if (el.querySelectorAll('*').length > 3) return; // Pas trop d'enfants
                                        
                                        const text = el.textContent?.trim();
                                        if (text && !shouldExclude(text) && !seen.has(text)) {
                                            seen.add(text);
                                            items.push(text);
                                        }
                                    });
                                }
                                
                                return items;
                            });
                            
                            console.log(`   ✓ ${standardEquipment.length} équipements de série`);
                            // DEBUG POINT 6: Afficher quelques équipements
                            standardEquipment.slice(0, 5).forEach(e => console.log(`      • ${e.substring(0, 60)}...`));
                        }
                    } catch (e) {
                        console.log(`   ⚠️ Onglet équipement: ${e.message}`);
                    }
                    
                    // Revenir au configurateur
                    await page.goBack();
                    await page.waitForTimeout(2000);
                }
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
            
            const extractionResult = await page.evaluate((imageMap) => {
                const results = [];
                const seen = new Set();
                let globalDisplayOrder = 0;
                
                // DEBUG info to return
                const debugInfo = {
                    point1_intSubCategories: {},
                    point2_hoods: [],
                    point4_exclusive: [],
                    h3sFound: [],
                    exclusiveElements: [],
                    intColorH3s: []
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
                    
                    // Extraire les inputs (couleurs, jantes, sièges)
                    section.querySelectorAll('input[name="options"]').forEach(input => {
                        const code = input.getAttribute('value');
                        const name = input.getAttribute('aria-label');
                        
                        if (!code || !name || seen.has(code)) return;
                        seen.add(code);
                        
                        // POINT 1: Trouver H3 avec prix pour sous-catégorie
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
                        
                        // Prix
                        let price = null;
                        let isStandard = false;
                        
                        // Utiliser le prix H3 pour les couleurs intérieures
                        if (type === 'color_int' && h3Price !== null) {
                            price = h3Price;
                            isStandard = (h3Price === 0);
                        } else {
                            // Chercher le prix dans le contexte
                            let el = input;
                            for (let i = 0; i < 10 && el; i++) {
                                el = el.parentElement;
                                if (!el) break;
                                
                                const text = el.innerText || '';
                                const priceMatch = text.match(/(\d{1,3}(?:[\s\u00a0]\d{3})*[,.]\d{2})\s*€/);
                                if (priceMatch) {
                                    const val = parseFloat(priceMatch[1].replace(/[\s\u00a0]/g, '').replace(',', '.'));
                                    if (val >= 0 && val < 100000) {
                                        price = val;
                                        isStandard = (val === 0);
                                        break;
                                    }
                                }
                            }
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
                    
                    // Extraire les liens (options)
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
                
                // DEBUG: capturer les boutons trouvés
                const debugButtons = [];
                
                sectionButtons.forEach(btn => {
                    // Utiliser aria-controls pour identifier les sections (plus fiable que le texte)
                    const sectionContainerId = btn.getAttribute('aria-controls') || '';
                    const btnText = btn.textContent?.trim() || '';
                    const btnLower = btnText.toLowerCase();
                    
                    // DEBUG: capturer tous les boutons avec "accessoires" ou "livraison"
                    if (btnLower.includes('accessoires') || btnLower.includes('livraison')) {
                        debugButtons.push({ 
                            text: btnText.substring(0, 60), 
                            id: sectionContainerId,
                            startsWithAccessoires: btnLower.startsWith('accessoires pour véhicules'),
                            includesAccessoiresPourV: btnLower.includes('accessoires pour v')
                        });
                    }
                    
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
                debugInfo.debugButtons = debugButtons;
                
                return { results, debugInfo };
            }, imageMap);
            
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
            // DEBUG OUTPUT
            // ═══════════════════════════════════════════════════════════════
            console.log('\n🔍 DEBUG - Vérification des 6 points:');
            
            // POINT 1: Sous-catégories intérieures
            console.log('\n   📍 POINT 1 - Sous-catégories couleurs intérieures:');
            if (Object.keys(debugInfo.point1_intSubCategories).length > 0) {
                Object.entries(debugInfo.point1_intSubCategories).forEach(([name, data]) => {
                    console.log(`      ✓ "${name}": ${data.count} items, prix: ${data.price !== null ? data.price + '€' : 'non trouvé'}`);
                });
            } else {
                console.log('      ⚠️ Aucune sous-catégorie trouvée');
                console.log('      H3s sur la page:', debugInfo.h3sFound.slice(0, 10).join(', '));
            }
            
            // POINT 2: Capotes
            console.log('\n   📍 POINT 2 - Capotes détectées:');
            if (debugInfo.point2_hoods.length > 0) {
                debugInfo.point2_hoods.forEach(h => {
                    console.log(`      ✓ ${h.code}: "${h.name}" (H3: "${h.h3}")`);
                });
            } else {
                console.log('      ⚠️ Aucune capote détectée');
            }
            
            // POINT 3: Ordre (vérifier display_order)
            console.log('\n   📍 POINT 3 - Ordre d\'extraction:');
            const first5 = allOptions.slice(0, 5);
            first5.forEach(o => console.log(`      ${o.displayOrder}. [${o.type}] ${o.code}: ${o.name?.substring(0, 40)}`));
            console.log(`      ... (${allOptions.length} total)`);
            
            // POINT 4: Exclusive Manufaktur
            console.log('\n   📍 POINT 4 - Exclusive Manufaktur:');
            if (debugInfo.point4_exclusive.length > 0) {
                debugInfo.point4_exclusive.forEach(e => {
                    console.log(`      ✓ ${e.code}: "${e.realName || '(nom non extrait)'}" [${e.source || 'link'}]`);
                });
            } else {
                console.log('      ⚠️ Aucune option Exclusive Manufaktur trouvée');
                // Vérifier si le texte existe sur la page
                const hasExclusive = debugInfo.h3sFound.some(h => h.toLowerCase().includes('exclusive'));
                console.log(`      (Texte "exclusive" dans H3: ${hasExclusive ? 'OUI' : 'NON'})`);
            }
            
            // POINT 5: Catégories (vérifier la séparation)
            console.log('\n   📍 POINT 5 - Catégories extraites:');
            const categories = [...new Set(allOptions.map(o => o.category))];
            categories.forEach(c => {
                const count = allOptions.filter(o => o.category === c).length;
                console.log(`      • ${c}: ${count} items`);
            });
            
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
            
            // DEBUG: afficher les boutons Accessoires/Livraison trouvés
            if (debugInfo.debugButtons && debugInfo.debugButtons.length > 0) {
                console.log(`\n   📍 DEBUG BOUTONS ACCESSOIRES/LIVRAISON:`);
                debugInfo.debugButtons.forEach(b => {
                    console.log(`      • "${b.text}" id=${b.id || 'none'} startsWith=${b.startsWithAccessoires} includes=${b.includesAccessoiresPourV}`);
                });
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
            console.log(`✅ TERMINÉ v6.1: ${allOptions.length} éléments`);
            console.log(`${'═'.repeat(70)}`);
            
            return allOptions.length;
            
        } catch (error) {
            console.error('❌ Erreur:', error.message);
            return 0;
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
║       PORSCHE OPTIONS EXTRACTOR v6.1 - COMPLETE FIX                      ║
╚══════════════════════════════════════════════════════════════════════════╝

Usage:
  node porsche_extractor_v6.1.js --init              Initialiser la BDD
  node porsche_extractor_v6.1.js --model <code>      Extraire un modèle
  node porsche_extractor_v6.1.js --model <code> --visible  Mode visible
  node porsche_extractor_v6.1.js --model <code> --debug    Mode debug

Corrections v6.1:
  1. ✅ Teintes INT: sous-catégories + prix H3
  2. ✅ Teintes EXT: capotes distinctes
  3. ✅ Ordre configurateur conservé
  4. ✅ Exclusive Manufaktur: nom complet
  5. ✅ Catégories séparées visuellement
  6. ✅ Données techniques + équipements
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
    
    await db.connect();
    
    const extractor = new PorscheExtractor(db);
    await extractor.init(!visible);
    
    console.log(`🚗 Modèle(s): ${modelCodes.join(', ')}`);
    
    for (const code of modelCodes) {
        await extractor.extractModel(code.trim(), debug);
    }
    
    await extractor.close();
    await db.close();
}

main().catch(console.error);