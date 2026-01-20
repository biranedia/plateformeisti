# Correction : Prévisualisation des Documents Avant Validation

## Problème résolu
Les étudiants ne pouvaient pas voir les documents qu'ils avaient uploadés avant la validation par l'administration.

## Solution apportée
Ajout d'une fonctionnalité de **prévisualisation en temps réel** directement depuis la page "Mes Documents".

### Fonctionnalités implémentées

#### 1. Bouton "Voir" dans la liste
- Chaque document a un bouton "Voir" permettant de prévisualiser
- Lien direct vers `documents.php?view=[id]`

#### 2. Affichage détaillé du document
Affiche les infos suivantes :
- 📄 **Type de document** (Relevé BAC, Diplôme BAC, etc.)
- 📝 **Nom du fichier**
- 📅 **Date d'upload** (avec heure)
- 📊 **Taille du fichier**
- ✓ **Statut actuel** (En attente, Validé, Rejeté)
- 📆 **Date de validation** (si déjà validé)
- 💬 **Commentaire** (Commentaire de validation ou raison du rejet)

#### 3. Prévisualisation du fichier
Selon le type de fichier :

**🖼️ Images (JPG, PNG, GIF)**
- Affichage direct de l'image avec zoom possible
- Zone scrollable pour les grandes images

**📋 PDF**
- Prévisualisation intégrée avec iframe
- Barre d'outils de navigation
- Possibilité de zoomer/dézoomer

**📦 Autres formats**
- Message informant que la prévisualisation n'est pas disponible
- Bouton pour télécharger le fichier

#### 4. Navigation
- Lien "Retour à la liste" pour revenir
- Bouton "Télécharger le fichier original" pour avoir une copie locale
- Lien pour télécharger depuis la liste principale

### Statuts des documents

| Statut | Icône | Couleur | Signification |
|--------|-------|--------|---------------|
| Soumis | ⏳ | Jaune | Document en attente de validation |
| Validé | ✓ | Vert | Document accepté par l'administration |
| Rejeté | ✗ | Rouge | Document refusé (voir la raison) |

### Utilisation

**Pour les étudiants :**
1. Aller dans "Mes Documents"
2. Cliquer sur le bouton "Voir" d'un document
3. Consulter tous les détails et l'aperçu
4. Voir les commentaires de validation (le cas échéant)

**Exemple de flux :**
```
Étudiant upload doc 
    ↓
Statut = "En attente"
    ↓
Étudiant peut voir la prévisualisation
    ↓
Admin valide le document
    ↓
Statut = "Validé" + commentaire
    ↓
Étudiant voit la validation confirmée
```

### Fichiers modifiés

- **`etudiant/documents.php`**
  - Ajout de la logique de récupération du document détaillé
  - Ajout de la section de prévisualisation
  - Ajout du bouton "Voir" dans la liste

### Sécurité

✅ Vérifications :
- Vérification que l'étudiant accède seulement à ses propres documents
- Échappement HTML de tous les textes affichés
- Validation des extensions de fichier pour la prévisualisation

### Améliorations futures

1. **Téléchargement multiple** - Télécharger plusieurs documents en ZIP
2. **Historique de validation** - Voir tous les commentaires passés
3. **Notifications** - Recevoir une alerte quand un document est validé/rejeté
4. **Re-upload** - Permettre de modifier un document rejeté
5. **Signature numérique** - Voir si le document est signé numériquement

---

**Implémentation :** 20 janvier 2026
