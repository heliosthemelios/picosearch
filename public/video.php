<?php
require_once __DIR__ . '/env_loader.php';
require_once __DIR__ . '/meilisearch_client.php';
loadEnv();

// Configuration Meilisearch
$meilisearch_host = env('MEILISEARCH_HOST', 'http://localhost:7700');
$meilisearch_key = env('MEILISEARCH_KEY');
$meilisearch = new MeilisearchClient($meilisearch_host, $meilisearch_key);

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
            <label style="margin-left:8px; font-size:13px; color:white;">Mode:</label>
            <select name="op" style="padding:8px; border-radius:5px;">
                <option value="or" <?= (!isset($_GET['op']) || $_GET['op'] === 'or') ? 'selected' : '' ?>>OR</option>
                <option value="and" <?= (isset($_GET['op']) && $_GET['op'] === 'and') ? 'selected' : '' ?>>AND</option>
            </select>
            <label style="margin-left:8px; font-size:13px; color:#333; background:white; padding:4px 8px; border-radius:3px;">Champs:</label>
            <?php
                $checked = $_GET['in'] ?? ['title','platform'];
                $checked = is_array($checked) ? $checked : [$checked];
            ?>
            <label style="color:#333; background:white; padding:4px 8px; border-radius:3px; font-size:13px;"><input type="checkbox" name="in[]" value="title" <?= in_array('title', $checked) ? 'checked' : '' ?>> titre</label>
            <label style="color:#333; background:white; padding:4px 8px; border-radius:3px; font-size:13px;"><input type="checkbox" name="in[]" value="platform" <?= in_array('platform', $checked) ? 'checked' : '' ?>> plateforme</label>
            <input type="hidden" name="lang" value="<?= htmlspecialchars($lang) ?>">
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
            <?php if (isset($_GET['op'])): ?><input type="hidden" name="op" value="<?= htmlspecialchars($_GET['op']) ?>"><?php endif; ?>
            <?php if (isset($_GET['in'])): foreach($_GET['in'] as $field): ?><input type="hidden" name="in[]" value="<?= htmlspecialchars($field) ?>"><?php endforeach; endif; ?>
        </div>
    </div>

    <?php
    if (!empty($query)) {
        // Déterminer les champs à rechercher
        $allowed = ['title','platform'];
        $selected = $_GET['in'] ?? ['title','platform'];
        if (!is_array($selected)) { $selected = [$selected]; }
        $selected_fields = array_values(array_intersect($allowed, $selected));
        if (empty($selected_fields)) { $selected_fields = ['title','platform']; }

        // Mode OR ou AND
        $op = $_GET['op'] ?? 'or';
        $op = ($op === 'and') ? 'and' : 'or';

        // Pagination
        $perPage = 12;
        $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
        if ($page < 1) { $page = 1; }

        // Construire le filtre de langue pour Meilisearch
        $filter = [];
        if ($lang === 'fr') {
            $filter[] = "page_url != 'search_dart'";
        } elseif ($lang === 'en') {
            $filter[] = "page_url = 'search_dart'";
        }

        // Recherche avec Meilisearch
        $search_result = $meilisearch->multiWordSearch(
            'videos',
            $query,
            $selected_fields,
            $op,
            [
                'hitsPerPage' => $perPage,
                'page' => $page,
                'filter' => !empty($filter) ? $filter : null
            ]
        );

        $results = $search_result['hits'];
        $total = $search_result['total'];

        $maxPage = max(1, (int)ceil($total / $perPage));
        if ($page > $maxPage && $maxPage > 0) { $page = $maxPage; }

        // Afficher le nombre total et la page
        echo '<div style="margin: 10px 0 20px; color:#fff; text-align: center;">' . htmlspecialchars((string)$total) . ' résultat(s) — Page ' . htmlspecialchars((string)$page) . ' / ' . htmlspecialchars((string)$maxPage) . '</div>';
    ?>

    <div class="video-grid">
    <?php
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
    ?>
    </div>

    <?php
    } else {
        echo "<p style='color:white; text-align: center;'>Utilisez la barre de recherche pour trouver des vidéos.</p>";
    }
    ?>

    <?php
    if (!empty($query) && $total > $perPage) {
        $q = urlencode($query);
        $langParam = '&lang=' . urlencode($lang);
        echo '<div class="pagination" style="margin-top:18px; display:flex; gap:8px; align-items:center;">';
        // First & Prev
        if ($page > 1) {
            echo '<a href="?q=' . $q . $langParam . '&page=1" class="page-link">« Première</a>';
            echo '<a href="?q=' . $q . $langParam . '&page=' . ($page - 1) . '" class="page-link">‹ Précédente</a>';
        } else {
            echo '<span class="page-link" style="opacity:.5">« Première</span>';
            echo '<span class="page-link" style="opacity:.5">‹ Précédente</span>';
        }

        // Current indicator
        echo '<span style="padding:4px 8px;">Page ' . htmlspecialchars((string)$page) . ' / ' . htmlspecialchars((string)$maxPage) . '</span>';

        // Next & Last
        if ($page < $maxPage) {
            echo '<a href="?q=' . $q . $langParam . '&page=' . ($page + 1) . '" class="page-link">Suivante ›</a>';
            echo '<a href="?q=' . $q . $langParam . '&page=' . $maxPage . '" class="page-link">Dernière »</a>';
        } else {
            echo '<span class="page-link" style="opacity:.5">Suivante ›</span>';
            echo '<span class="page-link" style="opacity:.5">Dernière »</span>';
        }
        echo '</div>';
    }
    ?>
</body>
</html>