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
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <!-- Google tag (gtag.js) -->
    <script async src="https://www.googletagmanager.com/gtag/js?id=G-4YGWP0F30D"></script>
    <script>
        window.dataLayer = window.dataLayer || [];
        function gtag(){dataLayer.push(arguments);}
        gtag('js', new Date());
        gtag('config', 'G-4YGWP0F30D');
    </script>

    <meta charset="UTF-8">
    <title>Moteur PicoSearch</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>

<div class="header-actions">
    <a href="https://paypal.me/picosearch" class="donate-paypal">Soutenir le projet</a>
</div>

<h1>PicoSearch</h1>

<div class="top-bar">
    <a class="link" href="index.php">Sites</a>
    <a class="link" href="images.php">Images</a>
    <a class="link" href="video.php">Vidéos</a>
</div>

<div class="search-container">
    <form method="get">
        <input type="text" name="q" placeholder="Chercher de l'art..."
               value="<?= htmlspecialchars($_GET['q'] ?? '') ?>">

        <select name="lang" class="lang-select">
            <option value="">Toutes les langues</option>
            <option value="fr" <?= ($_GET['lang'] ?? '') === 'fr' ? 'selected' : '' ?>>Français</option>
            <option value="en" <?= ($_GET['lang'] ?? '') === 'en' ? 'selected' : '' ?>>English</option>
        </select>

        <button type="submit">Chercher</button>
    </form>
</div>

<?php
if (!empty($_GET['q'])) {

    $perPage = 30;
    $page = max(1, (int)($_GET['page'] ?? 1));
    $offset = ($page - 1) * $perPage;

    // Termes de recherche
    $terms = array_filter(explode(' ', trim($_GET['q'])));
    $whereParts = [];
    $params = [];

    foreach ($terms as $term) {
        $whereParts[] = '(title LIKE ? OR url LIKE ? OR snippet LIKE ?)';
        $like = '%' . $term . '%';
        array_push($params, $like, $like, $like);
    }

    $whereSql = $whereParts ? implode(' AND ', $whereParts) : '1=1';

    // Filtre langue
    if (!empty($_GET['lang'])) {
        $whereSql .= ' AND langue = ?';
        $params[] = $_GET['lang'];
    }

    // Compter le total
    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM links WHERE $whereSql");
    $countStmt->execute($params);
    $total = (int)$countStmt->fetchColumn();

    $maxPage = max(1, (int)ceil($total / $perPage));
    if ($page > $maxPage) {
        $page = $maxPage;
        $offset = ($page - 1) * $perPage;
    }

    // Récupération des résultats
    $sql = "SELECT title, url, snippet
            FROM links
            WHERE $whereSql
            ORDER BY id DESC
            LIMIT $perPage OFFSET $offset";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $results = $stmt->fetchAll();

    echo '<div style="margin:10px 0;color:#555">'
        . $total . ' résultat(s) — Page ' . $page . ' / ' . $maxPage .
        '</div>';

    if ($results) {
        foreach ($results as $row) {
            echo '<div class="result">';
            echo '<a href="' . htmlspecialchars($row['url']) . '" target="_blank">'
                . htmlspecialchars($row['title'] ?: $row['url']) . '</a>';
            echo '<div class="url">' . htmlspecialchars($row['url']) . '</div>';
            echo '<div class="snippet">' . htmlspecialchars($row['snippet'] ?? '') . '</div>';
            echo '</div>';
        }
    } else {
        echo '<p>Aucun résultat pour ces mots-clés.</p>';
    }

    // Pagination
    if ($total > $perPage) {
        $q = urlencode($_GET['q']);
        $lang = !empty($_GET['lang']) ? '&lang=' . urlencode($_GET['lang']) : '';

        echo '<div class="pagination" style="margin-top:18px;display:flex;gap:8px;">';

        if ($page > 1) {
            echo '<a class="page-link" href="?q='.$q.$lang.'&page=1">« Première</a>';
            echo '<a class="page-link" href="?q='.$q.$lang.'&page='.($page-1).'">‹ Précédente</a>';
        }

        echo '<span>Page '.$page.' / '.$maxPage.'</span>';

        if ($page < $maxPage) {
            echo '<a class="page-link" href="?q='.$q.$lang.'&page='.($page+1).'">Suivante ›</a>';
            echo '<a class="page-link" href="?q='.$q.$lang.'&page='.$maxPage.'">Dernière »</a>';
        }

        echo '</div>';
    }
}
?>

</body>
</html>
