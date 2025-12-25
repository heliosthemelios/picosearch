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
    <title>Recherche d'images - PicoSearch</title>
    <link rel="stylesheet" href="style.css">

    <style>
        .img-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(180px,1fr)); gap:12px; max-width:1100px; }
        .thumb img { width:100%; height:140px; object-fit:cover; border-radius:6px; display:block; }
        .thumb { background:#fff; padding:8px; border-radius:6px; box-shadow:0 1px 3px rgba(0,0,0,.06); }
        .count { margin:10px 0 20px; color:#555 }
        .thumb-url { margin-top:6px; padding-top:6px; border-top:1px solid #eee; }
        .thumb-url a { font-size:12px; color:#0066cc; text-decoration:none; word-break:break-all; }
        .thumb-url a:hover { text-decoration:underline; }
    </style>
</head>

<body>

<h1>Recherche d'images - PicoSearch</h1>

<div class="top-bar">
    <a class="link" href="index.php">Sites</a>
    <a class="link" href="images.php">Images</a>
    <a class="link" href="video.php">Vidéos</a>
</div>

<form method="get">
    <input type="text" name="q" placeholder="Rechercher des images..."
           value="<?= htmlspecialchars($_GET['q'] ?? '') ?>">

    <label>Mode:</label>
    <select name="op">
        <option value="or" <?= ($_GET['op'] ?? 'or') !== 'and' ? 'selected' : '' ?>>OR</option>
        <option value="and" <?= ($_GET['op'] ?? '') === 'and' ? 'selected' : '' ?>>AND</option>
    </select>

    <label>Champs:</label>
    <?php
        $checked = $_GET['in'] ?? ['title','alt'];
        $checked = is_array($checked) ? $checked : [$checked];
    ?>
    <label><input type="checkbox" name="in[]" value="title" <?= in_array('title',$checked) ? 'checked' : '' ?>> titre</label>
    <label><input type="checkbox" name="in[]" value="alt" <?= in_array('alt',$checked) ? 'checked' : '' ?>> alt</label>
    <label><input type="checkbox" name="in[]" value="url" <?= in_array('url',$checked) ? 'checked' : '' ?>> url</label>

    <button type="submit">Chercher</button>
</form>

<?php
if (!empty($_GET['q'])) {

    $allowedFields = ['url','title','alt'];
    $selected = $_GET['in'] ?? ['title','alt'];
    $selected = is_array($selected) ? $selected : [$selected];
    $fields = array_values(array_intersect($allowedFields, $selected));
    if (!$fields) $fields = ['title','alt'];

    $operator = ($_GET['op'] ?? 'or') === 'and' ? 'AND' : 'OR';

    $perPage = 100;
    $page = max(1, (int)($_GET['page'] ?? 1));
    $offset = ($page - 1) * $perPage;

    $terms = array_filter(explode(' ', trim($_GET['q'])));
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

    $whereSql = $whereParts ? implode(" $operator ", $whereParts) : '1=1';

    // COUNT
    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM images WHERE $whereSql");
    $countStmt->execute($params);
    $total = (int)$countStmt->fetchColumn();

    $maxPage = max(1, (int)ceil($total / $perPage));
    if ($page > $maxPage) {
        $page = $maxPage;
        $offset = ($page - 1) * $perPage;
    }

    // DATA
    $sql = "SELECT title, url, alt, data
            FROM images
            WHERE $whereSql
            LIMIT $perPage OFFSET $offset";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    echo '<div class="count">'.$total.' résultat(s) — Page '.$page.' / '.$maxPage.'</div>';

    if ($rows) {
        echo '<div class="img-grid">';

        foreach ($rows as $row) {
            $src = '';

            if (!empty($row['url'])) {
                $src = $row['url'];
            } elseif (!empty($row['data'])) {
                $mime = 'image/jpeg';
                $src = 'data:'.$mime.';base64,'.base64_encode($row['data']);
            }

            if (!$src) continue;

            $alt = htmlspecialchars($row['alt'] ?: ($row['title'] ?: 'image'));
            $safeSrc = htmlspecialchars($src);

            echo '<div class="thumb">';
            echo '<a href="'.$safeSrc.'" target="_blank"><img src="'.$safeSrc.'" alt="'.$alt.'"></a>';

            if (!empty($row['url'])) {
                $display = strlen($row['url']) > 40 ? substr($row['url'],0,37).'…' : $row['url'];
                echo '<div class="thumb-url"><a href="'.$safeSrc.'" target="_blank">'.htmlspecialchars($display).'</a></div>';
            }

            echo '</div>';
        }

        echo '</div>';

        if ($total > $perPage) {
            $baseQS = http_build_query([
                'q' => $_GET['q'],
                'op' => $_GET['op'] ?? 'or',
                'in' => $fields
            ]);

            echo '<div class="pagination" style="margin-top:18px;display:flex;gap:8px;">';

            if ($page > 1) {
                echo '<a class="page-link" href="?'.$baseQS.'&page=1">« Première</a>';
                echo '<a class="page-link" href="?'.$baseQS.'&page='.($page-1).'">‹ Précédente</a>';
            }

            echo '<span>Page '.$page.' / '.$maxPage.'</span>';

            if ($page < $maxPage) {
                echo '<a class="page-link" href="?'.$baseQS.'&page='.($page+1).'">Suivante ›</a>';
                echo '<a class="page-link" href="?'.$baseQS.'&page='.$maxPage.'">Dernière »</a>';
            }

            echo '</div>';
        }
    } else {
        echo '<p>Aucune image trouvée.</p>';
    }
}
?>

</body>
</html>
