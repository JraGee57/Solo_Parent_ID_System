-- Step 20: Chat Support / Help Assistant -- the assistant's suggested links, hand-off to MSWDO
-- staff, and who replied. Safe to run once.
USE virac_solo_parent;

ALTER TABLE chat_messages
  ADD COLUMN topic VARCHAR(30) DEFAULT NULL AFTER message,              -- what the assistant understood (documents, renewal, ...)
  ADD COLUMN meta JSON DEFAULT NULL AFTER topic,                         -- assistant reply: suggested in-app links and follow-up questions
  ADD COLUMN handoff TINYINT(1) NOT NULL DEFAULT 0 AFTER meta,          -- 1 = the applicant asked for MSWDO staff with this message
  ADD COLUMN staff_user_id INT DEFAULT NULL AFTER handoff,              -- the staff member who wrote a STAFF message
  ADD CONSTRAINT fk_chat_staff FOREIGN KEY (staff_user_id) REFERENCES users(id) ON DELETE SET NULL,
  ADD KEY idx_chat_handoff (handoff, created_at);

-- More FAQ answers the assistant can draw on.
INSERT INTO faqs (question, answer, sort_order)
SELECT * FROM (
  SELECT 'How do I book, reschedule or cancel an appointment?' AS q, 'Open Appointment in your dashboard and pick an open slot. You can reschedule or cancel online until a few hours before your appointment (the exact cut-off is shown on the page); after that, please contact the MSWDO office.' AS a, 8 AS s UNION ALL
  SELECT 'I lost my application slip. What do I do?', 'Open your slip from My Application and choose "Lost it? Get a new QR". The old slip stops working immediately and you can print or save the new one.', 9 UNION ALL
  SELECT 'I forgot my password.', 'On the login page choose "Forgot password" and enter your username or mobile number. A reset code will be sent to your registered mobile number.', 10 UNION ALL
  SELECT 'Can the online assistant tell me if I am eligible or approved?', 'No. The assistant can explain the process and show where your application is, but only MSWD staff decide eligibility and approval after reviewing your documents. The Eligibility Checker gives a preliminary, non-binding result.', 11
) AS new_faqs
WHERE NOT EXISTS (SELECT 1 FROM faqs f WHERE f.question = new_faqs.q);
