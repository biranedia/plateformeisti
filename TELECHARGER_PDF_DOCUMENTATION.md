# Fonctionnalité de Téléchargement des Attestations PDF

## Vue d'ensemble
La fonctionnalité de téléchargement des attestations PDF permet aux agents administratifs de générer et télécharger les attestations d'inscription des étudiants en format PDF.

## Fichiers Implémentés

### 1. `agent_administratif/attestation_inscription.php` (Modifié)
- **Imports Ajoutés**: Dompdf et Options
- **Fonction modifiée**: `genererAttestationPDF()`
  - Génère un HTML formaté pour l'attestation
  - Crée le PDF via Dompdf
  - Stocke le fichier dans `documents/attestations/`
  - Prend les paramètres: `$inscription`, `$numero_attestation`, `$attestation_id`

### 2. `agent_administratif/download_attestation.php` (Nouveau)
- **Fonctionnalité**: Gère le téléchargement des attestations PDF
- **Sécurité**:
  - Vérifie l'authentification (agent_admin uniquement)
  - Valide l'ID de l'attestation
  - Vérifie que le fichier PDF existe
  - Vérifie que l'attestation est active
- **Headers HTTP**: Configure les headers pour le téléchargement
- **Format de fichier**: `Attestation_[NUMERO]_[MATRICULE].pdf`

### 3. `agent_administratif/certificat_scolarite.php` (Modifié)
- **Fonction modifiée**: `telechargerCertificat()`
  - Changée pour soumettre un formulaire POST
  - Appelle `download_certificat.php`

### 4. `agent_administratif/download_certificat.php` (Nouveau)
- **Fonctionnalité**: Gère le téléchargement des certificats de scolarité PDF
- **Sécurité**: Même niveau de sécurité que pour les attestations
- **Format de fichier**: `Certificat_[NUMERO]_[MATRICULE].pdf`

## Architecture

### Flux de Génération d'Attestation
```
Page attestation_inscription.php
    ↓ (POST avec action='generer_attestation')
    ↓
Insertion dans BD (attestations_inscription)
    ↓
genererAttestationPDF()
    ↓
Création du HTML avec données étudiantes
    ↓
Conversion en PDF via Dompdf
    ↓
Sauvegarde dans documents/attestations/[NUMERO].pdf
```

### Flux de Téléchargement
```
Bouton "Télécharger" dans le tableau
    ↓ (JavaScript telechargerAttestation())
    ↓
POST vers download_attestation.php
    ↓
Vérification BD (attestation existante + active)
    ↓
Vérification fichier (existence du PDF)
    ↓
Headers HTTP pour téléchargement
    ↓
Envoi du fichier au client
```

## Structure HTML de l'Attestation

L'attestation PDF contient:
- **En-tête**: Nom de l'établissement (ISTI), titre "ATTESTATION D'INSCRIPTION"
- **Données Étudiantes**: Nom, prénom, matricule, date de naissance
- **Informations Académiques**: Classe, filière, département, année académique
- **Dates**: Date d'inscription, date d'émission
- **Signature**: Espace pour signature du directeur
- **Numéro d'Attestation**: En bas à droite de la page

## Format et Style CSS

- **Police**: Arial, sans-serif
- **Marge**: 40px tout autour
- **Couleurs**: Bleu foncé (#003366) pour les titres
- **Papier**: A4 en orientation portrait
- **Boîtes d'information**: Bordure gauche bleue, fond gris clair

## Répertoires Créés

- `documents/attestations/` - Stockage des attestations PDF générées
- `documents/certificats/` - Stockage des certificats PDF générés

## Points de Sécurité

1. **Authentification**: Seuls les agents administratifs peuvent générer/télécharger
2. **Validation**: Vérification de l'existence des attestations en base de données
3. **Statut**: Seules les attestations actives peuvent être téléchargées
4. **Fichier**: Vérification de l'existence du fichier avant téléchargement
5. **Headers**: Configuration appropriée des headers HTTP pour les téléchargements

## Testing

Un script de test `test_attestation_pdf.php` a été créé pour vérifier:
- L'installation de Dompdf
- La génération d'un PDF d'exemple
- La création du répertoire de stockage

## Notes Importantes

- Les PDFs sont générés à la demande lors de la création de l'attestation
- Les fichiers sont stockés localement et peuvent être téléchargés à tout moment
- Le numéro d'attestation suit le format: `ATT-YYYY-XXXXXX` (YYYY = année, XXXXXX = ID inscription)
- Les certificats utilisent le même système que les attestations

## Prochaines Étapes Possibles

1. Ajouter un champ `last_downloaded` dans les tables pour tracer les téléchargements
2. Implémenter un système de signature électronique
3. Ajouter l'envoi par email automatique des attestations
4. Créer un portail étudiant pour télécharger ses propres attestations
5. Ajouter un système d'archivage avec versioning
