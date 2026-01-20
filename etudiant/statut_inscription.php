<?php
/**
 * Statut de l'inscription - Étudiant
 * Permet à l'étudiant de voir le statut de validation de ses documents
 */

session_start();
require_once '../config/database.php';
require_once '../config/utils.php';

if (!isLoggedIn() || !hasRole('etudiant')) {
    redirectWithMessage('../shared/login.php', 'Accès non autorisé.', 'error');
}

$database = new Database();
$conn = $database->getConnection();
$user_id = $_SESSION['user_id'];

// Récupération des informations utilisateur
$user_query = "SELECT * FROM users WHERE id = :user_id";
$user_stmt = $conn->prepare($user_query);
$user_stmt->bindParam(':user_id', $user_id);
$user_stmt->execute();
$user = $user_stmt->fetch(PDO::FETCH_ASSOC);

// Récupération des documents soumis
$docs_query = "SELECT d.*, 
                      CASE 
                        WHEN d.statut = 'soumis' THEN 'En attente de validation'
                        WHEN d.statut = 'valide' THEN 'Validé'
                        WHEN d.statut = 'rejete' THEN 'Rejeté'
                      END as statut_libelle,
                      v.name as valideur_nom
               FROM documents_inscription d
               LEFT JOIN users v ON d.valide_par = v.id
               WHERE d.user_id = :user_id
               ORDER BY d.type_document, d.date_upload DESC";
$docs_stmt = $conn->prepare($docs_query);
$docs_stmt->bindParam(':user_id', $user_id);
$docs_stmt->execute();
$documents = $docs_stmt->fetchAll(PDO::FETCH_ASSOC);

// Grouper les documents par type (garder seulement le plus récent pour chaque type)
$documents_par_type = [];
foreach ($documents as $doc) {
    if (!isset($documents_par_type[$doc['type_document']])) {
        $documents_par_type[$doc['type_document']] = $doc;
    }
}

// Vérifier si tous les documents requis sont validés
$documents_requis = ['releve_bac', 'diplome_bac'];
$tous_valides = true;
$manque_documents = [];

foreach ($documents_requis as $type_requis) {
    if (!isset($documents_par_type[$type_requis])) {
        $tous_valides = false;
        $manque_documents[] = $type_requis;
    } elseif ($documents_par_type[$type_requis]['statut'] !== 'valide') {
        $tous_valides = false;
    }
}

// Vérifier si l'étudiant a déjà une inscription
$inscription_query = "SELECT COUNT(*) as count FROM inscriptions WHERE user_id = :user_id";
$inscription_stmt = $conn->prepare($inscription_query);
$inscription_stmt->bindParam(':user_id', $user_id);
$inscription_stmt->execute();
$inscription_count = $inscription_stmt->fetch(PDO::FETCH_ASSOC)['count'];
$a_une_inscription = $inscription_count > 0;

$labels_documents = [
    'releve_bac' => 'Relevé de notes du BAC',
    'diplome_bac' => 'Diplôme du BAC',
    'certificat' => 'Certificat',
    'autre' => 'Autre document'
];
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Statut de mon inscription - ISTI</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
</head>
<body class="bg-gray-100">
    <header class="bg-green-600 text-white shadow-lg">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex justify-between items-center py-4">
                <div class="flex items-center">
                    <i class="fas fa-graduation-cap text-2xl mr-3"></i>
                    <h1 class="text-xl font-bold">Plateforme ISTI - Étudiant</h1>
                </div>
                <div class="flex items-center space-x-4">
                    <span class="text-sm">Bienvenue, <?php echo htmlspecialchars($user['name']); ?></span>
                    <a href="../shared/logout.php" class="bg-red-500 hover:bg-red-600 px-3 py-1 rounded text-sm">
                        <i class="fas fa-sign-out-alt mr-1"></i>Déconnexion
                    </a>
                </div>
            </div>
        </div>
    </header>

    <nav class="bg-white shadow-sm">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex space-x-8 py-3">
                <a href="dashboard.php" class="text-gray-600 hover:text-green-600">
                    <i class="fas fa-tachometer-alt mr-1"></i>Dashboard
                </a>
                <a href="statut_inscription.php" class="text-green-600 border-b-2 border-green-600 pb-2">
                    <i class="fas fa-file-check mr-1"></i>Statut inscription
                </a>
                <a href="documents.php" class="text-gray-600 hover:text-green-600">
                    <i class="fas fa-folder-open mr-1"></i>Mes documents
                </a>
            </div>
        </div>
    </nav>

    <main class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        <h2 class="text-2xl font-bold text-gray-800 mb-6">
            <i class="fas fa-clipboard-check mr-2"></i>Statut de mon inscription
        </h2>

        <!-- Statut global -->
        <div class="bg-white rounded-lg shadow-md p-6 mb-6">
            <h3 class="text-xl font-semibold text-gray-800 mb-4">
                <i class="fas fa-info-circle mr-2"></i>État de votre dossier
            </h3>

            <?php if ($a_une_inscription): ?>
                <div class="bg-green-50 border-l-4 border-green-500 p-4">
                    <div class="flex items-center">
                        <i class="fas fa-check-circle text-green-500 text-3xl mr-4"></i>
                        <div>
                            <p class="text-green-800 font-semibold">Inscription complète</p>
                            <p class="text-green-700 text-sm">Vous êtes inscrit(e) à une classe. Consultez votre dashboard pour plus d'informations.</p>
                        </div>
                    </div>
                </div>
            <?php elseif ($tous_valides): ?>
                <div class="bg-blue-50 border-l-4 border-blue-500 p-4">
                    <div class="flex items-center">
                        <i class="fas fa-clock text-blue-500 text-3xl mr-4"></i>
                        <div>
                            <p class="text-blue-800 font-semibold">Documents validés - En attente d'inscription</p>
                            <p class="text-blue-700 text-sm">Tous vos documents ont été validés ! L'agent administratif finalisera votre inscription à une classe sous peu.</p>
                        </div>
                    </div>
                </div>
            <?php elseif (!empty($manque_documents)): ?>
                <div class="bg-orange-50 border-l-4 border-orange-500 p-4">
                    <div class="flex items-center">
                        <i class="fas fa-exclamation-triangle text-orange-500 text-3xl mr-4"></i>
                        <div>
                            <p class="text-orange-800 font-semibold">Documents manquants</p>
                            <p class="text-orange-700 text-sm">Veuillez soumettre les documents suivants :</p>
                            <ul class="list-disc ml-5 mt-2 text-orange-700 text-sm">
                                <?php foreach ($manque_documents as $doc_manquant): ?>
                                    <li><?php echo htmlspecialchars($labels_documents[$doc_manquant]); ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    </div>
                </div>
            <?php else: ?>
                <div class="bg-yellow-50 border-l-4 border-yellow-500 p-4">
                    <div class="flex items-center">
                        <i class="fas fa-hourglass-half text-yellow-500 text-3xl mr-4"></i>
                        <div>
                            <p class="text-yellow-800 font-semibold">Validation en cours</p>
                            <p class="text-yellow-700 text-sm">Vos documents sont en cours de validation par l'administration. Vous serez notifié une fois la validation effectuée.</p>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <!-- Liste des documents soumis -->
        <div class="bg-white rounded-lg shadow-md p-6">
            <h3 class="text-xl font-semibold text-gray-800 mb-4">
                <i class="fas fa-file-alt mr-2"></i>Mes documents soumis
            </h3>

            <?php if (empty($documents_par_type)): ?>
                <div class="text-center py-8">
                    <i class="fas fa-inbox text-gray-300 text-6xl mb-4"></i>
                    <h4 class="text-xl font-semibold text-gray-600 mb-2">Aucun document soumis</h4>
                    <p class="text-gray-500 mb-4">Vous n'avez pas encore soumis de documents.</p>
                </div>
            <?php else: ?>
                <div class="space-y-4">
                    <?php foreach ($documents_par_type as $type => $doc): ?>
                        <div class="border rounded-lg p-4 <?php 
                            if ($doc['statut'] === 'valide') echo 'bg-green-50 border-green-200';
                            elseif ($doc['statut'] === 'rejete') echo 'bg-red-50 border-red-200';
                            else echo 'bg-gray-50 border-gray-200';
                        ?>">
                            <div class="flex items-start justify-between">
                                <div class="flex-1">
                                    <h4 class="font-semibold text-gray-800">
                                        <?php echo htmlspecialchars($labels_documents[$type] ?? $type); ?>
                                    </h4>
                                    <p class="text-sm text-gray-600 mt-1">
                                        <i class="fas fa-file mr-1"></i><?php echo htmlspecialchars($doc['nom_fichier']); ?>
                                    </p>
                                    <p class="text-sm text-gray-500 mt-1">
                                        <i class="fas fa-calendar mr-1"></i>Soumis le <?php echo date('d/m/Y à H:i', strtotime($doc['date_upload'])); ?>
                                    </p>
                                    
                                    <?php if ($doc['statut'] === 'valide' && $doc['date_validation']): ?>
                                        <p class="text-sm text-green-600 mt-2">
                                            <i class="fas fa-check mr-1"></i>Validé le <?php echo date('d/m/Y à H:i', strtotime($doc['date_validation'])); ?>
                                            <?php if ($doc['valideur_nom']): ?>
                                                par <?php echo htmlspecialchars($doc['valideur_nom']); ?>
                                            <?php endif; ?>
                                        </p>
                                    <?php elseif ($doc['statut'] === 'rejete' && $doc['date_validation']): ?>
                                        <p class="text-sm text-red-600 mt-2">
                                            <i class="fas fa-times mr-1"></i>Rejeté le <?php echo date('d/m/Y à H:i', strtotime($doc['date_validation'])); ?>
                                        </p>
                                        <?php if ($doc['commentaire_validation']): ?>
                                            <div class="mt-2 bg-red-100 border-l-4 border-red-500 p-3">
                                                <p class="text-sm text-red-800">
                                                    <strong>Raison du rejet :</strong><br>
                                                    <?php echo nl2br(htmlspecialchars($doc['commentaire_validation'])); ?>
                                                </p>
                                            </div>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </div>
                                
                                <div class="ml-4">
                                    <span class="inline-flex items-center px-3 py-1 rounded-full text-sm font-medium <?php 
                                        if ($doc['statut'] === 'valide') echo 'bg-green-100 text-green-800';
                                        elseif ($doc['statut'] === 'rejete') echo 'bg-red-100 text-red-800';
                                        else echo 'bg-yellow-100 text-yellow-800';
                                    ?>">
                                        <?php 
                                        if ($doc['statut'] === 'valide') echo '<i class="fas fa-check-circle mr-1"></i>';
                                        elseif ($doc['statut'] === 'rejete') echo '<i class="fas fa-times-circle mr-1"></i>';
                                        else echo '<i class="fas fa-clock mr-1"></i>';
                                        ?>
                                        <?php echo htmlspecialchars($doc['statut_libelle']); ?>
                                    </span>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- Actions disponibles -->
        <?php if (!$a_une_inscription && !empty($manque_documents)): ?>
            <div class="bg-white rounded-lg shadow-md p-6 mt-6">
                <h3 class="text-xl font-semibold text-gray-800 mb-4">
                    <i class="fas fa-upload mr-2"></i>Action requise
                </h3>
                <p class="text-gray-600 mb-4">
                    Pour compléter votre dossier, veuillez soumettre les documents manquants via votre profil ou contactez l'administration.
                </p>
                <a href="profil.php" class="inline-flex items-center bg-green-600 hover:bg-green-700 text-white font-medium py-2 px-4 rounded-md transition duration-200">
                    <i class="fas fa-user mr-2"></i>Accéder à mon profil
                </a>
            </div>
        <?php endif; ?>
    </main>
</body>
</html>
