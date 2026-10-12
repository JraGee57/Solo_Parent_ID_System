-- e-SoloParentID category/checklist update. Run once against the existing database.
-- Existing category IDs and application records are retained as inactive history.
ALTER TABLE categories ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1;
UPDATE categories SET is_active = 0;

INSERT IGNORE INTO categories (name, description, sort_order) VALUES
('Abandonment by the spouse', 'The spouse has abandoned the applicant and child/children.', 1),
('Declaration of nullity or annulment of marriage', 'A judicial declaration of nullity or annulment, including recognition of a foreign divorce.', 2),
('Legal guardian, adoptive parent, or foster parent', 'The applicant is the childâ€™s legal guardian, adoptive parent, or foster parent.', 3),
('Unmarried father or mother who keeps and rears the child/children', 'An unmarried father or mother keeps and rears the child/children.', 4),
('Detention or imprisonment of the spouse', 'The spouse is detained or serving a sentence for at least three months.', 5),
('Child/children as a consequence of rape', 'The child/children were conceived as a consequence of rape.', 6),
('Death of the spouse', 'The applicantâ€™s spouse has died.', 7),
('Legal or de facto separation of spouse for at least six months', 'The spouses have been legally or de facto separated for at least six months.', 8),
('Spouse or family member of an OFW', 'The applicant is the spouse or family member of an overseas Filipino worker.', 9);

UPDATE categories SET is_active = 1 WHERE name IN (
  'Abandonment by the spouse',
  'Declaration of nullity or annulment of marriage',
  'Legal guardian, adoptive parent, or foster parent',
  'Unmarried father or mother who keeps and rears the child/children',
  'Detention or imprisonment of the spouse',
  'Child/children as a consequence of rape',
  'Death of the spouse',
  'Legal or de facto separation of spouse for at least six months',
  'Spouse or family member of an OFW'
);

INSERT INTO document_requirements (category_id, doc_key, label, is_required, sort_order)
SELECT c.id, r.doc_key, r.label, 1, r.sort_order
FROM categories c
JOIN (
  SELECT 'Abandonment by the spouse' category_name, 'photo1x1' doc_key, 'Two 1x1 ID pictures' label, 1 sort_order UNION ALL
  SELECT 'Abandonment by the spouse', 'birthCert', 'Photocopy of the child''s/children''s birth certificate(s)', 2 UNION ALL
  SELECT 'Abandonment by the spouse', 'marriageOrAffidavit', 'Marriage certificate or affidavit of the applicant', 3 UNION ALL
  SELECT 'Abandonment by the spouse', 'witnessAffidavit', 'Affidavit of two disinterested persons attesting to abandonment', 4 UNION ALL
  SELECT 'Abandonment by the spouse', 'abandonmentRecord', 'Police or barangay record of abandonment', 5 UNION ALL
  SELECT 'Abandonment by the spouse', 'barangayCareAffidavit', 'Barangay official''s affidavit confirming residency and parental care/support', 6 UNION ALL
  SELECT 'Abandonment by the spouse', 'soleCareAffidavit', 'Sworn affidavit declaring sole parental care/support and non-cohabitation with a partner or co-parent', 7 UNION ALL
  SELECT 'Declaration of nullity or annulment of marriage', 'birthCert', 'Child''s/children''s birth certificate(s), photocopy', 1 UNION ALL
  SELECT 'Declaration of nullity or annulment of marriage', 'marriageCert', 'Marriage certificate, certified true copy', 2 UNION ALL
  SELECT 'Declaration of nullity or annulment of marriage', 'judicialDecree', 'Judicial decree of nullity/annulment or judicial recognition of foreign divorce', 3 UNION ALL
  SELECT 'Declaration of nullity or annulment of marriage', 'soleCareAffidavit', 'Sworn affidavit declaring sole parental care/support and non-cohabitation', 4 UNION ALL
  SELECT 'Legal guardian, adoptive parent, or foster parent', 'photo1x1', 'Two 1x1 ID pictures', 1 UNION ALL
  SELECT 'Legal guardian, adoptive parent, or foster parent', 'birthCert', 'Child''s/children''s birth certificate(s)', 2 UNION ALL
  SELECT 'Legal guardian, adoptive parent, or foster parent', 'guardianshipProof', 'Applicable proof of guardianship, adoption, or foster care', 3 UNION ALL
  SELECT 'Legal guardian, adoptive parent, or foster parent', 'soleCareAffidavit', 'Sworn affidavit declaring sole parental care/support and non-cohabitation', 4 UNION ALL
  SELECT 'Legal guardian, adoptive parent, or foster parent', 'barangayCareAffidavit', 'Barangay official''s affidavit confirming residency and parental care/support', 5 UNION ALL
  SELECT 'Unmarried father or mother who keeps and rears the child/children', 'photo1x1', 'Two 1x1 ID pictures', 1 UNION ALL
  SELECT 'Unmarried father or mother who keeps and rears the child/children', 'birthCert', 'Child''s/children''s birth certificate(s), photocopy', 2 UNION ALL
  SELECT 'Unmarried father or mother who keeps and rears the child/children', 'cenomar', 'CENOMAR, certified true copy', 3 UNION ALL
  SELECT 'Unmarried father or mother who keeps and rears the child/children', 'barangayCareAffidavit', 'Barangay official''s affidavit confirming residency and parental care/support', 4 UNION ALL
  SELECT 'Unmarried father or mother who keeps and rears the child/children', 'soleCareAffidavit', 'Sworn affidavit declaring sole parental care/support and non-cohabitation', 5 UNION ALL
  SELECT 'Detention or imprisonment of the spouse', 'photo1x1', 'Two 1x1 ID pictures', 1 UNION ALL
  SELECT 'Detention or imprisonment of the spouse', 'birthCert', 'Child''s/children''s birth certificate(s)', 2 UNION ALL
  SELECT 'Detention or imprisonment of the spouse', 'marriageOrCenomar', 'Marriage certificate or Certificate of No Marriage, as applicable', 3 UNION ALL
  SELECT 'Detention or imprisonment of the spouse', 'detentionCertificate', 'Certificate of detention or certification that the spouse is serving a sentence for at least three months', 4 UNION ALL
  SELECT 'Detention or imprisonment of the spouse', 'soleCareAffidavit', 'Sworn affidavit declaring sole parental care/support and non-cohabitation', 5 UNION ALL
  SELECT 'Detention or imprisonment of the spouse', 'barangayCareAffidavit', 'Barangay official''s affidavit confirming residency and parental care/support', 6 UNION ALL
  SELECT 'Child/children as a consequence of rape', 'birthCert', 'Child''s/children''s birth certificate(s), photocopy', 1 UNION ALL
  SELECT 'Child/children as a consequence of rape', 'complaintAffidavit', 'Complaint affidavit', 2 UNION ALL
  SELECT 'Child/children as a consequence of rape', 'incidentMedicalRecord', 'Medical record relating to the incident', 3 UNION ALL
  SELECT 'Child/children as a consequence of rape', 'soleCareAffidavit', 'Sworn affidavit declaring sole parental care/support at the time of execution', 4 UNION ALL
  SELECT 'Death of the spouse', 'photo1x1', 'Two 1x1 ID pictures', 1 UNION ALL
  SELECT 'Death of the spouse', 'birthCert', 'Child''s/children''s birth certificate(s)', 2 UNION ALL
  SELECT 'Death of the spouse', 'marriageCert', 'Marriage certificate, certified true copy', 3 UNION ALL
  SELECT 'Death of the spouse', 'deathCert', 'Spouse''s death certificate, certified true copy', 4 UNION ALL
  SELECT 'Death of the spouse', 'soleCareAffidavit', 'Sworn affidavit declaring sole parental care/support and non-cohabitation', 5 UNION ALL
  SELECT 'Death of the spouse', 'barangayCareAffidavit', 'Barangay official''s affidavit confirming residency and parental care/support', 6 UNION ALL
  SELECT 'Legal or de facto separation of spouse for at least six months', 'birthCert', 'Child''s/children''s birth certificate(s)', 1 UNION ALL
  SELECT 'Legal or de facto separation of spouse for at least six months', 'marriageCert', 'Marriage certificate, certified true copy', 2 UNION ALL
  SELECT 'Legal or de facto separation of spouse for at least six months', 'separationProof', 'Judicial decree of legal separation or affidavit of two disinterested persons attesting to de facto separation', 3 UNION ALL
  SELECT 'Legal or de facto separation of spouse for at least six months', 'soleCareAffidavit', 'Sworn affidavit declaring sole parental care/support and non-cohabitation', 4 UNION ALL
  SELECT 'Spouse or family member of an OFW', 'photo1x1', 'Two 1x1 ID pictures', 1 UNION ALL
  SELECT 'Spouse or family member of an OFW', 'birthCert', 'Dependents'' birth certificate(s), photocopy', 2 UNION ALL
  SELECT 'Spouse or family member of an OFW', 'ofwRelationshipProof', 'Marriage certificate for an OFW''s spouse, or birth certificate/competent proof of relationship for an OFW''s family member', 3 UNION ALL
  SELECT 'Spouse or family member of an OFW', 'ofwEmploymentContract', 'POEA Standard Employment Contract or equivalent', 4 UNION ALL
  SELECT 'Spouse or family member of an OFW', 'ofwPassportProof', 'Photocopy of the OFW''s passport showing continuous 12 months of overseas work, or Bureau of Immigration certification', 5 UNION ALL
  SELECT 'Spouse or family member of an OFW', 'ofwIncomeProof', 'Proof of income of the OFW''s spouse or family member', 6 UNION ALL
  SELECT 'Spouse or family member of an OFW', 'barangayCareAffidavit', 'Barangay official''s affidavit confirming residency and parental care/support', 7 UNION ALL
  SELECT 'Spouse or family member of an OFW', 'soleCareAffidavit', 'Sworn affidavit declaring sole parental care/support and non-cohabitation', 8
) r ON r.category_name = c.name;

UPDATE faqs
SET answer = 'The Required Documents Checklist shows the documentary requirements for your selected category. Renewal applicants see the renewal checklist for their existing record.'
WHERE question = 'What documents do I need?';
