<?php
/**
 * Téléchargement d'attestations PDF
 * Gère le téléchargement des attestations d'inscription générées
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

// Récupération de l'ID de l'attestation
if (!isset($_POST['attestation_id'])) {
    http_response_code(400);
    die('ID d\'attestation manquant');
}

$attestation_id = (int)$_POST['attestation_id'];

// Initialisation de la connexion à la base de données
$database = new Database();
$conn = $database->getConnection();

try {
    // Récupérer les informations de l'attestation
    $query = "SELECT ai.*, u.name, u.matricule
              FROM attestations_inscription ai
              JOIN inscriptions i ON ai.inscription_id = i.id
              JOIN users u ON i.user_id = u.id
              WHERE ai.id = :attestation_id AND ai.statut = 'active'";
    
    $stmt = $conn->prepare($query);
    $stmt->bindParam(':attestation_id', $attestation_id);
    $stmt->execute();
    $attestation = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$attestation) {
        http_response_code(404);
        die('Attestation non trouvée ou invalide');
    }

    // Construire le chemin du fichier PDF
    $pdf_dir = __DIR__ . '/../documents/attestations';
    $pdf_file = $pdf_dir . '/' . $attestation['numero_attestation'] . '.pdf';

    // Vérifier que le fichier existe
    if (!file_exists($pdf_file)) {
        http_response_code(404);
        die('Fichier PDF non trouvé');
    }

    // Mettre à jour la date d'accès (optionnel)
    // Vous pouvez ajouter un champ last_downloaded dans la table si souhaité

    // Préparer le téléchargement
    $filename = 'Attestation_' . $attestation['numero_attestation'] . '_' . $attestation['matricule'] . '.pdf';
    
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
