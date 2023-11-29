<?php
require_once "config.php";

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname", $username, $password, array(PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8"));
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Erreur de connexion à la base de données : " . $e->getMessage());
}

if(!isset($argv[1]) || empty($argv[1])){
    die("Site name is required");
}

if(!isset($argv[2]) || empty($argv[2])){
    die("Site url is required");
}



try {
    $shopName = $argv[1];
    $shopUrl = $argv[2];
    $shopUrl = rtrim($shopUrl, "/");
    $jsonUrl = $shopUrl . "/products.json";

    // Vérifier l'unicité du site
    $stmt = $pdo->prepare("SELECT * FROM shops WHERE shop_url = ?");
    $stmt->execute([$shopUrl]);

    if ($stmt->rowCount() > 0) {
        die("Ce site existe déjà dans la base de données.");
    }

    // Début de la transaction
    $pdo->beginTransaction();

    // Ajouter le site à la base de données
    $stmt = $pdo->prepare("INSERT INTO shops (shop_name, shop_url) VALUES (?, ?)");
    $stmt->execute([$shopName, $shopUrl]);
    $shopId = $pdo->lastInsertId();


    // Nombre maximum de produits par page
    $page = 1;
    $productsPerPage = 250;
    do {
    // Mise à jour de l'URL pour la pagination
        $paginatedUrl = $jsonUrl . "?limit=$productsPerPage&page=$page";

    // Récupérer le fichier products.json
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $paginatedUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Chrome/59.0.3071.115');
        $productsJson = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);


    // Vérifier que le fichier a bien été récupéré
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($httpCode !== 200) {
            throw new Exception("Erreur lors de la récupération du fichier products.json.");
        }

        if ($productsJson === false) {
            throw new Exception("Erreur lors de la récupération du fichier products.json.");
        }

    // Décoder le JSON
        $productsData = json_decode($productsJson, true);
        if ($productsData === null) {
            throw new Exception("Erreur lors du décodage du JSON.");
        }

    // Ajouter les produits et les variantes à la base de données
        foreach ($productsData['products'] as $product) {
        // Ajouter le produit
            $stmt = $pdo->prepare("INSERT INTO products (shopify_product_id, shop_id, title, handle, created_at, updated_at, image_url) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([
                $product['id'], 
                $shopId, 
                $product['title'], 
                $product['handle'], 
                $product['created_at'], 
                $product['updated_at'], 
                $product['images'][0]['src'] ?? null
            ]);

        // Ajouter les variantes
            foreach ($product['variants'] as $variant) {
                $stmt = $pdo->prepare("INSERT INTO variants (shopify_variant_id, product_id, title, price, available) VALUES (?, ?, ?, ?, ?)");
                $stmt->execute([
                    $variant['id'], 
                    $product['id'], 
                    $variant['title'], 
                    $variant['price'], 
                    $variant['available'] ? 1 : 0
                ]);
            }
        }

        $page++;
    } while (count($productsData['products']) >= $productsPerPage);


    // Valider la transaction
    $pdo->commit();

    echo "Site et produits ajoutés avec succès.";

} catch (PDOException $e) {
    // Gestion des erreurs de base de données
    $pdo->rollBack();
    die("Erreur de base de données : " . $e->getMessage());
} catch (Exception $e) {
    // Gestion des autres erreurs
    $pdo->rollBack();
    die("Erreur : " . $e->getMessage());
}
?>
