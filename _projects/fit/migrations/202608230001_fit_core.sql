-- FitGPT core schema.
-- This migration is additive and does not alter the existing portal tables.

CREATE TABLE IF NOT EXISTS fit_user_profile (
  user_id INT NOT NULL,
  profile_data LONGTEXT NULL,
  timezone VARCHAR(64) NOT NULL DEFAULT 'Europe/Paris',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id),
  CONSTRAINT fk_fit_profile_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS fit_rule_block (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id INT NOT NULL,
  code VARCHAR(64) NOT NULL,
  status VARCHAR(24) NOT NULL DEFAULT 'incomplete',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_fit_rule_block_user_code (user_id, code),
  KEY idx_fit_rule_block_user_status (user_id, status),
  CONSTRAINT fk_fit_rule_block_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS fit_rule_block_version (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  rule_block_id BIGINT UNSIGNED NOT NULL,
  version_number INT UNSIGNED NOT NULL,
  configuration_data LONGTEXT NOT NULL,
  changed_by_user_id INT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_fit_rule_block_version (rule_block_id, version_number),
  KEY idx_fit_rule_block_version_block_created (rule_block_id, created_at),
  CONSTRAINT fk_fit_rule_block_version_block FOREIGN KEY (rule_block_id) REFERENCES fit_rule_block(id) ON DELETE CASCADE,
  CONSTRAINT fk_fit_rule_block_version_changed_by FOREIGN KEY (changed_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS fit_exercise_reference (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  code VARCHAR(96) NOT NULL,
  name VARCHAR(190) NOT NULL,
  category VARCHAR(64) NULL,
  movement_family VARCHAR(96) NULL,
  equipment_requirements LONGTEXT NULL,
  primary_muscles LONGTEXT NULL,
  secondary_muscles LONGTEXT NULL,
  load_mode VARCHAR(48) NULL,
  analytical_unit VARCHAR(48) NULL,
  technique_data LONGTEXT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_fit_exercise_reference_code (code),
  KEY idx_fit_exercise_reference_active_name (is_active, name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS fit_user_exercise (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id INT NOT NULL,
  reference_exercise_id BIGINT UNSIGNED NULL,
  code VARCHAR(96) NOT NULL,
  name VARCHAR(190) NOT NULL,
  configuration_data LONGTEXT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_fit_user_exercise_code (user_id, code),
  KEY idx_fit_user_exercise_user_active (user_id, is_active),
  CONSTRAINT fk_fit_user_exercise_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_fit_user_exercise_reference FOREIGN KEY (reference_exercise_id) REFERENCES fit_exercise_reference(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS fit_equipment (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id INT NOT NULL,
  code VARCHAR(96) NOT NULL,
  name VARCHAR(190) NOT NULL,
  equipment_type VARCHAR(64) NULL,
  configuration_data LONGTEXT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_fit_equipment_user_code (user_id, code),
  KEY idx_fit_equipment_user_active (user_id, is_active),
  CONSTRAINT fk_fit_equipment_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS fit_availability_context (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id INT NOT NULL,
  starts_on DATE NOT NULL,
  ends_on DATE NULL,
  location_name VARCHAR(190) NULL,
  constraints_data LONGTEXT NULL,
  duration_limit_minutes SMALLINT UNSIGNED NULL,
  temporary_objective TEXT NULL,
  exit_rule TEXT NULL,
  status VARCHAR(24) NOT NULL DEFAULT 'active',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_fit_context_user_dates (user_id, status, starts_on, ends_on),
  CONSTRAINT fk_fit_context_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS fit_availability_context_equipment (
  context_id BIGINT UNSIGNED NOT NULL,
  equipment_id BIGINT UNSIGNED NOT NULL,
  availability VARCHAR(16) NOT NULL,
  PRIMARY KEY (context_id, equipment_id),
  CONSTRAINT fk_fit_context_equipment_context FOREIGN KEY (context_id) REFERENCES fit_availability_context(id) ON DELETE CASCADE,
  CONSTRAINT fk_fit_context_equipment_equipment FOREIGN KEY (equipment_id) REFERENCES fit_equipment(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS fit_pain_event (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id INT NOT NULL,
  started_on DATE NOT NULL,
  ended_on DATE NULL,
  location_label VARCHAR(190) NOT NULL,
  intensity TINYINT UNSIGNED NULL,
  affected_movements LONGTEXT NULL,
  comment TEXT NULL,
  status VARCHAR(24) NOT NULL DEFAULT 'active',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_fit_pain_user_status_dates (user_id, status, started_on, ended_on),
  CONSTRAINT fk_fit_pain_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS fit_program (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id INT NOT NULL,
  name VARCHAR(190) NOT NULL,
  status VARCHAR(24) NOT NULL DEFAULT 'active',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_fit_program_user_status (user_id, status),
  CONSTRAINT fk_fit_program_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS fit_program_revision (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  program_id BIGINT UNSIGNED NOT NULL,
  revision_number INT UNSIGNED NOT NULL,
  status VARCHAR(24) NOT NULL DEFAULT 'draft',
  notes TEXT NULL,
  created_by_user_id INT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_fit_program_revision_number (program_id, revision_number),
  KEY idx_fit_program_revision_program_status (program_id, status),
  CONSTRAINT fk_fit_program_revision_program FOREIGN KEY (program_id) REFERENCES fit_program(id) ON DELETE CASCADE,
  CONSTRAINT fk_fit_program_revision_created_by FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS fit_program_workout (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  program_revision_id BIGINT UNSIGNED NOT NULL,
  code VARCHAR(64) NOT NULL,
  name VARCHAR(190) NOT NULL,
  sequence_number SMALLINT UNSIGNED NOT NULL,
  configuration_data LONGTEXT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_fit_program_workout_code (program_revision_id, code),
  UNIQUE KEY uq_fit_program_workout_sequence (program_revision_id, sequence_number),
  CONSTRAINT fk_fit_program_workout_revision FOREIGN KEY (program_revision_id) REFERENCES fit_program_revision(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS fit_program_exercise (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  program_workout_id BIGINT UNSIGNED NOT NULL,
  reference_exercise_id BIGINT UNSIGNED NULL,
  user_exercise_id BIGINT UNSIGNED NULL,
  sequence_number SMALLINT UNSIGNED NOT NULL,
  prescription_data LONGTEXT NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_fit_program_exercise_sequence (program_workout_id, sequence_number),
  CONSTRAINT fk_fit_program_exercise_workout FOREIGN KEY (program_workout_id) REFERENCES fit_program_workout(id) ON DELETE CASCADE,
  CONSTRAINT fk_fit_program_exercise_reference FOREIGN KEY (reference_exercise_id) REFERENCES fit_exercise_reference(id) ON DELETE SET NULL,
  CONSTRAINT fk_fit_program_exercise_user FOREIGN KEY (user_exercise_id) REFERENCES fit_user_exercise(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS fit_session_draft (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id INT NOT NULL,
  status VARCHAR(24) NOT NULL DEFAULT 'active',
  program_revision_id BIGINT UNSIGNED NULL,
  started_at DATETIME NULL,
  last_checkpoint_at DATETIME NULL,
  context_snapshot LONGTEXT NOT NULL,
  user_note TEXT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_fit_session_draft_user_status (user_id, status),
  CONSTRAINT fk_fit_session_draft_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_fit_session_draft_revision FOREIGN KEY (program_revision_id) REFERENCES fit_program_revision(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS fit_session_draft_exercise (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  draft_id BIGINT UNSIGNED NOT NULL,
  reference_exercise_id BIGINT UNSIGNED NULL,
  user_exercise_id BIGINT UNSIGNED NULL,
  sequence_number SMALLINT UNSIGNED NOT NULL,
  planned_data LONGTEXT NULL,
  was_omitted TINYINT(1) NOT NULL DEFAULT 0,
  omission_reason VARCHAR(190) NULL,
  note TEXT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_fit_session_draft_exercise_sequence (draft_id, sequence_number),
  CONSTRAINT fk_fit_session_draft_exercise_draft FOREIGN KEY (draft_id) REFERENCES fit_session_draft(id) ON DELETE CASCADE,
  CONSTRAINT fk_fit_session_draft_exercise_reference FOREIGN KEY (reference_exercise_id) REFERENCES fit_exercise_reference(id) ON DELETE SET NULL,
  CONSTRAINT fk_fit_session_draft_exercise_user FOREIGN KEY (user_exercise_id) REFERENCES fit_user_exercise(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS fit_session_draft_set (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  draft_exercise_id BIGINT UNSIGNED NOT NULL,
  set_number SMALLINT UNSIGNED NOT NULL,
  result_type VARCHAR(32) NOT NULL,
  repetitions INT UNSIGNED NULL,
  duration_seconds INT UNSIGNED NULL,
  load_value DECIMAL(10,3) NULL,
  load_unit VARCHAR(32) NULL,
  machine_setting DECIMAL(10,3) NULL,
  rir DECIMAL(3,1) NULL,
  rpe DECIMAL(3,1) NULL,
  technique_score TINYINT UNSIGNED NULL,
  pain_score TINYINT UNSIGNED NULL,
  note TEXT NULL,
  performed_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_fit_session_draft_set_number (draft_exercise_id, set_number),
  CONSTRAINT fk_fit_session_draft_set_exercise FOREIGN KEY (draft_exercise_id) REFERENCES fit_session_draft_exercise(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS fit_active_session (
  user_id INT NOT NULL,
  draft_id BIGINT UNSIGNED NOT NULL,
  acquired_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id),
  UNIQUE KEY uq_fit_active_session_draft (draft_id),
  CONSTRAINT fk_fit_active_session_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_fit_active_session_draft FOREIGN KEY (draft_id) REFERENCES fit_session_draft(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS fit_session (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id INT NOT NULL,
  draft_id BIGINT UNSIGNED NULL,
  status VARCHAR(24) NOT NULL,
  program_revision_id BIGINT UNSIGNED NULL,
  started_at DATETIME NULL,
  ended_at DATETIME NULL,
  declared_duration_seconds INT UNSIGNED NULL,
  energy_before TINYINT UNSIGNED NULL,
  energy_after TINYINT UNSIGNED NULL,
  context_snapshot LONGTEXT NOT NULL,
  note TEXT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_fit_session_draft (draft_id),
  KEY idx_fit_session_user_started (user_id, started_at),
  KEY idx_fit_session_user_status (user_id, status),
  CONSTRAINT fk_fit_session_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_fit_session_draft FOREIGN KEY (draft_id) REFERENCES fit_session_draft(id) ON DELETE SET NULL,
  CONSTRAINT fk_fit_session_revision FOREIGN KEY (program_revision_id) REFERENCES fit_program_revision(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS fit_session_exercise (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  session_id BIGINT UNSIGNED NOT NULL,
  reference_exercise_id BIGINT UNSIGNED NULL,
  user_exercise_id BIGINT UNSIGNED NULL,
  sequence_number SMALLINT UNSIGNED NOT NULL,
  planned_data LONGTEXT NULL,
  was_omitted TINYINT(1) NOT NULL DEFAULT 0,
  omission_reason VARCHAR(190) NULL,
  note TEXT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_fit_session_exercise_sequence (session_id, sequence_number),
  KEY idx_fit_session_exercise_reference (reference_exercise_id),
  KEY idx_fit_session_exercise_user_exercise (user_exercise_id),
  CONSTRAINT fk_fit_session_exercise_session FOREIGN KEY (session_id) REFERENCES fit_session(id) ON DELETE CASCADE,
  CONSTRAINT fk_fit_session_exercise_reference FOREIGN KEY (reference_exercise_id) REFERENCES fit_exercise_reference(id) ON DELETE SET NULL,
  CONSTRAINT fk_fit_session_exercise_user FOREIGN KEY (user_exercise_id) REFERENCES fit_user_exercise(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS fit_set (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  session_exercise_id BIGINT UNSIGNED NOT NULL,
  set_number SMALLINT UNSIGNED NOT NULL,
  result_type VARCHAR(32) NOT NULL,
  repetitions INT UNSIGNED NULL,
  duration_seconds INT UNSIGNED NULL,
  load_value DECIMAL(10,3) NULL,
  load_unit VARCHAR(32) NULL,
  machine_setting DECIMAL(10,3) NULL,
  rir DECIMAL(3,1) NULL,
  rpe DECIMAL(3,1) NULL,
  technique_score TINYINT UNSIGNED NULL,
  pain_score TINYINT UNSIGNED NULL,
  note TEXT NULL,
  performed_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_fit_set_number (session_exercise_id, set_number),
  CONSTRAINT fk_fit_set_exercise FOREIGN KEY (session_exercise_id) REFERENCES fit_session_exercise(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS fit_evidence_source (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  domain_code VARCHAR(64) NOT NULL,
  title VARCHAR(255) NOT NULL,
  source_url VARCHAR(1024) NULL,
  source_type VARCHAR(64) NULL,
  published_on DATE NULL,
  reviewed_on DATE NULL,
  summary TEXT NULL,
  status VARCHAR(24) NOT NULL DEFAULT 'active',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_fit_evidence_domain_status (domain_code, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
