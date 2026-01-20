<?php
// Seed default templates for certificat_scolarite and bulletin
require_once __DIR__ . '/../config/database.php';

$database = new Database();
$conn = $database->getConnection();

$templates = [
    [
        'type' => 'certificat_scolarite',
        'name' => 'Certificat standard',
        'content_html' => <<<HTML
<!DOCTYPE html>
<html>
<head>
  <meta charset="UTF-8">
  <style>
    body { font-family: Arial, sans-serif; padding: 32px; }
    h1 { text-align: center; text-transform: uppercase; letter-spacing: 1px; }
    .meta { margin-top: 24px; }
    .meta p { margin: 6px 0; }
    .section { margin-top: 24px; line-height: 1.5; }
    .signature { margin-top: 48px; text-align: right; }
  </style>
</head>
<body>
  <h1>Certificat de scolarité</h1>
  <div class="meta">
    <p><strong>Numéro :</strong> {{numero_certificat}}</p>
    <p><strong>Date d'émission :</strong> {{date_emission}}</p>
  </div>
  <div class="section">
    <p>Je soussigné, certifie que l'étudiant(e) :</p>
    <p><strong>Nom et prénom :</strong> {{name}}</p>
    <p><strong>Matricule :</strong> {{matricule}}</p>
    <p><strong>Date de naissance :</strong> {{date_naissance}}</p>
    <p><strong>Classe :</strong> {{nom_classe}}</p>
    <p><strong>Filière :</strong> {{nom_filiere}}</p>
    <p><strong>Département :</strong> {{nom_departement}}</p>
    <p><strong>Année académique :</strong> {{annee_academique}}</p>
  </div>
  <div class="section">
    <p>Est régulièrement inscrit(e) pour l'année académique mentionnée ci-dessus.</p>
  </div>
  <div class="signature">
    <p>Fait le {{date_emission}}</p>
    <p>Signature</p>
  </div>
</body>
</html>
HTML
    ],
    [
        'type' => 'bulletin',
        'name' => 'Bulletin simple',
        'content_html' => <<<HTML
<!DOCTYPE html>
<html>
<head>
  <meta charset="UTF-8">
  <style>
    body { font-family: Arial, sans-serif; padding: 24px; }
    h1 { text-align: center; }
    table { width: 100%; border-collapse: collapse; margin-top: 16px; }
    th, td { border: 1px solid #333; padding: 6px; font-size: 12px; }
    th { background: #f0f0f0; }
    .meta p { margin: 4px 0; }
  </style>
</head>
<body>
  <h1>Bulletin de notes</h1>
  <div class="meta">
    <p><strong>Étudiant :</strong> {{name}} ({{matricule}})</p>
    <p><strong>Classe :</strong> {{nom_classe}} — <strong>Filière :</strong> {{nom_filiere}}</p>
    <p><strong>Année académique :</strong> {{annee_academique}}</p>
  </div>
  <table>
    <thead>
      <tr>
        <th>Matière</th>
        <th>Type</th>
        <th>Note</th>
      </tr>
    </thead>
    <tbody>
      {{#notes}}
      <tr>
        <td>{{matiere}}</td>
        <td>{{type}}</td>
        <td>{{note}}</td>
      </tr>
      {{/notes}}
    </tbody>
  </table>
</body>
</html>
HTML
    ],
];

foreach ($templates as $tpl) {
    $exists = $conn->prepare("SELECT 1 FROM document_templates WHERE type = :type AND name = :name LIMIT 1");
    $exists->execute([':type' => $tpl['type'], ':name' => $tpl['name']]);
    if ($exists->fetch()) {
        continue;
    }
    $insert = $conn->prepare("INSERT INTO document_templates (type, name, content_html) VALUES (:type, :name, :content_html)");
    $insert->execute([
        ':type' => $tpl['type'],
        ':name' => $tpl['name'],
        ':content_html' => $tpl['content_html'],
    ]);
}

echo "Templates insérés si absents.";
