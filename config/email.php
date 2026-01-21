<?php
/**
 * Configuration Email et SMTP
 * Gestion de l'envoi d'emails via PHPMailer
 */

// Charger l'autoloader de Composer ou PHPMailer manuel
if (file_exists(__DIR__ . '/../vendor/autoload.php')) {
    require_once __DIR__ . '/../vendor/autoload.php';
} elseif (file_exists(__DIR__ . '/../vendor/phpmailer/phpmailer/src/PHPMailer.php')) {
    require_once __DIR__ . '/../vendor/phpmailer/phpmailer/src/PHPMailer.php';
    require_once __DIR__ . '/../vendor/phpmailer/phpmailer/src/SMTP.php';
    require_once __DIR__ . '/../vendor/phpmailer/phpmailer/src/Exception.php';
}

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// Configuration SMTP
define('SMTP_HOST', 'smtp.gmail.com');          // Serveur SMTP (Gmail, Outlook, etc.)
define('SMTP_PORT', 587);                        // Port SMTP (587 pour TLS, 465 pour SSL)
define('SMTP_USERNAME', 'votre-email@gmail.com'); // Votre email
define('SMTP_PASSWORD', 'votre-mot-de-passe');   // Mot de passe ou mot de passe d'application
define('SMTP_ENCRYPTION', 'tls');                // 'tls' ou 'ssl'
define('SMTP_FROM_EMAIL', 'noreply@isti.edu');   // Email d'expédition
define('SMTP_FROM_NAME', 'Plateforme ISTI');     // Nom de l'expéditeur

/**
 * Envoyer un email
 * 
 * @param string $to Email du destinataire
 * @param string $subject Sujet de l'email
 * @param string $body Corps de l'email (HTML)
 * @param string $toName Nom du destinataire (optionnel)
 * @param array $attachments Fichiers joints (optionnel)
 * @return bool
 */
function sendEmail($to, $subject, $body, $toName = '', $attachments = []) {
    // Vérifier si PHPMailer est installé
    if (!class_exists('PHPMailer\PHPMailer\PHPMailer')) {
        error_log("PHPMailer n'est pas installé. Installez-le avec: composer require phpmailer/phpmailer");
        return false;
    }

    $mail = new PHPMailer(true);

    try {
        // Configuration du serveur SMTP
        $mail->isSMTP();
        $mail->Host       = SMTP_HOST;
        $mail->SMTPAuth   = true;
        $mail->Username   = SMTP_USERNAME;
        $mail->Password   = SMTP_PASSWORD;
        $mail->SMTPSecure = SMTP_ENCRYPTION;
        $mail->Port       = SMTP_PORT;
        $mail->CharSet    = 'UTF-8';

        // Expéditeur et destinataire
        $mail->setFrom(SMTP_FROM_EMAIL, SMTP_FROM_NAME);
        $mail->addAddress($to, $toName);

        // Contenu de l'email
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $body;
        $mail->AltBody = strip_tags($body);

        // Ajouter les pièces jointes
        foreach ($attachments as $file) {
            if (file_exists($file)) {
                $mail->addAttachment($file);
            }
        }

        // Envoyer l'email
        $mail->send();
        return true;

    } catch (Exception $e) {
        error_log("Erreur d'envoi d'email: {$mail->ErrorInfo}");
        return false;
    }
}

/**
 * Envoyer un email de notification d'emploi du temps
 */
function sendEdtNotification($recipientEmail, $recipientName, $coursDetails) {
    $subject = "Nouvel horaire ajouté à votre emploi du temps";
    
    $body = "
    <html>
    <head>
        <style>
            body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
            .container { max-width: 600px; margin: 0 auto; padding: 20px; }
            .header { background-color: #4F46E5; color: white; padding: 20px; text-align: center; }
            .content { background-color: #f9f9f9; padding: 20px; margin-top: 20px; }
            .footer { text-align: center; margin-top: 20px; font-size: 12px; color: #777; }
            .details { background: white; padding: 15px; margin: 15px 0; border-left: 4px solid #4F46E5; }
        </style>
    </head>
    <body>
        <div class='container'>
            <div class='header'>
                <h1>Plateforme ISTI</h1>
                <p>Gestion des emplois du temps</p>
            </div>
            <div class='content'>
                <h2>Bonjour " . htmlspecialchars($recipientName) . ",</h2>
                <p>Un nouveau cours a été ajouté à votre emploi du temps :</p>
                <div class='details'>
                    <p><strong>Matière :</strong> " . htmlspecialchars($coursDetails['matiere']) . "</p>
                    <p><strong>Classe :</strong> " . htmlspecialchars($coursDetails['classe']) . "</p>
                    <p><strong>Jour :</strong> " . htmlspecialchars($coursDetails['jour']) . "</p>
                    <p><strong>Horaire :</strong> " . htmlspecialchars($coursDetails['creneau']) . "</p>
                    <p><strong>Salle :</strong> " . htmlspecialchars($coursDetails['salle'] ?? 'Non spécifiée') . "</p>
                    <p><strong>Année :</strong> " . htmlspecialchars($coursDetails['annee']) . "</p>
                </div>
                <p>Connectez-vous à la plateforme pour consulter votre emploi du temps complet.</p>
            </div>
            <div class='footer'>
                <p>Ceci est un message automatique, merci de ne pas y répondre.</p>
                <p>&copy; 2026 Institut Supérieur de Technologie et d'Informatique</p>
            </div>
        </div>
    </body>
    </html>
    ";

    return sendEmail($recipientEmail, $subject, $body, $recipientName);
}

/**
 * Envoyer un email de notification générique
 */
function sendNotificationEmail($recipientEmail, $recipientName, $titre, $message) {
    $subject = $titre;
    
    $body = "
    <html>
    <head>
        <style>
            body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
            .container { max-width: 600px; margin: 0 auto; padding: 20px; }
            .header { background-color: #4F46E5; color: white; padding: 20px; text-align: center; }
            .content { background-color: #f9f9f9; padding: 20px; margin-top: 20px; }
            .footer { text-align: center; margin-top: 20px; font-size: 12px; color: #777; }
        </style>
    </head>
    <body>
        <div class='container'>
            <div class='header'>
                <h1>Plateforme ISTI</h1>
            </div>
            <div class='content'>
                <h2>Bonjour " . htmlspecialchars($recipientName) . ",</h2>
                <p>" . nl2br(htmlspecialchars($message)) . "</p>
            </div>
            <div class='footer'>
                <p>Ceci est un message automatique, merci de ne pas y répondre.</p>
                <p>&copy; 2026 Institut Supérieur de Technologie et d'Informatique</p>
            </div>
        </div>
    </body>
    </html>
    ";

    return sendEmail($recipientEmail, $subject, $body, $recipientName);
}
