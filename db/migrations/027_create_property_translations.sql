-- Backs the new admin "Traductions" page: lets an admin manually enter (or
-- accept an automatic suggestion for) the French name/description of a
-- Lodgify property whenever Lodgify itself has no French translation
-- configured for it (Lodgify's API never machine-translates — it only
-- returns whatever text was typed into its own back-office per language).
-- One row per property/field/language so this can later cover other
-- languages/fields without a schema change.
CREATE TABLE IF NOT EXISTS property_translations (
  id INT AUTO_INCREMENT PRIMARY KEY,
  property_id INT NOT NULL,
  field VARCHAR(50) NOT NULL,
  language VARCHAR(5) NOT NULL,
  text_value MEDIUMTEXT NOT NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY unique_property_field_lang (property_id, field, language)
);
