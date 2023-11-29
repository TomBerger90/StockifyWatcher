<?php
include_once "vendor/autoload.php";
use Twilio\Rest\Client;
include_once "config.php";

echo "[".date("Y-m-d H:i:s")."]\n";

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname", $username, $password, [PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8"]);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    error_log("Erreur de connexion à la base de données : " . $e->getMessage() . "\n", 3, __DIR__ . "/check_shop.log");
    die("Erreur de connexion à la base de données. Veuillez consulter le fichier de log pour plus de détails.");
}



try {

    // Récupérer la liste des boutiques
    $shops = $pdo->query("SELECT shop_id, shop_url, last_checked FROM shops")->fetchAll(PDO::FETCH_ASSOC);

    foreach ($shops as $shop) {
        echo "Vérification de " . $shop['shop_url'] . "\n";
        $page = 1;
        $productsPerPage = 250; 
        do {
            // Récupération des produits de la boutique
            $jsonUrl = $shop['shop_url'] . "/products.json?limit=$productsPerPage&page=$page";
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $jsonUrl);
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
            
            // Début de la transaction
            $pdo->beginTransaction();

            foreach ($productsData['products'] as $product) {
                processProductChanges($pdo, $product, $shop);
            }

            // Valider la transaction
            $pdo->commit();

            $page++;
        } while (count($productsData['products']) >= $productsPerPage);

        // Mettre à jour le `last_checked`
        $stmt = $pdo->prepare("UPDATE shops SET last_checked = NOW() WHERE shop_id = ?");
        $stmt->execute([$shop['shop_id']]);
    }
} catch (PDOException $e) {
    $pdo->rollBack();
    error_log("Erreur de base de données : " . $e->getMessage(). "\n", 3, __DIR__ . "/check_shop.log");
} catch (Exception $e) {
    error_log("Erreur : " . $e->getMessage(). "\n", 3, __DIR__ . "/check_shop.log");
}

function processProductChanges($pdo, $product, $shop) {
    $stmt = $pdo->prepare("SELECT * FROM products WHERE shopify_product_id = ?");
    $stmt->execute([$product['id']]);
    $existingProduct = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($existingProduct) {
        // Vérifier les changements pour chaque variante
        foreach ($product['variants'] as $variant) {
            $variantStmt = $pdo->prepare("SELECT * FROM variants WHERE shopify_variant_id = ?");
            $variantStmt->execute([$variant['id']]);
            $existingVariant = $variantStmt->fetch(PDO::FETCH_ASSOC);

            if ($existingVariant) {
                // Vérifier les changements de disponibilité et de prix
                if ($existingVariant['available'] != $variant['available'] || $existingVariant['price'] != $variant['price']) {
                    $message = "Changement détecté pour " . $product['title'] . " (Variante: " . $variant['title'] . ")";
                    if ($existingVariant['price'] != $variant['price']) {
                        $message .= " - Prix modifié de " . $existingVariant['price'] . "€ à " . $variant['price'] . "€";
                        $changeDetails = "Prix modifié de " . $existingVariant['price'] . "€ à " . $variant['price'] . "€";
                        $changeType = 'PriceChange';
                        recordProductChange($pdo, $product['id'], $changeType, $changeDetails);

                    }
                    if ($existingVariant['available'] != $variant['available']) {
                        $message .= " - Disponibilité modifiée: " . ($variant['available'] ? "Disponible" : "Non disponible");
                        $changeDetails = "Disponibilité modifiée: " . ($variant['available'] ? "Disponible" : "Non disponible");
                        $changeType = 'AvailabilityChange';
                        recordProductChange($pdo, $product['id'], $changeType, $changeDetails);

                    }
                    // Envoi de la notification
                    sendWebhookNotification($message, $product['images'][0]['src'] ?? '', $shop['shop_url'] . '/products/' . $product['handle']);

                    // Conversion de la disponibilité en entier (1 pour vrai, 0 pour faux)
                    $availableInt = $variant['available'] ? 1 : 0;

                    // Mise à jour de la variante dans la base de données
                    $updateVariantStmt = $pdo->prepare("UPDATE variants SET price = ?, available = ? WHERE shopify_variant_id = ?");
                    $updateVariantStmt->execute([$variant['price'], $availableInt, $variant['id']]);

                }
            } else {
                // Nouvelle variante détectée
                $message = "Nouvelle variante ajoutée pour " . $product['title'] . ": " . $variant['title'];
                sendWebhookNotification($message, $product['images'][0]['src'] ?? '', $shop['shop_url'] . '/products/' . $product['handle']);
                $availableInt = $variant['available'] ? 1 : 0;

                // Insertion de la nouvelle variante dans la base de données
                $insertVariantStmt = $pdo->prepare("INSERT INTO variants (shopify_variant_id, product_id, title, price, available) VALUES (?, ?, ?, ?, ?)");
                $insertVariantStmt->execute([$variant['id'], $product['id'], $variant['title'], $variant['price'], $availableInt]);

                $changeType = 'NewVariant';
                recordProductChange($pdo, $product['id'], $changeType, $message);


            }
        }
    } else {
        // Nouveau produit détecté
        $message = "Nouveau produit ajouté: " . $product['title'];
        sendWebhookNotification($message, $product['images'][0]['src'] ?? '', $shop['shop_url'] . '/products/' . $product['handle']);

        // Insertion du nouveau produit dans la base de données
        $insertProductStmt = $pdo->prepare("INSERT INTO products (shopify_product_id, shop_id, title, handle, created_at, updated_at, image_url) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $insertProductStmt->execute([
            $product['id'], 
            $shop['shop_id'], 
            $product['title'], 
            $product['handle'], 
            $product['created_at'], 
            $product['updated_at'], 
            $product['images'][0]['src'] ?? ''
        ]);

        $changeType = 'NewProduct';
        recordProductChange($pdo, $product['id'], $changeType, $message);

    }
}

function recordProductChange($pdo, $productId, $changeType, $changeDetails) {
    $stmt = $pdo->prepare("INSERT INTO product_changes (product_id, change_type, change_details, change_date) VALUES (?, ?, ?, NOW())");
    $stmt->execute([$productId, $changeType, $changeDetails]);
}

function sendWebhookNotification($message, $imageUrl, $productUrl) {
    global $sid, $to, $from, $token;

    // Création du client Twilio
    $client = new Twilio\Rest\Client($sid, $token);

    //Add url at the beginning of the message
    $message = $productUrl . "\n" . $message;


    // Envoi du message WhatsApp
    try {
        $client->messages->create(
            $to,
            array(
                'from' => $from,
                'body' => $message,
                'mediaUrl' => [$imageUrl]
            )
        );
        echo "Message envoyé: $message\n";
        //Twilio rate limit
        sleep(2);
    } catch (Exception $e) {
        echo "Erreur lors de l'envoi du message: " . $e->getMessage();
        error_log("Erreur lors de l'envoi du message: " . $e->getMessage(). "\n", 3, __DIR__ . "/check_shop.log");

    }
}

?>
