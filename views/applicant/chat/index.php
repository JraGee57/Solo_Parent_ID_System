<?php
/** @var array $thread @var bool $awaitingStaff @var ?string $chatError @var string $typed @var array $faqs */
$lastAssistant = null;
foreach ($thread as $message) {
    if ($message['sender'] === 'ASSISTANT') $lastAssistant = $message;
}
$suggestions = $lastAssistant['meta']['suggestions'] ?? array_values(HelpAssistantService::SUGGESTIONS);
$chatIcon = '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><path d="M21 11.5a8.4 8.4 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.4 8.4 0 0 1-3.8-.9L3 21l1.9-5.7a8.4 8.4 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.4 8.4 0 0 1 3.8-.9H13a8.5 8.5 0 0 1 8 8v.5Z"/><path d="M8 11h8m-8 4h5"/></svg>';
?>
<link rel="stylesheet" href="<?= public_asset('css/applicant-chat.css') ?>">
<div class="applicant-chat">
  <header class="support-intro"><div><div class="support-eyebrow">YOUR SOLO PARENT PORTAL</div><h2>How can we help you?</h2><p>Ask about your application, requirements, appointments, or ID renewal.</p></div><a href="?section=requirements" class="support-guide-link">Browse requirements <span aria-hidden="true">&rarr;</span></a></header>
  <div class="support-workspace">
    <section class="support-conversation" aria-labelledby="support-conversation-title">
      <header class="support-chat-header"><span class="support-avatar" aria-hidden="true"><?= $chatIcon ?></span><div><h3 id="support-conversation-title">Your support conversation</h3><p>Help Assistant &amp; MSWDO staff</p></div><span class="support-status<?= $awaitingStaff ? ' is-waiting' : '' ?>"><?= $awaitingStaff ? 'Staff reply requested' : 'Assistant guidance' ?></span></header>
      <div class="support-guidance" role="note"><span aria-hidden="true">&#9432;</span><p><strong>Guidance only.</strong> <?= e(HelpAssistantService::DISCLAIMER) ?></p></div>
      <div class="chat-thread" id="chat-thread" role="log" aria-label="Support messages" aria-live="polite" tabindex="0">
        <?php if (!$thread): ?><div class="support-empty"><span class="support-empty-icon" aria-hidden="true"><?= $chatIcon ?></span><h3>Let’s start with your question</h3><p>Type a message below or choose a suggested question. You can ask in English or Filipino.</p></div><?php endif; ?>
        <?php $previousDate = null; foreach ($thread as $i => $m):
            $mine = $m['sender'] === 'APPLICANT';
            $isStaff = $m['sender'] === 'STAFF';
            $who = $mine ? 'You' : ($isStaff ? 'MSWDO staff' . (!empty($m['staff_name']) ? ' · ' . $m['staff_name'] : '') : 'Help Assistant');
            $messageDate = date('Y-m-d', strtotime($m['created_at']));
        ?>
          <?php if ($messageDate !== $previousDate): ?><div class="support-date-divider"><span><?= e(date('F j, Y', strtotime($m['created_at']))) ?></span></div><?php $previousDate = $messageDate; endif; ?>
          <article class="chat-msg <?= $mine ? 'chat-msg-mine' : ($isStaff ? 'chat-msg-staff' : 'chat-msg-bot') ?>"<?= $i === count($thread) - 1 ? ' id="chat-latest"' : '' ?>>
            <div class="chat-meta"><strong><?= e($who) ?></strong><time datetime="<?= e(date('c', strtotime($m['created_at']))) ?>"><?= e(date('g:i A', strtotime($m['created_at']))) ?></time></div>
            <div class="chat-bubble"><?= nl2br(e($m['message'])) ?></div>
            <?php if (!empty($m['handoff'])): ?><span class="support-handoff">Sent to MSWDO staff</span><?php endif; ?>
            <?php $links = array_filter(array_map([HelpAssistantService::class, 'resolveLink'], array_map('strval', $m['meta']['links'] ?? []))); if ($links): ?>
              <div class="chat-links"><?php foreach ($links as [$label, $url]): ?><a href="<?= e($url) ?>" class="chat-link"><?= e($label) ?> <span aria-hidden="true">&rarr;</span></a><?php endforeach; ?></div>
            <?php endif; ?>
          </article>
        <?php endforeach; ?>
      </div>
      <div class="support-composer">
        <?php if ($awaitingStaff): ?><div class="chat-waiting" role="status"><strong>Your question is with MSWDO.</strong><span>A social worker will reply here during office hours: <?= e(setting('office_hours')) ?>.</span></div><?php endif; ?>
        <?php if ($chatError): ?><div class="auth-error" role="alert"><?= e($chatError) ?></div><?php endif; ?>
        <?php if ($suggestions): ?><div class="support-suggestions-heading">Try asking about</div><form method="post" action="<?= e(base_url('applicant/dashboard.php?section=chat')) ?>" class="chat-suggestions" aria-label="Suggested questions">
          <?= csrf_field() ?><input type="hidden" name="action" value="ask">
          <?php foreach ($suggestions as $suggestion): ?><button type="submit" name="message" value="<?= e($suggestion) ?>" class="chat-chip"><?= e($suggestion) ?></button><?php endforeach; ?>
        </form><?php endif; ?>
        <form method="post" action="<?= e(base_url('applicant/dashboard.php?section=chat')) ?>" class="chat-input">
          <?= csrf_field() ?><label for="chat-message">Your message</label>
          <textarea id="chat-message" name="message" rows="2" maxlength="<?= HelpAssistantService::MAX_LENGTH ?>" placeholder="Type your question in English or Filipino…" aria-describedby="chat-message-help chat-character-count"><?= e($typed) ?></textarea>
          <div class="support-compose-meta"><span id="chat-message-help">Enter to send · Shift + Enter for a new line</span><span id="chat-character-count"><?= mb_strlen($typed) ?> / <?= HelpAssistantService::MAX_LENGTH ?></span></div>
          <div class="chat-actions"><button type="submit" name="action" value="ask" class="btn-navy">Ask assistant <span aria-hidden="true">&rarr;</span></button><button type="submit" name="action" value="handoff" class="btn-outline">Ask MSWDO staff</button></div>
        </form>
        <p class="chat-hint">“Ask MSWDO staff” requests a social worker’s reply. You can leave the message empty. Keep passwords and verification codes private.</p>
      </div>
    </section>
    <aside class="support-sidebar" aria-label="Support resources">
      <section class="support-resource-card" aria-labelledby="support-faq-title"><div class="support-resource-heading"><span class="support-resource-icon" aria-hidden="true">?</span><div><h3 id="support-faq-title">Quick answers</h3><p>Frequently asked questions</p></div></div>
        <label for="faq-search" class="visually-hidden">Search frequently asked questions</label><input type="search" id="faq-search" placeholder="Search questions…" class="support-faq-search" aria-controls="faq-list">
        <div id="faq-list"><?php foreach ($faqs as $f): ?><details class="faq-item" data-faq-q="<?= e(mb_strtolower($f['question'] . ' ' . $f['answer'])) ?>"><summary><?= e($f['question']) ?><span aria-hidden="true">+</span></summary><div class="support-faq-answer"><?= nl2br(e($f['answer'])) ?></div></details><?php endforeach; ?>
          <?php if (!$faqs): ?><p class="support-faq-empty">No questions are listed yet. Send us a message for help.</p><?php endif; ?>
        </div><p id="faq-no-results" class="support-faq-empty" role="status" hidden>No matching questions. Try another word or ask in the chat.</p>
      </section>
      <section class="support-resource-card support-office" aria-labelledby="support-office-title"><div class="support-resource-heading"><span class="support-resource-icon" aria-hidden="true"><?= $chatIcon ?></span><div><h3 id="support-office-title">Contact the office</h3><p><?= e(setting('office_name')) ?></p></div></div>
        <dl><div><dt>Visit us</dt><dd><?= e(setting('office_address')) ?></dd></div><div><dt>Office hours</dt><dd><?= e(setting('office_hours')) ?></dd></div><div><dt>Phone</dt><dd><?= e(setting('office_phone')) ?></dd></div><div><dt>Email</dt><dd><?= e(setting('office_email')) ?></dd></div></dl>
        <a href="?section=help#help-contact" class="support-office-link">View help &amp; contact details <span aria-hidden="true">&rarr;</span></a>
      </section>
    </aside>
  </div>
</div>
<script src="<?= public_asset('js/applicant-chat.js') ?>"></script>
