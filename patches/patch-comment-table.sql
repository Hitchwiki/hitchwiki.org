--
-- patch-comment-table.sql
--
-- T166732. Add a `comment` table and various columns (and temporary tables) to reference it.

CREATE TABLE /*_*/ IF NOT EXISTS comment (
  comment_id bigint unsigned NOT NULL PRIMARY KEY AUTO_INCREMENT,
  comment_hash INT NOT NULL,
  comment_text BLOB NOT NULL,
  comment_data BLOB,
  INDEX `comment_hash` (comment_hash)
) /*$wgDBTableOptions*/;

CREATE TABLE /*_*/ IF NOT EXISTS revision_comment_temp (
  revcomment_rev int unsigned NOT NULL,
  revcomment_comment_id bigint unsigned NOT NULL,
  PRIMARY KEY (revcomment_rev, revcomment_comment_id),
  INDEX `rev_comment_rev` (revcomment_rev)
) /*$wgDBTableOptions*/;

CREATE TABLE /*_*/ IF NOT EXISTS image_comment_temp (
  imgcomment_name varchar(255) binary NOT NULL,
  imgcomment_description_id bigint unsigned NOT NULL,
  PRIMARY KEY (imgcomment_name, imgcomment_description_id),
  INDEX `imgcomment_name` (imgcomment_name)
) /*$wgDBTableOptions*/;

ALTER TABLE /*_*/revision
  ALTER COLUMN rev_comment SET DEFAULT '';

ALTER TABLE /*_*/archive
  ALTER COLUMN ar_comment SET DEFAULT '',
  ADD COLUMN ar_comment_id bigint unsigned NOT NULL DEFAULT 0 AFTER ar_comment;

ALTER TABLE /*_*/ipblocks
  ALTER COLUMN ipb_reason SET DEFAULT '',
  ADD COLUMN ipb_reason_id bigint unsigned NOT NULL DEFAULT 0 AFTER ipb_reason;

ALTER TABLE /*_*/image
  ALTER COLUMN img_description SET DEFAULT '';

ALTER TABLE /*_*/oldimage
  ALTER COLUMN oi_description SET DEFAULT '',
  ADD COLUMN oi_description_id bigint unsigned NOT NULL DEFAULT 0 AFTER oi_description;

ALTER TABLE /*_*/filearchive
  ADD COLUMN fa_deleted_reason_id bigint unsigned NOT NULL DEFAULT 0 AFTER fa_deleted_reason,
  ALTER COLUMN fa_description SET DEFAULT '',
  ADD COLUMN fa_description_id bigint unsigned NOT NULL DEFAULT 0 AFTER fa_description;

ALTER TABLE /*_*/recentchanges
  ADD COLUMN rc_comment_id bigint unsigned NOT NULL DEFAULT 0 AFTER rc_comment;

ALTER TABLE /*_*/logging
  ADD COLUMN log_comment_id bigint unsigned NOT NULL DEFAULT 0 AFTER log_comment;

ALTER TABLE /*_*/protected_titles
  ALTER COLUMN pt_reason SET DEFAULT '',
  ADD COLUMN pt_reason_id bigint unsigned NOT NULL DEFAULT 0 AFTER pt_reason;