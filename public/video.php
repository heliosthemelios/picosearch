<?php
require_once __DIR__ . '/env_loader.php'; // Ajusté selon votre structure
loadEnv();

$host = env('DB_HOST', 'localhost');
$dbname = env('DB_NAME', 'pico');
$user = env('DB_USER', 'root');
$pass = env('DB_PASSWORD');

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Erreur de connexion : " . $e->getMessage());
}

// Configuration pagination
$videos_per_page = 12;
$current_page = max(1, intval($_GET['page'] ?? 1));
$offset = ($current_page - 1) * $videos_per_page;

// Récupération des paramètres de recherche
$query = trim($_GET['q'] ?? '');
$lang = $_GET['lang'] ?? 'all'; // 'fr', 'en' ou 'all'
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Vidéos d'Art - PicoSearch</title>
    <link rel="stylesheet" href="style.css"> <style>
        .video-grid { 
            display: grid; 
            grid-template-columns: repeat(auto-fill, minmax(350px, 1fr)); 
            gap: 25px; 
            width: 95%; 
            max-width: 1200px; 
            margin-top: 30px; 
        }
        .video-card { 
            background: #ffffff; 
            border-radius: 10px; 
            padding: 15px; 
            box-shadow: 0 4px 15px rgba(0,0,0,0.2); 
            border-left: 5px solid #8ebde7; 
        }
        .video-container { 
            position: relative; 
            padding-bottom: 56.25%; 
            height: 0; 
            overflow: hidden; 
            border-radius: 6px;
            background: #000;
        }
        .video-container iframe { 
            position: absolute; top: 0; left: 0; width: 100%; height: 100%; border: 0; 
        }
        .video-title { 
            margin-top: 12px; 
            color: #003366; 
            font-weight: bold; 
            font-size: 1.1em;
            display: block;
        }
        .platform-badge { 
            display: inline-block;
            margin-top: 8px;
            font-size: 0.75em; 
            background: #3793ef; 
            color: white; 
            padding: 3px 8px; 
            border-radius: 20px; 
        }
        .filters-container {
            display: flex;
            gap: 20px;
            align-items: center;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }
        .filter-group {
            display: flex;
            gap: 10px;
            align-items: center;
        }
        .filter-group label {
            color: white;
            font-weight: bold;
        }
        .filter-group select {
            padding: 8px 12px;
            border-radius: 5px;
            border: 1px solid #888;
            background: #333;
            color: white;
            cursor: pointer;
        }
        .pagination {
            display: flex;
            justify-content: center;
            gap: 10px;
            margin: 40px 0;
            flex-wrap: wrap;
        }
        .pagination a, .pagination span {
            display: inline-block;
            padding: 8px 12px;
            background: #3793ef;
            color: white;
            text-decoration: none;
            border-radius: 5px;
            transition: background 0.3s;
        }
        .pagination a:hover {
            background: #2575c5;
        }
        .pagination span.current {
            background: #8ebde7;
            cursor: default;
        }
        .pagination span.disabled {
            background: #555;
            cursor: not-allowed;
        }
    </style>
</head>
<body>
    <h1>PicoSearch Vidéos</h1>
    
    <div class="top-bar">
        <a class="link" href="index.php">Sites</a>
        <a class="link" href="images.php">Images</a>
        <a class="link" href="video.php" style="background:#e7c30b;">Vidéos</a>
    </div>

    <div class="search-container">
        <form method="get">
            <input type="text" name="q" placeholder="Rechercher un vlog, portfolio ou artiste..." value="<?= htmlspecialchars($query) ?>">
            <button type="submit">Chercher</button>
        </form>
    </div>

    <div class="filters-container">
        <div class="filter-group">
            <label for="lang">Langue :</label>
            <select name="lang" id="lang" onchange="document.querySelector('form').submit();">
                <option value="all" <?= $lang === 'all' ? 'selected' : '' ?>>Toutes</option>
                <option value="fr" <?= $lang === 'fr' ? 'selected' : '' ?>>Français</option>
                <option value="en" <?= $lang === 'en' ? 'selected' : '' ?>>English</option>
            </select>
            <input type="hidden" name="q" value="<?= htmlspecialchars($query) ?>">
        </div>
    </div>

    <div class="video-grid">
    <?php
    if (!empty($query)) {
        // Construction de la requête avec filtre langue
        $where = "(title LIKE :q OR platform LIKE :q)";
        $params = ['q' => "%$query%"];
        
        // Filtre langue basé sur le champ page_url (search_dart = anglais généralement)
        if ($lang === 'fr') {
            $where .= " AND page_url != 'search_dart'"; 
        } elseif ($lang === 'en') {
            $where .= " AND page_url = 'search_dart'"; 
        }

        // Compter le total de résultats pour la pagination
        $count_stmt = $pdo->prepare("SELECT COUNT(*) as total FROM videos WHERE $where");
        $count_stmt->execute($params);
        $total_results = $count_stmt->fetch(PDO::FETCH_ASSOC)['total'];
        $total_pages = ceil($total_results / $videos_per_page);

        // Récupérer les vidéos pour la page actuelle
        $sql = "SELECT * FROM videos WHERE $where ORDER BY id DESC LIMIT $videos_per_page OFFSET $offset";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if ($results) {
            foreach ($results as $video) {
                // Convertir l'URL YouTube au format embed si nécessaire
                $iframe_url = $video['url'];
                
                // Si c'est un URL YouTube classique, le convertir en embed
                if (strpos($iframe_url, 'youtube.com') !== false || strpos($iframe_url, 'youtu.be') !== false) {
                    // Extraire l'ID vidéo YouTube
                    if (preg_match('/(?:youtube\.com\/watch\?v=|youtu\.be\/)([a-zA-Z0-9_-]{11})/', $iframe_url, $matches)) {
                        $video_id = $matches[1];
                        $iframe_url = "https://www.youtube.com/embed/$video_id?modestbranding=1&rel=0";
                    }
                }
                
                echo '<div class="video-card">';
                echo '  <div class="video-container">';
                echo '    <iframe src="' . htmlspecialchars($iframe_url) . '" allowfullscreen loading="lazy" title="' . htmlspecialchars($video['title']) . '"></iframe>';
                echo '  </div>';
                echo '  <span class="video-title">' . htmlspecialchars($video['title']) . '</span>';
                echo '  <span class="platform-badge">' . htmlspecialchars($video['platform']) . '</span>';
                echo '</div>';
            }
        } else {
            echo "<p style='color:white;'>Aucune vidéo d'art trouvée pour ce mot-clé.</p>";
        }
    } else {
        echo "<p style='color:white;'>Utilisez la barre de recherche pour trouver des vidéos.</p>";
    }
    ?>
    </div>

    <?php if (!empty($query) && $total_pages > 1): ?>
    <div class="pagination">
        <?php
        // Lien page précédente
        if ($current_page > 1) {
            echo '<a href="?q=' . urlencode($query) . '&lang=' . $lang . '&page=' . ($current_page - 1) . '">← Précédent</a>';
        } else {
            echo '<span class="disabled">← Précédent</span>';
        }

        // Numéros de pages
        for ($i = 1; $i <= $total_pages; $i++) {
            if ($i == $current_page) {
                echo '<span class="current">' . $i . '</span>';
            } else {
                echo '<a href="?q=' . urlencode($query) . '&lang=' . $lang . '&page=' . $i . '">' . $i . '</a>';
            }
        }

        // Lien page suivante
        if ($current_page < $total_pages) {
            echo '<a href="?q=' . urlencode($query) . '&lang=' . $lang . '&page=' . ($current_page + 1) . '">Suivant →</a>';
        } else {
            echo '<span class="disabled">Suivant →</span>';
        }
        ?>
    </div>
    <?php endif; ?>
</body>
</html>