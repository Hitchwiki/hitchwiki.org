-- Central interlanguage-link store for the Hitchwiki language family.
-- Lives in the shared (English) database; registered via $wgSharedTables so
-- every language wiki reads it through the normal DB connection.
--
-- One row per (concept, language). The concept is keyed by the English page
-- title (DB key form, underscores), which is the single source of truth.
CREATE TABLE /*_*/page_translations (
	-- English page title (DB key) identifying the concept.
	pt_concept VARBINARY(255) NOT NULL,
	-- Language/wiki code, e.g. 'de', 'fr', matching $wgLanguageCode of each wiki.
	pt_lang VARBINARY(35) NOT NULL,
	-- Page title (DB key) in that language.
	pt_title VARBINARY(255) NOT NULL,
	PRIMARY KEY (pt_concept, pt_lang)
) /*$wgDBTableOptions*/;

-- Reverse lookup: given the wiki we are on (pt_lang) and the page (pt_title),
-- find its concept so we can fetch the sibling translations.
CREATE INDEX /*i*/pt_lang_title ON /*_*/page_translations (pt_lang, pt_title);
