-- ============================================================
-- Real Estate App: database schema for XAMPP (MariaDB / MySQL)
-- Import with phpMyAdmin (Import tab) or: mysql -u root < schema.sql
-- Creates the database `realestate_app` with tables, triggers,
-- views, and reference data (regions, cities, amenities, settings).
-- No admin accounts are created here; the seed file does that.
-- ============================================================

CREATE DATABASE IF NOT EXISTS realestate_app
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE realestate_app;

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ============================================================
-- 1. ACCOUNTS AND VERIFICATION
-- ============================================================

CREATE TABLE users (
  id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  role                ENUM('owner','agent','user','admin') NOT NULL,
  admin_tier          ENUM('root','seeded','promoted') NULL,          -- only for role = admin
  name                VARCHAR(100) NOT NULL,                          -- shown as chat title
  username            VARCHAR(20)  NOT NULL,                          -- unique, case-insensitive (collation)
  email               VARCHAR(190) NOT NULL,
  email_verified_at   DATETIME NULL,
  phone               VARCHAR(16) NULL,                               -- +233 format, one account per number
  phone_verified_at   DATETIME NULL,                                  -- required only at payment steps
  password_hash       VARCHAR(255) NOT NULL,
  must_change_password TINYINT(1) NOT NULL DEFAULT 0,
  profile_photo       VARCHAR(255) NULL,                              -- app requires it before activation
  status              ENUM('unverified','active','banned') NOT NULL DEFAULT 'unverified',
  terms_version       VARCHAR(20) NULL,
  terms_accepted_at   DATETIME NULL,
  name_changed_at     DATETIME NULL,
  promoted_by         BIGINT UNSIGNED NULL,                           -- seeded admin who promoted this user
  promoted_at         DATETIME NULL,
  last_seen_at        DATETIME NULL,
  created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_users_username (username),
  UNIQUE KEY uq_users_email (email),
  UNIQUE KEY uq_users_phone (phone),
  KEY idx_users_role (role, status),
  CONSTRAINT fk_users_promoted_by FOREIGN KEY (promoted_by) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE reserved_usernames (
  username VARCHAR(20) PRIMARY KEY
) ENGINE=InnoDB;

CREATE TABLE blocked_email_domains (
  domain VARCHAR(190) PRIMARY KEY
) ENGINE=InnoDB;

CREATE TABLE verification_codes (
  id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id     BIGINT UNSIGNED NOT NULL,
  channel     ENUM('email','phone') NOT NULL,
  target      VARCHAR(190) NOT NULL,                                  -- address or number being verified
  code_hash   CHAR(64) NOT NULL,
  attempts    TINYINT UNSIGNED NOT NULL DEFAULT 0,                    -- lock after 5
  expires_at  DATETIME NOT NULL,
  used_at     DATETIME NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_vc_user (user_id, channel),
  CONSTRAINT fk_vc_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE password_resets (
  id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id     BIGINT UNSIGNED NOT NULL,
  token_hash  CHAR(64) NOT NULL,
  expires_at  DATETIME NOT NULL,
  used_at     DATETIME NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_pr_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE sms_log (                                              -- cost control and resend limits
  id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id     BIGINT UNSIGNED NULL,
  phone       VARCHAR(16) NOT NULL,
  purpose     VARCHAR(40) NOT NULL DEFAULT 'payment_verification',
  device_id   VARCHAR(100) NULL,
  sent_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_sms_phone (phone, sent_at),
  CONSTRAINT fk_sms_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE device_tokens (                                        -- push notifications
  id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id     BIGINT UNSIGNED NOT NULL,
  token       VARCHAR(255) NOT NULL,
  platform    ENUM('android','ios','web') NOT NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_device_token (token),
  CONSTRAINT fk_dt_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ============================================================
-- 2. LOCATIONS (Ghana: region > city > area)
-- ============================================================

CREATE TABLE regions (
  id    SMALLINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name  VARCHAR(60) NOT NULL UNIQUE
) ENGINE=InnoDB;

CREATE TABLE cities (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  region_id  SMALLINT UNSIGNED NOT NULL,
  name       VARCHAR(80) NOT NULL,
  active     TINYINT(1) NOT NULL DEFAULT 1,
  UNIQUE KEY uq_city (region_id, name),
  CONSTRAINT fk_city_region FOREIGN KEY (region_id) REFERENCES regions(id)
) ENGINE=InnoDB;

CREATE TABLE areas (                                                -- neighbourhoods (e.g. East Legon)
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  city_id       INT UNSIGNED NOT NULL,
  name          VARCHAR(80) NOT NULL,
  status        ENUM('approved','pending') NOT NULL DEFAULT 'approved',  -- "Other" entries wait for admin review
  suggested_by  BIGINT UNSIGNED NULL,
  active        TINYINT(1) NOT NULL DEFAULT 1,
  UNIQUE KEY uq_area (city_id, name),
  CONSTRAINT fk_area_city FOREIGN KEY (city_id) REFERENCES cities(id),
  CONSTRAINT fk_area_user FOREIGN KEY (suggested_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ============================================================
-- 3. BUILDINGS, ROOMS, AGREEMENTS, PHOTOS
-- ============================================================

CREATE TABLE termination_policies (
  id           SMALLINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  label        VARCHAR(120) NOT NULL,                                 -- e.g. "15% of remaining duration deducted"
  basis        ENUM('remaining_duration','remaining_rent','forfeit_months','no_refund') NOT NULL,
  percent      DECIMAL(5,2) NULL,
  months       TINYINT UNSIGNED NULL,
  active       TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB;

CREATE TABLE owner_fee_acknowledgements (                           -- 5% notice acknowledged before first upload
  id               BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  owner_id         BIGINT UNSIGNED NOT NULL,
  fee_percent      DECIMAL(5,2) NOT NULL,
  acknowledged_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_ofa_owner (owner_id),
  CONSTRAINT fk_ofa_owner FOREIGN KEY (owner_id) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE buildings (
  id                    BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  owner_id              BIGINT UNSIGNED NOT NULL,
  name                  VARCHAR(120) NOT NULL,
  description           TEXT NULL,
  city_id               INT UNSIGNED NOT NULL,
  area_id               INT UNSIGNED NULL,
  area_other            VARCHAR(80) NULL,                             -- typed "Other" area, sent to admin
  gps_address           VARCHAR(20) NULL,                             -- Ghana Post GPS, e.g. GA-123-4567
  landmark              VARCHAR(150) NULL,
  termination_policy_id SMALLINT UNSIGNED NULL,
  agreement_scope       ENUM('general','by_floor','by_room_type') NULL,
  floors_count          TINYINT UNSIGNED NULL,
  status                ENUM('draft','live','banned') NOT NULL DEFAULT 'draft',
  created_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_b_owner (owner_id),
  KEY idx_b_city (city_id, area_id, status),
  FULLTEXT KEY ft_b_name (name),
  CONSTRAINT fk_b_owner FOREIGN KEY (owner_id) REFERENCES users(id),
  CONSTRAINT fk_b_city FOREIGN KEY (city_id) REFERENCES cities(id),
  CONSTRAINT fk_b_area FOREIGN KEY (area_id) REFERENCES areas(id),
  CONSTRAINT fk_b_policy FOREIGN KEY (termination_policy_id) REFERENCES termination_policies(id)
) ENGINE=InnoDB;

CREATE TABLE room_types (
  id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  building_id  BIGINT UNSIGNED NOT NULL,
  name         VARCHAR(80) NOT NULL,                                  -- "Self-contain", "Single room"
  UNIQUE KEY uq_room_type (building_id, name),
  CONSTRAINT fk_rt_building FOREIGN KEY (building_id) REFERENCES buildings(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE agreements (                                           -- tenancy agreement PDFs, versioned
  id             BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  building_id    BIGINT UNSIGNED NOT NULL,
  scope          ENUM('general','floor','room_type') NOT NULL,
  floor_number   TINYINT NULL,                                        -- 0 = ground floor (open question)
  room_type_id   BIGINT UNSIGNED NULL,
  pdf_path       VARCHAR(255) NOT NULL,
  file_hash      CHAR(64) NULL,
  version        INT UNSIGNED NOT NULL DEFAULT 1,
  is_current     TINYINT(1) NOT NULL DEFAULT 1,
  uploaded_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_ag_building (building_id, is_current),
  CONSTRAINT fk_ag_building FOREIGN KEY (building_id) REFERENCES buildings(id) ON DELETE CASCADE,
  CONSTRAINT fk_ag_roomtype FOREIGN KEY (room_type_id) REFERENCES room_types(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE rooms (
  id             BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  building_id    BIGINT UNSIGNED NOT NULL,
  room_type_id   BIGINT UNSIGNED NULL,
  floor_number   TINYINT NULL,
  label          VARCHAR(80) NOT NULL,                                -- e.g. "2 Bedroom Self-Contain"
  description    TEXT NULL,
  monthly_rent   DECIMAL(12,2) NULL,                                  -- GHS
  agreement_id   BIGINT UNSIGNED NULL,                                -- matched agreement
  status         ENUM('draft','available','reserved','occupied','banned') NOT NULL DEFAULT 'draft',
  created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_r_building (building_id, status),
  CONSTRAINT fk_r_building FOREIGN KEY (building_id) REFERENCES buildings(id) ON DELETE CASCADE,
  CONSTRAINT fk_r_roomtype FOREIGN KEY (room_type_id) REFERENCES room_types(id) ON DELETE SET NULL,
  CONSTRAINT fk_r_agreement FOREIGN KEY (agreement_id) REFERENCES agreements(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE room_rent_options (                                    -- durations offered per room
  room_id          BIGINT UNSIGNED NOT NULL,
  duration_months  TINYINT UNSIGNED NOT NULL,
  PRIMARY KEY (room_id, duration_months),
  CONSTRAINT chk_duration CHECK (duration_months IN (6,12,24)),
  CONSTRAINT fk_rro_room FOREIGN KEY (room_id) REFERENCES rooms(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE building_photos (                                      -- max 7 per building (trigger below)
  id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  building_id  BIGINT UNSIGNED NOT NULL,
  path         VARCHAR(255) NOT NULL,
  sort_order   TINYINT UNSIGNED NOT NULL DEFAULT 0,
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_bp (building_id, sort_order),
  CONSTRAINT fk_bp_building FOREIGN KEY (building_id) REFERENCES buildings(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE room_photos (                                          -- max 10 per room (trigger below)
  id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  room_id     BIGINT UNSIGNED NOT NULL,
  path        VARCHAR(255) NOT NULL,
  sort_order  TINYINT UNSIGNED NOT NULL DEFAULT 0,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_rp (room_id, sort_order),
  CONSTRAINT fk_rp_room FOREIGN KEY (room_id) REFERENCES rooms(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ============================================================
-- 4. AMENITIES
-- ============================================================

CREATE TABLE amenity_definitions (
  id          SMALLINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code        VARCHAR(40) NOT NULL UNIQUE,
  scope       ENUM('building','room') NOT NULL,
  label       VARCHAR(80) NOT NULL,
  kind        ENUM('yes_no','choice') NOT NULL,
  searchable  TINYINT(1) NOT NULL DEFAULT 1,
  sort_order  SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  active      TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB;

CREATE TABLE amenity_options (                                      -- choices for 'choice' amenities
  id            SMALLINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  amenity_id    SMALLINT UNSIGNED NOT NULL,
  value         VARCHAR(40) NOT NULL,
  label         VARCHAR(80) NOT NULL,
  needs_payer   TINYINT(1) NOT NULL DEFAULT 0,                      -- public provider: ask who pays
  UNIQUE KEY uq_opt (amenity_id, value),
  CONSTRAINT fk_ao_amenity FOREIGN KEY (amenity_id) REFERENCES amenity_definitions(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE building_amenity_answers (                             -- every amenity must be answered; no defaults
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  building_id   BIGINT UNSIGNED NOT NULL,
  amenity_id    SMALLINT UNSIGNED NOT NULL,
  answer        ENUM('yes','no') NULL,                              -- for yes_no
  choice_value  VARCHAR(40) NULL,                                   -- for choice
  payer         ENUM('tenant','owner','split','included_in_rent') NULL,
  note          VARCHAR(120) NULL,                                  -- max 10 words, enforced by the server
  UNIQUE KEY uq_baa (building_id, amenity_id),
  CONSTRAINT fk_baa_building FOREIGN KEY (building_id) REFERENCES buildings(id) ON DELETE CASCADE,
  CONSTRAINT fk_baa_amenity FOREIGN KEY (amenity_id) REFERENCES amenity_definitions(id)
) ENGINE=InnoDB;

CREATE TABLE room_amenity_answers (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  room_id       BIGINT UNSIGNED NOT NULL,
  amenity_id    SMALLINT UNSIGNED NOT NULL,
  answer        ENUM('yes','no') NULL,
  choice_value  VARCHAR(40) NULL,
  payer         ENUM('tenant','owner','split','included_in_rent') NULL,
  note          VARCHAR(120) NULL,
  UNIQUE KEY uq_raa (room_id, amenity_id),
  CONSTRAINT fk_raa_room FOREIGN KEY (room_id) REFERENCES rooms(id) ON DELETE CASCADE,
  CONSTRAINT fk_raa_amenity FOREIGN KEY (amenity_id) REFERENCES amenity_definitions(id)
) ENGINE=InnoDB;

CREATE TABLE custom_amenities (                                     -- owner-added, shown only, not searchable
  id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  building_id  BIGINT UNSIGNED NULL,
  room_id      BIGINT UNSIGNED NULL,
  label        VARCHAR(60) NOT NULL,
  answer       ENUM('yes','no') NOT NULL,
  note         VARCHAR(120) NULL,
  KEY idx_ca_b (building_id),
  KEY idx_ca_r (room_id),
  CONSTRAINT fk_ca_building FOREIGN KEY (building_id) REFERENCES buildings(id) ON DELETE CASCADE,
  CONSTRAINT fk_ca_room FOREIGN KEY (room_id) REFERENCES rooms(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ============================================================
-- 5. AGENTS: AVAILABILITY, APPLICATIONS, REVOCATION
-- ============================================================

CREATE TABLE agent_availability (                                   -- building_id NULL = applies to all buildings
  id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  agent_id     BIGINT UNSIGNED NOT NULL,
  building_id  BIGINT UNSIGNED NULL,
  weekday      TINYINT UNSIGNED NOT NULL,                           -- 1 = Monday ... 7 = Sunday
  UNIQUE KEY uq_av (agent_id, building_id, weekday),
  CONSTRAINT chk_weekday CHECK (weekday BETWEEN 1 AND 7),
  CONSTRAINT fk_av_agent FOREIGN KEY (agent_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_av_building FOREIGN KEY (building_id) REFERENCES buildings(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE agency_applications (                                  -- one row per application; history is kept
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  agent_id      BIGINT UNSIGNED NOT NULL,
  building_id   BIGINT UNSIGNED NOT NULL,
  status        ENUM('pending','approved','declined','revoked') NOT NULL DEFAULT 'pending',
  applied_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  decided_at    DATETIME NULL,
  revoked_at    DATETIME NULL,                                      -- reviews lock at this moment
  KEY idx_aa_agent (agent_id, status),
  KEY idx_aa_building (building_id, status),
  CONSTRAINT fk_aa_agent FOREIGN KEY (agent_id) REFERENCES users(id),
  CONSTRAINT fk_aa_building FOREIGN KEY (building_id) REFERENCES buildings(id)
) ENGINE=InnoDB;

CREATE TABLE revocation_surveys (                                   -- one from the owner, one from the agent
  id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  application_id  BIGINT UNSIGNED NOT NULL,
  respondent_id   BIGINT UNSIGNED NOT NULL,
  respondent_role ENUM('owner','agent') NOT NULL,
  reason_code     VARCHAR(40) NULL,
  note            VARCHAR(500) NULL,
  deadline_at     DATETIME NULL,
  submitted_at    DATETIME NULL,
  UNIQUE KEY uq_survey (application_id, respondent_role),
  CONSTRAINT fk_rs_app FOREIGN KEY (application_id) REFERENCES agency_applications(id),
  CONSTRAINT fk_rs_user FOREIGN KEY (respondent_id) REFERENCES users(id)
) ENGINE=InnoDB;

-- ============================================================
-- 6. VIEWINGS
-- ============================================================

CREATE TABLE viewings (
  id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id             BIGINT UNSIGNED NOT NULL,
  agent_id            BIGINT UNSIGNED NOT NULL,
  building_id         BIGINT UNSIGNED NOT NULL,
  room_id             BIGINT UNSIGNED NULL,
  requested_date      DATE NOT NULL,
  time_slot           ENUM('morning','afternoon','evening') NULL,
  status              ENUM('pending','accepted','rejected','date_change_requested',
                           'cancelled','expired','completed') NOT NULL DEFAULT 'pending',
  round_count         TINYINT UNSIGNED NOT NULL DEFAULT 1,           -- cap new-date rounds (3)
  request_expires_at  DATETIME NULL,                                 -- unanswered request expiry
  timer_ends_at       DATETIME NULL,                                 -- 2-day timer after "ask for another date"
  reject_reason       VARCHAR(60) NULL,
  completed_by        ENUM('agent','user') NULL,
  completed_at        DATETIME NULL,
  agent_disputes      TINYINT(1) NOT NULL DEFAULT 0,                 -- user confirmed, agent disagrees: admin review
  reminder_stage      TINYINT UNSIGNED NOT NULL DEFAULT 0,
  created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_v_user (user_id, status),
  KEY idx_v_agent (agent_id, status),
  KEY idx_v_timer (status, timer_ends_at),
  CONSTRAINT fk_v_user FOREIGN KEY (user_id) REFERENCES users(id),
  CONSTRAINT fk_v_agent FOREIGN KEY (agent_id) REFERENCES users(id),
  CONSTRAINT fk_v_building FOREIGN KEY (building_id) REFERENCES buildings(id),
  CONSTRAINT fk_v_room FOREIGN KEY (room_id) REFERENCES rooms(id)
) ENGINE=InnoDB;

CREATE TABLE viewing_date_history (
  id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  viewing_id   BIGINT UNSIGNED NOT NULL,
  proposed_by  BIGINT UNSIGNED NOT NULL,
  proposed_date DATE NOT NULL,
  action       ENUM('requested','date_change_asked','new_date_picked','accepted','rejected') NOT NULL,
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_vdh (viewing_id),
  CONSTRAINT fk_vdh_viewing FOREIGN KEY (viewing_id) REFERENCES viewings(id) ON DELETE CASCADE,
  CONSTRAINT fk_vdh_user FOREIGN KEY (proposed_by) REFERENCES users(id)
) ENGINE=InnoDB;

-- ============================================================
-- 7. CHAT
-- ============================================================

CREATE TABLE chats (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  type          ENUM('viewing','agent_owner','tenant_owner') NOT NULL,
  building_id   BIGINT UNSIGNED NOT NULL,
  room_id       BIGINT UNSIGNED NULL,
  viewing_id    BIGINT UNSIGNED NULL,
  process_id    BIGINT UNSIGNED NULL,                               -- tenancy process for tenant_owner chats
  status        ENUM('open','read_only','closed') NOT NULL DEFAULT 'open',
  closed_at     DATETIME NULL,
  delete_after  DATETIME NULL,                                      -- one week after closing / viewing date
  hold_for_review TINYINT(1) NOT NULL DEFAULT 0,                    -- reported chats are kept
  warning_sent  TINYINT(1) NOT NULL DEFAULT 0,                      -- day-5 deletion warning
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_chat_delete (status, delete_after),
  CONSTRAINT fk_chat_building FOREIGN KEY (building_id) REFERENCES buildings(id),
  CONSTRAINT fk_chat_room FOREIGN KEY (room_id) REFERENCES rooms(id),
  CONSTRAINT fk_chat_viewing FOREIGN KEY (viewing_id) REFERENCES viewings(id)
) ENGINE=InnoDB;

CREATE TABLE chat_members (
  chat_id     BIGINT UNSIGNED NOT NULL,
  user_id     BIGINT UNSIGNED NOT NULL,
  muted       TINYINT(1) NOT NULL DEFAULT 0,
  last_read_message_id BIGINT UNSIGNED NULL,
  PRIMARY KEY (chat_id, user_id),
  CONSTRAINT fk_cm_chat FOREIGN KEY (chat_id) REFERENCES chats(id) ON DELETE CASCADE,
  CONSTRAINT fk_cm_user FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE messages (
  id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  chat_id     BIGINT UNSIGNED NOT NULL,
  sender_id   BIGINT UNSIGNED NULL,                                 -- NULL = system message
  type        ENUM('text','system','image') NOT NULL DEFAULT 'text',
  body        TEXT NOT NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_msg_chat (chat_id, id),
  CONSTRAINT fk_msg_chat FOREIGN KEY (chat_id) REFERENCES chats(id) ON DELETE CASCADE,
  CONSTRAINT fk_msg_sender FOREIGN KEY (sender_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE user_blocks (
  blocker_id  BIGINT UNSIGNED NOT NULL,
  blocked_id  BIGINT UNSIGNED NOT NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (blocker_id, blocked_id),
  CONSTRAINT fk_ub_blocker FOREIGN KEY (blocker_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_ub_blocked FOREIGN KEY (blocked_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ============================================================
-- 8. REVIEWS (users and approved agents; owners cannot review)
-- ============================================================

CREATE TABLE reviews (                                              -- one rating per reviewer per target
  id             BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  reviewer_id    BIGINT UNSIGNED NOT NULL,
  reviewer_role  ENUM('user','agent') NOT NULL,                     -- drives the separate User / Agent scores
  target_type    ENUM('agent','building','room') NOT NULL,
  target_id      BIGINT UNSIGNED NOT NULL,
  rating         TINYINT UNSIGNED NOT NULL,
  comment        VARCHAR(1000) NULL,
  is_edited      TINYINT(1) NOT NULL DEFAULT 0,
  edited_at      DATETIME NULL,
  is_locked      TINYINT(1) NOT NULL DEFAULT 0,                     -- locked when the agent is revoked
  locked_at      DATETIME NULL,
  viewing_id     BIGINT UNSIGNED NULL,                              -- gate: completed viewing
  created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_review (reviewer_id, target_type, target_id),
  KEY idx_rev_target (target_type, target_id, reviewer_role),
  CONSTRAINT chk_rating CHECK (rating BETWEEN 1 AND 5),
  CONSTRAINT fk_rev_user FOREIGN KEY (reviewer_id) REFERENCES users(id),
  CONSTRAINT fk_rev_viewing FOREIGN KEY (viewing_id) REFERENCES viewings(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE review_comments (                                      -- extra comments, e.g. reapproved agents (no new rating)
  id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  review_id    BIGINT UNSIGNED NOT NULL,
  author_id    BIGINT UNSIGNED NOT NULL,
  body         VARCHAR(1000) NOT NULL,
  is_edited    TINYINT(1) NOT NULL DEFAULT 0,
  edited_at    DATETIME NULL,
  after_reapproval TINYINT(1) NOT NULL DEFAULT 0,
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_rc_review FOREIGN KEY (review_id) REFERENCES reviews(id) ON DELETE CASCADE,
  CONSTRAINT fk_rc_author FOREIGN KEY (author_id) REFERENCES users(id)
) ENGINE=InnoDB;

-- ============================================================
-- 9. TENANT PROCESS, PAYMENTS, TENANCIES
-- ============================================================

CREATE TABLE app_payment_details (                                  -- single row; only the root admin edits it
  id              TINYINT UNSIGNED PRIMARY KEY DEFAULT 1,
  bank_name       VARCHAR(80) NULL,
  account_name    VARCHAR(120) NULL,
  account_number  VARCHAR(40) NULL,
  momo_network    VARCHAR(30) NULL,
  momo_number     VARCHAR(16) NULL,
  momo_name       VARCHAR(120) NULL,
  updated_by      BIGINT UNSIGNED NULL,
  updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT chk_single_row CHECK (id = 1),
  CONSTRAINT fk_apd_user FOREIGN KEY (updated_by) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE app_payment_details_history (
  id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  changed_by  BIGINT UNSIGNED NOT NULL,
  old_values  JSON NULL,
  new_values  JSON NOT NULL,
  changed_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_apdh_user FOREIGN KEY (changed_by) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE owner_payout_details (                                 -- revealed only during a tenant process
  owner_id        BIGINT UNSIGNED PRIMARY KEY,
  momo_network    VARCHAR(30) NULL,
  momo_number     VARCHAR(16) NULL,
  momo_name       VARCHAR(120) NULL,
  bank_name       VARCHAR(80) NULL,
  account_name    VARCHAR(120) NULL,
  account_number  VARCHAR(40) NULL,
  updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_opd_owner FOREIGN KEY (owner_id) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE tenancy_processes (
  id                BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id           BIGINT UNSIGNED NOT NULL,
  building_id       BIGINT UNSIGNED NOT NULL,
  room_id           BIGINT UNSIGNED NOT NULL,                       -- current room (can change at the room check)
  viewing_id        BIGINT UNSIGNED NULL,                           -- NULL for renewals
  agent_id          BIGINT UNSIGNED NULL,
  duration_months   TINYINT UNSIGNED NULL,
  status            ENUM('started','agreement_read','method_chosen','proof_uploaded',
                         'verified','expired','cancelled') NOT NULL DEFAULT 'started',
  payment_method    ENUM('app','third_party') NULL,
  started_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  expires_at        DATETIME NOT NULL,                              -- started_at + 5 days; stops mattering once proof is uploaded
  agreement_id      BIGINT UNSIGNED NULL,
  agreement_opened_at DATETIME NULL,                                -- user opened/downloaded the PDF
  agreement_read_at DATETIME NULL,
  room_confirmed_at DATETIME NULL,                                  -- 'same room?' step done
  renewal_of_tenancy_id BIGINT UNSIGNED NULL,
  reminder_stage    TINYINT UNSIGNED NOT NULL DEFAULT 0,            -- day-1 / day-3 / last-24h reminders
  proof_uploaded_at DATETIME NULL,
  verified_at       DATETIME NULL,
  ended_reason      VARCHAR(80) NULL,
  KEY idx_tp_status (status, expires_at),
  KEY idx_tp_room (room_id, status),
  CONSTRAINT fk_tp_user FOREIGN KEY (user_id) REFERENCES users(id),
  CONSTRAINT fk_tp_building FOREIGN KEY (building_id) REFERENCES buildings(id),
  CONSTRAINT fk_tp_room FOREIGN KEY (room_id) REFERENCES rooms(id),
  CONSTRAINT fk_tp_viewing FOREIGN KEY (viewing_id) REFERENCES viewings(id),
  CONSTRAINT fk_tp_agent FOREIGN KEY (agent_id) REFERENCES users(id),
  CONSTRAINT fk_tp_agreement FOREIGN KEY (agreement_id) REFERENCES agreements(id)
) ENGINE=InnoDB;

ALTER TABLE chats
  ADD CONSTRAINT fk_chat_process FOREIGN KEY (process_id) REFERENCES tenancy_processes(id);

CREATE TABLE tenancy_process_room_changes (                         -- room switches at the room check
  id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  process_id  BIGINT UNSIGNED NOT NULL,
  from_room_id BIGINT UNSIGNED NOT NULL,
  to_room_id   BIGINT UNSIGNED NOT NULL,
  changed_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_tprc_process FOREIGN KEY (process_id) REFERENCES tenancy_processes(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE payments (
  id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  process_id          BIGINT UNSIGNED NOT NULL,
  method              ENUM('app','third_party') NOT NULL,
  amount              DECIMAL(12,2) NOT NULL,
  fee_percent         DECIMAL(5,2) NOT NULL DEFAULT 5.00,           -- 5% on app payments, 0 on third party
  fee_amount          DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  owner_amount        DECIMAL(12,2) NOT NULL,
  fee_payer           ENUM('owner','tenant') NULL,                  -- open question: who pays the 5%
  reference_username  VARCHAR(20) NULL,                             -- username in the payment reference (app route)
  transaction_id      VARCHAR(80) NULL,
  proof_path          VARCHAR(255) NULL,
  status              ENUM('awaiting_proof','proof_uploaded','verified','rejected',
                           'disputed','paid_to_owner','owner_confirmed','refunded') NOT NULL DEFAULT 'awaiting_proof',
  verifier_id         BIGINT UNSIGNED NULL,                         -- seeded admin (app) or owner (third party)
  verifier_role       ENUM('admin','owner') NULL,
  verify_by           DATETIME NULL,                                -- proof upload + 48h for owners
  verified_at         DATETIME NULL,
  rejection_reason    VARCHAR(200) NULL,
  resubmit_by         DATETIME NULL,                                -- 24h window after rejection
  paid_to_owner_at    DATETIME NULL,
  owner_received_at   DATETIME NULL,
  reminder_stage      TINYINT UNSIGNED NOT NULL DEFAULT 0,
  created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_txn (transaction_id),                               -- rejects reused receipts
  KEY idx_pay_status (status, verify_by),
  CONSTRAINT fk_pay_process FOREIGN KEY (process_id) REFERENCES tenancy_processes(id),
  CONSTRAINT fk_pay_verifier FOREIGN KEY (verifier_id) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE payment_disputes (
  id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  payment_id   BIGINT UNSIGNED NOT NULL,
  opened_by    BIGINT UNSIGNED NOT NULL,
  reason       VARCHAR(500) NOT NULL,
  evidence_path VARCHAR(255) NULL,
  status       ENUM('open','resolved') NOT NULL DEFAULT 'open',
  handled_by   BIGINT UNSIGNED NULL,                                -- seeded admin only (money)
  outcome      VARCHAR(500) NULL,
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  resolved_at  DATETIME NULL,
  CONSTRAINT fk_pd_payment FOREIGN KEY (payment_id) REFERENCES payments(id),
  CONSTRAINT fk_pd_opened FOREIGN KEY (opened_by) REFERENCES users(id),
  CONSTRAINT fk_pd_handler FOREIGN KEY (handled_by) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE payment_detail_reveals (                               -- log of every time owner details were shown
  id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  process_id  BIGINT UNSIGNED NOT NULL,
  user_id     BIGINT UNSIGNED NOT NULL,
  owner_id    BIGINT UNSIGNED NOT NULL,
  kind        ENUM('owner_details','app_details') NOT NULL,
  revealed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_pdr_process FOREIGN KEY (process_id) REFERENCES tenancy_processes(id),
  CONSTRAINT fk_pdr_user FOREIGN KEY (user_id) REFERENCES users(id),
  CONSTRAINT fk_pdr_owner FOREIGN KEY (owner_id) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE tenancies (                                            -- created when payment is verified
  id                    BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  process_id            BIGINT UNSIGNED NOT NULL UNIQUE,
  user_id               BIGINT UNSIGNED NOT NULL,                   -- the tenant
  owner_id              BIGINT UNSIGNED NOT NULL,
  building_id           BIGINT UNSIGNED NOT NULL,
  room_id               BIGINT UNSIGNED NOT NULL,
  -- terms locked at payment (copies, so later owner edits do not affect this tenant)
  rent_monthly          DECIMAL(12,2) NOT NULL,
  duration_months       TINYINT UNSIGNED NOT NULL,
  termination_label     VARCHAR(120) NOT NULL,
  termination_basis     VARCHAR(30) NOT NULL,
  termination_percent   DECIMAL(5,2) NULL,
  agreement_id          BIGINT UNSIGNED NULL,
  agreement_version     INT UNSIGNED NULL,
  agreement_path        VARCHAR(255) NULL,
  -- move-in and lease dates
  move_in_deadline      DATE NULL,                                  -- window to pick a move-in date
  move_in_date          DATE NULL,
  end_date              DATE NULL,                                  -- move_in_date + duration
  signed_at             DATETIME NULL,
  witness_name          VARCHAR(100) NULL,
  status                ENUM('reserved','active','ended','terminated') NOT NULL DEFAULT 'reserved',
  renewal_answer        ENUM('yes','no','not_sure') NULL,
  renewal_answered_at   DATETIME NULL,
  last_renewal_notice_at DATETIME NULL,
  terminate_requested_at DATETIME NULL,
  created_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_t_user (user_id, status),
  KEY idx_t_owner (owner_id, status),
  KEY idx_t_end (status, end_date),
  CONSTRAINT fk_t_process FOREIGN KEY (process_id) REFERENCES tenancy_processes(id),
  CONSTRAINT fk_t_user FOREIGN KEY (user_id) REFERENCES users(id),
  CONSTRAINT fk_t_owner FOREIGN KEY (owner_id) REFERENCES users(id),
  CONSTRAINT fk_t_building FOREIGN KEY (building_id) REFERENCES buildings(id),
  CONSTRAINT fk_t_room FOREIGN KEY (room_id) REFERENCES rooms(id),
  CONSTRAINT fk_t_agreement FOREIGN KEY (agreement_id) REFERENCES agreements(id)
) ENGINE=InnoDB;

CREATE TABLE renewal_prompts (                                      -- which reminders were already sent
  id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenancy_id   BIGINT UNSIGNED NOT NULL,
  kind         ENUM('in_app','push','pinned') NOT NULL,
  sent_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_rp_tenancy (tenancy_id),
  CONSTRAINT fk_rpr_tenancy FOREIGN KEY (tenancy_id) REFERENCES tenancies(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ============================================================
-- 10. TENANT PROBLEM REPORTS
-- ============================================================

CREATE TABLE tenant_reports (
  id                BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenancy_id        BIGINT UNSIGNED NOT NULL,
  category          ENUM('water','electricity','amenity','room','other') NOT NULL,
  amenity_id        SMALLINT UNSIGNED NULL,
  description       VARCHAR(1000) NOT NULL,
  status            ENUM('sent','seen','in_progress','resolved','reopened') NOT NULL DEFAULT 'sent',
  escalated_to_admin TINYINT(1) NOT NULL DEFAULT 0,
  escalated_at      DATETIME NULL,
  admin_handler_id  BIGINT UNSIGNED NULL,
  admin_outcome     VARCHAR(500) NULL,
  created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  resolved_at       DATETIME NULL,
  KEY idx_tr_tenancy (tenancy_id, status),
  CONSTRAINT fk_tr_tenancy FOREIGN KEY (tenancy_id) REFERENCES tenancies(id),
  CONSTRAINT fk_tr_amenity FOREIGN KEY (amenity_id) REFERENCES amenity_definitions(id),
  CONSTRAINT fk_tr_admin FOREIGN KEY (admin_handler_id) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE tenant_report_photos (
  id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  report_id   BIGINT UNSIGNED NOT NULL,
  path        VARCHAR(255) NOT NULL,
  CONSTRAINT fk_trp_report FOREIGN KEY (report_id) REFERENCES tenant_reports(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE tenant_report_updates (
  id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  report_id   BIGINT UNSIGNED NOT NULL,
  author_id   BIGINT UNSIGNED NOT NULL,
  status      VARCHAR(20) NULL,
  note        VARCHAR(500) NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_tru_report FOREIGN KEY (report_id) REFERENCES tenant_reports(id) ON DELETE CASCADE,
  CONSTRAINT fk_tru_author FOREIGN KEY (author_id) REFERENCES users(id)
) ENGINE=InnoDB;

-- ============================================================
-- 11. MODERATION, ADMIN, NOTIFICATIONS, SETTINGS
-- ============================================================

CREATE TABLE reports (                                              -- user reports (chats, reviews, photos, people)
  id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  reporter_id  BIGINT UNSIGNED NOT NULL,
  target_type  ENUM('user','building','review','comment','chat','message','photo') NOT NULL,
  target_id    BIGINT UNSIGNED NOT NULL,
  reason       VARCHAR(500) NOT NULL,
  status       ENUM('open','reviewing','resolved','dismissed') NOT NULL DEFAULT 'open',
  handled_by   BIGINT UNSIGNED NULL,
  outcome      VARCHAR(500) NULL,
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  resolved_at  DATETIME NULL,
  KEY idx_rep_status (status),
  CONSTRAINT fk_rep_reporter FOREIGN KEY (reporter_id) REFERENCES users(id),
  CONSTRAINT fk_rep_handler FOREIGN KEY (handled_by) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE bans (
  id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  target_type ENUM('user','building') NOT NULL,
  target_id   BIGINT UNSIGNED NOT NULL,
  reason      VARCHAR(500) NOT NULL,
  banned_by   BIGINT UNSIGNED NOT NULL,
  banned_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  lifted_by   BIGINT UNSIGNED NULL,
  lifted_at   DATETIME NULL,
  KEY idx_ban_target (target_type, target_id),
  CONSTRAINT fk_ban_by FOREIGN KEY (banned_by) REFERENCES users(id),
  CONSTRAINT fk_ban_lift FOREIGN KEY (lifted_by) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE ban_appeals (
  id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  ban_id      BIGINT UNSIGNED NOT NULL,
  message     VARCHAR(1000) NOT NULL,
  status      ENUM('open','upheld','overturned') NOT NULL DEFAULT 'open',
  reviewed_by BIGINT UNSIGNED NULL,                                 -- a different admin than the one who banned
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  reviewed_at DATETIME NULL,
  CONSTRAINT fk_ba_ban FOREIGN KEY (ban_id) REFERENCES bans(id),
  CONSTRAINT fk_ba_rev FOREIGN KEY (reviewed_by) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE audit_log (                                            -- append-only (triggers block edits and deletes)
  id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  actor_id     BIGINT UNSIGNED NULL,
  actor_tier   ENUM('root','seeded','promoted','system') NOT NULL,
  action       VARCHAR(60) NOT NULL,
  target_type  VARCHAR(40) NULL,
  target_id    BIGINT UNSIGNED NULL,
  reason       VARCHAR(500) NULL,
  old_values   JSON NULL,
  new_values   JSON NULL,
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_al_actor (actor_id, created_at),
  KEY idx_al_target (target_type, target_id)
) ENGINE=InnoDB;

CREATE TABLE notifications (
  id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id     BIGINT UNSIGNED NOT NULL,
  type        VARCHAR(40) NOT NULL,                                 -- e.g. viewing_request, payment_verified
  title       VARCHAR(120) NOT NULL,
  body        VARCHAR(300) NULL,
  data        JSON NULL,
  read_at     DATETIME NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_n_user (user_id, read_at, created_at),
  CONSTRAINT fk_n_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE settings (
  `key`       VARCHAR(60) PRIMARY KEY,
  value       VARCHAR(255) NOT NULL,
  description VARCHAR(200) NULL,
  updated_by  BIGINT UNSIGNED NULL,
  updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

SET FOREIGN_KEY_CHECKS = 1;

-- ============================================================
-- 12. TRIGGERS (limits the database can enforce itself)
-- ============================================================

DELIMITER $$

CREATE TRIGGER trg_building_photos_limit BEFORE INSERT ON building_photos
FOR EACH ROW
BEGIN
  IF (SELECT COUNT(*) FROM building_photos WHERE building_id = NEW.building_id) >= 7 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'A building can have at most 7 photos';
  END IF;
END$$

CREATE TRIGGER trg_room_photos_limit BEFORE INSERT ON room_photos
FOR EACH ROW
BEGIN
  IF (SELECT COUNT(*) FROM room_photos WHERE room_id = NEW.room_id) >= 10 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'A room can have at most 10 photos';
  END IF;
END$$

CREATE TRIGGER trg_custom_amenity_limit BEFORE INSERT ON custom_amenities
FOR EACH ROW
BEGIN
  IF NEW.building_id IS NOT NULL AND
     (SELECT COUNT(*) FROM custom_amenities WHERE building_id = NEW.building_id) >= 5 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'At most 5 custom amenities per building';
  END IF;
  IF NEW.room_id IS NOT NULL AND
     (SELECT COUNT(*) FROM custom_amenities WHERE room_id = NEW.room_id) >= 5 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'At most 5 custom amenities per room';
  END IF;
END$$

CREATE TRIGGER trg_audit_no_update BEFORE UPDATE ON audit_log
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'The audit log cannot be edited';
END$$

CREATE TRIGGER trg_audit_no_delete BEFORE DELETE ON audit_log
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'The audit log cannot be deleted from';
END$$

DELIMITER ;

-- ============================================================
-- 13. VIEWS (ratings: separate User and Agent scores, with counts)
-- ============================================================

CREATE VIEW v_ratings AS
SELECT target_type, target_id, reviewer_role,
       ROUND(AVG(rating), 2) AS average_rating,
       COUNT(*)              AS review_count
FROM reviews
GROUP BY target_type, target_id, reviewer_role;

CREATE VIEW v_available_rooms AS                                    -- rooms users can see in search and the slideshow
SELECT r.*
FROM rooms r
JOIN buildings b ON b.id = r.building_id
WHERE r.status = 'available' AND b.status = 'live';

-- ============================================================
-- 14. REFERENCE DATA
-- ============================================================

INSERT INTO settings (`key`, value, description) VALUES
 ('platform_fee_percent',        '5.00', 'Fee on tenant payments made through the app'),
 ('tenant_process_days',         '5',    'Days to upload payment proof after starting the tenant process'),
 ('owner_verify_hours',          '48',   'Hours the owner has to verify a third-party payment after proof upload'),
 ('proof_resubmit_hours',        '24',   'Hours to resubmit proof after a rejection'),
 ('viewing_date_change_days',    '2',    'Days to pick a new date after the agent asks for another date'),
 ('viewing_request_expiry_hours','48',   'Hours before an unanswered viewing request expires'),
 ('viewing_max_date_rounds',     '3',    'Maximum new-date rounds per viewing'),
 ('chat_delete_days',            '7',    'Days after a chat closes before it is deleted'),
 ('renewal_prompt_start_days',   '60',   'Start asking tenants about renewal this many days before the end'),
 ('renewal_prompt_end_days',     '45',   'Stop the in-app question window at this many days before the end'),
 ('move_in_window_days',         '30',   'Days after verified payment to choose a move-in date (to confirm)'),
 ('max_building_photos',         '7',    'Photo limit per building'),
 ('max_room_photos',             '10',   'Photo limit per room'),
 ('verification_code_minutes',   '15',   'Email code expiry'),
 ('sms_code_minutes',            '10',   'SMS code expiry'),
 ('sms_daily_cap_per_number',    '3',    'Maximum SMS codes per number per day');

INSERT INTO app_payment_details (id) VALUES (1);                    -- the root admin fills this in

INSERT INTO reserved_usernames (username) VALUES
 ('admin'),('administrator'),('support'),('help'),('root'),('system'),('moderator'),('staff');

INSERT INTO termination_policies (label, basis, percent, months) VALUES
 ('10% of remaining duration deducted', 'remaining_duration', 10.00, NULL),
 ('15% of remaining duration deducted', 'remaining_duration', 15.00, NULL),
 ('25% of remaining duration deducted', 'remaining_duration', 25.00, NULL),
 ('One month rent forfeited',           'forfeit_months',     NULL,  1),
 ('No refund on early termination',     'no_refund',          NULL,  NULL);

INSERT INTO regions (name) VALUES
 ('Greater Accra'),('Ashanti'),('Western'),('Western North'),('Central'),('Eastern'),
 ('Volta'),('Oti'),('Northern'),('Savannah'),('North East'),('Upper East'),
 ('Upper West'),('Bono'),('Bono East'),('Ahafo');

INSERT INTO cities (region_id, name) VALUES
 ((SELECT id FROM regions WHERE name='Greater Accra'),'Accra'),
 ((SELECT id FROM regions WHERE name='Greater Accra'),'Tema'),
 ((SELECT id FROM regions WHERE name='Greater Accra'),'Madina'),
 ((SELECT id FROM regions WHERE name='Greater Accra'),'Adenta'),
 ((SELECT id FROM regions WHERE name='Greater Accra'),'Teshie'),
 ((SELECT id FROM regions WHERE name='Greater Accra'),'Nungua'),
 ((SELECT id FROM regions WHERE name='Greater Accra'),'Ashaiman'),
 ((SELECT id FROM regions WHERE name='Greater Accra'),'Dome'),
 ((SELECT id FROM regions WHERE name='Ashanti'),'Kumasi'),
 ((SELECT id FROM regions WHERE name='Ashanti'),'Obuasi'),
 ((SELECT id FROM regions WHERE name='Ashanti'),'Ejisu'),
 ((SELECT id FROM regions WHERE name='Ashanti'),'Mampong'),
 ((SELECT id FROM regions WHERE name='Ashanti'),'Konongo'),
 ((SELECT id FROM regions WHERE name='Ashanti'),'Bekwai'),
 ((SELECT id FROM regions WHERE name='Western'),'Sekondi-Takoradi'),
 ((SELECT id FROM regions WHERE name='Western'),'Tarkwa'),
 ((SELECT id FROM regions WHERE name='Western'),'Axim'),
 ((SELECT id FROM regions WHERE name='Western'),'Prestea'),
 ((SELECT id FROM regions WHERE name='Western North'),'Sefwi Wiawso'),
 ((SELECT id FROM regions WHERE name='Western North'),'Bibiani'),
 ((SELECT id FROM regions WHERE name='Central'),'Cape Coast'),
 ((SELECT id FROM regions WHERE name='Central'),'Kasoa'),
 ((SELECT id FROM regions WHERE name='Central'),'Winneba'),
 ((SELECT id FROM regions WHERE name='Central'),'Swedru'),
 ((SELECT id FROM regions WHERE name='Central'),'Elmina'),
 ((SELECT id FROM regions WHERE name='Eastern'),'Koforidua'),
 ((SELECT id FROM regions WHERE name='Eastern'),'Nkawkaw'),
 ((SELECT id FROM regions WHERE name='Eastern'),'Suhum'),
 ((SELECT id FROM regions WHERE name='Eastern'),'Nsawam'),
 ((SELECT id FROM regions WHERE name='Eastern'),'Akim Oda'),
 ((SELECT id FROM regions WHERE name='Volta'),'Ho'),
 ((SELECT id FROM regions WHERE name='Volta'),'Hohoe'),
 ((SELECT id FROM regions WHERE name='Volta'),'Aflao'),
 ((SELECT id FROM regions WHERE name='Volta'),'Keta'),
 ((SELECT id FROM regions WHERE name='Volta'),'Kpando'),
 ((SELECT id FROM regions WHERE name='Oti'),'Dambai'),
 ((SELECT id FROM regions WHERE name='Oti'),'Nkwanta'),
 ((SELECT id FROM regions WHERE name='Oti'),'Kadjebi'),
 ((SELECT id FROM regions WHERE name='Northern'),'Tamale'),
 ((SELECT id FROM regions WHERE name='Northern'),'Yendi'),
 ((SELECT id FROM regions WHERE name='Northern'),'Savelugu'),
 ((SELECT id FROM regions WHERE name='Northern'),'Bimbilla'),
 ((SELECT id FROM regions WHERE name='Savannah'),'Damongo'),
 ((SELECT id FROM regions WHERE name='Savannah'),'Bole'),
 ((SELECT id FROM regions WHERE name='North East'),'Nalerigu'),
 ((SELECT id FROM regions WHERE name='North East'),'Walewale'),
 ((SELECT id FROM regions WHERE name='Upper East'),'Bolgatanga'),
 ((SELECT id FROM regions WHERE name='Upper East'),'Bawku'),
 ((SELECT id FROM regions WHERE name='Upper East'),'Navrongo'),
 ((SELECT id FROM regions WHERE name='Upper West'),'Wa'),
 ((SELECT id FROM regions WHERE name='Upper West'),'Tumu'),
 ((SELECT id FROM regions WHERE name='Upper West'),'Lawra'),
 ((SELECT id FROM regions WHERE name='Bono'),'Sunyani'),
 ((SELECT id FROM regions WHERE name='Bono'),'Berekum'),
 ((SELECT id FROM regions WHERE name='Bono'),'Dormaa Ahenkro'),
 ((SELECT id FROM regions WHERE name='Bono East'),'Techiman'),
 ((SELECT id FROM regions WHERE name='Bono East'),'Kintampo'),
 ((SELECT id FROM regions WHERE name='Bono East'),'Atebubu'),
 ((SELECT id FROM regions WHERE name='Ahafo'),'Goaso'),
 ((SELECT id FROM regions WHERE name='Ahafo'),'Bechem');

-- A few starter areas; admin adds more and approves owner suggestions
INSERT INTO areas (city_id, name) VALUES
 ((SELECT id FROM cities WHERE name='Accra'),'East Legon'),
 ((SELECT id FROM cities WHERE name='Accra'),'Osu'),
 ((SELECT id FROM cities WHERE name='Accra'),'Labone'),
 ((SELECT id FROM cities WHERE name='Accra'),'Cantonments'),
 ((SELECT id FROM cities WHERE name='Accra'),'Dansoman'),
 ((SELECT id FROM cities WHERE name='Accra'),'Achimota'),
 ((SELECT id FROM cities WHERE name='Kumasi'),'Ahodwo'),
 ((SELECT id FROM cities WHERE name='Kumasi'),'Nhyiaeso'),
 ((SELECT id FROM cities WHERE name='Kumasi'),'Ayigya'),
 ((SELECT id FROM cities WHERE name='Kumasi'),'Bantama');

-- Building amenities
INSERT INTO amenity_definitions (code, scope, label, kind, searchable, sort_order) VALUES
 ('water_supply',       'building','Water supply',        'choice', 1, 1),
 ('electricity',        'building','Electricity',         'choice', 1, 2),
 ('fenced_wall',        'building','Fenced wall',         'yes_no', 1, 3),
 ('security_guard',     'building','Security guard',      'yes_no', 1, 4),
 ('cctv',               'building','CCTV',                'yes_no', 1, 5),
 ('gate_intercom',      'building','Gate / intercom',     'yes_no', 1, 6),
 ('parking',            'building','Parking',             'yes_no', 1, 7),
 ('generator',          'building','Generator',           'yes_no', 1, 8),
 ('solar',              'building','Solar power',         'yes_no', 1, 9),
 ('water_storage_tank', 'building','Water storage tank',  'yes_no', 1, 10),
 ('drainage',           'building','Drainage',            'yes_no', 1, 11),
 ('paved_road_access',  'building','Paved road access',   'yes_no', 1, 12),
 ('street_lighting',    'building','Street lighting',     'yes_no', 1, 13),
 ('waste_collection',   'building','Waste collection',    'yes_no', 1, 14),
 ('elevator',           'building','Elevator',            'yes_no', 1, 15);

-- Room amenities
INSERT INTO amenity_definitions (code, scope, label, kind, searchable, sort_order) VALUES
 ('meter_type',         'room','Electricity meter',   'choice', 1, 1),
 ('floor_type',         'room','Floor',               'choice', 1, 2),
 ('bathroom_type',      'room','Bathroom',            'choice', 1, 3),
 ('kitchen_type',       'room','Kitchen',             'choice', 1, 4),
 ('air_conditioning',   'room','Air conditioning',    'yes_no', 1, 5),
 ('ceiling_fan',        'room','Ceiling fan',         'yes_no', 1, 6),
 ('wardrobe',           'room','Wardrobe',            'yes_no', 1, 7),
 ('balcony',            'room','Balcony',             'yes_no', 1, 8),
 ('water_heater',       'room','Water heater',        'yes_no', 1, 9),
 ('furnished',          'room','Furnished',           'yes_no', 1, 10),
 ('internet_ready',     'room','Internet / fibre ready','yes_no', 1, 11),
 ('prepaid_meter',      'room','Prepaid meter',       'yes_no', 1, 12);

INSERT INTO amenity_options (amenity_id, value, label, needs_payer) VALUES
 ((SELECT id FROM amenity_definitions WHERE code='water_supply'),'borehole',   'Borehole',                 0),
 ((SELECT id FROM amenity_definitions WHERE code='water_supply'),'ghana_water','Ghana Water (public)',     1),
 ((SELECT id FROM amenity_definitions WHERE code='water_supply'),'both',       'Borehole and Ghana Water', 1),
 ((SELECT id FROM amenity_definitions WHERE code='water_supply'),'none',       'None',                     0),
 ((SELECT id FROM amenity_definitions WHERE code='electricity'),'ecg_prepaid', 'ECG prepaid',              1),
 ((SELECT id FROM amenity_definitions WHERE code='electricity'),'ecg_postpaid','ECG postpaid',             1),
 ((SELECT id FROM amenity_definitions WHERE code='electricity'),'generator',   'Generator',                0),
 ((SELECT id FROM amenity_definitions WHERE code='electricity'),'solar',       'Solar',                    0),
 ((SELECT id FROM amenity_definitions WHERE code='electricity'),'none',        'None',                     0),
 ((SELECT id FROM amenity_definitions WHERE code='meter_type'),'shared',       'Shared meter',             0),
 ((SELECT id FROM amenity_definitions WHERE code='meter_type'),'private',      'Private meter',            0),
 ((SELECT id FROM amenity_definitions WHERE code='floor_type'),'tiles',        'Tiles',                    0),
 ((SELECT id FROM amenity_definitions WHERE code='floor_type'),'terrazzo',     'Terrazzo',                 0),
 ((SELECT id FROM amenity_definitions WHERE code='floor_type'),'other',        'Other',                    0),
 ((SELECT id FROM amenity_definitions WHERE code='bathroom_type'),'shared',    'Shared bathroom',          0),
 ((SELECT id FROM amenity_definitions WHERE code='bathroom_type'),'private',   'Private bathroom',         0),
 ((SELECT id FROM amenity_definitions WHERE code='kitchen_type'),'shared',     'Shared kitchen',           0),
 ((SELECT id FROM amenity_definitions WHERE code='kitchen_type'),'private',    'Private kitchen',          0),
 ((SELECT id FROM amenity_definitions WHERE code='kitchen_type'),'none',       'No kitchen',               0);

-- ============================================================
-- 15. ADDITIONS USED BY THE APPLICATION CODE
-- ============================================================

CREATE TABLE api_tokens (                                           -- login sessions (token stored hashed)
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id       BIGINT UNSIGNED NOT NULL,
  token_hash    CHAR(64) NOT NULL,
  device        VARCHAR(100) NULL,
  expires_at    DATETIME NOT NULL,
  last_used_at  DATETIME NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_token (token_hash),
  KEY idx_tok_user (user_id),
  CONSTRAINT fk_tok_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE rate_limits (                                          -- simple fixed-window limiter
  key_name      VARCHAR(120) PRIMARY KEY,
  hits          INT UNSIGNED NOT NULL DEFAULT 0,
  window_start  DATETIME NOT NULL
) ENGINE=InnoDB;

ALTER TABLE tenancy_processes
  ADD CONSTRAINT fk_tp_renewal FOREIGN KEY (renewal_of_tenancy_id) REFERENCES tenancies(id);

INSERT INTO settings (`key`, value, description) VALUES
 ('fee_payer',              'owner', 'Who bears the 5% fee on app payments: owner (deducted from payout) or tenant (added on top)'),
 ('tenant_report_wait_days','3',     'Days an owner can leave a tenant report unanswered before the tenant may escalate to admin'),
 ('unverified_delete_days', '7',     'Delete accounts that never verified their email after this many days'),
 ('early_exit_rounding',    '2',     'Decimal places used in early-exit quotes');

ALTER TABLE viewings
  ADD COLUMN rent_decision ENUM('rent_now','not_now','not_interested') NULL AFTER completed_at,
  ADD COLUMN rent_prompt_count TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER rent_decision,
  ADD COLUMN user_answer ENUM('yes','no') NULL AFTER rent_prompt_count;
