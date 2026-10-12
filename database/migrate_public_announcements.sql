-- Additive migration: retains existing tables and records.
CREATE TABLE IF NOT EXISTS public_announcements (
  id INT AUTO_INCREMENT PRIMARY KEY,
  title VARCHAR(200) NOT NULL,
  short_desc VARCHAR(500) NOT NULL,
  category VARCHAR(80) NOT NULL,
  details TEXT NOT NULL,
  status ENUM('draft','published') NOT NULL DEFAULT 'draft',
  published_at DATETIME NOT NULL,
  expires_at DATETIME NULL,
  priority INT NOT NULL DEFAULT 0,
  image VARCHAR(255) NULL,
  attachment VARCHAR(255) NULL,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_public_announcements_visibility (status, published_at, expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Correct only the original FAQ copy; preserve customized answers.
UPDATE faqs SET answer = 'If information or a document requirement needs correction, your application status will show "For Revision" with remarks from MSWDO staff. Open My Applications or Document Checklist to review the remarks, update your information or checklist as requested, and prepare the documents for MSWDO review.' WHERE question = 'What if my application needs correction?' AND answer = 'If a document is unclear or a requirement is missing, your application status will show "For Revision" with the specific remarks from MSWDO staff. Go to My Application or Upload Documents to see what needs fixing and to re-upload the corrected file.';
