-- Script SQL complet pour la plateforme ISTI (avec gestion des rôles multiples)
-- Base de données relationnelle (compatible PostgreSQL ou MySQL)

-- 1. TABLE DES UTILISATEURS
CREATE TABLE users (
    id SERIAL PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    email VARCHAR(100) UNIQUE NOT NULL,
    password_hash TEXT NOT NULL,
    phone VARCHAR(20),
    matricule VARCHAR(20) UNIQUE,
    date_naissance DATE,
    telephone VARCHAR(20),
    role ENUM('admin', 'resp_dept', 'resp_filiere', 'resp_classe', 'etudiant', 'enseignant', 'agent_admin') DEFAULT 'etudiant',
    photo_url TEXT,
    is_active BOOLEAN DEFAULT true,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- 2. TABLE DES RÔLES (multi-rôles)
CREATE TABLE user_roles (
    id SERIAL PRIMARY KEY,
    user_id INTEGER REFERENCES users(id) ON DELETE CASCADE,
    role ENUM('admin', 'resp_dept', 'resp_filiere', 'resp_classe', 'etudiant', 'enseignant', 'agent_admin') NOT NULL
);

-- 3. DEPARTEMENTS
CREATE TABLE departements (
    id SERIAL PRIMARY KEY,
    nom VARCHAR(100) NOT NULL,
    responsable_id INTEGER REFERENCES users(id)
);

-- 4. FILIERES
CREATE TABLE filieres (
    id SERIAL PRIMARY KEY,
    nom VARCHAR(100) NOT NULL,
    departement_id INTEGER REFERENCES departements(id) ON DELETE CASCADE,
    responsable_id INTEGER REFERENCES users(id)
);

-- 5. CLASSES
CREATE TABLE classes (
    id SERIAL PRIMARY KEY,
    nom_classe VARCHAR(100) NOT NULL,
    niveau ENUM('L1', 'L2', 'L3', 'M1', 'M2') NOT NULL,
    capacite_max INTEGER DEFAULT 30,
    filiere_id INTEGER REFERENCES filieres(id) ON DELETE CASCADE,
    annee_academique_id INTEGER REFERENCES annees_academiques(id),
    description TEXT,
    responsable_id INTEGER REFERENCES users(id),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- 6. INSCRIPTIONS
CREATE TABLE inscriptions (
    id SERIAL PRIMARY KEY,
    user_id INTEGER REFERENCES users(id),
    classe_id INTEGER REFERENCES classes(id),
    annee_academique VARCHAR(20) NOT NULL,
    statut ENUM('inscrit', 'reinscrit', 'abandon') NOT NULL
);

-- 7. ETUDIANTS_CLASSES (affectations effectives)
CREATE TABLE etudiants_classes (
    id SERIAL PRIMARY KEY,
    etudiant_id INTEGER REFERENCES users(id),
    classe_id INTEGER REFERENCES classes(id),
    annee_academique_id INTEGER REFERENCES annees_academiques(id),
    date_inscription TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    statut ENUM('actif', 'transfere', 'exclu') DEFAULT 'actif'
);

-- 8. ENSEIGNEMENTS
CREATE TABLE enseignements (
    id SERIAL PRIMARY KEY,
    enseignant_id INTEGER REFERENCES users(id),
    classe_id INTEGER REFERENCES classes(id),
    matiere VARCHAR(100) NOT NULL,
    volume_horaire INTEGER NOT NULL
);

-- 9. EMPLOIS DU TEMPS
CREATE TABLE emplois_du_temps (
    id SERIAL PRIMARY KEY,
    classe_id INTEGER REFERENCES classes(id),
    jour ENUM('Lundi', 'Mardi', 'Mercredi', 'Jeudi', 'Vendredi', 'Samedi', 'Dimanche') NOT NULL,
    heure_debut TIME NOT NULL,
    heure_fin TIME NOT NULL,
    matiere VARCHAR(100) NOT NULL,
    enseignant_id INTEGER REFERENCES users(id),
    salle VARCHAR(50)
);

-- 10. DOCUMENTS ADMINISTRATIFS
CREATE TABLE documents (
    id SERIAL PRIMARY KEY,
    user_id INTEGER REFERENCES users(id),
    type_document ENUM('attestation_scolarite', 'releve_notes', 'certificat_reussite') NOT NULL,
    statut ENUM('en_attente', 'valide', 'rejete') DEFAULT 'en_attente',
    fichier_url TEXT,
    date_creation TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    valide_par INTEGER REFERENCES users(id)
);

-- 11. NOTIFICATIONS
CREATE TABLE notifications (
    id SERIAL PRIMARY KEY,
    user_id INTEGER REFERENCES users(id),
    message TEXT NOT NULL,
    statut ENUM('non_lu', 'lu') DEFAULT 'non_lu',
    type ENUM('info', 'alerte', 'admin') NOT NULL,
    date_envoi TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- 12. JOURNALISATION (AUDIT LOG)
CREATE TABLE audit_logs (
    id SERIAL PRIMARY KEY,
    user_id INTEGER REFERENCES users(id),
    action TEXT NOT NULL,
    table_cible VARCHAR(100),
    date_action TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- 13. FICHIERS PEDAGOGIQUES
CREATE TABLE fichiers_pedagogiques (
    id SERIAL PRIMARY KEY,
    enseignement_id INTEGER REFERENCES enseignements(id),
    fichier_url TEXT NOT NULL,
    titre VARCHAR(150),
    date_upload TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- 14. FEEDBACK UTILISATEURS
CREATE TABLE feedbacks (
    id SERIAL PRIMARY KEY,
    user_id INTEGER REFERENCES users(id),
    message TEXT NOT NULL,
    date_envoi TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    type ENUM('bug', 'suggestion', 'plainte')
);

-- 15. EVENEMENTS (Examens, soutenances, etc.)
CREATE TABLE evenements (
    id SERIAL PRIMARY KEY,
    titre VARCHAR(150) NOT NULL,
    description TEXT,
    date_debut TIMESTAMP NOT NULL,
    date_fin TIMESTAMP NOT NULL,
    classe_id INTEGER REFERENCES classes(id)
);

-- 16. SESSIONS D'AUTHENTIFICATION
CREATE TABLE auth_sessions (
    id SERIAL PRIMARY KEY,
    user_id INTEGER REFERENCES users(id),
    token TEXT NOT NULL,
    date_connexion TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    ip_adresse VARCHAR(45),
    agent_user TEXT
);

-- 17. NOTES DES ÉTUDIANTS
CREATE TABLE notes (
    id SERIAL PRIMARY KEY,
    etudiant_id INTEGER REFERENCES users(id),
    enseignement_id INTEGER REFERENCES enseignements(id),
    note DECIMAL(5,2) NOT NULL,
    type_evaluation ENUM('devoir', 'examen', 'tp', 'projet') NOT NULL,
    date_saisie TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    commentaire TEXT
);

-- 17. PARAMÈTRES SYSTÈME
CREATE TABLE settings (
    id SERIAL PRIMARY KEY,
    setting_key VARCHAR(100) UNIQUE NOT NULL,
    setting_value TEXT,
    setting_type ENUM('string', 'integer', 'boolean', 'json') DEFAULT 'string',
    description TEXT,
    category VARCHAR(50) DEFAULT 'general',
    is_system BOOLEAN DEFAULT false,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Index supplémentaires utiles
CREATE INDEX idx_user_roles_user ON user_roles(user_id);
CREATE INDEX idx_user_roles_role ON user_roles(role);
CREATE INDEX idx_inscriptions_user ON inscriptions(user_id);
CREATE INDEX idx_notifications_user ON notifications(user_id);
CREATE INDEX idx_documents_user ON documents(user_id);
CREATE INDEX idx_notes_etudiant ON notes(etudiant_id);

-- Insertion des paramètres par défaut
INSERT INTO settings (setting_key, setting_value, setting_type, description, category, is_system) VALUES
('site_name', 'Plateforme ISTI', 'string', 'Nom du site web', 'general', true),
('site_description', 'Plateforme de gestion éducative', 'string', 'Description du site', 'general', true),
('admin_email', 'admin@isti.edu', 'string', 'Email de l\'administrateur', 'contact', true),
('max_file_size', '10485760', 'integer', 'Taille maximale des fichiers (en octets)', 'upload', false),
('allowed_file_types', 'pdf,doc,docx,xls,xlsx,ppt,pptx,txt,jpg,jpeg,png,gif', 'string', 'Types de fichiers autorisés', 'upload', false),
('academic_year_start', '09-01', 'string', 'Début de l\'année académique (MM-JJ)', 'academic', false),
('academic_year_end', '06-30', 'string', 'Fin de l\'année académique (MM-JJ)', 'academic', false),
('timezone', 'Africa/Casablanca', 'string', 'Fuseau horaire', 'system', true),
('language', 'fr', 'string', 'Langue par défaut', 'system', false),
('maintenance_mode', 'false', 'boolean', 'Mode maintenance activé', 'system', false),
('registration_open', 'true', 'boolean', 'Inscriptions ouvertes', 'academic', false),
('notification_email', 'true', 'boolean', 'Activer les notifications par email', 'notification', false);

-- 18. ANNÉES ACADÉMIQUES
CREATE TABLE annees_academiques (
    id SERIAL PRIMARY KEY,
    annee_academique VARCHAR(20) NOT NULL UNIQUE,
    date_debut DATE NOT NULL,
    date_fin DATE NOT NULL,
    is_active BOOLEAN DEFAULT false,
    created_by INTEGER REFERENCES users(id),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);
