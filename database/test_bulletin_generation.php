<?php
/**
 * Script de test pour générer un bulletin PDF
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../vendor/autoload.php';

use Dompdf\Dompdf;
use Dompdf\Options;

$database = new Database();
$conn = $database->getConnection();

// Fonctions utilitaires
function getBulletinTemplate($conn) {
    $query = "SELECT content_html FROM document_templates WHERE type = 'bulletin' ORDER BY id DESC LIMIT 1";
    $stmt = $conn->prepare($query);
    $stmt->execute();
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    return $result ? $result['content_html'] : null;
}

function renderTemplate($template, $data) {
    $html = $template;
    
    // Remplacement des variables simples
    foreach ($data as $key => $value) {
        if (!is_array($value)) {
            $html = str_replace('{{' . $key . '}}', htmlspecialchars($value), $html);
        }
    }
    
    // Gestion des boucles {{#notes}}...{{/notes}}
    if (isset($data['notes']) && is_array($data['notes'])) {
        $pattern = '/\{\{#notes\}\}(.*?)\{\{\/notes\}\}/s';
        if (preg_match($pattern, $html, $matches)) {
            $loopTemplate = $matches[1];
            $loopHtml = '';
            foreach ($data['notes'] as $note) {
                $itemHtml = $loopTemplate;
                foreach ($note as $key => $value) {
                    $itemHtml = str_replace('{{' . $key . '}}', htmlspecialchars($value), $itemHtml);
                }
                $loopHtml .= $itemHtml;
            }
            $html = preg_replace($pattern, $loopHtml, $html);
        }
    }
    
    return $html;
}

function generatePdfFromHtml($html, $outputPath) {
    $options = new Options();
    $options->set('isRemoteEnabled', true);
    $options->set('defaultFont', 'DejaVu Sans');
    
    $dompdf = new Dompdf($options);
    $dompdf->loadHtml($html);
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->render();
    
    file_put_contents($outputPath, $dompdf->output());
}

// Récupérer le premier étudiant avec des notes
$etudiant_query = "SELECT u.id, u.name, u.matricule, i.classe_id, i.annee_academique,
                          c.nom_classe, f.nom as nom_filiere, d.nom as nom_departement
                   FROM users u
                   JOIN inscriptions i ON u.id = i.user_id
                   JOIN classes c ON i.classe_id = c.id
                   JOIN filieres f ON c.filiere_id = f.id
                   JOIN departements d ON f.departement_id = d.id
                   WHERE i.statut = 'inscrit' 
                   AND EXISTS (SELECT 1 FROM notes n WHERE n.etudiant_id = u.id)
                   LIMIT 1";
$etudiant_stmt = $conn->prepare($etudiant_query);
$etudiant_stmt->execute();
$etudiant = $etudiant_stmt->fetch(PDO::FETCH_ASSOC);

if (!$etudiant) {
    echo "❌ Aucun étudiant avec des notes trouvé.\n";
    exit(1);
}

echo "✓ Étudiant trouvé: {$etudiant['name']} ({$etudiant['matricule']})\n";

// Récupérer les notes de l'étudiant
$notes_query = "SELECT e.matiere, n.note, n.type_evaluation
                FROM notes n
                JOIN enseignements e ON n.enseignement_id = e.id
                WHERE n.etudiant_id = :etudiant_id
                ORDER BY e.matiere, n.type_evaluation";
$notes_stmt = $conn->prepare($notes_query);
$notes_stmt->bindParam(':etudiant_id', $etudiant['id']);
$notes_stmt->execute();
$notes_data = $notes_stmt->fetchAll(PDO::FETCH_ASSOC);

echo "✓ " . count($notes_data) . " notes récupérées\n";

// Formater les notes pour le template
$notes_formatted = [];
foreach ($notes_data as $note) {
    $notes_formatted[] = [
        'matiere' => $note['matiere'],
        'type' => ucfirst($note['type_evaluation']),
        'note' => number_format($note['note'], 2)
    ];
}

// Récupérer le template de bulletin
$template = getBulletinTemplate($conn);
if (!$template) {
    echo "❌ Template de bulletin introuvable.\n";
    exit(1);
}

echo "✓ Template récupéré\n";

// Préparer les données pour le template
$data = [
    'name' => $etudiant['name'],
    'matricule' => $etudiant['matricule'],
    'nom_classe' => $etudiant['nom_classe'],
    'nom_filiere' => $etudiant['nom_filiere'],
    'annee_academique' => $etudiant['annee_academique'],
    'notes' => $notes_formatted
];

// Rendu du template
$html = renderTemplate($template, $data);
echo "✓ Template rendu\n";

// Créer le dossier de sortie si nécessaire
$output_dir = __DIR__ . '/../agent_administratif/outputs/bulletins/';
if (!file_exists($output_dir)) {
    mkdir($output_dir, 0777, true);
}

// Générer les noms de fichiers
$filename_base = 'bulletin_test_' . $etudiant['matricule'] . '_' . date('YmdHis');
$html_path = $output_dir . $filename_base . '.html';
$pdf_path = $output_dir . $filename_base . '.pdf';

// Sauvegarder le HTML
file_put_contents($html_path, $html);
echo "✓ HTML sauvegardé: $html_path\n";

// Générer le PDF
generatePdfFromHtml($html, $pdf_path);
echo "✓ PDF généré: $pdf_path\n";

echo "\n✅ Bulletin généré avec succès!\n";
echo "   HTML: agent_administratif/outputs/bulletins/$filename_base.html\n";
echo "   PDF:  agent_administratif/outputs/bulletins/$filename_base.pdf\n";
