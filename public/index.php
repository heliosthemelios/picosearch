<?php
require_once __DIR__ . '/env_loader.php';
loadEnv();
    $host = env('DB_HOST', 'localhost');
    $dbname = env('DB_NAME', 'pico');
    $user = env('DB_USER', 'root');
    $pass = env('DB_PASSWORD');

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Connexion échouée : " . $e->getMessage());
}
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Moteur PicoSearch</title>
    <link rel="stylesheet" href="styles.css">
    <style>
    /* Lien esthétique vers la recherche d'images placé au-dessus de la barre */
    .images-link { display:inline-block; margin-bottom:12px; background:#001f3f; color:#fff; padding:8px 14px; border-radius:6px; text-decoration:none; font-weight:600; box-shadow:0 2px 6px rgba(0,0,0,0.08); }
    .images-link:hover { background:#003366; }
    </style>
</head>
<body>
    <h1>PicoSearch</h1>
    <!-- Bouton/ lien vers la page d'images (au-dessus du formulaire de recherche) -->
    <div>
       <!--  <a class="images-link" href="images.php" title="Recherche d'images">Recherche d'images</a> -->
    </div>
    <form method="get">
        <input type="text" name="q" placeholder="Chercher de l'art..." value="<?= htmlspecialchars($_GET['q'] ?? '') ?>">
        <button type="submit">Chercher</button>
    </form>

    <?php
        if (!empty($_GET['q'])) {
            // 1. On nettoie et on sépare les mots-clés par les espaces
            $search_terms = explode(' ', trim($_GET['q']));
            $params = [];
            $conditions = [];

            // 2. On construit dynamiquement la requête pour chaque mot
            foreach ($search_terms as $index => $term) {
                if (strlen($term) > 1) { // On ignore les mots de 1 seule lettre
                    $key = ":term" . $index;
                    // On cherche le mot dans le titre, le snippet ou l'URL
                    $conditions[] = "(title LIKE $key OR snippet LIKE $key OR url LIKE $key)";
                    $params[$key] = "%" . $term . "%";
                }
            }

            if (!empty($conditions)) {
                $where = implode(" AND ", $conditions);

                // Pagination
                $perPage = 30;
                $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
                if ($page < 1) { $page = 1; }

                // Compter le total des résultats pour cette recherche
                $countSql = "SELECT COUNT(*) FROM links WHERE $where";
                $countStmt = $pdo->prepare($countSql);
                foreach ($params as $k => $v) { $countStmt->bindValue($k, $v, PDO::PARAM_STR); }
                $countStmt->execute();
                $total = (int)$countStmt->fetchColumn();

                $maxPage = max(1, (int)ceil($total / $perPage));
                if ($page > $maxPage) { $page = $maxPage; }
                $offset = ($page - 1) * $perPage;

                // Sélection page courante avec LIMIT/OFFSET et paramètres typés
                $sql = "SELECT url, title, snippet FROM links WHERE $where ORDER BY id DESC LIMIT :limit OFFSET :offset";
                
                $stmt = $pdo->prepare($sql);
                foreach ($params as $k => $v) { $stmt->bindValue($k, $v, PDO::PARAM_STR); }
                $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
                $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
                $stmt->execute();
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

                // Afficher le nombre total et la page
                echo '<div style="margin: 10px 0 20px; color:#555">' . htmlspecialchars((string)$total) . ' résultat(s) — Page ' . htmlspecialchars((string)$page) . ' / ' . htmlspecialchars((string)$maxPage) . '</div>';

                if (count($rows) > 0) {
                    foreach ($rows as $row) {
                        echo '<div class="result">';
                        echo '<a href="' . htmlspecialchars($row['url']) . '" target="_blank">' . htmlspecialchars($row['title'] ?: $row['url']) . '</a><br>';
                        echo '<div class="url">' . htmlspecialchars($row['url']) . '</div>';
                        echo '<div class="snippet">' . htmlspecialchars($row['snippet']) . '</div>';
                        echo '</div>';
                    }

                    // Pagination UI (afficher seulement si plus de 50 résultats)
                    if ($total > $perPage) {
                        $q = isset($_GET['q']) ? urlencode($_GET['q']) : '';
                        echo '<div class="pagination" style="margin-top:18px; display:flex; gap:8px; align-items:center;">';
                        // First & Prev
                        if ($page > 1) {
                            echo '<a href="?q=' . $q . '&page=1" class="page-link">« Première</a>';
                            echo '<a href="?q=' . $q . '&page=' . ($page - 1) . '" class="page-link">‹ Précédente</a>';
                        } else {
                            echo '<span class="page-link" style="opacity:.5">« Première</span>';
                            echo '<span class="page-link" style="opacity:.5">‹ Précédente</span>';
                        }

                        // Current indicator
                        echo '<span style="padding:4px 8px;">Page ' . htmlspecialchars((string)$page) . ' / ' . htmlspecialchars((string)$maxPage) . '</span>';

                        // Next & Last
                        if ($page < $maxPage) {
                            echo '<a href="?q=' . $q . '&page=' . ($page + 1) . '" class="page-link">Suivante ›</a>';
                            echo '<a href="?q=' . $q . '&page=' . $maxPage . '" class="page-link">Dernière »</a>';
                        } else {
                            echo '<span class="page-link" style="opacity:.5">Suivante ›</span>';
                            echo '<span class="page-link" style="opacity:.5">Dernière »</span>';
                        }
                        echo '</div>';
                    }
                } else {
                    echo "<p>Aucun résultat pour ces mots-clés.</p>";
                }
            }
        }
    ?>

    <div class="btc-donation">
        <strong>Soutenez PicoSearch 🎨</strong>
        <p>Aidez-nous à indexer plus d'art avec un don en Bitcoin :</p>
        
        <img src="https://api.qrserver.com/v1/create-qr-code/?size=150x150&data=bc1qyl7htz3lqtwt0ptam9470w5qyeysqpamewgknk" alt="QR Code Bitcoin">
        
        <span class="btc-address" id="btcAddr">bc1qyl7htz3lqtwt0ptam9470w5qyeysqpamewgknk</span>
        <button class="copy-btn" onclick="copyAddress()">Copier l'adresse</button>
    </div>

    <script>
    function copyAddress() {
        var addr = document.getElementById("btcAddr").innerText;
        navigator.clipboard.writeText(addr).then(function() {
            alert("Adresse copiée ! Merci pour votre soutien.");
        });
    }
    </script>

</body>
</html>