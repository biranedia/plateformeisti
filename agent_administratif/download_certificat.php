<?php
/**
 * Téléchargement de certificats de scolarité PDF
 * Gère le téléchargement des certificats de scolarité générés
 */

session_start();

// Inclusion des fichiers de configuration
require_once '../config/database.php';
require_once '../config/utils.php';

// Vérification de l'authentification et des droits d'accès
if (!isLoggedIn() || !hasRole('agent_admin')) {
    http_response_code(403);
    die('Accès refusé');
}

// Récupération de l'ID du certificat
if (!isset($_POST['certificat_id'])) {
    http_response_code(400);
    die('ID de certificat manquant');
}

$certificat_id = (int)$_POST['certificat_id'];

// Initialisation de la connexion à la base de données
$database = new Database();
$conn = $database->getConnection();

try {
    // Récupérer les informations du certificat
    $query = "SELECT cs.*, u.name, u.matricule
              FROM certificats_scolarite cs
              JOIN inscriptions i ON cs.inscription_id = i.id
              JOIN users u ON i.user_id = u.id
              WHERE cs.id = :certificat_id AND cs.statut = 'active'";
    
    $stmt = $conn->prepare($query);
    $stmt->bindParam(':certificat_id', $certificat_id);
    $stmt->execute();
    $certificat = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$certificat) {
        http_response_code(404);
        die('Certificat non trouvé ou invalide');
    }

    // Construire le chemin du fichier PDF
    $pdf_dir = __DIR__ . '/../documents/certificats';
    $pdf_file = $pdf_dir . '/' . $certificat['numero_certificat'] . '.pdf';

    // Vérifier que le fichier existe
    if (!file_exists($pdf_file)) {
        http_response_code(404);
        die('Fichier PDF non trouvé');
    }

    // Préparer le téléchargement
    $filename = 'Certificat_' . $certificat['numero_certificat'] . '_' . $certificat['matricule'] . '.pdf';
    
    // Headers pour le téléchargement
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . filesize($pdf_file));
    header('Cache-Control: no-cache, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');

    // Lire et envoyer le fichier
    readfile($pdf_file);
    exit;

} catch (Exception $e) {
    http_response_code(500);
    die('Erreur serveur: ' . $e->getMessage());
}
?>
