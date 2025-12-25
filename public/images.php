<?php
require_once __DIR__ . '/env_loader.php';
require_once __DIR__ . '/meilisearch_client.php';
loadEnv();

// Configuration Meilisearch
$meilisearch_host = env('MEILISEARCH_HOST', 'http://localhost:7700');
$meilisearch_key = env('MEILISEARCH_KEY');
$meilisearch = new MeilisearchClient($meilisearch_host, $meilisearch_key);
?>

<!DOCTYPE html>
<html lang="fr">
<head><!-- Google tag (gtag.js) -->
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
    .img-grid { display: grid; grid-template-columns: repeat(auto-fill,minmax(180px,1fr)); gap:12px; max-width:1100px; }
    .thumb img { width:100%; height:140px; object-fit:cover; border-radius:6px; display:block; }
    .thumb { background:#fff; padding:8px; border-radius:6px; box-shadow:0 1px 3px rgba(0,0,0,0.06); }
    .count { margin: 10px 0 20px; color:#555 }
    .thumb-url { margin-top:6px; padding-top:6px; border-top:1px solid #eee; }
    .thumb-url a { font-size:12px; color:#0066cc; text-decoration:none; word-break:break-all; display:block; }
    .thumb-url a:hover { text-decoration:underline; }
    </style>
</head>
<body>

    <h1>Recherche d'images - PicoSearch</h1>
     <div class="top-bar">
        <a class="link" href="index.php" title="Recherche de site">Sites</a>
        <a class="link" href="images.php" title="Recherche d'images">images</a>
        <a class="link" href="video.php" title="Recherche de vidéos">vidéos</a>
    </div>
    <!-- Formulaire simple de recherche (GET) -->
    <form method="get">
        <input type="text" name="q" placeholder="Rechercher des images..." value="<?= htmlspecialchars($_GET['q'] ?? '') ?>">
        <label style="margin-left:8px; font-size:13px">Mode:</label>
        <select name="op">
            <option value="or" <?= (isset($_GET['op']) && $_GET['op'] === 'and') ? '' : 'selected' ?>>OR</option>
            <option value="and" <?= (isset($_GET['op']) && $_GET['op'] === 'and') ? 'selected' : '' ?>>AND</option>
        </select>
        <label style="margin-left:8px; font-size:13px">Champs:</label>
        <?php
            $checked = $_GET['in'] ?? ['title','alt'];
            $checked = is_array($checked) ? $checked : [$checked];
        ?>
        <label><input type="checkbox" name="in[]" value="title" <?= in_array('title', $checked) ? 'checked' : '' ?>> titre</label>
        <label><input type="checkbox" name="in[]" value="alt" <?= in_array('alt', $checked) ? 'checked' : '' ?>> alt</label>
        <label><input type="checkbox" name="in[]" value="url" <?= in_array('url', $checked) ? 'checked' : '' ?>> url</label>
        <button type="submit">Chercher</button>
    </form>

    <?php
    // Si un terme de recherche est fourni, on le traite
    if (!empty($_GET['q'])) {
        // Déterminer les champs à rechercher (par défaut title + alt)
        $allowed = ['url','title','alt'];
        $selected = $_GET['in'] ?? ['title','alt'];
        if (!is_array($selected)) { $selected = [$selected]; }
        $selected_fields = array_values(array_intersect($allowed, $selected));
        if (empty($selected_fields)) { $selected_fields = ['title','alt']; }

        // Mode OR ou AND
        $op = $_GET['op'] ?? 'or';
        $op = ($op === 'and') ? 'and' : 'or';

        // Pagination
        $perPage = 100;
        $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
        if ($page < 1) { $page = 1; }

        // Recherche avec Meilisearch
        $search_result = $meilisearch->multiWordSearch(
            'images',
            $_GET['q'],
            $selected_fields,
            $op,
            [
                'hitsPerPage' => $perPage,
                'page' => $page
            ]
        );

        $rows = $search_result['hits'];
        $total = $search_result['total'];

        if (!empty($rows)) {
            $maxPage = max(1, (int)ceil($total / $perPage));
            if ($page > $maxPage && $maxPage > 0) { $page = $maxPage; }

            // Afficher le nombre total et la page
            echo '<div class="count">' . htmlspecialchars((string)$total) . ' résultat(s) — Page ' . htmlspecialchars((string)$page) . ' / ' . htmlspecialchars((string)$maxPage) . '</div>';

            if (count($rows) > 0) {
                echo '<div class="img-grid">';

                // Pour chaque ligne, déterminer la source de l'image : URL ou BLOB
                foreach ($rows as $row) {
                    $src = '';

                    // 1) Si une URL est stockée, l'utiliser directement (meilleure option)
                    if (!empty($row['url'])) {
                        $src = $row['url'];

                    // 2) Sinon, si on a le BLOB `data`, on l'encapsule en data-uri
                    } elseif (!empty($row['data'])) {
                        // Par défaut, on suppose JPEG. Si l'URL est dispo, on essaie d'en déduire le type
                        $mime = 'image/jpeg';
                        if (!empty($row['url'])) {
                            $ext = strtolower(pathinfo(parse_url($row['url'], PHP_URL_PATH), PATHINFO_EXTENSION));
                            $map = ['png'=>'image/png','jpg'=>'image/jpeg','jpeg'=>'image/jpeg','gif'=>'image/gif','webp'=>'image/webp'];
                            if (!empty($map[$ext])) $mime = $map[$ext];
                        }
                        // encoder le BLOB en base64 pour afficher dans `src`
                        $src = 'data:' . $mime . ';base64,' . base64_encode($row['data']);
                    }

                    // Si aucune source n'est trouvée, on ignore l'entrée
                    if (empty($src)) continue;

                    // Échapper la valeur pour éviter les injections HTML/JS
                    $safeSrc = htmlspecialchars($src);

                    // Déterminer l'attribut alt à afficher (priorité: alt, title, fallback)
                    $imgAlt = '';
                    if (!empty($row['alt'])) {
                        $imgAlt = $row['alt'];
                    } elseif (!empty($row['title'])) {
                        $imgAlt = $row['title'];
                    } else {
                        $imgAlt = 'image';
                    }
                    $safeAlt = htmlspecialchars($imgAlt);

                    // Rendu d'une vignette cliquable (ouvre l'image complète dans un nouvel onglet)
                    echo '<div class="thumb">';
                    echo '<a href="' . $safeSrc . '" target="_blank"><img src="' . $safeSrc . '" alt="' . $safeAlt . '"></a>';
                    
                    // Ajouter le lien source en dessous de l'image
                    if (!empty($row['url'])) {
                        $displayUrl = strlen($row['url']) > 40 ? substr($row['url'], 0, 37) . '...' : $row['url'];
                        // Utiliser le titre/alt comme tooltip si disponible
                        $linkTitle = !empty($row['title']) ? htmlspecialchars($row['title']) : $safeSrc;
                        echo '<div class="thumb-url"><a href="' . $safeSrc . '" target="_blank" title="' . $linkTitle . '">' . htmlspecialchars($displayUrl) . '</a></div>';
                    }
                    
                    echo '</div>';
                }

                echo '</div>'; // .img-grid

                // Pagination UI (afficher seulement si plus de 100 résultats)
                if ($total > $perPage) {
                    // Construire la base de la querystring pour pagination en préservant q/op/in
                    $op = (isset($_GET['op']) && $_GET['op'] === 'and') ? 'and' : 'or';
                    $baseQS = http_build_query(['q' => $_GET['q'] ?? '', 'op' => $op, 'in' => $selected_fields]);
                    echo '<div class="pagination" style="margin-top:18px; display:flex; gap:8px; align-items:center;">';
                    // First & Prev
                    if ($page > 1) {
                        echo '<a href="?' . $baseQS . '&page=1" class="page-link">« Première</a>';
                        echo '<a href="?' . $baseQS . '&page=' . ($page - 1) . '" class="page-link">‹ Précédente</a>';
                    } else {
                        echo '<span class="page-link" style="opacity:.5">« Première</span>';
                        echo '<span class="page-link" style="opacity:.5">‹ Précédente</span>';
                    }

                    // Current indicator
                    echo '<span style="padding:4px 8px;">Page ' . htmlspecialchars((string)$page) . ' / ' . htmlspecialchars((string)$maxPage) . '</span>';

                    // Next & Last
                    if ($page < $maxPage) {
                        echo '<a href="?' . $baseQS . '&page=' . ($page + 1) . '" class="page-link">Suivante ›</a>';
                        echo '<a href="?' . $baseQS . '&page=' . $maxPage . '" class="page-link">Dernière »</a>';
                    } else {
                        echo '<span class="page-link" style="opacity:.5">Suivante ›</span>';
                        echo '<span class="page-link" style="opacity:.5">Dernière »</span>';
                    }
                    echo '</div>';
                }
            } else {
                echo '<p>Aucune image trouvée.</p>';
            }
        } else {
            echo '<p>Aucune image trouvée.</p>';
        }
    }
    ?>

</body>
</html>

</body>
</html>
