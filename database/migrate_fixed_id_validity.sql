-- Fixed one-year validity for future new and renewed ID releases.
-- Existing application and QR expiry dates are preserved.
INSERT INTO system_settings (setting_key, setting_value)
VALUES ('id_validity_years', '1')
ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value);
