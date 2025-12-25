<?php
/**
 * Charge les variables d'environnement depuis le fichier .env
 * Le fichier .env doit être placé HORS du répertoire web pour la sécurité
 */
function loadEnv($path = null) {
    // Cherche .env dans l'ordre de priorité (du plus sécurisé au moins sécurisé)
    if ($path === null) {
        $possiblePaths = [
            '/var/www/.env',                    // Hors de /var/www/html (recommandé)
            dirname(__DIR__) . '/.env',         // Un niveau au-dessus
            __DIR__ . '/.env'                   // Dans le répertoire actuel (moins sécurisé)
        ];
        
        foreach ($possiblePaths as $testPath) {
            if (file_exists($testPath)) {
                $path = $testPath;
                break;
            }
        }
    }
    
    if (!$path || !file_exists($path)) {
        die("Fichier .env introuvable. Veuillez créer un fichier .env basé sur .env.example");
    }
    
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        // Ignorer les commentaires
        if (strpos(trim($line), '#') === 0) {
            continue;
        }
        
        // Parser la ligne KEY=VALUE
        if (strpos($line, '=') !== false) {
            list($key, $value) = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value);
            
            // Supprimer les guillemets si présents
            $value = trim($value, '"\'');
            
            // Définir la variable d'environnement
            putenv("$key=$value");
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
        }
    }
}

/**
 * Récupère une variable d'environnement
 */
function env($key, $default = null) {
    $value = getenv($key);
    return $value !== false ? $value : $default;
}
