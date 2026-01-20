<?php
/**
 * Système de messagerie interne - ISTI Platform
 * Messagerie entre utilisateurs selon leur rôle
 */

// Démarrage de la session
session_start();

// Inclusion des fichiers de configuration
require_once '../config/database.php';
require_once '../config/utils.php';

// Vérification de l'authentification
if (!isLoggedIn()) {
    redirectWithMessage('login.php', 'Vous devez être connecté pour accéder à cette page.', 'error');
}

// Initialisation de la connexion à la base de données
$database = new Database();
$conn = $database->getConnection();

// Récupération des informations de l'utilisateur
$user_id = $_SESSION['user_id'];
$user_role = $_SESSION['user_role'] ?? '';

// Variables
$messages = [];
$conversations = [];
$selected_conversation = null;
$errors = [];
$success = '';

// Traitement des actions POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = sanitize($_POST['action'] ?? '');

    if ($action === 'send_message') {
        $recipient_id = (int)($_POST['recipient_id'] ?? 0);
        $subject = sanitize($_POST['subject'] ?? '');
        $message_content = sanitize($_POST['message'] ?? '');

        // Validation
        if (empty($recipient_id) || empty($subject) || empty($message_content)) {
            $errors[] = 'Tous les champs sont obligatoires.';
        } elseif (strlen($subject) > 255) {
            $errors[] = 'Le sujet ne peut pas dépasser 255 caractères.';
        } elseif (strlen($message_content) > 5000) {
            $errors[] = 'Le message ne peut pas dépasser 5000 caractères.';
        } else {
            try {
                // Vérifier que le destinataire existe et est autorisé
                $recipient_check = "SELECT id, role FROM users WHERE id = :id";
                $check_stmt = $conn->prepare($recipient_check);
                $check_stmt->bindParam(':id', $recipient_id);
                $check_stmt->execute();
                $recipient = $check_stmt->fetch(PDO::FETCH_ASSOC);

                if (!$recipient) {
                    $errors[] = 'Destinataire introuvable.';
                } elseif (!canSendMessageTo($user_role, $recipient['role'])) {
                    $errors[] = 'Vous n\'êtes pas autorisé à envoyer un message à ce type d\'utilisateur.';
                } else {
                    // Créer une nouvelle conversation ou récupérer l'existante
                    $conversation_query = "SELECT id FROM conversations
                                         WHERE ((sender_id = :user1 AND recipient_id = :user2)
                                             OR (sender_id = :user2 AND recipient_id = :user1))
                                         AND subject = :subject
                                         LIMIT 1";
                    $conv_stmt = $conn->prepare($conversation_query);
                    $conv_stmt->bindParam(':user1', $user_id);
                    $conv_stmt->bindParam(':user2', $recipient_id);
                    $conv_stmt->bindParam(':subject', $subject);
                    $conv_stmt->execute();
                    $existing_conv = $conv_stmt->fetch(PDO::FETCH_ASSOC);

                    if ($existing_conv) {
                        $conversation_id = $existing_conv['id'];
                    } else {
                        // Créer une nouvelle conversation
                        $new_conv_query = "INSERT INTO conversations (sender_id, recipient_id, subject, created_at)
                                         VALUES (:sender, :recipient, :subject, NOW())";
                        $new_conv_stmt = $conn->prepare($new_conv_query);
                        $new_conv_stmt->bindParam(':sender', $user_id);
                        $new_conv_stmt->bindParam(':recipient', $recipient_id);
                        $new_conv_stmt->bindParam(':subject', $subject);
                        $new_conv_stmt->execute();
                        $conversation_id = $conn->lastInsertId();
                    }

                    // Ajouter le message
                    $message_query = "INSERT INTO messages (conversation_id, sender_id, recipient_id, content, sent_at, is_read)
                                    VALUES (:conversation, :sender, :recipient, :content, NOW(), 0)";
                    $message_stmt = $conn->prepare($message_query);
                    $message_stmt->bindParam(':conversation', $conversation_id);
                    $message_stmt->bindParam(':sender', $user_id);
                    $message_stmt->bindParam(':recipient', $recipient_id);
                    $message_stmt->bindParam(':content', $message_content);
                    $message_stmt->execute();

                    $success = 'Message envoyé avec succès.';

                    // Ajout dans le journal d'audit
                    addAuditLog($conn, $user_id, "Message envoyé à l'utilisateur ID $recipient_id", "messages");

                    // Redirection pour éviter la resoumission du formulaire
                    header("Location: messages.php?conversation=$conversation_id");
                    exit;
                }
            } catch (Exception $e) {
                $errors[] = 'Erreur lors de l\'envoi du message: ' . $e->getMessage();
            }
        }
    }

    if ($action === 'mark_as_read') {
        $message_ids = $_POST['message_ids'] ?? [];
        if (!empty($message_ids)) {
            try {
                $placeholders = str_repeat('?,', count($message_ids) - 1) . '?';
                $query = "UPDATE messages SET is_read = 1, read_at = NOW()
                         WHERE id IN ($placeholders) AND recipient_id = ?";
                $stmt = $conn->prepare($query);
                $params = array_merge($message_ids, [$user_id]);
                $stmt->execute($params);

                $success = count($message_ids) . ' message(s) marqué(s) comme lu(s).';
            } catch (Exception $e) {
                $errors[] = 'Erreur lors de la mise à jour: ' . $e->getMessage();
            }
        }
    }

    if ($action === 'delete_conversation') {
        $conversation_id = (int)($_POST['conversation_id'] ?? 0);
        try {
            // Vérifier que l'utilisateur fait partie de la conversation
            $check_query = "SELECT id FROM conversations
                           WHERE id = :id AND (sender_id = :user OR recipient_id = :user)";
            $check_stmt = $conn->prepare($check_query);
            $check_stmt->bindParam(':id', $conversation_id);
            $check_stmt->bindParam(':user', $user_id);
            $check_stmt->execute();

            if ($check_stmt->fetch()) {
                // Supprimer les messages d'abord
                $delete_messages = "DELETE FROM messages WHERE conversation_id = :id";
                $del_msg_stmt = $conn->prepare($delete_messages);
                $del_msg_stmt->bindParam(':id', $conversation_id);
                $del_msg_stmt->execute();

                // Supprimer la conversation
                $delete_conv = "DELETE FROM conversations WHERE id = :id";
                $del_conv_stmt = $conn->prepare($delete_conv);
                $del_conv_stmt->bindParam(':id', $conversation_id);
                $del_conv_stmt->execute();

                $success = 'Conversation supprimée avec succès.';

                // Ajout dans le journal d'audit
                addAuditLog($conn, $user_id, "Conversation ID $conversation_id supprimée", "messages");
            } else {
                $errors[] = 'Conversation introuvable ou accès non autorisé.';
            }
        } catch (Exception $e) {
            $errors[] = 'Erreur lors de la suppression: ' . $e->getMessage();
        }
    }
}

// Récupération de la conversation sélectionnée
$conversation_id = (int)($_GET['conversation'] ?? 0);
if ($conversation_id > 0) {
    try {
        // Vérifier l'accès à la conversation
        $conv_query = "SELECT c.*, u1.nom as sender_nom, u1.prenom as sender_prenom,
                      u2.nom as recipient_nom, u2.prenom as recipient_prenom
                      FROM conversations c
                      JOIN users u1 ON c.sender_id = u1.id
                      JOIN users u2 ON c.recipient_id = u2.id
                      WHERE c.id = :id AND (c.sender_id = :user OR c.recipient_id = :user)";
        $conv_stmt = $conn->prepare($conv_query);
        $conv_stmt->bindParam(':id', $conversation_id);
        $conv_stmt->bindParam(':user', $user_id);
        $conv_stmt->execute();
        $selected_conversation = $conv_stmt->fetch(PDO::FETCH_ASSOC);

        if ($selected_conversation) {
            // Récupérer les messages de la conversation
            $msg_query = "SELECT m.*, u.nom as sender_nom, u.prenom as sender_prenom
                         FROM messages m
                         JOIN users u ON m.sender_id = u.id
                         WHERE m.conversation_id = :conversation
                         ORDER BY m.sent_at ASC";
            $msg_stmt = $conn->prepare($msg_query);
            $msg_stmt->bindParam(':conversation', $conversation_id);
            $msg_stmt->execute();
            $messages = $msg_stmt->fetchAll(PDO::FETCH_ASSOC);

            // Marquer les messages comme lus
            $update_read = "UPDATE messages SET is_read = 1, read_at = NOW()
                           WHERE conversation_id = :conversation AND recipient_id = :user AND is_read = 0";
            $read_stmt = $conn->prepare($update_read);
            $read_stmt->bindParam(':conversation', $conversation_id);
            $read_stmt->bindParam(':user', $user_id);
            $read_stmt->execute();
        }
    } catch (Exception $e) {
        $errors[] = 'Erreur lors du chargement de la conversation.';
    }
}

// Récupération des conversations de l'utilisateur
try {
    $conversations_query = "SELECT c.*, u1.nom as sender_nom, u1.prenom as sender_prenom,
                           u2.nom as recipient_nom, u2.prenom as recipient_prenom,
                           (SELECT COUNT(*) FROM messages m WHERE m.conversation_id = c.id AND m.is_read = 0 AND m.recipient_id = :user) as unread_count,
                           (SELECT m.sent_at FROM messages m WHERE m.conversation_id = c.id ORDER BY m.sent_at DESC LIMIT 1) as last_message_date
                           FROM conversations c
                           JOIN users u1 ON c.sender_id = u1.id
                           JOIN users u2 ON c.recipient_id = u2.id
                           WHERE c.sender_id = :user OR c.recipient_id = :user
                           ORDER BY last_message_date DESC";
    $conv_stmt = $conn->prepare($conversations_query);
    $conv_stmt->bindParam(':user', $user_id);
    $conv_stmt->execute();
    $conversations = $conv_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $errors[] = 'Erreur lors du chargement des conversations.';
}

// Récupération des utilisateurs auxquels on peut envoyer des messages
$available_recipients = [];
try {
    $recipients_query = "SELECT id, nom, prenom, role FROM users WHERE id != :user AND active = 1";
    $recipients_stmt = $conn->prepare($recipients_query);
    $recipients_stmt->bindParam(':user', $user_id);
    $recipients_stmt->execute();
    $all_users = $recipients_stmt->fetchAll(PDO::FETCH_ASSOC);

    // Filtrer selon les permissions
    foreach ($all_users as $user) {
        if (canSendMessageTo($user_role, $user['role'])) {
            $available_recipients[] = $user;
        }
    }
} catch (Exception $e) {
    $errors[] = 'Erreur lors du chargement des destinataires.';
}

// Fonction pour vérifier les permissions d'envoi de messages
function canSendMessageTo($sender_role, $recipient_role) {
    $allowed_combinations = [
        'admin_general' => ['agent_administratif', 'resp_filiere', 'resp_departement', 'enseignant', 'etudiant', 'resp_classe'],
        'agent_administratif' => ['admin_general', 'resp_filiere', 'resp_departement', 'enseignant', 'etudiant', 'resp_classe'],
        'resp_filiere' => ['admin_general', 'agent_administratif', 'resp_departement', 'enseignant', 'etudiant', 'resp_classe'],
        'resp_departement' => ['admin_general', 'agent_administratif', 'resp_filiere', 'enseignant', 'etudiant', 'resp_classe'],
        'enseignant' => ['admin_general', 'agent_administratif', 'resp_filiere', 'resp_departement', 'etudiant', 'resp_classe'],
        'etudiant' => ['admin_general', 'agent_administratif', 'resp_filiere', 'resp_departement', 'enseignant', 'resp_classe'],
        'resp_classe' => ['admin_general', 'agent_administratif', 'resp_filiere', 'resp_departement', 'enseignant', 'etudiant']
    ];

    return in_array($recipient_role, $allowed_combinations[$sender_role] ?? []);
}

// Statistiques des messages
$stats = [
    'total_conversations' => count($conversations),
    'unread_messages' => 0,
    'total_messages' => 0
];

foreach ($conversations as $conv) {
    $stats['unread_messages'] += $conv['unread_count'];
    // Compter les messages de cette conversation
    try {
        $count_query = "SELECT COUNT(*) as count FROM messages WHERE conversation_id = :id";
        $count_stmt = $conn->prepare($count_query);
        $count_stmt->bindParam(':id', $conv['id']);
        $count_stmt->execute();
        $stats['total_messages'] += $count_stmt->fetch(PDO::FETCH_ASSOC)['count'];
    } catch (Exception $e) {
        // Ignorer l'erreur
    }
}
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Messagerie - ISTI</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
</head>
<body class="bg-gray-100">
    <!-- Header -->
    <header class="bg-blue-600 text-white shadow-lg">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex justify-between items-center py-4">
                <div class="flex items-center">
                    <i class="fas fa-envelope text-2xl mr-3"></i>
                    <h1 class="text-xl font-bold">Plateforme ISTI - Messagerie</h1>
                </div>
                <div class="flex items-center space-x-4">
                    <span class="text-sm">Bienvenue, <?php echo htmlspecialchars($_SESSION['user_name'] ?? 'Utilisateur'); ?></span>
                    <a href="logout.php" class="bg-red-500 hover:bg-red-600 px-3 py-1 rounded text-sm transition duration-200">
                        <i class="fas fa-sign-out-alt mr-1"></i>Déconnexion
                    </a>
                </div>
            </div>
        </div>
    </header>

    <!-- Main Content -->
    <main class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        <!-- Messages d'erreur et de succès -->
        <?php if (!empty($errors)): ?>
            <div class="mb-8 bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded">
                <ul class="list-disc list-inside">
                    <?php foreach ($errors as $error): ?>
                        <li><?php echo htmlspecialchars($error); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <?php if (!empty($success)): ?>
            <div class="mb-8 bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded">
                <?php echo htmlspecialchars($success); ?>
            </div>
        <?php endif; ?>

        <!-- Statistiques -->
        <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8">
            <div class="bg-white rounded-lg shadow-md p-6">
                <div class="flex items-center">
                    <div class="bg-blue-100 rounded-full p-3">
                        <i class="fas fa-comments text-blue-600 text-xl"></i>
                    </div>
                    <div class="ml-4">
                        <h3 class="font-semibold text-gray-800">Conversations</h3>
                        <p class="text-2xl font-bold text-blue-600"><?php echo $stats['total_conversations']; ?></p>
                        <p class="text-sm text-gray-600">actives</p>
                    </div>
                </div>
            </div>

            <div class="bg-white rounded-lg shadow-md p-6">
                <div class="flex items-center">
                    <div class="bg-orange-100 rounded-full p-3">
                        <i class="fas fa-envelope text-orange-600 text-xl"></i>
                    </div>
                    <div class="ml-4">
                        <h3 class="font-semibold text-gray-800">Messages non lus</h3>
                        <p class="text-2xl font-bold text-orange-600"><?php echo $stats['unread_messages']; ?></p>
                        <p class="text-sm text-gray-600">en attente</p>
                    </div>
                </div>
            </div>

            <div class="bg-white rounded-lg shadow-md p-6">
                <div class="flex items-center">
                    <div class="bg-green-100 rounded-full p-3">
                        <i class="fas fa-mail-bulk text-green-600 text-xl"></i>
                    </div>
                    <div class="ml-4">
                        <h3 class="font-semibold text-gray-800">Total messages</h3>
                        <p class="text-2xl font-bold text-green-600"><?php echo $stats['total_messages']; ?></p>
                        <p class="text-sm text-gray-600">échangés</p>
                    </div>
                </div>
            </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
            <!-- Liste des conversations -->
            <div class="lg:col-span-1">
                <div class="bg-white rounded-lg shadow-md">
                    <div class="px-6 py-4 border-b border-gray-200">
                        <div class="flex justify-between items-center">
                            <h2 class="text-lg font-bold text-gray-800">
                                <i class="fas fa-list mr-2"></i>Conversations
                            </h2>
                            <button onclick="openNewMessageModal()"
                                    class="bg-blue-600 hover:bg-blue-700 text-white font-medium py-1 px-3 rounded-md transition duration-200 text-sm">
                                <i class="fas fa-plus mr-1"></i>Nouveau
                            </button>
                        </div>
                    </div>

                    <?php if (empty($conversations)): ?>
                        <div class="p-8 text-center">
                            <i class="fas fa-comments text-4xl text-gray-300 mb-4"></i>
                            <h3 class="text-lg font-medium text-gray-900 mb-2">Aucune conversation</h3>
                            <p class="text-gray-500 mb-4">Vous n'avez encore aucune conversation.</p>
                            <button onclick="openNewMessageModal()"
                                    class="bg-blue-600 hover:bg-blue-700 text-white font-medium py-2 px-4 rounded-md transition duration-200">
                                <i class="fas fa-plus mr-2"></i>Démarrer une conversation
                            </button>
                        </div>
                    <?php else: ?>
                        <div class="divide-y divide-gray-200 max-h-96 overflow-y-auto">
                            <?php foreach ($conversations as $conv): ?>
                                <?php
                                $other_user = ($conv['sender_id'] == $user_id) ? $conv['recipient_nom'] . ' ' . $conv['recipient_prenom'] : $conv['sender_nom'] . ' ' . $conv['sender_prenom'];
                                $is_active = ($selected_conversation && $selected_conversation['id'] == $conv['id']);
                                ?>
                                <a href="?conversation=<?php echo $conv['id']; ?>"
                                   class="block p-4 hover:bg-gray-50 <?php echo $is_active ? 'bg-blue-50 border-r-4 border-blue-500' : ''; ?>">
                                    <div class="flex items-center justify-between">
                                        <div class="flex-1 min-w-0">
                                            <div class="flex items-center space-x-3">
                                                <div class="flex-shrink-0">
                                                    <div class="w-10 h-10 rounded-full bg-blue-100 flex items-center justify-center">
                                                        <i class="fas fa-user text-blue-600"></i>
                                                    </div>
                                                </div>
                                                <div class="min-w-0 flex-1">
                                                    <p class="text-sm font-medium text-gray-900 truncate">
                                                        <?php echo htmlspecialchars($other_user); ?>
                                                    </p>
                                                    <p class="text-sm text-gray-500 truncate">
                                                        <?php echo htmlspecialchars($conv['subject']); ?>
                                                    </p>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="flex flex-col items-end space-y-1">
                                            <?php if ($conv['unread_count'] > 0): ?>
                                                <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium bg-red-100 text-red-800">
                                                    <?php echo $conv['unread_count']; ?>
                                                </span>
                                            <?php endif; ?>
                                            <p class="text-xs text-gray-500">
                                                <?php echo htmlspecialchars(date('d/m', strtotime($conv['last_message_date']))); ?>
                                            </p>
                                        </div>
                                    </div>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Zone de messages -->
            <div class="lg:col-span-2">
                <?php if ($selected_conversation): ?>
                    <div class="bg-white rounded-lg shadow-md">
                        <!-- En-tête de la conversation -->
                        <div class="px-6 py-4 border-b border-gray-200">
                            <div class="flex justify-between items-center">
                                <div>
                                    <h2 class="text-lg font-bold text-gray-800">
                                        <?php
                                        $other_user = ($selected_conversation['sender_id'] == $user_id) ?
                                            $selected_conversation['recipient_nom'] . ' ' . $selected_conversation['recipient_prenom'] :
                                            $selected_conversation['sender_nom'] . ' ' . $selected_conversation['sender_prenom'];
                                        echo htmlspecialchars($other_user);
                                        ?>
                                    </h2>
                                    <p class="text-sm text-gray-600">
                                        <?php echo htmlspecialchars($selected_conversation['subject']); ?>
                                    </p>
                                </div>
                                <div class="flex space-x-2">
                                    <button onclick="openNewMessageModal()"
                                            class="bg-blue-600 hover:bg-blue-700 text-white font-medium py-1 px-3 rounded-md transition duration-200 text-sm">
                                        <i class="fas fa-reply mr-1"></i>Répondre
                                    </button>
                                    <button onclick="confirmDeleteConversation(<?php echo $selected_conversation['id']; ?>)"
                                            class="bg-red-600 hover:bg-red-700 text-white font-medium py-1 px-3 rounded-md transition duration-200 text-sm">
                                        <i class="fas fa-trash mr-1"></i>Supprimer
                                    </button>
                                </div>
                            </div>
                        </div>

                        <!-- Messages -->
                        <div class="p-6 max-h-96 overflow-y-auto space-y-4">
                            <?php if (empty($messages)): ?>
                                <div class="text-center text-gray-500">
                                    <i class="fas fa-comments text-4xl mb-4"></i>
                                    <p>Aucun message dans cette conversation.</p>
                                </div>
                            <?php else: ?>
                                <?php foreach ($messages as $message): ?>
                                    <div class="flex <?php echo $message['sender_id'] == $user_id ? 'justify-end' : 'justify-start'; ?>">
                                        <div class="max-w-xs lg:max-w-md px-4 py-2 rounded-lg <?php echo $message['sender_id'] == $user_id ? 'bg-blue-600 text-white' : 'bg-gray-200 text-gray-900'; ?>">
                                            <div class="text-sm">
                                                <?php echo nl2br(htmlspecialchars($message['content'])); ?>
                                            </div>
                                            <div class="text-xs mt-1 <?php echo $message['sender_id'] == $user_id ? 'text-blue-200' : 'text-gray-500'; ?>">
                                                <?php echo htmlspecialchars(date('d/m/Y H:i', strtotime($message['sent_at']))); ?>
                                                <?php if ($message['sender_id'] == $user_id): ?>
                                                    <i class="fas fa-check ml-1"></i>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>

                        <!-- Formulaire de réponse -->
                        <div class="px-6 py-4 border-t border-gray-200">
                            <form method="POST" class="space-y-3">
                                <input type="hidden" name="action" value="send_message">
                                <input type="hidden" name="recipient_id" value="<?php echo $selected_conversation['sender_id'] == $user_id ? $selected_conversation['recipient_id'] : $selected_conversation['sender_id']; ?>">
                                <input type="hidden" name="subject" value="<?php echo htmlspecialchars($selected_conversation['subject']); ?>">

                                <div>
                                    <textarea id="reply_message" name="message" rows="3"
                                              class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500"
                                              placeholder="Tapez votre message..." required></textarea>
                                </div>

                                <div class="flex justify-end">
                                    <button type="submit"
                                            class="bg-blue-600 hover:bg-blue-700 text-white font-medium py-2 px-4 rounded-md transition duration-200">
                                        <i class="fas fa-paper-plane mr-2"></i>Envoyer
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="bg-white rounded-lg shadow-md p-8 text-center">
                        <i class="fas fa-envelope-open text-4xl text-gray-300 mb-4"></i>
                        <h3 class="text-lg font-medium text-gray-900 mb-2">Sélectionnez une conversation</h3>
                        <p class="text-gray-500">Choisissez une conversation dans la liste pour voir les messages.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </main>

    <!-- Modal Nouveau message -->
    <div id="newMessageModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full hidden z-50">
        <div class="relative top-20 mx-auto p-5 border w-11/12 md:w-96 shadow-lg rounded-md bg-white">
            <div class="mt-3">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="text-lg font-medium text-gray-900">
                        <i class="fas fa-plus mr-2"></i>Nouveau message
                    </h3>
                    <button onclick="closeNewMessageModal()" class="text-gray-400 hover:text-gray-600">
                        <i class="fas fa-times"></i>
                    </button>
                </div>

                <form method="POST" class="space-y-4">
                    <input type="hidden" name="action" value="send_message">

                    <div>
                        <label for="recipient" class="block text-sm font-medium text-gray-700 mb-2">
                            Destinataire *
                        </label>
                        <select id="recipient" name="recipient_id" required
                                class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                            <option value="">Sélectionner un destinataire...</option>
                            <?php foreach ($available_recipients as $recipient): ?>
                                <option value="<?php echo $recipient['id']; ?>">
                                    <?php echo htmlspecialchars($recipient['nom'] . ' ' . $recipient['prenom'] . ' (' . $recipient['role'] . ')'); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div>
                        <label for="subject" class="block text-sm font-medium text-gray-700 mb-2">
                            Sujet *
                        </label>
                        <input type="text" id="subject" name="subject" required maxlength="255"
                               class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500"
                               placeholder="Sujet du message">
                    </div>

                    <div>
                        <label for="message" class="block text-sm font-medium text-gray-700 mb-2">
                            Message *
                        </label>
                        <textarea id="message" name="message" rows="4" required maxlength="5000"
                                  class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500"
                                  placeholder="Tapez votre message..."></textarea>
                    </div>

                    <div class="flex justify-end space-x-3">
                        <button type="button" onclick="closeNewMessageModal()"
                                class="bg-gray-300 hover:bg-gray-400 text-gray-800 font-medium py-2 px-4 rounded-md transition duration-200">
                            Annuler
                        </button>
                        <button type="submit"
                                class="bg-blue-600 hover:bg-blue-700 text-white font-medium py-2 px-4 rounded-md transition duration-200">
                            Envoyer
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Footer -->
    <footer class="bg-gray-800 text-white mt-12">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
            <div class="text-center">
                <p>&copy; 2024 Institut Supérieur de Technologie et d'Informatique. Tous droits réservés.</p>
            </div>
        </div>
    </footer>

    <script>
        function openNewMessageModal() {
            document.getElementById('newMessageModal').classList.remove('hidden');
        }

        function closeNewMessageModal() {
            document.getElementById('newMessageModal').classList.add('hidden');
            document.getElementById('recipient').selectedIndex = 0;
            document.getElementById('subject').value = '';
            document.getElementById('message').value = '';
        }

        function confirmDeleteConversation(conversationId) {
            if (confirm('Êtes-vous sûr de vouloir supprimer cette conversation ? Cette action est irréversible.')) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.innerHTML = `
                    <input type="hidden" name="action" value="delete_conversation">
                    <input type="hidden" name="conversation_id" value="${conversationId}">
                `;
                document.body.appendChild(form);
                form.submit();
            }
        }

        // Auto-scroll vers le bas des messages
        <?php if ($selected_conversation): ?>
            document.addEventListener('DOMContentLoaded', function() {
                const messageContainer = document.querySelector('.max-h-96.overflow-y-auto');
                if (messageContainer) {
                    messageContainer.scrollTop = messageContainer.scrollHeight;
                }
            });
        <?php endif; ?>
    </script>
</body>
</html>