<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
set_time_limit(300);
ini_set('memory_limit', '256M'); // Augmenter la limite de mémoire

require_once __DIR__ . '/env_loader.php';
loadEnv();

$meilisearch_host = env('MEILISEARCH_HOST', 'http://localhost:7700');
$meilisearch_key = env('MEILISEARCH_KEY');

function meilisearch_request($method, $endpoint, $data = null) {
    global $meilisearch_host, $meilisearch_key;
    $url = rtrim($meilisearch_host, '/') . $endpoint;
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $meilisearch_key
    ]);
    if ($data !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    }
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['code' => $http_code, 'body' => json_decode($response, true)];
}

$table = $_GET['table'] ?? 'links';
$index_map = [
    'links' => ['index' => 'sites', 'searchable' => ['title', 'url', 'snippet'], 'filterable' => ['langue']],
    'images' => ['index' => 'images', 'searchable' => ['title', 'alt', 'url'], 'filterable' => ['page_url']],
    'videos' => ['index' => 'videos', 'searchable' => ['title', 'platform', 'url'], 'filterable' => ['page_url']]
];

if (!isset($index_map[$table])) {
    die("Table invalide. Utilisez: ?table=links ou ?table=images ou ?table=videos");
}

$config = $index_map[$table];
$index_name = $config['index'];

echo "<pre>";
echo "=== Indexation de $table → $index_name ===\n\n";
flush();

// Connexion MySQL
$host = env('DB_HOST', 'localhost');
$dbname = env('DB_NAME', 'pico');
$user = env('DB_USER', 'root');
$pass = env('DB_PASSWORD');

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    echo "✓ MySQL connecté\n\n";
} catch (PDOException $e) {
    die("✗ Erreur MySQL: " . $e->getMessage());
}

// Créer l'index
echo "Création de l'index $index_name...\n";
meilisearch_request('POST', "/indexes", ['uid' => $index_name, 'primaryKey' => 'id']);
echo "✓ Index créé\n\n";
flush();

// Configuration
echo "Configuration des attributs...\n";
meilisearch_request('PATCH', "/indexes/$index_name/settings/searchable-attributes", $config['searchable']);
meilisearch_request('PATCH', "/indexes/$index_name/settings/filterable-attributes", $config['filterable']);
echo "✓ Attributs configurés\n\n";
flush();

// Compter
$count_stmt = $pdo->query("SELECT COUNT(*) as total FROM $table");
$total = (int)$count_stmt->fetch(PDO::FETCH_ASSOC)['total'];
echo "Total: $total enregistrements\n\n";
flush();

// Indexer par batch de 200 (réduit pour économiser la mémoire)
$batch_size = 200;
$offset = 0;
$batch_num = 1;

// Colonnes à sélectionner (exclure les BLOB pour images)
if ($table === 'images') {
    // Vérifier quelles colonnes existent
    $cols_stmt = $pdo->query("SHOW COLUMNS FROM images");
    $available_cols = [];
    while ($col = $cols_stmt->fetch(PDO::FETCH_ASSOC)) {
        $available_cols[] = $col['Field'];
    }
    // Exclure 'data' (BLOB) et ne sélectionner que les colonnes qui existent
    $wanted = ['id', 'url', 'title', 'alt', 'page_url'];
    $select_cols = array_intersect($wanted, $available_cols);
    $select = implode(', ', $select_cols);
} else {
    $select = '*';
}

while ($offset < $total) {
    $stmt = $pdo->prepare("SELECT $select FROM $table LIMIT :limit OFFSET :offset");
    $stmt->bindValue(':limit', $batch_size, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $batch = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($batch)) break;
    
    $result = meilisearch_request('POST', "/indexes/$index_name/documents", $batch);
    
    if ($result['code'] >= 200 && $result['code'] < 300) {
        echo "✓ Batch $batch_num: " . count($batch) . " documents indexés (offset: $offset)\n";
    } else {
        echo "✗ Erreur batch $batch_num: " . json_encode($result['body']) . "\n";
    }
    flush();
    
    // Libérer la mémoire
    unset($batch);
    unset($stmt);
    gc_collect_cycles();
    
    $offset += $batch_size;
    $batch_num++;
    usleep(50000); // 50ms
}

echo "\n=== Terminé: $table indexé dans Meilisearch ===\n";
echo "\nIndexer une autre table:\n";
echo "- <a href='?table=links'>Indexer les sites (links)</a>\n";
echo "- <a href='?table=images'>Indexer les images</a>\n";
echo "- <a href='?table=videos'>Indexer les vidéos</a>\n";
echo "</pre>";
