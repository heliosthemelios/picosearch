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
            <input type="text" name="q" placeholder="Rechercher un vlog, portfolio ou artiste..." value="<?= htmlspecialchars($_GET['q'] ?? '') ?>">
            <button type="submit">Chercher</button>
        </form>
    </div>

    <div class="video-grid">
    <?php
    $query = trim($_GET['q'] ?? '');
    if (!empty($query)) {
        $stmt = $pdo->prepare("SELECT * FROM videos WHERE title LIKE :q OR platform LIKE :q ORDER BY id DESC LIMIT 50");
        $stmt->execute(['q' => "%$query%"]);
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if ($results) {
            foreach ($results as $video) {
                echo '<div class="video-card">';
                echo '  <div class="video-container">';
                echo '    <iframe src="' . htmlspecialchars($video['url']) . '" allowfullscreen loading="lazy"></iframe>';
                echo '  </div>';
                echo '  <span class="video-title">' . htmlspecialchars($video['title']) . '</span>';
                echo '  <span class="platform-badge">' . htmlspecialchars($video['platform']) . '</span>';
                echo '</div>';
            }
        } else {
            echo "<p style='color:white;'>Aucune vidéo d'art trouvée pour ce mot-clé.</p>";
        }
    }
    ?>
    </div>
</body>
</html>