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
    <!-- Conteneur pour la barre de recherche et le bouton images -->
        <div class="top-bar">
        <a class="link" href="index.php" title="Recherche de site">Sites</a>
        <a class="link" href="images.php" title="Recherche d'images">images</a>
        <a class="link" href="video.php" title="Recherche de vidéos">vidéos</a>
        </div>
        <div class="search-container">
            <form method="get">
                <input type="text" name="q" placeholder="Chercher de l'art..." value="<?= htmlspecialchars($_GET['q'] ?? '') ?>">
                <select name="lang" class="lang-select">
                    <option value="" <?= empty($_GET['lang']) ? 'selected' : '' ?>>Toutes les langues</option>
                    <option value="fr" <?= ($_GET['lang'] ?? '') === 'fr' ? 'selected' : '' ?>>Français</option>
                    <option value="en" <?= ($_GET['lang'] ?? '') === 'en' ? 'selected' : '' ?>>English</option>
                </select>
                <button type="submit">Chercher</button>
            </form>
        </div>

    <?php
        if (!empty($_GET['q'])) {
            // Pagination
            $perPage = 30;
            $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
            if ($page < 1) { $page = 1; }
            $offset = ($page - 1) * $perPage;

            // Construire le filtre de langue pour Meilisearch
            $filter = [];
            if (!empty($_GET['lang'])) {
                $filter[] = "langue = '" . $_GET['lang'] . "'";
            }

            // Recherche avec Meilisearch (mode AND par défaut pour les sites)
            $search_result = $meilisearch->multiWordSearch(
                'sites',
                $_GET['q'],
                ['title', 'url', 'snippet'],
                'and',
                [
                    'limit' => $perPage,
                    'offset' => $offset,
                    'filter' => !empty($filter) ? $filter : null
                ]
            );

            $results = $search_result['hits'];
            $total = $search_result['total'];

            $maxPage = max(1, (int)ceil($total / $perPage));
            if ($page > $maxPage && $maxPage > 0) { $page = $maxPage; }

            // Afficher le nombre total et la page
            echo '<div style="margin: 10px 0 20px; color:#555">' . htmlspecialchars((string)$total) . ' résultat(s) — Page ' . htmlspecialchars((string)$page) . ' / ' . htmlspecialchars((string)$maxPage) . '</div>';

            if (!empty($results)) {
                foreach ($results as $row) {
                    echo '<div class="result">';
                    echo '<a href="' . htmlspecialchars($row['url']) . '" target="_blank">' . htmlspecialchars($row['title'] ?: $row['url']) . '</a><br>';
                    echo '<div class="url">' . htmlspecialchars($row['url']) . '</div>';
                    echo '<div class="snippet">' . htmlspecialchars($row['snippet'] ?? '') . '</div>';
                    echo '</div>';
                }
            } else {
                echo "<p>Aucun résultat pour ces mots-clés.</p>";
            }

            // Pagination UI (afficher seulement si plus de perPage résultats)
            if ($total > $perPage) {
                $q = isset($_GET['q']) ? urlencode($_GET['q']) : '';
                $lang = !empty($_GET['lang']) ? '&lang=' . urlencode($_GET['lang']) : '';
                echo '<div class="pagination" style="margin-top:18px; display:flex; gap:8px; align-items:center;">';
                // First & Prev
                if ($page > 1) {
                    echo '<a href="?q=' . $q . $lang . '&page=1" class="page-link">« Première</a>';
                    echo '<a href="?q=' . $q . $lang . '&page=' . ($page - 1) . '" class="page-link">‹ Précédente</a>';
                } else {
                    echo '<span class="page-link" style="opacity:.5">« Première</span>';
                    echo '<span class="page-link" style="opacity:.5">‹ Précédente</span>';
                }

                // Current indicator
                echo '<span style="padding:4px 8px;">Page ' . htmlspecialchars((string)$page) . ' / ' . htmlspecialchars((string)$maxPage) . '</span>';

                // Next & Last
                if ($page < $maxPage) {
                    echo '<a href="?q=' . $q . $lang . '&page=' . ($page + 1) . '" class="page-link">Suivante ›</a>';
                    echo '<a href="?q=' . $q . $lang . '&page=' . $maxPage . '" class="page-link">Dernière »</a>';
                } else {
                    echo '<span class="page-link" style="opacity:.5">Suivante ›</span>';
                    echo '<span class="page-link" style="opacity:.5">Dernière »</span>';
                }
                echo '</div>';
            }
        }
    ?>
</body>
</html>