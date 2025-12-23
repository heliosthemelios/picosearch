<?php
echo "<h3>Débogage de l'emplacement .env</h3>";

echo "<strong>Répertoire actuel (__DIR__) :</strong> " . __DIR__ . "<br>";
echo "<strong>dirname(__DIR__) :</strong> " . dirname(__DIR__) . "<br><br>";

echo "<h4>Chemins testés :</h4>";

$possiblePaths = [
    '/var/www/.env',
    dirname(__DIR__) . '/.env',
    __DIR__ . '/.env'
];

foreach ($possiblePaths as $path) {
    $exists = file_exists($path) ? '✅ EXISTE' : '❌ N\'existe pas';
    $readable = is_readable($path) ? '✅ Lisible' : '❌ Non lisible';
    echo "$path<br>";
    echo "&nbsp;&nbsp;→ $exists | $readable<br><br>";
}

echo "<h4>Trouver le .env :</h4>";
exec('find /var/www -name ".env" 2>/dev/null', $output);
if (!empty($output)) {
    foreach ($output as $file) {
        echo "Trouvé : $file<br>";
    }
} else {
    echo "Aucun fichier .env trouvé dans /var/www<br>";
}

echo "<br><strong>⚠️ SUPPRIMEZ CE FICHIER après avoir trouvé le problème !</strong>";
?>
