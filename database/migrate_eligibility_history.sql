-- Eligibility history and result-view events. Existing assessments are retained.
-- Re-running this migration preserves recorded checks and views.
ALTER TABLE eligibility_assessments
  MODIFY preliminary_result ENUM('LIKELY_ELIGIBLE','NOT_ELIGIBLE','ELIGIBLE','NEEDS_GUIDANCE') NOT NULL;

CREATE TABLE IF NOT EXISTS eligibility_result_views (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  assessment_id INT NOT NULL,
  viewer_user_id INT DEFAULT NULL,
  visitor_token CHAR(64) NOT NULL,
  viewed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_elig_view_assessment (assessment_id, viewed_at),
  KEY idx_elig_view_visitor (visitor_token, viewed_at),
  KEY idx_elig_view_user (viewer_user_id, viewed_at),
  CONSTRAINT fk_elig_view_assessment FOREIGN KEY (assessment_id) REFERENCES eligibility_assessments(id) ON DELETE CASCADE,
  CONSTRAINT fk_elig_view_user FOREIGN KEY (viewer_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;
