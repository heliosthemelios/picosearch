<?php
require_once __DIR__ . '/env_loader.php';
loadEnv();

// Configuration MySQL
$db_host = env('DB_HOST', 'localhost');
$db_name = env('DB_NAME', 'pico');
$db_user = env('DB_USER', 'root');
$db_password = env('DB_PASSWORD', '');

try {
    $pdo = new PDO(
        "mysql:host=$db_host;dbname=$db_name;charset=utf8mb4",
        $db_user,
        $db_password,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false
        ]
    );
} catch (PDOException $e) {
    die('Erreur de connexion: ' . htmlspecialchars($e->getMessage()));
}

// Paramètres
$query = trim($_GET['q'] ?? '');
$lang  = $_GET['lang'] ?? 'all';
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Vidéos d'Art - PicoSearch</title>
    <link rel="stylesheet" href="style.css">

    <style>
        .video-grid {
            display:grid;
            grid-template-columns:repeat(auto-fill,minmax(350px,1fr));
            gap:25px;
            width:95%;
            max-width:1200px;
            margin-top:30px;
        }
        .video-card {
            background:#fff;
            border-radius:10px;
            padding:15px;
            box-shadow:0 4px 15px rgba(0,0,0,.2);
            border-left:5px solid #8ebde7;
        }
        .video-container {
            position:relative;
            padding-bottom:56.25%;
            height:0;
            overflow:hidden;
            background:#000;
            border-radius:6px;
        }
        .video-container iframe {
            position:absolute;
            top:0; left:0;
            width:100%; height:100%;
            border:0;
        }
        .video-title {
            margin-top:12px;
            color:#003366;
            font-weight:bold;
            font-size:1.1em;
            display:block;
        }
        .platform-badge {
            display:inline-block;
            margin-top:8px;
            font-size:.75em;
            background:#3793ef;
            color:white;
            padding:3px 8px;
            border-radius:20px;
        }
        .pagination {
            display:flex;
            justify-content:center;
            gap:10px;
            margin:40px 0;
            flex-wrap:wrap;
        }
        .pagination a, .pagination span {
            padding:8px 12px;
            background:#3793ef;
            color:white;
            border-radius:5px;
            text-decoration:none;
        }
        .pagination span.disabled {
            background:#555;
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
        <input type="text" name="q" placeholder="Rechercher un vlog, portfolio ou artiste..."
               value="<?= htmlspecialchars($query) ?>">

        <label>Mode:</label>
        <select name="op">
            <option value="or" <?= ($_GET['op'] ?? 'or') !== 'and' ? 'selected' : '' ?>>OR</option>
            <option value="and" <?= ($_GET['op'] ?? '') === 'and' ? 'selected' : '' ?>>AND</option>
        </select>

        <?php
            $checked = $_GET['in'] ?? ['title','platform'];
            $checked = is_array($checked) ? $checked : [$checked];
        ?>
        <label><input type="checkbox" name="in[]" value="title" <?= in_array('title',$checked) ? 'checked' : '' ?>> titre</label>
        <label><input type="checkbox" name="in[]" value="platform" <?= in_array('platform',$checked) ? 'checked' : '' ?>> plateforme</label>

        <input type="hidden" name="lang" value="<?= htmlspecialchars($lang) ?>">
        <button type="submit">Chercher</button>
    </form>
</div>

<?php
if ($query !== '') {

    $allowed = ['title','platform'];
    $fields = array_values(array_intersect($allowed, $checked));
    if (!$fields) $fields = ['title','platform'];

    $op = ($_GET['op'] ?? 'or') === 'and' ? 'AND' : 'OR';

    $perPage = 12;
    $page = max(1, (int)($_GET['page'] ?? 1));
    $offset = ($page - 1) * $perPage;

    $terms = array_filter(explode(' ', $query));
    $whereParts = [];
    $params = [];

    foreach ($terms as $term) {
        $like = '%' . $term . '%';
        $sub = [];
        foreach ($fields as $f) {
            $sub[] = "$f LIKE ?";
            $params[] = $like;
        }
        $whereParts[] = '(' . implode(' OR ', $sub) . ')';
    }

    $whereSql = $whereParts ? implode(" $op ", $whereParts) : '1=1';

    // Filtre langue
    if ($lang === 'fr') {
        $whereSql .= " AND page_url != 'search_dart'";
    } elseif ($lang === 'en') {
        $whereSql .= " AND page_url = 'search_dart'";
    }

    // COUNT
    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM videos WHERE $whereSql");
    $countStmt->execute($params);
    $total = (int)$countStmt->fetchColumn();

    $maxPage = max(1, (int)ceil($total / $perPage));
    if ($page > $maxPage) {
        $page = $maxPage;
        $offset = ($page - 1) * $perPage;
    }

    // DATA
    $stmt = $pdo->prepare(
        "SELECT title, url, platform
         FROM videos
         WHERE $whereSql
         LIMIT $perPage OFFSET $offset"
    );
    $stmt->execute($params);
    $results = $stmt->fetchAll();

    echo '<div style="color:white;text-align:center;margin:15px 0">'
        .$total.' résultat(s) — Page '.$page.' / '.$maxPage.'</div>';
?>

<div class="video-grid">
<?php
    if ($results) {
        foreach ($results as $video) {

            $iframe = $video['url'];

            if (preg_match('/(?:youtube\.com\/watch\?v=|youtu\.be\/)([a-zA-Z0-9_-]{11})/', $iframe, $m)) {
                $iframe = 'https://www.youtube.com/embed/'.$m[1].'?rel=0&modestbranding=1';
            }

            echo '<div class="video-card">';
            echo '<div class="video-container">';
            echo '<iframe src="'.htmlspecialchars($iframe).'" loading="lazy" allowfullscreen></iframe>';
            echo '</div>';
            echo '<span class="video-title">'.htmlspecialchars($video['title']).'</span>';
            echo '<span class="platform-badge">'.htmlspecialchars($video['platform']).'</span>';
            echo '</div>';
        }
    } else {
        echo "<p style='color:white'>Aucune vidéo trouvée.</p>";
    }
?>
</div>

<?php
    if ($total > $perPage) {
        $baseQS = http_build_query([
            'q' => $query,
            'lang' => $lang,
            'op' => $_GET['op'] ?? 'or',
            'in' => $fields
        ]);

        echo '<div class="pagination">';
        if ($page > 1) {
            echo '<a href="?'.$baseQS.'&page=1">« Première</a>';
            echo '<a href="?'.$baseQS.'&page='.($page-1).'">‹ Précédente</a>';
        }
        echo '<span class="current">'.$page.' / '.$maxPage.'</span>';
        if ($page < $maxPage) {
            echo '<a href="?'.$baseQS.'&page='.($page+1).'">Suivante ›</a>';
            echo '<a href="?'.$baseQS.'&page='.$maxPage.'">Dernière »</a>';
        }
        echo '</div>';
    }

} else {
    echo "<p style='color:white;text-align:center'>Utilisez la barre de recherche pour trouver des vidéos.</p>";
}
?>

</body>
</html>
